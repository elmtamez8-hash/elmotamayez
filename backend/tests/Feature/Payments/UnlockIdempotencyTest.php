<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\SendSessionReportsJob;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Actions\UnlockSessionContent;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Models\SessionUnlock;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionContentAccess;
use DomainException;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · SC-003 · T024 — ONE CONSENT SPENDS EXACTLY ONE CREDIT, HOWEVER OFTEN IT
| IS PRESSED.
|
| ⛔ AND IT IS NOT MEASURED AS «THE DEDUCTIONS EQUAL THE UNLOCKS», which is the
| shape SC-003 is written in and the shape that proves nothing. On a build with
| no feature at all, zero equals zero. On a build carrying the exact defect
| FR-023 names — the unlock entry colliding with the SEAT's entry on the ledger's
| four-column idempotency key, `insertOrIgnore` writing zero rows, `post()`
| returning null, and the caller reading null as «already done» — zero ALSO
| equals zero, while the content opens for nothing. An equality that is constant
| under the failure it is meant to catch is not a measurement.
|
| Four assertions instead, and each one fails on a different defect:
|
|  · exactly ONE `session_unlocks` row  ⇒ the unique key bites;
|  · exactly ONE entry at `source_type = 'session_unlock'` ⇒ the movement was
|    really posted, not swallowed;
|  · the SEAT's own `class_session` entry is still there, UNCHANGED ⇒ FR-023's
|    independent key — this is the assertion the equality cannot make;
|  · the balance fell by exactly one, read before and after.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class]);
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 1);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $this->student, $this->course);

    app(BookSeat::class)->handle($this->session->refresh(), $this->student);

    // The teacher accepted the excuse before the room closed, so the seat is
    // exempt — the only state in which a consent is possible at all. An already
    // open session cannot be bought a second time, which is FR-011 and is why
    // this fixture cannot be «a student who attended».
    SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $this->session->getKey())
        ->update(['excused_at' => now(), 'excused_by_user_id' => $this->owner->getKey()]);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
    $this->session = deliverBillableSession($this->session->refresh(), $this->owner, []);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course);
});

/** Consent rows only — T043's automatic openings cost zero and are a different fact. */
function unlockConsentRows(): int
{
    return SessionUnlock::query()->withoutWorkspaceScope()
        ->where('reason', SessionUnlock::REASON_CONSENT)
        ->count();
}

function unlockEntriesFrom(string $sourceType): int
{
    return CreditTransaction::query()->withoutWorkspaceScope()
        ->where('source_type', $sourceType)
        ->count();
}

it('spends exactly one credit however many presses arrive together', function (): void {
    $before = (int) $this->balance->refresh()->remaining_credits;

    // The seat's own entry, written at close. It is what the unlock must NOT
    // collide with, so it is read before the unlock and compared after.
    $seatEntry = CreditTransaction::query()->withoutWorkspaceScope()
        ->where('source_type', 'class_session')
        ->where('source_id', $this->session->getKey())
        ->firstOrFail();

    /*
    | ⛔ THE SECOND PRESS IS CONCURRENT, NOT SEQUENTIAL, and calling the Action
    | twice IS the concurrent shape rather than an approximation of it. The read
    | that would make a sequential second call return early lives in the
    | CONTROLLER; the Action has none by design, because a read-then-write here
    | is the race two taps win together. So both calls reach the insert and the
    | unique key is what separates them — which is exactly what two workers do.
    */
    app(UnlockSessionContent::class)->handle($this->student, $this->session);

    expect(fn () => app(UnlockSessionContent::class)->handle($this->student, $this->session))
        ->toThrow(DomainException::class);

    expect(unlockConsentRows())->toBe(1)
        ->and(unlockEntriesFrom('session_unlock'))->toBe(1)
        /*
        | ⚠️ FR-023, AND THE ONE ASSERTION AN EQUALITY CANNOT MAKE. The seat's
        | charge already occupies `('class_session', <this session>)` on the
        | ledger's four-column key. Post the unlock under the same source and
        | `insertOrIgnore` writes nothing, the read-back finds the SEAT's row,
        | and the content opens with no charge and no record — with every count
        | in this file still agreeing with itself.
        */
        ->and(CreditTransaction::query()->withoutWorkspaceScope()
            ->whereKey($seatEntry->getKey())->value('credits'))
        ->toBe($seatEntry->credits)
        ->and(unlockEntriesFrom('class_session'))->toBe(1)
        // And the money, which is the thing the student actually feels.
        ->and((int) $this->balance->refresh()->remaining_credits)->toBe($before - 1)
        ->and(app(SessionContentAccess::class)
            ->mayOpenSessionContent($this->student, (int) $this->session->getKey()))->toBeTrue();
});

it('refuses a second consent over HTTP with a 409 and charges nothing more', function (): void {
    app(UnlockSessionContent::class)->handle($this->student, $this->session);

    $after = (int) $this->balance->refresh()->remaining_credits;

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/unlock")
        // ⚠️ 409 AND NOT 200. A repeated press is «you already own this», which
        // is a different sentence from «bought» — and a 200 here is what a
        // client turns into a second «تم الخصم» toast for a credit nobody spent.
        ->assertStatus(409);

    expect(unlockConsentRows())->toBe(1)
        ->and((int) $this->balance->refresh()->remaining_credits)->toBe($after);
});
