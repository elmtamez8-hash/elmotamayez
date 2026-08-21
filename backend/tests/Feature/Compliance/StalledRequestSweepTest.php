<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Actions\ExecuteDataExport;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Jobs\PruneExpiredExportsJob;
use App\Modules\Compliance\Jobs\RetryStalledDataRequestsJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/**
 * SC-021 — zero requests stuck in `processing` past a declared timeout.
 *
 * ⚠️ THE JOB RUNS WITH `tries: 1` AND NOTHING ELSE SWEEPS THIS STATE. So a worker
 * killed mid-export — a deploy, an OOM, a restart — leaves the request `processing`
 * for ever: `due_at` passes, a legal deadline is missed, and nobody is told,
 * because nothing is broken enough to log. That is the
 * `recording_status = 'ingesting'` family exactly — a state written just before a
 * killable call, and a sweep that asked about a different value.
 *
 * ⚠️ AND THE SWEEP MUST RESET BEFORE IT RE-DISPATCHES. `FulfilDataRequestJob`
 * claims with `WHERE status = 'pending'`, so re-sending a request still marked
 * `processing` queues a job that refuses itself on arrival — for ever, every ten
 * minutes, a sweep that reports success and moves nothing. The second assertion
 * below is the one that fails if the reset is dropped.
 */
function stalledRequestFor(User $subject, int $minutesAgo): DataRequest
{
    $request = app(CreateDataRequest::class)->handle($subject, (string) $subject->uuid, DataRequestType::Export);

    // The shape a killed worker leaves: claimed, stamped, never finished.
    $request->forceFill([
        'status' => DataRequestStatus::Processing->value,
        'last_attempt_at' => now()->subMinutes($minutesAgo),
    ])->save();

    return $request->refresh();
}

beforeEach(function (): void {
    Storage::fake('local');

    $this->subject = User::factory()->create();
});

it('returns a stalled request to pending and sends it again', function (): void {
    Queue::fake();

    $request = stalledRequestFor($this->subject, ComplianceSettings::stalledAfterMinutes() + 5);

    (new RetryStalledDataRequestsJob)->handle();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Pending);

    Queue::assertPushed(
        FulfilDataRequestJob::class,
        fn (FulfilDataRequestJob $job): bool => (new ReflectionClass($job))
            ->getProperty('dataRequestId')
            ->getValue($job) === (int) $request->getKey(),
    );
});

it('leaves a request that is merely slow alone', function (): void {
    Queue::fake();

    $request = stalledRequestFor($this->subject, 1);

    (new RetryStalledDataRequestsJob)->handle();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Processing);

    Queue::assertNothingPushed();
});

/*
 * ⚠️ AND THE RE-SENT JOB MUST ACTUALLY RUN. Asserting only that something was
 * pushed passes against a sweep that re-dispatches without resetting the status —
 * the queue fills, every job refuses itself on arrival, and the request is stuck
 * exactly as before with a busier queue. This runs the whole loop for real.
 */
it('completes the request end to end after the sweep', function (): void {
    $request = stalledRequestFor($this->subject, ComplianceSettings::stalledAfterMinutes() + 5);

    (new RetryStalledDataRequestsJob)->handle();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and($request->export_path)->not->toBeNull();
});

/*
 * ⚠️ A FAILED EXPORT STAYS `processing`, WHICH IS THE ONE STATE THE SWEEP READS.
 *
 * The tidy-looking alternative — catching the failure and putting the request back
 * to `pending` — is a dead end: the initial dispatch has already fired, `tries: 1`
 * means the queue will not retry, and NOTHING sweeps `pending`. The request would
 * sit there while `due_at` passed, silently, which is the
 * `recording_status = 'ingesting'` family reached by trying to be helpful. It
 * shipped that way and this is what would have caught it.
 */
it('leaves a failed export where the sweep can find it', function (): void {
    $request = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);

    // The narrowest way to make the walk fail for real: the registry is what
    // `ExecuteDataExport` iterates, and a member that throws is the shape of any
    // broken module.
    app()->bind(ExecuteDataExport::class, fn (): ExecuteDataExport => new class extends ExecuteDataExport
    {
        public function __construct() {}

        public function handle(DataRequest $request): array
        {
            throw new RuntimeException('module exploded');
        }
    });

    try {
        FulfilDataRequestJob::dispatchSync((int) $request->getKey());
    } catch (RuntimeException) {
        // Rethrown on purpose, so the worker records a failure rather than
        // reporting success over a request that produced nothing.
    }

    expect($request->refresh()->status)->toBe(DataRequestStatus::Processing);

    // And the sweep does pick it up, once it is old enough.
    $request->forceFill(['last_attempt_at' => now()->subMinutes(ComplianceSettings::stalledAfterMinutes() + 5)])->save();

    Queue::fake();
    (new RetryStalledDataRequestsJob)->handle();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Pending);
    Queue::assertPushed(FulfilDataRequestJob::class);
});

/*
 * ⚠️ AN ERASURE REQUEST IS NEVER CLAIMED, AND CLAIMING ONE WAS AN INFINITE LOOP.
 *
 * `ExecuteDataErasure` lands with US4. Until it does, a job that claimed the
 * request to `processing` and then returned took a lock nothing releases: the sweep
 * found it stale, reset it, re-dispatched, and it claimed and returned again —
 * every thirty minutes for ever, logging a stall each time, while the person's
 * request never moved and was never refused.
 */
it('does not claim a request it cannot run', function (): void {
    $request = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Erasure);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    expect($request->refresh()->status)->toBe(DataRequestStatus::Pending)
        ->and($request->last_attempt_at)->toBeNull();
});

/*
 * ⚠️ AND IT IS REFUSED AT THE DOOR AS WELL, WHICH IS THE HONEST HALF.
 *
 * The job not claiming it stops the loop; it does not tell the person anything. A
 * request accepted with a 201 and then never executed is a row somebody believes is
 * deleting their data. A 422 says so, and matches what the screen already says.
 */
it('refuses an erasure request over the endpoint until US4 lands', function (): void {
    Sanctum::actingAs($this->subject);

    $this->postJson('/api/v1/privacy/requests', [
        'type' => DataRequestType::Erasure->value,
    ])->assertStatus(422);

    expect(DataRequest::query()->count())->toBe(0);
});

/*
 * ⚠️ THE STREAM ROUTE IS GUEST-KEYED, AND THE ACCOUNT-KEYED LIMITER THERE IS ONE
 * GLOBAL BUCKET.
 *
 * `throttle:data-rights` keys on `'user:'.$request->user()?->getKey()`, and this
 * route carries no `auth:sanctum` on purpose — a browser following a `302` to
 * another origin sends no `Authorization` header. So `user()` is null and every
 * anonymous hit shares the key `'user:'`: six a minute for the WHOLE PLATFORM, and
 * the second person to download their own archive in the same minute is refused it.
 * That is the shared-counter defect that got inline throttles banned, wearing a
 * named limiter — which is why this asserts the NAME rather than a behaviour.
 */
it('keys the archive stream on the address, not on an absent account', function (): void {
    $route = collect(Route::getRoutes())->first(
        fn ($route): bool => $route->getName() === 'compliance.exports.stream',
    );

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain('throttle:public')
        ->and($route?->gatherMiddleware())->not->toContain('throttle:data-rights')
        ->and($route?->gatherMiddleware())->toContain('signed');
});

/*
 * FR-018 — the archive does not outlive its link.
 *
 * ⚠️ THE FILE GOES AND THE REQUEST ROW STAYS. FR-026 wants a record that a request
 * was made and answered; what is deleted is the copy of the data, not the audit of
 * it. Without this job the archives accumulate for ever, and each one is everything
 * the platform knows about one person in a single object.
 */
it('deletes an expired archive and keeps the record of it', function (): void {
    $request = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $path = (string) $request->refresh()->export_path;

    expect(Storage::disk(ComplianceSettings::exportDisk())->exists($path))->toBeTrue();

    $request->forceFill(['export_expires_at' => now()->subHour()])->save();

    (new PruneExpiredExportsJob)->handle();

    expect(Storage::disk(ComplianceSettings::exportDisk())->exists($path))->toBeFalse()
        ->and($request->refresh()->export_path)->toBeNull()
        ->and(DataRequest::query()->whereKey($request->getKey())->exists())->toBeTrue()
        ->and($request->status)->toBe(DataRequestStatus::Completed);
});
