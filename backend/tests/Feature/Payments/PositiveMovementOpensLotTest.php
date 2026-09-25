<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Jobs\ReconcileCreditBalancesJob;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Models\CreditReconciliationRun;
use App\Modules\Payments\Support\CreditLedger;
use App\Shared\Contracts\SessionSeatCharges;
use Carbon\CarbonImmutable;

/*
| Every movement that RAISES a balance opens a lot.
|
| ⛔ Only purchases and bonuses used to. So the reversal of a charge when an
| excuse is accepted after the room closed (+1, `Adjustment`) and a manual
| correction upwards raised `remaining_credits` with no lot behind it: the lots
| fell short of the balance, `ReconcileCreditBalancesJob` raised a false
| `lot_remainder` finding every night for that student, and with expiry on the
| returned credit could never expire. Each case asserts the lot arithmetic
| directly AND through the nightly job, so neither can pass by the other missing.
*/
beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
    $this->balance = billingBalance($this->workspace, $this->student);
});

function positiveLotCharge(int $classSessionId): void
{
    app(CreditLedger::class)->post(new CreditMovement(
        balance: test()->balance,
        type: CreditTransactionType::Consume,
        credits: -1,
        sourceType: 'class_session',
        sourceId: $classSessionId,
    ));
}

function positiveLotFindings(): int
{
    (new ReconcileCreditBalancesJob)->handle();

    return (int) CreditReconciliationRun::query()->latest('id')->value('findings_count');
}

function positiveLotHeld(): int
{
    return (int) CreditLot::query()->withoutWorkspaceScope()
        ->where('credit_balance_id', test()->balance->getKey())
        ->sum('credits_remaining');
}

it('gives a reversed charge a lot, so the reconciliation finds nothing', function (): void {
    grantCredits($this->balance, 4, 'package');
    positiveLotCharge(9001);

    expect(app(SessionSeatCharges::class)->reverse($this->student, 9001, 'عذر مقبول'))->toBeTrue();

    expect($this->balance->refresh()->remaining_credits)->toBe(4)
        ->and(positiveLotHeld())->toBe(4)
        ->and(positiveLotFindings())->toBe(0);
});

it('gives a manual correction upwards a lot, so the reconciliation finds nothing', function (): void {
    grantCredits($this->balance, 4, 'package');

    app(AdjustCredits::class)->handle(
        $this->balance,
        CreditTransactionType::Adjustment,
        2,
        'تصحيح يدوي',
        'positive-adjustment-1',
    );

    expect($this->balance->refresh()->remaining_credits)->toBe(6)
        ->and(positiveLotHeld())->toBe(6)
        ->and(positiveLotFindings())->toBe(0);
});

it('opens no lot for a correction downwards', function (): void {
    grantCredits($this->balance, 4, 'package');

    app(AdjustCredits::class)->handle(
        $this->balance,
        CreditTransactionType::Adjustment,
        -1,
        'تصحيح يدوي',
        'negative-adjustment-1',
    );

    expect(CreditLot::query()->withoutWorkspaceScope()->where('credit_balance_id', $this->balance->getKey())->count())->toBe(1)
        ->and(positiveLotHeld())->toBe(3)
        ->and(positiveLotFindings())->toBe(0);
});

it('returns a reversed charge into a lot that expires when the one it came from does', function (): void {
    $expires = CarbonImmutable::now()->addDays(20)->startOfSecond();

    grantCredits($this->balance, 4, 'dated-package', $expires);
    positiveLotCharge(9002);

    app(SessionSeatCharges::class)->reverse($this->student, 9002, 'عذر مقبول');

    $returned = CreditLot::query()->withoutWorkspaceScope()
        ->where('credit_balance_id', $this->balance->getKey())
        ->orderByDesc('id')
        ->firstOrFail();

    expect($returned->credits_total)->toBe(1)
        ->and(CarbonImmutable::parse($returned->expires_at)->toDateTimeString())->toBe($expires->toDateTimeString());
});
