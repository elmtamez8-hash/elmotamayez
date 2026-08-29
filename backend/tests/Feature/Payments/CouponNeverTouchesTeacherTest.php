<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Support\StoreSettings;
use App\Modules\Tenancy\Support\PlatformSettings;

/*
| FR-010's arithmetic, exactly as `data-model.md` writes it (T074).
|
|     list price      50      ← the teacher writes it
|     teacher's cut   45      ← taken from 50, BEFORE the discount · does not move
|     platform coupon −10
|     ─────────────────────
|     buyer pays      40
|     platform's cut  −5      ← negative, and that is the platform's own decision
|
| ⚠️ `commission_minor` IS ALLOWED TO GO NEGATIVE AND `teacher_net_minor` IS NOT
| ALLOWED TO MOVE. A teacher never agreed to the campaign and did not set its
| price; taking a platform discount out of their pay is the one thing FR-010
| forbids by name. Both columns are signed `bigInteger` for exactly this row.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    PlatformSettings::set('store.commission_bps', 1_000);

    $this->buyer = User::factory()->create();
});

afterEach(function (): void {
    PlatformSettings::flush();
});

it('leaves the teacher whole and lets the platform share go negative', function (): void {
    $item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 5_000,
    ]);

    $coupon = Coupon::factory()->fixed(1_000)->create();

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'coupon_code' => $coupon->code,
    ]));

    expect((int) $purchase->teacher_net_minor)->toBe(4_500)
        ->and((int) $purchase->commission_minor)->toBe(-500)
        ->and((int) $purchase->discount_minor)->toBe(1_000)
        ->and((int) $purchase->order()->firstOrFail()->amount_minor)->toBe(4_000);
});

it('pays the teacher the same with a coupon as without one', function (): void {
    // The comparison the sentence «the discount does not touch the teacher's
    // due» actually makes. One assertion on a single purchase cannot see a
    // formula that subtracts the discount from BOTH sides.
    $bare = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 5_000,
    ]);

    $discounted = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 5_000,
    ]);

    $coupon = Coupon::factory()->percent(30)->create();

    $without = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $bare->uuid,
    ]));

    $with = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $discounted->uuid,
        'coupon_code' => $coupon->code,
    ]));

    expect((int) $with->teacher_net_minor)->toBe((int) $without->teacher_net_minor)
        // And the buyer really did pay less, or the assertion above is satisfied
        // by a coupon that did nothing at all.
        ->and((int) $with->order()->firstOrFail()->amount_minor)
        ->toBeLessThan((int) $without->order()->firstOrFail()->amount_minor);
});

it('never discounts the postage', function (): void {
    /*
    | The shipping fee is money the teacher hands to a courier. A percentage of
    | it is the platform's campaign paying part of somebody else's invoice — and
    | it would make a teacher who posts far away earn less on the same book.
    */
    $item = StoreItem::factory()->physical(stock: 5)->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => 5_000,
        'shipping_fee_minor' => 1_500,
    ]);

    $coupon = Coupon::factory()->percent(100)->create();

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
        'coupon_code' => $coupon->code,
    ]));

    // Everything off the goods and nothing off the carriage: the buyer still
    // pays the postage, and the teacher still receives it.
    expect((int) $purchase->discount_minor)->toBe(5_000)
        ->and((int) $purchase->order()->firstOrFail()->amount_minor)->toBe(1_500)
        ->and((int) $purchase->teacher_net_minor)->toBe(5_000 - StoreSettings::commissionOn(5_000) + 1_500);
});
