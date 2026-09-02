<?php

declare(strict_types=1);

use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Actions\DecidePrivateSessionRequest;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\PendingPrivateRequest;
use DomainException;
use Laravel\Sanctum\Sanctum;

/*
| Spec 023 · T042 — SC-007. Two decisions on one request.
|
| ⚠️ A SEQUENTIAL «ACCEPT IT TWICE» TEST PASSES AGAINST A BUILD WITH NO CLAIM IN
| IT AT ALL. The second call bounces off `isPending()` one step higher and never
| reaches the write, so the assertion is true of a read-then-save that races
| perfectly happily. That is why the rival here is injected IN THE MIDDLE OF THE
| WINDOW — inside a model event that fires between the pending read and the
| conditional UPDATE. No threads, no sleeps: a callback there IS the other
| decider winning inside exactly that window.
|
| The seam is `Cohort::created`, which the acceptance reaches after its read and
| before its settle. `OpenBroadcastRoom` and `IngestSessionRecordingJob` are
| tested with the same trick against `createRoom()` and `recording()`.
*/

it('lets only one of two overlapping decisions produce a lesson', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();

    Sanctum::actingAs($fx['student']);
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->firstOrFail();

    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    // The rival: it settles the row while our acceptance is mid-transaction,
    // exactly as a second teacher's press on a second screen would.
    Cohort::created(function () use ($request): void {
        PendingPrivateRequest::settle(
            $request,
            PrivateSessionRequest::REJECTED,
            null,
            'رفضه زميل في اللحظة نفسها.',
        );
    });

    expect(fn () => app(DecidePrivateSessionRequest::class)->handle($request, $fx['owner'], true))
        ->toThrow(DomainException::class);

    // The loser's whole transaction is gone: no lesson on the calendar, no seat
    // out of the balance, and no group left standing behind a refused request.
    expect(ClassSession::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(Cohort::query()->withoutWorkspaceScope()->whereNotNull('individual_for_user_id')->count())->toBe(0);

    /*
    | And the loser settled nothing: the request did not become `accepted` and
    | carries no session.
    |
    | ⚠️ IT READS `pending` RATHER THAN `rejected`, AND THAT IS THE TEST HARNESS,
    | NOT THE PRODUCT. Two deciders in production are two connections and the
    | winner's row is already committed; here there is ONE in-memory SQLite
    | connection, so the rival's write lives inside the same transaction and the
    | rollback takes it with it. Asserting `rejected` would be asserting an
    | artefact of the fixture — what this file measures is that the losing
    | decision leaves nothing behind, which is the half a second connection
    | cannot fake.
    */
    expect($request->refresh()->status)->not->toBe(PrivateSessionRequest::ACCEPTED)
        ->and($request->class_session_id)->toBeNull();
});
