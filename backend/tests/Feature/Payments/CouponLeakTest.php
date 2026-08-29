<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Payments\Actions\CreateOrder;
use App\Modules\Payments\Actions\PreviewDiscount;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;

/*
| The LEAKING direction (T072), which `WorkspaceIsolationTest` does not host.
|
| ⚠️ `Coupon` CARRIES NO `BelongsToWorkspace` ON PURPOSE, so the global scope
| that catches every other cross-tenant read is simply not there. What stands in
| its place is one grouped clause in `DiscountResolver` — and a reader who does
| not know why the parentheses are there will flatten them, at which point the
| `OR` splits the whole predicate and an expired coupon from another teacher is
| accepted.
|
| So this file walks EVERY ACTION THAT READS A COUPON rather than testing the
| resolver once. A guard written in one place and reached from three is a guard
| whose coverage is «whichever caller somebody remembered».
|
| ⚠️ AND «EVERY» MEANS FOUR, NOT THE THREE BELOW. `PurchaseCredits` is the
| fourth, and its case lives in `CouponOnCreditsPathTest` because it needs an
| approved settlement rate and a participating student — a fixture three times
| the size of this file's. The cross-reference is written here rather than left
| implied: a header claiming completeness while covering three quarters is worse
| than no header, because it is read as coverage. If you add a fifth caller, it
| gets a case in one of these two files or the claim above becomes false again.
|
| ⚠️ AND EVERY CASE HAS ITS POSITIVE CONTROL BESIDE IT. Nine cases that are each
| individually true and all green with the check deleted is what 010's US6
| shipped; a refusal test with no allow test beside it is satisfied by a branch
| that refuses everything.
*/
beforeEach(function (): void {
    [$this->mine, $this->owner] = $this->createWorkspaceWithOwner();
    [$this->theirs, $this->stranger] = $this->createWorkspaceWithOwner();

    $this->setCurrentWorkspace($this->mine, $this->owner);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->mine->getKey(),
        'price_minor' => 10_000,
    ]);

    $this->course = Course::factory()->create([
        'workspace_id' => $this->mine->getKey(),
        'price_minor' => 10_000,
        'status' => 'published',
    ]);

    // Their coupon. Live, generous, and not spendable here.
    $this->foreign = Coupon::factory()
        ->percent(50)
        ->forWorkspace((int) $this->theirs->getKey())
        ->create();

    // Mine. The control that proves each refusal below is about the SCOPE.
    $this->local = Coupon::factory()
        ->percent(50)
        ->forWorkspace((int) $this->mine->getKey())
        ->create();

    $this->buyer = User::factory()->create();
});

it('refuses another workspace coupon on a store purchase, and accepts its own', function (): void {
    expect(fn () => app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
        'coupon_code' => $this->foreign->code,
    ])))->toThrow(DomainException::class);

    $allowed = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
        'coupon_code' => $this->local->code,
    ]));

    expect((int) $allowed->discount_minor)->toBe(5_000);
});

it('refuses another workspace coupon on a course order, and accepts its own', function (): void {
    expect(fn () => app(CreateOrder::class)->handle($this->course, $this->buyer, $this->foreign->code))
        ->toThrow(DomainException::class);

    $order = app(CreateOrder::class)->handle($this->course, $this->buyer, $this->local->code);

    expect((int) $order->amount_minor)->toBe(5_000);
});

it('refuses another workspace coupon on a preview, and accepts its own', function (): void {
    expect(fn () => app(PreviewDiscount::class)->handle(
        $this->buyer,
        CouponScope::StoreItem,
        (string) $this->item->uuid,
        $this->foreign->code,
    ))->toThrow(DomainException::class);

    $preview = app(PreviewDiscount::class)->handle(
        $this->buyer,
        CouponScope::StoreItem,
        (string) $this->item->uuid,
        $this->local->code,
    );

    expect($preview->amountMinor)->toBe(5_000);
});

it('keeps refusing a foreign coupon that has also expired', function (): void {
    /*
    | ⚠️ THE CASE THE FLATTENED PARENTHESES BREAK, AND THE ONLY ONE THAT SEES IT.
    | Written `->whereNull('workspace_id')->orWhere('workspace_id', $id)` without
    | the grouping closure, the `OR` splits the whole predicate: everything
    | before it binds to one side and the expired coupon comes back anyway. A
    | live foreign coupon is still refused in that build — by the scope check
    | that survives on the other branch — so every other case in this file
    | passes and only this one fails.
    */
    $expiredForeign = Coupon::factory()
        ->percent(50)
        ->forWorkspace((int) $this->theirs->getKey())
        ->expired()
        ->create();

    expect(fn () => app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
        'coupon_code' => $expiredForeign->code,
    ])))->toThrow(DomainException::class);
});

it('never applies a foreign coupon silently as a zero', function (): void {
    // The failure mode that would make every case above pass while the product
    // is wrong: a resolver that answers «no discount» instead of refusing. The
    // buyer would then be charged full price for an order they believe is
    // discounted, and the code would look broken rather than out of scope.
    try {
        app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
            'item_uuid' => $this->item->uuid,
            'coupon_code' => $this->foreign->code,
        ]));

        $this->fail('a foreign coupon was accepted');
    } catch (DomainException $e) {
        expect($e->getMessage())->not->toBe('');
    }
});
