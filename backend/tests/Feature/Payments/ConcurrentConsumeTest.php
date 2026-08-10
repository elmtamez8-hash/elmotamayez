<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Exceptions\InsufficientCreditsException;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Payments\Support\CreditLedger;

/*
| SC-003 · SC-004 — two claims on a balance that covers one.
|
| The guard is one conditional UPDATE, not a row lock. `lockForUpdate()` is a
| no-op on SQLite, so a test written around it passes here and says nothing about
| the MySQL it will run on. Two sequential calls travel exactly the path
| concurrent ones do: the second finds the condition false and is refused.
| Precedent: SeatConcurrencyTest.
|
| And the floor is enforced only where the caller asks for it — see
| `records a debt below the floor when the floor is not enforced` at the bottom,
| which is the charge path, not a loophole.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
    $this->balance = billingBalance($this->workspace, $this->student);
    $this->ledger = app(CreditLedger::class);

    // A recorded consent, because both files test balances that hold a real
    // deferral ceiling — and since US9 the floor asks for one (FR-048). Without
    // it every ceiling below reads as zero and the tests pass for the wrong
    // reason.
    TermsConsent::factory()->create([
        'user_id' => $this->student->getKey(),
        'student_user_id' => $this->student->getKey(),
    ]);

});

function consume(int $credits, int $source, bool $enforce = true, bool $zeroFloor = true): void
{
    app(CreditLedger::class)->post(new CreditMovement(
        balance: test()->balance,
        type: CreditTransactionType::Consume,
        credits: -$credits,
        sourceType: 'session_seat',
        sourceId: $source,
        enforceFloor: $enforce,
        zeroFloor: $zeroFloor,
    ));
}

it('accepts the first claim and refuses the second on the last credit', function (): void {
    grantCredits($this->balance, 1, 'last-credit');

    consume(1, 1);

    expect(fn () => consume(1, 2))->toThrow(InsufficientCreditsException::class);

    expect($this->balance->refresh()->remaining_credits)->toBe(0);
});

it('never lets the balance fall below the floor under repeated claims', function (): void {
    grantCredits($this->balance, 3, 'repeat');

    $accepted = 0;

    foreach (range(1, 8) as $source) {
        try {
            consume(1, $source);
            $accepted++;
        } catch (InsufficientCreditsException) {
            // Expected once the balance empties.
        }
    }

    expect($accepted)->toBe(3)
        ->and($this->balance->refresh()->remaining_credits)->toBe(0);
});

it('rolls the refused entry back with the balance', function (): void {
    grantCredits($this->balance, 1, 'rollback');

    consume(1, 1);

    try {
        consume(1, 2);
    } catch (InsufficientCreditsException) {
        // The point of the case.
    }

    // Two entries would mean the ledger recorded a movement that never happened,
    // and SC-001 would fail on the next comparison. The refusal happens inside
    // the transaction that wrote the entry, so both go back together.
    expect($this->balance->transactions()->withoutWorkspaceScope()->count())->toBe(2);
});

it('lets a deferring mode go negative down to the limit and no further', function (): void {
    $this->balance->forceFill(['credit_limit_credits' => 2])->save();

    // Manual collection allows deferral, so the floor is −limit rather than zero.
    expect(app(CreditLedger::class)->floorFor($this->balance, BillingMode::ManualCollection, consentCurrent: true))->toBe(-2);

    consume(1, 1, enforce: true, zeroFloor: false);
    consume(1, 2, enforce: true, zeroFloor: false);

    expect($this->balance->refresh()->remaining_credits)->toBe(-2);

    expect(fn () => consume(1, 3, enforce: true, zeroFloor: false))
        ->toThrow(InsufficientCreditsException::class);

    expect($this->balance->refresh()->remaining_credits)->toBe(-2);
});

/*
| data-model §5هـ — the floor guards BOOKING, not the recording of a debt.
|
| A delivered session is owed whether or not the student can pay: spec 014 has
| already earned the teacher their fee from the same event. Refusing to write the
| entry would leave the platform owing money with no claim against anyone — and
| exam mode, which forces the floor to zero, would make that systematic.
*/
it('records a debt below the floor when the floor is not enforced', function (): void {
    consume(3, 1, enforce: false);

    expect($this->balance->refresh()->remaining_credits)->toBe(-3)
        ->and($this->balance->consumed_credits)->toBe(3);
});
