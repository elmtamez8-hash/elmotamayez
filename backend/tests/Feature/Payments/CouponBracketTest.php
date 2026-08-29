<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;

/*
| The clock and the switch (T073 · FR-012).
|
| ⚠️ THE COLUMNS ARE TIMESTAMPS, AND THE LAST-DAY CASE IS THE WHOLE POINT OF IT.
| A `date` compared with `<=` binds midnight, so a coupon advertised «until the
| 31st» dies at one minute past midnight ON the 31st — a live campaign refusing
| every code it printed, on the busiest day it has. That boundary has already
| cost this repository three separate fixes: `FreezePeriod::covering()`, the
| settlement close, and the coming-of-age sweep.
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

function discountFrom(string $code): int
{
    return (int) app(PurchaseStoreItem::class)->handle(test()->buyer, PurchaseData::fromArray([
        'item_uuid' => test()->item->uuid,
        'coupon_code' => $code,
    ]))->discount_minor;
}

it('accepts a live coupon', function (): void {
    // The positive control every refusal below is measured against.
    $coupon = Coupon::factory()->percent(10)->create([
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    expect(discountFrom($coupon->code))->toBe(1_000);
});

it('accepts a coupon on the last afternoon of its final day', function (): void {
    // ⚠️ THE CASE A `date` COLUMN FAILS. Ends tonight; it is now the afternoon.
    $coupon = Coupon::factory()->percent(10)->create([
        'ends_at' => now()->endOfDay(),
    ]);

    expect(discountFrom($coupon->code))->toBe(1_000);
});

it('refuses an expired coupon', function (): void {
    $coupon = Coupon::factory()->percent(10)->expired()->create();

    expect(fn (): int => discountFrom($coupon->code))
        ->toThrow(DomainException::class, 'انتهت صلاحية هذا الكود.');
});

it('refuses a coupon whose campaign has not started', function (): void {
    $coupon = Coupon::factory()->percent(10)->notYetStarted()->create();

    expect(fn (): int => discountFrom($coupon->code))
        ->toThrow(DomainException::class, 'انتهت صلاحية هذا الكود.');
});

it('refuses a switched-off coupon', function (): void {
    // Retirement is `is_active = false`, and it takes effect on the next
    // purchase — the row stays because every redemption made from it is FR-015's
    // record of a discount somebody actually received.
    $coupon = Coupon::factory()->percent(10)->create(['is_active' => false]);

    expect(fn (): int => discountFrom($coupon->code))->toThrow(DomainException::class);
});

it('treats a coupon with no dates at all as live', function (): void {
    $coupon = Coupon::factory()->percent(10)->create();

    expect(discountFrom($coupon->code))->toBe(1_000);
});
