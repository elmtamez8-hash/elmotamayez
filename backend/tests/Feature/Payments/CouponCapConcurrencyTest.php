<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\RedeemCoupon;
use App\Modules\Payments\Data\AppliedDiscount;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Support\Facades\DB;

/*
| SC-003 — zero coupons spent past their ceiling, under any concurrency.
|
| ⚠️ THE MECHANISM IS PINNED RATHER THAN THE RACE REPRODUCED, AND THAT DECISION
| WAS MEASURED IN THIS REPOSITORY LAST WEEK. `StockConcurrencyTest` was written
| the obvious way first; `ClaimStock` was then rewritten as a `SELECT` followed by
| an `UPDATE` — the exact defect — and every behavioural case stayed GREEN. A
| single-process seam can only fire between two statements that BOTH exist, and
| the broken version's window lies where the correct version has no statement at
| all.
|
| So the first case here asserts the SHAPE: one statement on `coupons`, carrying
| the ceiling predicate and an increment rather than an assignment. Weaker than a
| reproduction, and far stronger than a green test that proves the opposite.
|
| ⚠️ AND THE ORDER OF THE TWO WRITES IS PINNED BESIDE IT. The redemption row goes
| in FIRST — `CreditLedger`'s order. Reversed, a redelivered event has its INSERT
| swallowed by `unique(coupon_id, order_id)` and increments the counter a second
| time: the coupon runs out early and `redemptions_count` disagrees with
| `COUNT(*)` for ever, with nothing anywhere that notices.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 10_000,
    ]);

    // ⚠️ BUILT WITHOUT A SEEDER AND WITHOUT `setCurrentWorkspace()`. A student is
    // a member of no workspace in production, and a fixture that stamps
    // `last_workspace_id` is measuring a person who does not exist.
    $this->buyer = User::factory()->create();
    $this->other = User::factory()->create();
});

function buyWithCode(User $buyer, string $code): void
{
    app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => test()->item->uuid,
        'coupon_code' => $code,
    ]));
}

it('claims the ceiling in one conditional statement, never a read then a write', function (): void {
    $coupon = Coupon::factory()->percent(10)->used(max: 5, used: 0)->create();

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, '"coupons"')) {
            $statements[] = $query->sql;
        }
    });

    buyWithCode($this->buyer, $coupon->code);

    $writes = array_values(array_filter(
        $statements,
        fn (string $sql): bool => str_starts_with($sql, 'update "coupons"'),
    ));

    expect($writes)->toHaveCount(1);

    // The predicate is INSIDE the write. Split into a `SELECT redemptions_count`
    // and an `UPDATE … SET redemptions_count = ?`, two buyers both read the last
    // place and both take it — and no behavioural case here could tell you.
    expect($writes[0])->toContain('redemptions_count < max_redemptions')
        // `count = count + 1`, not `count = ?`. The second form carries a number
        // computed in PHP from a row that may already have moved.
        ->and($writes[0])->toContain('"redemptions_count" = "redemptions_count" +');
});

it('writes the redemption row before it moves the counter', function (): void {
    $coupon = Coupon::factory()->percent(10)->used(max: 5, used: 0)->create();

    $order = [];

    DB::listen(function ($query) use (&$order): void {
        if (str_starts_with($query->sql, 'insert into "coupon_redemptions"')) {
            $order[] = 'redemption';
        }

        if (str_starts_with($query->sql, 'update "coupons"')) {
            $order[] = 'counter';
        }
    });

    buyWithCode($this->buyer, $coupon->code);

    expect($order)->toBe(['redemption', 'counter']);
});

it('refuses the code once its ceiling is gone', function (): void {
    $coupon = Coupon::factory()->percent(10)->used(max: 1, used: 1)->create();

    expect(fn () => buyWithCode($this->buyer, $coupon->code))
        ->toThrow(DomainException::class, 'استُنفد هذا الكود.');

    // Nothing was written on the way to the refusal: no order at a discounted
    // price, and no redemption row inflating the record FR-015 keeps.
    expect(CouponRedemption::query()->count())->toBe(0);
});

it('rolls the whole purchase back when the last place goes in between', function (): void {
    /*
    | The seam. `RedeemCoupon` is asked BETWEEN the resolver's read (which saw a
    | place free) and its own claim, so a callback there is the other buyer
    | winning inside exactly that window — no threads, no sleeps. A sequential
    | «buy twice» test does NOT cover this: the second call is refused a step
    | earlier, by the resolver, and never reaches the claim at all.
    */
    $coupon = Coupon::factory()->percent(10)->used(max: 1, used: 0)->create();

    $fired = false;

    DB::listen(function ($query) use (&$fired, $coupon): void {
        if ($fired || ! str_starts_with($query->sql, 'insert into "coupon_redemptions"')) {
            return;
        }

        $fired = true;

        // The rival takes the last place, outside this transaction.
        Coupon::query()->whereKey($coupon->getKey())->increment('redemptions_count');
    });

    expect(fn () => buyWithCode($this->buyer, $coupon->code))
        ->toThrow(DomainException::class);

    expect($fired)->toBeTrue('the seam never fired — the test proved nothing');

    /*
    | The loser's redemption row went back with the transaction rather than
    | standing as a discount nobody got.
    |
    | ⚠️ THE COUNTER IS DELIBERATELY NOT ASSERTED HERE, AND THE REASON IS WORTH
    | MORE THAN THE ASSERTION WOULD BE. The rival's increment runs on THIS
    | connection, inside the transaction the refusal then rolls back — so it is
    | undone with everything else and the column reads 0, which says nothing
    | about the two real workers it stands in for. Asserting 1 would mean
    | «measure the artifact»; asserting 0 would enshrine it. What this case
    | proves is the branch: the claim matched zero rows and threw, and nothing
    | was left behind.
    */
    expect(CouponRedemption::query()->count())->toBe(0);
});

it('is a no-op for a discount with no coupon behind it', function (): void {
    $order = app(PurchaseStoreItem::class)
        ->handle($this->buyer, PurchaseData::fromArray(['item_uuid' => $this->item->uuid]))
        ->order()
        ->firstOrFail();

    expect(app(RedeemCoupon::class)->handle($order, AppliedDiscount::none()))->toBeNull()
        ->and(CouponRedemption::query()->count())->toBe(0);
});
