<?php

declare(strict_types=1);

use App\Modules\Payments\Enums\BillingMode;
use App\Modules\Payments\Enums\ZeroBalanceBehavior;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Modules\Payments\Support\CreditLedger;

/*
| data-model §5أ — the one predicate, on its own.
|
| A unit test because the predicate is arithmetic and deserves to fail for
| arithmetic reasons: a Feature test that boots a workspace would report the same
| red for a broken factory.
|
| ⚠️ The MySQL trap this predicate carries in SQL — ERROR 1690 BIGINT UNSIGNED
| out of range, raised when `remaining + limit >= n` is evaluated at
| `remaining = −3` — CANNOT be reproduced on SQLite, which has no unsigned
| arithmetic to overflow. Nothing here can catch it. The guard is the
| `CAST(... AS SIGNED)` written into CreditLedger::applyToBalance and the signed
| column widths declared in the migration, both of which carry the reason in a
| comment beside them.
*/

function balanceWith(int $remaining, int $limit): CreditBalance
{
    $balance = new CreditBalance;
    $balance->remaining_credits = $remaining;
    $balance->credit_limit_credits = $limit;

    return $balance;
}

beforeEach(function (): void {
    $this->ledger = new CreditLedger(new BillingSettings, new ConsentRegistry);
});

it('forces the floor to zero in prepaid mode whatever the limit says', function (): void {
    expect($this->ledger->floorFor(balanceWith(0, 5), BillingMode::PrepaidCredits))->toBe(0);
});

it('forces the floor to zero inside an exam window even in a deferring mode', function (): void {
    expect($this->ledger->floorFor(balanceWith(0, 5), BillingMode::ManualCollection, insideExamWindow: true))
        ->toBe(0);
});

it('uses minus the limit in a deferring mode, and only with a current consent', function (): void {
    expect($this->ledger->floorFor(balanceWith(0, 5), BillingMode::ManualCollection, consentCurrent: true))
        ->toBe(-5);

    // ⚠️ THE DEFAULT IS THE REFUSAL, not the permission (FR-048). Asserted here
    // rather than assumed: a caller that forgets the argument must get the floor
    // that tells the student to pay, not the one that lets debt run on an
    // agreement nobody gave.
    expect($this->ledger->floorFor(balanceWith(0, 5), BillingMode::ManualCollection))->toBe(0);
});

/*
| The default case, and the whole reason the predicate was rewritten.
|
| `remaining = 0, limit = 0` is every student before they have bought anything.
| The earlier formulation answered "not withheld" there — which reads as "let
| them book" for exactly the population the guard exists for.
*/
it('withholds at the default state of zero credits and zero limit', function (): void {
    $balance = balanceWith(0, 0);

    expect($this->ledger->isBlocked($balance, $this->ledger->floorFor($balance, BillingMode::PrepaidCredits), ZeroBalanceBehavior::Block))
        ->toBeTrue();
});

it('does not withhold while a credit remains', function (): void {
    $balance = balanceWith(1, 0);

    expect($this->ledger->isBlocked($balance, $this->ledger->floorFor($balance, BillingMode::PrepaidCredits), ZeroBalanceBehavior::Block))
        ->toBeFalse();
});

it('does not withhold inside an unused ceiling', function (): void {
    $balance = balanceWith(0, 2);

    expect($this->ledger->isBlocked($balance, $this->ledger->floorFor($balance, BillingMode::ManualCollection, consentCurrent: true), ZeroBalanceBehavior::Block))
        ->toBeFalse();
});

it('withholds once the ceiling is reached', function (): void {
    $balance = balanceWith(-2, 2);

    expect($this->ledger->isBlocked($balance, $this->ledger->floorFor($balance, BillingMode::ManualCollection, consentCurrent: true), ZeroBalanceBehavior::Block))
        ->toBeTrue();
});

it('answers canAfford for the exact quantity asked, not for one', function (): void {
    $balance = balanceWith(3, 0);
    $floor = $this->ledger->floorFor($balance, BillingMode::PrepaidCredits);

    expect($this->ledger->canAfford($balance, 3, $floor))->toBeTrue()
        ->and($this->ledger->canAfford($balance, 4, $floor))->toBeFalse();
});

/*
| FR-027 — the zero-balance switch, and the ceiling it must never reach.
|
| Two cases, and the second is the one that makes the first safe. A workspace set
| to `remind` wants its students to keep booking when the credits run out and to
| get a reminder instead — but running out is not the same as OWING, and a switch
| that relaxed both would carry a student straight past the credit limit that
| exists to bound exactly that.
*/
it('lets a remind-only workspace book on at zero', function (): void {
    $balance = balanceWith(0, 0);
    $floor = $this->ledger->floorFor($balance, BillingMode::PrepaidCredits);

    expect($this->ledger->isBlocked($balance, $floor, ZeroBalanceBehavior::Remind))->toBeFalse()
        ->and($this->ledger->isBlocked($balance, $floor, ZeroBalanceBehavior::Both))->toBeTrue();
});

it('still withholds at the ceiling however the switch is set', function (): void {
    $balance = balanceWith(-2, 2);
    $floor = $this->ledger->floorFor($balance, BillingMode::ManualCollection, consentCurrent: true);

    // Owing the full ceiling. `remind` is about running out, not about debt.
    expect($this->ledger->isBlocked($balance, $floor, ZeroBalanceBehavior::Remind))->toBeTrue();
});
