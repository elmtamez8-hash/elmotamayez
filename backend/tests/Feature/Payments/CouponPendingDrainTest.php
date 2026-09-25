<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Actions\RedeemCoupon;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;

/*
| A coupon's place is claimed at PURCHASE, and the store door takes any number of
| pending orders. So one buyer placing order after order with one code — paying
| none — drained a campaign of ten places and every other buyer read
| «استُنفد هذا الكود». One undecided order per buyer per code is the bound.
|
| ⚠️ EACH REFUSAL HAS ITS POSITIVE CONTROL BESIDE IT: another buyer, the same
| buyer after a decision, and the same buyer without the code are all let
| through — a guard that refused everything would pass the refusal alone.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 10_000,
    ]);

    $this->coupon = Coupon::factory()
        ->percent(50)
        ->forWorkspace((int) $this->workspace->getKey())
        ->create(['max_redemptions' => 10]);

    $this->buyer = User::factory()->create();
});

function drainPurchase(User $buyer, StoreItem $item, ?string $code): StoreOrder
{
    return app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'coupon_code' => $code,
    ]));
}

it('refuses a second place on the same code while the buyer first order is undecided', function (): void {
    drainPurchase($this->buyer, $this->item, $this->coupon->code);

    expect(fn () => drainPurchase($this->buyer, $this->item, $this->coupon->code))
        ->toThrow(DomainException::class, RedeemCoupon::UNDECIDED_REFUSAL);

    // One place taken, not two — and no second order left behind.
    expect((int) $this->coupon->refresh()->redemptions_count)->toBe(1)
        ->and(Order::query()->withoutWorkspaceScope()->where('user_id', $this->buyer->getKey())->count())->toBe(1);
});

it('still lets the same buyer order without the code, and another buyer use it', function (): void {
    drainPurchase($this->buyer, $this->item, $this->coupon->code);

    expect(drainPurchase($this->buyer, $this->item, null)->discount_minor)->toBe(0)
        ->and(drainPurchase(User::factory()->create(), $this->item, $this->coupon->code)->discount_minor)->toBe(5_000);
});

it('lets the buyer use the code again once the first order is decided', function (): void {
    $first = drainPurchase($this->buyer, $this->item, $this->coupon->code);

    app(ApproveOrder::class)->handle(Order::query()->withoutWorkspaceScope()->findOrFail($first->order_id), $this->owner);

    expect(drainPurchase($this->buyer, $this->item, $this->coupon->code)->discount_minor)->toBe(5_000)
        ->and((int) $this->coupon->refresh()->redemptions_count)->toBe(2);
});
