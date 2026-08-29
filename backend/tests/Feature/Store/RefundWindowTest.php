<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\IssueStoreAccess;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Actions\RefundStorePurchase;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;

/*
| Decision C4 — 48 hours, unless it has been opened.
|
| ⚠️ THE TWO CONDITIONS ARE TESTED SEPARATELY, WITH THE OTHER ONE NEUTRALISED IN
| THE FIXTURE. Spec 010's US6 shipped nine cases that were all individually true
| and all green with the check they existed for DELETED, because a second
| condition fired first. So: the window case is measured on a purchase that was
| never opened, and the opened case on one well inside the window.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->asset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->item = StoreItem::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'media_asset_id' => $this->asset->getKey(),
    ]);

    $this->buyer = User::factory()->create();
    $this->session = AuthSession::factory()->create(['user_id' => $this->buyer->getKey()]);
});

function boughtAndPaid(): StoreOrder
{
    $purchase = app(PurchaseStoreItem::class)->handle(test()->buyer, PurchaseData::fromArray([
        'item_uuid' => test()->item->uuid,
    ]));

    app(FulfilStorePurchase::class)->handle(
        Order::query()->whereKey($purchase->order_id)->firstOrFail(),
    );

    return $purchase->refresh();
}

it('refunds an unopened purchase inside the window', function (): void {
    $purchase = boughtAndPaid();

    $refunded = app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    expect($refunded->refunded_at)->not->toBeNull()
        ->and(Order::query()->whereKey($purchase->order_id)->value('status'))->toBe('refund_due');
});

it('refuses once the file has been opened, however early', function (): void {
    $purchase = boughtAndPaid();

    // One minute after buying. The clock is nowhere near the refusal; opening it
    // is.
    app(IssueStoreAccess::class)->handle($purchase->uuid, $this->buyer, $this->session);

    expect(fn (): StoreOrder => app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer))
        ->toThrow(DomainException::class, 'فُتِح هذا الملف، ولم يعد الاسترداد متاحاً.');
});

it('refuses once the window has passed, even unopened', function (): void {
    $purchase = boughtAndPaid();

    $this->travel(49)->hours();

    // Never opened, so the other condition cannot be what refuses it.
    expect($purchase->refresh()->first_accessed_at)->toBeNull();

    expect(fn (): StoreOrder => app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer))
        ->toThrow(DomainException::class, 'انتهت مهلة الاسترداد لهذا الطلب.');
});

it('refuses a second refund of the same purchase', function (): void {
    $purchase = boughtAndPaid();

    app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    expect(fn (): StoreOrder => app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer))
        ->toThrow(DomainException::class);
});

it('refuses a stranger the refund of somebody else purchase', function (): void {
    $purchase = boughtAndPaid();

    expect(fn (): StoreOrder => app(RefundStorePurchase::class)->handle($purchase->uuid, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect($purchase->refresh()->refunded_at)->toBeNull();
});

it('puts a printed copy back on the shelf and never invents one', function (): void {
    $printed = StoreItem::factory()->physical(2)->create(['workspace_id' => $this->workspace->getKey()]);

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $printed->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    app(FulfilStorePurchase::class)->handle(
        Order::query()->whereKey($purchase->order_id)->firstOrFail(),
    );

    expect((int) $printed->refresh()->stock)->toBe(1);

    app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    expect((int) $printed->refresh()->stock)->toBe(2);
});

it('never restocks a purchase that was never delivered', function (): void {
    $printed = StoreItem::factory()->physical(2)->create(['workspace_id' => $this->workspace->getKey()]);

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $printed->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    // Refunded before the transfer ever cleared. No copy was taken, so returning
    // one would add a book to the shelf that does not exist.
    app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    expect((int) $printed->refresh()->stock)->toBe(2);
});
