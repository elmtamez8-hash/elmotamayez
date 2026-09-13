<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\AbandonClassSession;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CancelBooking;
use App\Modules\LiveSessions\Actions\CancelClassSession;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Payments\Actions\SettleCreditHold;
use App\Modules\Payments\Models\CreditHold;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionCreditHolds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · US2 · T054 — «الحجزُ يُجمِّدُ الرصيدَ ولا يخصمُه»، بدايةً ونهايةً.
|
| ⛔ AND THE COUNTER IS THE SUBJECT, NOT THE ROW. `credit_holds` is read by
| nothing in the product — which is why `ReconcileCreditBalancesJob` had to grow
| a fourth invariant for it — so a test that counts rows and never reads
| `held_credits` measures the half nobody depends on. Every case here asserts the
| counter, and both settlement cases assert `remaining_credits` as well: a
| release that quietly refunds, or a settlement that also deducts, is invisible
| from the hold table alone.
|
| ⛔ AND THE TWO CONCURRENCY CASES ARE THIS FILE'S REASON TO EXIST. Called twice
| in a row, the second `place()` reads the counter the first one already moved
| and refuses at the claim — so «two seats, one credit» is green against a build
| whose claim is a read followed by a write. The rival therefore runs in the
| window between the writer's READ and its WRITE, which is where two real workers
| meet: `DB::beforeExecuting` fires there with no threads, no sleeps, and no seam
| added to production code for a test's benefit.
|
| ⚠️ AND BOTH RIVALS FIRE INSIDE THE WRITER'S OWN TRANSACTION, WHICH IS SAFE ONLY
| BECAUSE NEITHER WRITER ROLLS BACK. Every test here runs on ONE in-memory SQLite
| connection, so a rival started inside an open transaction JOINS it: its writes
| commit with the winner's and are visible to it, which is what these two cases
| need, and a rival whose caller then THREW would vanish with it — which is why
| the needles are chosen on paths that commit. `CohortConcurrencyTest` fires
| before the transaction for the opposite reason: there, the rival must survive
| the first caller's failure.
*/

beforeEach(function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);
    $this->other = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course);
    $this->holds = app(SessionCreditHolds::class);
});

/**
 * Run `$rival` in the window before the first query matching `$needle`.
 *
 * ⚠️ NAMED AFTER THIS FILE ON PURPOSE. A Pest helper is a GLOBAL function, so
 * two files may not spell one name differently — `interposeOnce` and
 * `interposeAdaptiveOnce` already exist for exactly this, and a third bare
 * `interpose` would be a fatal redeclaration the moment one parallel worker
 * loads two of them.
 */
function interposeHoldOnce(string $needle, Closure $rival): void
{
    $fired = false;

    DB::beforeExecuting(function (string $query) use (&$fired, $needle, $rival): void {
        // The guard is not decoration: the rival issues queries of its own, and
        // without it this recurses until the stack gives out.
        if ($fired || ! str_contains($query, $needle)) {
            return;
        }

        $fired = true;

        $rival();
    });
}

function heldCounter(int $balanceId): int
{
    return (int) DB::table('credit_balances')->where('id', $balanceId)->value('held_credits');
}

function liveHold(int $sessionId): ?object
{
    return DB::table('credit_holds')->where('class_session_id', $sessionId)->first();
}

function placeHold(object $test, object $session): object
{
    return $test->holds->place(
        $test->student,
        (int) $session->getKey(),
        (int) $test->course->getKey(),
        (int) $test->workspace->getKey(),
    );
}

it('freezes a credit without spending it', function (): void {
    grantCredits($this->balance, 3, 'lifecycle-place');

    $result = placeHold($this, $this->session);

    expect($result->granted)->toBeTrue()
        // ⛔ THE WHOLE POINT OF THE PHASE: the balance did NOT fall. A hold that
        // deducts is the pre-035 rule wearing a new column name.
        ->and((int) $this->balance->refresh()->remaining_credits)->toBe(3)
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(1)
        ->and($result->availableCredits)->toBe(2);

    $hold = liveHold((int) $this->session->getKey());

    expect($hold)->not->toBeNull()
        ->and($hold->settled_at)->toBeNull()
        // The sequence starts at zero and discriminates the unique key; a
        // revived seat is what makes a second row legitimate (T055).
        ->and((int) $hold->hold_seq)->toBe(0)
        ->and((string) $hold->uuid)->not->toBe('');
});

it('settles to charged, and the deduction is not its business', function (): void {
    grantCredits($this->balance, 3, 'lifecycle-charge');
    placeHold($this, $this->session);

    $settled = app(SettleCreditHold::class)
        ->handle((int) $this->session->getKey(), CreditHold::OUTCOME_CHARGED);

    expect($settled)->toBe(1)
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(0);

    $hold = liveHold((int) $this->session->getKey());

    expect($hold->outcome)->toBe(CreditHold::OUTCOME_CHARGED)
        ->and($hold->settled_at)->not->toBeNull();

    /*
     | ⚠️ AND THE BALANCE HAS NOT MOVED. Settling to «charged» records that the
     | freeze ENDED; the credit itself leaves through the LEDGER, in
     | `ChargeSessionSeats`. A settlement that also deducted would take the credit
     | twice — once here and once at the entry — and the balance would stop
     | equalling the sum of its entries, which is the one drift the nightly
     | reconcile can see and cannot repair.
    */
    expect((int) $this->balance->refresh()->remaining_credits)->toBe(3);
});

it('settles to released: the credit comes back and nothing was spent', function (): void {
    grantCredits($this->balance, 3, 'lifecycle-release');
    placeHold($this, $this->session);

    expect($this->holds->release((int) $this->session->getKey()))->toBe(1)
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(0)
        ->and((int) $this->balance->refresh()->remaining_credits)->toBe(3)
        ->and($this->holds->availableFor($this->student, (int) $this->course->getKey()))->toBe(3);

    expect(liveHold((int) $this->session->getKey())->outcome)->toBe(CreditHold::OUTCOME_RELEASED);
});

it('settles once when two settlers meet on the same seat', function (): void {
    grantCredits($this->balance, 3, 'lifecycle-double');
    placeHold($this, $this->session);

    $holds = $this->holds;
    $sessionId = (int) $this->session->getKey();
    $rivalSettled = null;

    /*
     | The rival runs while the first settler sits between reading the balances it
     | is about to correct and stamping the rows it found — the window a
     | read-then-write claim leaves open. Both settlers therefore start from «one
     | live hold», which is the only state in which the guard is asked anything.
    */
    interposeHoldOnce('select distinct "credit_balance_id"', function () use ($holds, $sessionId, &$rivalSettled): void {
        $rivalSettled = $holds->release($sessionId);

        // A minute apart, so a re-stamp shows in the column instead of hiding
        // behind two writes landing in the same second.
        Carbon::setTestNow(now()->addMinute());
    });

    $loser = $holds->release($sessionId);
    $hold = liveHold($sessionId);

    expect($rivalSettled)->toBe(1)
        // ⛔ THE LOSER SETTLES NOTHING. `WHERE settled_at IS NULL` is the check
        // AND the claim; without it this is 1, the row is stamped a second time,
        // and a counter written relatively goes negative with nothing in the
        // whole product reading `credit_holds` to notice.
        ->and($loser)->toBe(0)
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(0)
        ->and((int) $this->balance->refresh()->remaining_credits)->toBe(3);

    // The stamp belongs to the winner, and the minute that passed is what makes
    // «not rewritten» measurable rather than asserted.
    expect(Carbon::parse($hold->settled_at)->lessThan(now()))->toBeTrue();

    Carbon::setTestNow();
});

it('gives one credit to one of two seats claimed at once', function (): void {
    // quickstart §٢ — إلزاميّة. ⚠️ AND THE TWO SEATS ARE ON DIFFERENT SESSIONS:
    // two claims on ONE session collide on the seat index long before either
    // reaches the credit floor, so that fixture measures the seat guard twice
    // and this rule not at all.
    grantCredits($this->balance, 1, 'lifecycle-race');

    $test = $this;
    $rival = null;

    /*
     | ⛔ THE NEEDLE IS THE CLAIM ITSELF, AND THE FIRST DRAFT OF THIS CASE WAS
     | VACUOUS. Interposed on the course lookup — before the transaction — the
     | rival finishes entirely before the first caller reads anything, so the
     | first caller simply sees `held = 1` and refuses: the case passed against a
     | build whose claim was a read followed by a write, which is the only build
     | it exists to fail. Fired here, both callers have decided there is room
     | before either has written, which is the race itself.
    */
    interposeHoldOnce('update "credit_balances" set "held_credits"', function () use ($test, &$rival): void {
        $rival = placeHold($test, $test->other);
    });

    $first = placeHold($this, $this->session);

    $granted = array_filter(
        [$first, $rival],
        static fn (?object $result): bool => $result !== null && $result->granted,
    );

    expect($rival)->not->toBeNull()
        ->and($granted)->toHaveCount(1)
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(1)
        ->and(DB::table('credit_holds')->count())->toBe(1);

    // ⚠️ AND THE REFUSAL CARRIES THE DATE. «لا رصيد» with nothing after it is a
    // refusal the student can do nothing with (FR-013).
    $refused = $first->granted ? $rival : $first;

    expect($refused->availableCredits)->toBe(0)
        ->and($refused->firstReleaseAt)->not->toBeNull();
});

it('holds a revived seat a second time instead of holding it for free', function (): void {
    /*
     | ٠٣٥ · T055 — احجزْ ⇒ أُفرِجَ ⇒ خُتِمَ ⇒ أُحيِيَ.
     |
     | ⚠️ AND THE FIXTURE IS BUILT BY HAND ON PURPOSE. `reviveReleasedSeat()` has
     | exactly ONE caller today (`ClaimSubscriptionSeats`), and T059 exempts a
     | subscription seat from holding credit at all — so there is no production
     | road to this state yet, and dressing the case up as one would read as
     | coverage of a live path. What it measures is the COLUMN: `hold_seq` exists
     | so that a seat held twice in its life is two rows.
     |
     | ⛔ A two-column key refuses the second one — and the two ways of writing
     | that are both defects rather than one: with `insertOrIgnore` the revived
     | seat has NO hold at all (a free session, with the nightly invariant green
     | because nothing was ever written), and written directly it is a 500 on a
     | perfectly legitimate re-booking.
    */
    grantCredits($this->balance, 3, 'lifecycle-revive');

    expect(placeHold($this, $this->session)->granted)->toBeTrue();
    expect($this->holds->release((int) $this->session->getKey()))->toBe(1);

    $again = placeHold($this, $this->session);

    expect($again->granted)->toBeTrue()
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(1)
        ->and((int) $this->balance->refresh()->remaining_credits)->toBe(3);

    $rows = DB::table('credit_holds')
        ->where('class_session_id', $this->session->getKey())
        ->orderBy('hold_seq')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->hold_seq)->toBe(0)
        ->and($rows[0]->settled_at)->not->toBeNull()
        ->and((int) $rows[1]->hold_seq)->toBe(1)
        // The live one is the revival, and it is the only one the counter and
        // the sweep may see.
        ->and($rows[1]->settled_at)->toBeNull();
});

it('gives the credit back when the seat is given up in time, and holds it when it is not', function (): void {
    /*
     | ٠٣٥ · T060 — ⛔ THE TWO ARMS OF `CancelBooking::handle()`, IN ONE CASE
     | BECAUSE ONE ARM ALONE PASSES AGAINST A BUILD THAT RELEASES ALWAYS.
     |
     | A late cancellation keeps the seat and keeps `is_billable`, and that seat
     | is exactly what `CloseClassSession` charges at the end. Freeing its credit
     | here lets the student freeze it again for another hour before the charge
     | lands — and the floor is switched OFF in the charge path on purpose,
     | because a session that was delivered is owed whether or not anyone can pay
     | for it. So the balance goes negative and the student reads as in arrears,
     | over a button that behaved correctly.
    */
    grantCredits($this->balance, 5, 'lifecycle-cancel');

    // The window is 1440 minutes, so one session is a whisker inside the
    // deadline and the other a whisker past it.
    $inTime = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 2);
    $inTime->forceFill([
        'starts_at' => now()->addHours(24)->addMinutes(5),
        'ends_at' => now()->addHours(25)->addMinutes(5),
    ])->save();

    $tooLate = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 2);
    $tooLate->forceFill([
        'starts_at' => now()->addHours(24)->subMinutes(5),
        'ends_at' => now()->addHours(25)->subMinutes(5),
    ])->save();

    app(BookSeat::class)->handle($inTime->refresh(), $this->student);
    app(BookSeat::class)->handle($tooLate->refresh(), $this->student);

    expect(heldCounter((int) $this->balance->getKey()))->toBe(2);

    $cancel = app(CancelBooking::class);

    $cancel->handle(SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $inTime->getKey())->firstOrFail());
    $cancel->handle(SessionBooking::query()->withoutWorkspaceScope()
        ->where('class_session_id', $tooLate->getKey())->firstOrFail());

    expect(liveHold((int) $inTime->getKey())->settled_at)->not->toBeNull()
        ->and(liveHold((int) $inTime->getKey())->outcome)->toBe(CreditHold::OUTCOME_RELEASED)
        // ⛔ AND THE LATE ONE IS STILL FROZEN. This is the assertion a
        // well-meaning "release on every cancellation" would fail.
        ->and(liveHold((int) $tooLate->getKey())->settled_at)->toBeNull()
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(1);
});

it('gives every credit back when the teacher never turned up at all', function (): void {
    /*
     | ٠٣٥ · T062 — `AbandonClassSession` is the state with no close at all:
     | nothing was ever scheduled against the session, so no register, no
     | `SessionDelivered`, no charge — and none of the six release doors runs
     | either, because no seat was given up. Without the arm there, these credits
     | stay frozen for ever and the nightly invariant is green about it.
    */
    grantCredits($this->balance, 3, 'lifecycle-abandon');
    app(BookSeat::class)->handle($this->session->refresh(), $this->student);

    expect(heldCounter((int) $this->balance->getKey()))->toBe(1);

    app(AbandonClassSession::class)->handle($this->session->refresh());

    expect(liveHold((int) $this->session->getKey())->outcome)->toBe(CreditHold::OUTCOME_RELEASED)
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(0);
});

it('gives every credit back when the teacher calls the hour off', function (): void {
    // T060's bulk door: two statements whatever the seat count, and nobody on a
    // cancelled hour is billable.
    grantCredits($this->balance, 3, 'lifecycle-cancel-session');
    app(BookSeat::class)->handle($this->session->refresh(), $this->student);

    app(CancelClassSession::class)->handle($this->session->refresh(), 'ظرفٌ طارئ');

    expect(liveHold((int) $this->session->getKey())->outcome)->toBe(CreditHold::OUTCOME_RELEASED)
        ->and(heldCounter((int) $this->balance->getKey()))->toBe(0);
});
