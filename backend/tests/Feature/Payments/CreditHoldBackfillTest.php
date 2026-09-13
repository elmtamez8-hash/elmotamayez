<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeBroadcastProvider;

/*
| ٠٣٥ · US2 · T066 — الترحيلُ الضيّق، ومقياسُه.
|
| ⛔ AND THE FIXTURE IS THE WHOLE POINT. The migration writes ZERO rows against
| today's data and that is the correct answer — measured: eighteen bookings stand
| at `booked` and not one of them is on a session that has not happened yet. So a
| test that merely ran it and found nothing would be green against a migration
| with no body at all. A booking on a FUTURE session is built here on purpose, to
| make the right outcome measurable in both directions.
|
| ⚠️ AND THE MIGRATION IS RE-RUN BY HAND. `RefreshDatabase` ran it at boot, long
| before any of these rows existed, so the only way to measure it is to call
| `up()` again — which is also how its re-runnability gets asserted for free.
*/

beforeEach(function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->balance = billingBalance($this->workspace, $this->student, $this->course);
    grantCredits($this->balance, 5, 'backfill-fixture');
});

function runHoldBackfill(): void
{
    $migration = require base_path(
        'app/Modules/Payments/Database/Migrations/2026_09_13_000800_backfill_credit_holds_for_open_bookings.php'
    );

    $migration->up();
}

/** A seat on a session, written straight to the table — no Action, no hold. */
function seatWithoutHold(object $test, ClassSessionStatus $status, ?int $billableSeats = null): int
{
    $session = billableSession($test->workspace, $test->owner, $test->course, seatsTotal: 5);

    $session->forceFill([
        'status' => $status,
        'billable_seats' => $billableSeats,
    ])->save();

    DB::table('session_bookings')->insert([
        'uuid' => (string) Str::uuid(),
        'workspace_id' => $test->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $test->student->getKey(),
        'status' => 'booked',
        'is_billable' => true,
        'booked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) $session->getKey();
}

it('freezes a credit for a seat whose session has not happened yet', function (): void {
    $sessionId = seatWithoutHold($this, ClassSessionStatus::Scheduled);

    runHoldBackfill();

    $hold = DB::table('credit_holds')->where('class_session_id', $sessionId)->first();

    expect($hold)->not->toBeNull()
        ->and($hold->settled_at)->toBeNull()
        ->and((int) $hold->credits)->toBe(1)
        // ⛔ THE COLUMNS THE MODEL LAYER WOULD HAVE SUPPLIED, supplied here. On
        // MySQL a null uuid is a WARNING and stores `''`, after which every
        // later hold on the platform collides with this row and is silently
        // refused.
        ->and((string) $hold->uuid)->not->toBe('')
        ->and((int) $hold->workspace_id)->toBe((int) $this->workspace->getKey());

    expect((int) DB::table('credit_balances')->where('id', $this->balance->getKey())->value('held_credits'))->toBe(1);
});

it('leaves the seats of sessions that already ended alone', function (): void {
    // ⚠️ THE CASE THE NARROW PREDICATE EXISTS FOR, and the only shape today's
    // data actually has. A hold written here is settled by nothing — no close,
    // no cancel, no sweep reaches a session that ended last month — so it is one
    // credit subtracted from this student's available balance for ever, with the
    // nightly invariant green because the row really is unsettled.
    $ended = seatWithoutHold($this, ClassSessionStatus::Completed, billableSeats: 1);
    $frozen = seatWithoutHold($this, ClassSessionStatus::Scheduled, billableSeats: 3);

    runHoldBackfill();

    expect(DB::table('credit_holds')->where('class_session_id', $ended)->count())->toBe(0)
        ->and(DB::table('credit_holds')->where('class_session_id', $frozen)->count())->toBe(0)
        ->and((int) DB::table('credit_balances')->where('id', $this->balance->getKey())->value('held_credits'))->toBe(0);
});

it('writes nothing the second time it runs', function (): void {
    seatWithoutHold($this, ClassSessionStatus::Scheduled);

    runHoldBackfill();
    runHoldBackfill();

    expect(DB::table('credit_holds')->count())->toBe(1)
        ->and((int) DB::table('credit_balances')->where('id', $this->balance->getKey())->value('held_credits'))->toBe(1);
});
