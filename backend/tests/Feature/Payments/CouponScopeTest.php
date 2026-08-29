<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;

/*
| The scope guard, in the direction that DISAPPEARS (T071).
|
| ⚠️ THIS FILE FALLS OVER THE DAY SOMEBODY ADDS `BelongsToWorkspace` TO `Coupon`,
| AND THAT IS ITS WHOLE JOB. A platform coupon is `workspace_id IS NULL`; the
| global scope would add `= X` to every read, and every platform coupon — the
| ordinary case — would vanish from every workspace in existence, silently, with
| the model looking more correct than it did before.
|
| Its mirror is `CouponLeakTest`, which walks the other direction. Neither one
| covers the other: a resolver that finds nothing passes the leak test, and one
| that finds everything passes this.
*/
beforeEach(function (): void {
    [$this->first, $this->firstOwner] = $this->createWorkspaceWithOwner();
    [$this->second, $this->secondOwner] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($this->first, $this->firstOwner);

    $this->firstItem = StoreItem::factory()->create([
        'workspace_id' => $this->first->getKey(),
        'price_minor' => 10_000,
    ]);

    $this->secondItem = StoreItem::factory()->create([
        'workspace_id' => $this->second->getKey(),
        'price_minor' => 10_000,
    ]);

    // No seeder, no `setCurrentWorkspace()`: a student belongs to no workspace,
    // and a fixture that stamps `last_workspace_id` measures a person who does
    // not exist in production.
    $this->buyer = User::factory()->create();
});

function buyItem(StoreItem $item, string $code): int
{
    $purchase = app(PurchaseStoreItem::class)->handle(test()->buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'coupon_code' => $code,
    ]));

    return (int) $purchase->discount_minor;
}

it('spends one platform coupon in two different workspaces', function (): void {
    $coupon = Coupon::factory()->percent(10)->create();

    expect(buyItem($this->firstItem, $coupon->code))->toBe(1_000)
        ->and(buyItem($this->secondItem, $coupon->code))->toBe(1_000);
});

it('spends a workspace coupon inside its own workspace', function (): void {
    // The positive control. Without it, every refusal in `CouponLeakTest` is
    // satisfied by a branch that refuses everything.
    $coupon = Coupon::factory()->percent(25)->forWorkspace((int) $this->first->getKey())->create();

    expect(buyItem($this->firstItem, $coupon->code))->toBe(2_500);
});

it('applies a coupon scoped to one product only to that product', function (): void {
    $coupon = Coupon::factory()
        ->percent(50)
        ->scopedTo(CouponScope::StoreItem, (string) $this->firstItem->uuid)
        ->create();

    expect(buyItem($this->firstItem, $coupon->code))->toBe(5_000);

    $another = StoreItem::factory()->create([
        'workspace_id' => $this->first->getKey(),
        'price_minor' => 10_000,
    ]);

    // Same workspace, same buyer, same code — a different product. The spec's own
    // edge case: «its scope is stated explicitly and it does not apply outside
    // it».
    expect(fn (): int => buyItem($another, $coupon->code))->toThrow(DomainException::class);
});

it('refuses a code that names the right kind and the wrong row', function (): void {
    // A scope is TWO columns, and a check on `scope_type` alone would let a
    // «store item» coupon be spent on every store item on the platform.
    $coupon = Coupon::factory()
        ->percent(50)
        ->scopedTo(CouponScope::StoreItem, (string) $this->secondItem->uuid)
        ->create();

    expect(fn (): int => buyItem($this->firstItem, $coupon->code))->toThrow(DomainException::class);
});

it('matches a code however it was typed', function (): void {
    $coupon = Coupon::factory()->percent(10)->create(['code' => 'SUMMER26']);

    // Normalised in PHP at both ends. Left to the engine this passes on MySQL and
    // fails on SQLite — which is the same defect wearing whichever face the
    // environment gives it.
    expect(buyItem($this->firstItem, ' summer26 '))->toBe(1_000)
        ->and($coupon->code)->toBe('SUMMER26');
});
