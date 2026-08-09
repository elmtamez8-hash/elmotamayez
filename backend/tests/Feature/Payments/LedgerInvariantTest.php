<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\CreditLedger;

/*
| SC-001 · FR-004 — the displayed balance equals the sum of its entries, always.
|
| 10,000 sequential movements with refunds and corrections mixed through them,
| then one comparison. The number is the spec's; what makes it worth running is
| the mixture, not the count — a suite of purchases alone would agree with any
| implementation that only ever adds.
|
| The second invariant is asserted separately because SC-001 CANNOT SEE IT:
| `remaining = purchased − consumed` involves two columns the check never reads,
| so the pair can drift apart under a green SC-001 for as long as nobody opens
| the teacher's panel.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
    $this->balance = billingBalance($this->workspace, $this->student);
    $this->ledger = app(CreditLedger::class);
});

it('keeps the balance equal to the sum of its entries after 10,000 movements', function (): void {
    $expected = 0;

    for ($i = 1; $i <= 10_000; $i++) {
        // A repeating cycle rather than random values: the same run every time,
        // so a failure is reproducible instead of being a number nobody can get
        // back.
        [$type, $credits] = match ($i % 5) {
            0 => [CreditTransactionType::Purchase, 8],
            1 => [CreditTransactionType::Consume, -1],
            2 => [CreditTransactionType::Consume, -2],
            3 => [CreditTransactionType::Refund, -1],
            default => [CreditTransactionType::Adjustment, 1],
        };

        $this->ledger->post(new CreditMovement(
            balance: $this->balance,
            type: $type,
            credits: $credits,
            sourceType: 'invariant',
            sourceId: $i,
        ));

        $expected += $credits;
    }

    $sum = (int) CreditTransaction::query()
        ->withoutWorkspaceScope()
        ->where('credit_balance_id', $this->balance->getKey())
        ->sum('credits');

    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(10_000)
        ->and($sum)->toBe($expected)
        ->and($this->balance->refresh()->remaining_credits)->toBe($sum);
})->group('slow');

it('keeps remaining equal to purchased minus consumed through every type', function (): void {
    $moves = [
        [CreditTransactionType::Purchase, 16],
        [CreditTransactionType::Consume, -5],
        [CreditTransactionType::Bonus, 2],
        [CreditTransactionType::Adjustment, -1],
        [CreditTransactionType::Refund, -4],
        [CreditTransactionType::Consume, -3],
    ];

    foreach ($moves as $index => [$type, $credits]) {
        $this->ledger->post(new CreditMovement(
            balance: $this->balance,
            type: $type,
            credits: $credits,
            sourceType: 'counters',
            sourceId: $index,
        ));
    }

    $balance = $this->balance->refresh();

    // 16 + 2 − 1 − 4 = 13 bought and kept; 5 + 3 = 8 taken; 5 left.
    expect($balance->purchased_credits)->toBe(13)
        ->and($balance->consumed_credits)->toBe(8)
        ->and($balance->remaining_credits)->toBe(5)
        ->and($balance->remaining_credits)->toBe($balance->purchased_credits - $balance->consumed_credits);
});

it('lowers purchased on a refund and never touches consumed', function (): void {
    $this->ledger->post(new CreditMovement(
        balance: $this->balance,
        type: CreditTransactionType::Purchase,
        credits: 8,
        sourceType: 'refund-case',
        sourceId: 1,
    ));

    $this->ledger->post(new CreditMovement(
        balance: $this->balance,
        type: CreditTransactionType::Consume,
        credits: -3,
        sourceType: 'refund-case',
        sourceId: 2,
    ));

    $before = $this->balance->refresh()->consumed_credits;

    // A refund returns credits to the platform and cash to the student (spec
    // 007). It is the reverse of a purchase, so it lowers `purchased` — raising
    // `consumed` instead would show the student sessions they never attended,
    // and the invariant would still hold, which is what makes it hard to spot.
    $this->ledger->post(new CreditMovement(
        balance: $this->balance,
        type: CreditTransactionType::Refund,
        credits: -2,
        sourceType: 'credit_refund',
        sourceId: 3,
    ));

    $balance = $this->balance->refresh();

    expect($balance->purchased_credits)->toBe(6)
        ->and($balance->consumed_credits)->toBe($before)
        ->and($balance->remaining_credits)->toBe(3);
});
