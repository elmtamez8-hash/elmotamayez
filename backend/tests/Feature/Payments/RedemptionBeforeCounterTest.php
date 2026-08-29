<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\RedeemCoupon;
use App\Modules\Payments\Data\AppliedDiscount;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use Illuminate\Database\QueryException;

/*
| Idempotency: the row first, the counter second (T073 · T066).
|
| ⚠️ THIS IS `CreditLedger`'s ORDER AND IT IS HERE FOR `CreditLedger`'s REASON.
| Reversed, a redelivered event has its INSERT swallowed by
| `unique(coupon_id, order_id)` — silently, because that is what a unique index
| does — and then increments the counter a SECOND time. The coupon runs out
| early, `redemptions_count` disagrees with `COUNT(*)` for ever, and nothing
| anywhere notices: every caller reports success.
|
| ⚠️ AND THE ASSERTION IS ON THE COUNTER, NOT ON A ROW COUNT. Counting one
| redemption row passes against a build that writes the row and increments twice
| — the exact defect — because the index does its job on the row and nothing
| guards the column beside it.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 10_000,
    ]);

    $this->buyer = User::factory()->create();
});

it('moves the counter exactly once when the same redemption arrives twice', function (): void {
    $coupon = Coupon::factory()->percent(10)->used(max: 5, used: 0)->create();

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
        'coupon_code' => $coupon->code,
    ]));

    expect((int) $coupon->refresh()->redemptions_count)->toBe(1);

    $order = Order::query()->whereKey($purchase->order_id)->firstOrFail();

    // The redelivery. Same coupon, same order — the shape a queue retry produces.
    expect(fn () => app(RedeemCoupon::class)->handle(
        $order,
        AppliedDiscount::coupon($coupon->fresh(), 1_000),
    ))->toThrow(QueryException::class);

    // ⚠️ THE COLUMN, NOT THE ROWS. One row would also be true of a build that
    // counted twice.
    expect((int) $coupon->refresh()->redemptions_count)->toBe(1)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->getKey())->count())->toBe(1);
});

it('lets one coupon be spent on two different orders', function (): void {
    // The positive control for the index above: it keys on the PAIR, so a
    // single-column guard would make a campaign spendable exactly once in its
    // life — and every assertion in the case above would still pass.
    $coupon = Coupon::factory()->percent(10)->used(max: 5, used: 0)->create();

    foreach ([$this->buyer, User::factory()->create()] as $buyer) {
        app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
            'item_uuid' => $this->item->uuid,
            'coupon_code' => $coupon->code,
        ]));
    }

    expect((int) $coupon->refresh()->redemptions_count)->toBe(2)
        ->and(CouponRedemption::query()->where('coupon_id', $coupon->getKey())->count())->toBe(2);
});

it('records the amount actually taken off, not the coupon face value', function (): void {
    // FR-015 asks for the amount DISCOUNTED. A fixed coupon is clamped at the
    // line and a percentage depends on it, so the coupon's own `value` answers a
    // different question — and it is the one an accountant would be reading.
    $coupon = Coupon::factory()->fixed(50_000)->create();

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
        'coupon_code' => $coupon->code,
    ]));

    $redemption = CouponRedemption::query()->where('order_id', $purchase->order_id)->firstOrFail();

    expect((int) $redemption->discount_minor)->toBe(10_000)
        ->and((int) $redemption->user_id)->toBe((int) $this->buyer->getKey())
        // Assigned explicitly from the order: the model carries no
        // `BelongsToWorkspace`, and every path that writes it is a buyer's —
        // whose workspace context is always null.
        ->and((int) $redemption->workspace_id)->toBe((int) $this->workspace->getKey());
});
