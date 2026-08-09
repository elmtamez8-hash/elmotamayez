<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\AdjustCredits;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditTransaction;
use App\Modules\Payments\Support\CreditLedger;

/*
| FR-007 — the same source twice is one entry.
|
| Redelivery is normal on a queue, not exceptional, so "at least once" has to
| become "exactly once" here or the balance stops equalling the sum of its
| entries the first time a worker restarts mid-job.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
    $this->balance = billingBalance($this->workspace, $this->student);
    $this->ledger = app(CreditLedger::class);
});

function post(CreditTransactionType $type, int $credits, ?int $sourceId): ?CreditTransaction
{
    return app(CreditLedger::class)->post(new CreditMovement(
        balance: test()->balance,
        type: $type,
        credits: $credits,
        sourceType: 'delivery',
        sourceId: $sourceId,
    ));
}

it('writes one entry when the same source arrives twice', function (): void {
    $first = post(CreditTransactionType::Purchase, 8, 42);
    $second = post(CreditTransactionType::Purchase, 8, 42);

    expect($first)->not->toBeNull()
        // Null, not a second entry and not an exception: the caller asked for a
        // state that already holds, and reporting failure would make a worker
        // retry for ever.
        ->and($second)->toBeNull()
        ->and(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(1)
        ->and($this->balance->refresh()->remaining_credits)->toBe(8);
});

/*
| ⚠️ The uuid case, stated explicitly.
|
| insertOrIgnore is a Query Builder call: no model is instantiated, `creating`
| never fires, and HasUuid's boot hook does not run. Leave uuid out of the insert
| array and MySQL downgrades the NOT NULL violation to a warning and stores '' —
| after which EVERY later entry in the whole product collides with that row on
| `unique(uuid)`, is read as "already recorded", and is skipped. The ledger stops
| after one row while every call reports success.
|
| SQLite would raise instead of storing '', so this case cannot reproduce the
| MySQL failure. What it CAN do is assert the property that makes the failure
| impossible — every entry carries its own non-empty uuid — and that assertion
| fails on both engines the moment the key is dropped from the array.
*/
it('gives every entry its own non-empty uuid', function (): void {
    post(CreditTransactionType::Purchase, 8, 1);
    post(CreditTransactionType::Purchase, 8, 2);

    $uuids = CreditTransaction::query()->withoutWorkspaceScope()->pluck('uuid')->all();

    expect($uuids)->toHaveCount(2);

    foreach ($uuids as $uuid) {
        expect($uuid)->toBeString()->not->toBe('');
    }

    expect(array_unique($uuids))->toHaveCount(2);
});

/*
| T044 — the manual entry, where the database cannot help.
|
| NULL is distinct from NULL in a unique index on MySQL and SQLite alike, so a
| null `source_id` deduplicates nothing: two taps on "grant a bonus" would be two
| bonuses. The guard is the idempotency key the Action mints into `source_id`,
| not the index.
*/
it('grants one bonus for two taps carrying the same key', function (): void {
    $adjust = app(AdjustCredits::class);

    $first = $adjust->handle($this->balance, CreditTransactionType::Bonus, 2, 'ترحيب', 'tap-1');
    $second = $adjust->handle($this->balance, CreditTransactionType::Bonus, 2, 'ترحيب', 'tap-1');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($this->balance->refresh()->remaining_credits)->toBe(2);
});

it('grants two bonuses for two deliberate grants', function (): void {
    $adjust = app(AdjustCredits::class);

    $adjust->handle($this->balance, CreditTransactionType::Bonus, 2, 'ترحيب', 'tap-1');
    $adjust->handle($this->balance, CreditTransactionType::Bonus, 2, 'تعويض', 'tap-2');

    expect($this->balance->refresh()->remaining_credits)->toBe(4);
});

it('shows that a null source id deduplicates nothing, which is why the key exists', function (): void {
    post(CreditTransactionType::Adjustment, 1, null);
    post(CreditTransactionType::Adjustment, 1, null);

    // Two rows, both null, both accepted by the unique index. This is the
    // behaviour the Action's minted key works around — asserted so that anyone
    // tempted to drop the key finds out what the index does not do.
    expect(CreditTransaction::query()->withoutWorkspaceScope()->count())->toBe(2);
});

it('refuses an adjustment with no reason', function (): void {
    expect(fn () => app(AdjustCredits::class)->handle(
        $this->balance,
        CreditTransactionType::Adjustment,
        -1,
        '   ',
        'no-reason',
    ))->toThrow(DomainException::class);
});
