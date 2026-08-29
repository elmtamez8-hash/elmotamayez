<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\CouponRedemption;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Support\PlatformSettings;

/*
| «The highest alone applies, there is no stacking» (T074 · FR-014 · D13).
|
| ⚠️ THE POLICY IS DECIDED IN `DiscountResolver` AND RETURNED AS ONE VALUE, so it
| cannot be re-decided by whoever sums the parts next — in a different file,
| months later, without noticing they were deciding anything. That singularity is
| also what makes SC-005 true by construction for a percentage: one discount of
| at most 100% cannot take a line below zero.
|
| ⚠️ AND BOTH DIRECTIONS ARE MEASURED. A file that only tested «coupon beats
| family» would pass against an implementation that ignores the family discount
| entirely — which is a different bug wearing the same green.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 10_000,
    ]);

    // A family of two: the buyer qualifies for the family rate on every purchase.
    $guardian = User::factory()->create();
    $this->buyer = User::factory()->create();

    foreach ([$this->buyer, User::factory()->create()] as $child) {
        ParentStudentRelation::factory()->create([
            'guardian_user_id' => $guardian->getKey(),
            'student_user_id' => $child->getKey(),
        ]);
    }

    PlatformSettings::set('billing.sibling_discount', 10);
});

afterEach(function (): void {
    PlatformSettings::flush();
});

function purchaseWith(?string $code): array
{
    $purchase = app(PurchaseStoreItem::class)->handle(test()->buyer, PurchaseData::fromArray(array_filter([
        'item_uuid' => test()->item->uuid,
        'coupon_code' => $code,
    ], fn ($value): bool => $value !== null)));

    return [(int) $purchase->discount_minor, (int) $purchase->order()->firstOrFail()->amount_minor];
}

it('applies the coupon alone when it beats the family rate', function (): void {
    $coupon = Coupon::factory()->percent(20)->create();

    // 20% and 10% ⇒ 20%. NOT 30%, and not 28% compounded.
    expect(purchaseWith($coupon->code))->toBe([2_000, 8_000]);
});

it('applies the family rate alone when it beats the coupon', function (): void {
    $coupon = Coupon::factory()->percent(5)->create();

    expect(purchaseWith($coupon->code))->toBe([1_000, 9_000]);
});

it('records no redemption when the family rate won', function (): void {
    // The coupon was not spent, so its ceiling must not move and FR-015's record
    // must not claim a discount that came from somewhere else.
    $coupon = Coupon::factory()->percent(5)->used(max: 10, used: 0)->create();

    purchaseWith($coupon->code);

    expect(CouponRedemption::query()->count())->toBe(0)
        ->and((int) $coupon->refresh()->redemptions_count)->toBe(0);
});

it('applies the family rate with no code typed at all', function (): void {
    // FR-013: automatic, with no code and no request.
    expect(purchaseWith(null))->toBe([1_000, 9_000]);
});

it('gives a tie to the coupon the buyer typed', function (): void {
    // Being told a code «did nothing» when it matched the family rate exactly
    // reads as the code being rejected.
    $coupon = Coupon::factory()->percent(10)->create();

    purchaseWith($coupon->code);

    expect(CouponRedemption::query()->count())->toBe(1);
});
