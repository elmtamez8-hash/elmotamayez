<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditLot;
use App\Modules\Payments\Support\CreditLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| quickstart §14 — lots, and the reason they are a table.
|
| "How much is left in this lot" used to be derived with SUM(credit_allocations):
| a read followed by a write, in the one place the phase's own concurrency rule
| was not applied. Two consumers both read "1 left" and both insert, the lot is
| over-allocated, and SC-001 still passes because it never looks at allocations.
| A lot is a package of 8 or 16, so this was live from day one with expiry off.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->balance = billingBalance($this->workspace, User::factory()->create());
    $this->ledger = app(CreditLedger::class);
});

function take(int $credits, int $source): void
{
    app(CreditLedger::class)->post(new CreditMovement(
        balance: test()->balance,
        type: CreditTransactionType::Consume,
        credits: -$credits,
        sourceType: 'seat',
        sourceId: $source,
        enforceFloor: true,
    ));
}

it('draws 12 across a lot of 8 and a lot of 16 without over-allocating either', function (): void {
    grantCredits($this->balance, 8, 'first');
    grantCredits($this->balance, 16, 'second');

    take(12, 1);

    $lots = CreditLot::query()->withoutWorkspaceScope()->orderBy('id')->get();

    expect($lots)->toHaveCount(2)
        // Oldest first while nothing expires: 8 emptied, 4 taken from the 16.
        ->and($lots[0]->credits_remaining)->toBe(0)
        ->and($lots[1]->credits_remaining)->toBe(12);

    expect((int) CreditLot::query()->withoutWorkspaceScope()->sum('credits_remaining'))
        ->toBe($this->balance->refresh()->remaining_credits);

    foreach ($lots as $lot) {
        $allocated = (int) DB::table('credit_allocations')
            ->where('lot_transaction_id', $lot->credit_transaction_id)
            ->sum('credits');

        expect($allocated)->toBeLessThanOrEqual($lot->credits_total)
            ->and($allocated)->toBe($lot->credits_total - $lot->credits_remaining);
    }
});

it('moves to the next lot rather than over-drawing one with a single credit left', function (): void {
    grantCredits($this->balance, 1, 'small');
    grantCredits($this->balance, 4, 'large');

    take(1, 1);
    take(1, 2);

    $lots = CreditLot::query()->withoutWorkspaceScope()->orderBy('id')->get();

    expect($lots[0]->credits_remaining)->toBe(0)
        ->and($lots[1]->credits_remaining)->toBe(3)
        ->and((int) CreditLot::query()->withoutWorkspaceScope()->sum('credits_remaining'))
        ->toBe($this->balance->refresh()->remaining_credits);
});

it('draws the soonest-expiring lot first, whatever order it was bought in', function (): void {
    // Bought SECOND but expiring FIRST. Consuming in purchase order would let the
    // sooner lot die with credits in it that the student had already paid for.
    grantCredits($this->balance, 4, 'never', null);
    grantCredits($this->balance, 4, 'soon', CarbonImmutable::now()->addDays(7));

    take(4, 1);

    $lots = CreditLot::query()->withoutWorkspaceScope()->orderBy('id')->get();

    expect($lots[0]->credits_remaining)->toBe(4)
        ->and($lots[1]->credits_remaining)->toBe(0);
});

it('records which lot paid for which consumption', function (): void {
    grantCredits($this->balance, 2, 'a');
    grantCredits($this->balance, 2, 'b');

    take(3, 1);

    $rows = DB::table('credit_allocations')->orderBy('id')->get();

    // Two rows for one consumption: the answer to "which batch paid" cannot be
    // derived afterwards, because soonest-expiry-first rewrites it retroactively
    // every time a sooner-expiring lot arrives.
    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->credits + (int) $rows[1]->credits)->toBe(3)
        ->and((int) $rows[0]->consumed_transaction_id)->toBe((int) $rows[1]->consumed_transaction_id);
});
