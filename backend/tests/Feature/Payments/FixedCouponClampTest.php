<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\CouponValueKind;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;

/*
| «Fifty off a thirty-riyal notebook is zero, never minus twenty» (T073 · D17).
|
| ⚠️ THE CLAMP LIVES IN `CouponValueKind::discountOn()` AND NOWHERE ELSE, and
| that is the requirement rather than an implementation detail: there are THREE
| purchase paths, and a clamp repeated per path is a rule with three spellings of
| which one eventually gets forgotten. FR-014's «declared minimum» is ZERO, and
| it is enforced by cutting rather than by a column.
|
| Research §D13 originally argued SC-005 was true by construction because «the
| highest alone applies» — true of a PERCENTAGE and false of a fixed amount,
| which is the correction §D17 records. This file is that correction.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->buyer = User::factory()->create();
});

function buyAt(int $priceMinor, string $code, int $quantity = 1): array
{
    $item = StoreItem::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'price_minor' => $priceMinor,
    ]);

    $purchase = app(PurchaseStoreItem::class)->handle(test()->buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'quantity' => $quantity,
        'coupon_code' => $code,
    ]));

    return [(int) $purchase->discount_minor, (int) $purchase->order()->firstOrFail()->amount_minor];
}

it('cuts a fixed coupon at the value of the line', function (): void {
    $coupon = Coupon::factory()->fixed(5_000)->create();

    // 50 off a 30. The discount is 30 and the buyer pays nothing — never −20.
    expect(buyAt(3_000, $coupon->code))->toBe([3_000, 0]);
});

it('takes a fixed coupon whole when the line can carry it', function (): void {
    // The positive control: the clamp is a ceiling, not a flat «pay nothing».
    $coupon = Coupon::factory()->fixed(5_000)->create();

    expect(buyAt(30_000, $coupon->code))->toBe([5_000, 25_000]);
});

it('measures a fixed coupon against the whole line, not one unit', function (): void {
    // Per LINE. Clamped per unit, a 50 coupon on three 30-riyal notebooks would
    // come off three times and pay the buyer to shop.
    $coupon = Coupon::factory()->fixed(5_000)->create();

    expect(buyAt(3_000, $coupon->code, quantity: 3))->toBe([5_000, 4_000]);
});

it('floors a percentage rather than rounding it', function (): void {
    // `intdiv`, never `round`: rounding a half in the buyer's favour is a
    // decision nobody made, and a floor is the same answer on every engine and
    // in every locale.
    expect(CouponValueKind::Percent->discountOn(33, 100))->toBe(33)
        ->and(CouponValueKind::Percent->discountOn(33, 101))->toBe(33)
        ->and(CouponValueKind::Percent->discountOn(50, 101))->toBe(50);
});

it('never returns more than the line, whatever the stored value says', function (): void {
    // A seeder runs inside `Model::unguarded()` and the panel has no form, so a
    // nonsense value can reach the enum with nothing between. Both ends clamp.
    expect(CouponValueKind::Percent->discountOn(500, 1_000))->toBe(1_000)
        ->and(CouponValueKind::FixedMinor->discountOn(-500, 1_000))->toBe(0)
        ->and(CouponValueKind::Percent->discountOn(50, -1_000))->toBe(0);
});
