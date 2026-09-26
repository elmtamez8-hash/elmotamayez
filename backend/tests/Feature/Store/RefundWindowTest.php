<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Payments\Actions\ApproveOrder;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\IssueStoreAccess;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Actions\RefundStorePurchase;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Http\Resources\StoreOrderResource;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

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

    // ⚠️ APPROVED, NOT MERELY FULFILLED: a refund is refused on an order that
    // was never paid, and `FulfilStorePurchase` alone leaves it `pending`.
    // Approval fulfils through `FulfilOnPaymentApproved` like production does.
    app(ApproveOrder::class)->handle(
        Order::query()->whereKey($purchase->order_id)->firstOrFail(),
        test()->owner,
    );

    return $purchase->refresh();
}

it('refunds an unopened purchase inside the window', function (): void {
    $purchase = boughtAndPaid();

    $refunded = app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    expect($refunded->refunded_at)->not->toBeNull()
        ->and(Order::query()->whereKey($purchase->order_id)->value('status'))->toBe('refund_due');
});

it('moves the order for a buyer stamped with ANOTHER teacher workspace', function (): void {
    $purchase = boughtAndPaid();

    // `last_workspace_id` is guarded, so `create([...])` would drop it in silence
    // and rebuild the null-context buyer this case exists to go beyond.
    [$elsewhere] = $this->createWorkspaceWithOwner();
    $this->buyer->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();
    Sanctum::actingAs($this->buyer);
    app()->forgetInstance(WorkspaceContext::class);

    app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    expect(Order::withoutWorkspaceScope()->whereKey($purchase->order_id)->value('status'))->toBe('refund_due');
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

/*
| ⛔ OWNER DECISION 2026-09-25 — A PRINTED COPY THAT HAS LEFT THE SHELF IS NOT
| REFUNDED THROUGH THE SITE. «Not opened» means nothing for a book, so the old
| rule let a buyer receive the parcel, keep it, press «استرداد» inside the window
| and get the money back — while the stock count gained a copy that was sitting
| in their house. The site now sends them to the administration.
*/
function printedAndFulfilled(): array
{
    $printed = StoreItem::factory()->physical(2)->create(['workspace_id' => test()->workspace->getKey()]);

    $purchase = app(PurchaseStoreItem::class)->handle(test()->buyer, PurchaseData::fromArray([
        'item_uuid' => $printed->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    // ⚠️ APPROVED, NOT MERELY FULFILLED: a refund is refused on an order that
    // was never paid, and `FulfilStorePurchase` alone leaves it `pending`.
    // Approval fulfils through `FulfilOnPaymentApproved` like production does.
    app(ApproveOrder::class)->handle(
        Order::query()->whereKey($purchase->order_id)->firstOrFail(),
        test()->owner,
    );

    return [$printed, $purchase->refresh()];
}

it('refuses to refund a fulfilled printed copy and sends the buyer to the administration', function (): void {
    [$printed, $purchase] = printedAndFulfilled();

    expect((int) $printed->refresh()->stock)->toBe(1)
        // Well inside the window and never «opened» — so neither of the file's
        // two conditions can be what refuses it.
        ->and($purchase->first_accessed_at)->toBeNull();

    expect(fn (): StoreOrder => app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer))
        ->toThrow(DomainException::class, RefundStorePurchase::PRINTED_REFUSAL);

    expect($purchase->refresh()->refunded_at)->toBeNull()
        ->and(Order::query()->whereKey($purchase->order_id)->value('status'))->not->toBe('refund_due')
        // No copy invented on the shelf.
        ->and((int) $printed->refresh()->stock)->toBe(1);
});

it('does not offer the refund button on a printed copy on its way', function (): void {
    [, $purchase] = printedAndFulfilled();

    $payload = (new StoreOrderResource($purchase->load('shipment')))->toArray(request());

    expect($payload['is_refundable'])->toBeFalse();
});

it('refuses it for a buyer whose context names another workspace too', function (): void {
    [, $purchase] = printedAndFulfilled();

    // The student a teacher once added to ANOTHER workspace: the scope resolves
    // that workspace, and a scoped read of the shipment would find no parcel.
    [$elsewhere, $otherOwner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($elsewhere, $otherOwner);

    expect(fn (): StoreOrder => app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer))
        ->toThrow(DomainException::class, RefundStorePurchase::PRINTED_REFUSAL);
});

it('still refunds a file inside the window, unopened, and offers the button for it', function (): void {
    $purchase = boughtAndPaid();

    expect($purchase->shipment)->toBeNull();

    $payload = (new StoreOrderResource($purchase->load('shipment')))->toArray(request());

    expect($payload['is_refundable'])->toBeTrue();

    expect(app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer)->refunded_at)->not->toBeNull();
});

it('refuses to refund an order that was never paid, and leaves it pending', function (): void {
    /*
    | ⛔ A pending order used to be «refunded» — and became `refund_due`, an
    | instruction to the platform's finance officer to send back a transfer that
    | never arrived. Nothing was paid, so there is nothing to give back: the
    | buyer simply does not pay.
    */
    $printed = StoreItem::factory()->physical(2)->create(['workspace_id' => $this->workspace->getKey()]);

    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $printed->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    expect(fn (): StoreOrder => app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer))
        ->toThrow(DomainException::class, RefundStorePurchase::UNPAID_REFUSAL);

    expect($purchase->refresh()->refunded_at)->toBeNull()
        ->and(Order::query()->whereKey($purchase->order_id)->value('status'))->toBe('pending')
        // And no copy invented on the shelf.
        ->and((int) $printed->refresh()->stock)->toBe(2);
});

it('leaves the refund open when the file was not ready to open', function (): void {
    /*
    | ⚠️ THE ORDER OF TWO LINES IN `IssueStoreAccess`, AND IT WAS WRONG.
    |
    | The stamp used to be written BEFORE the mint, which throws while a file is
    | still transcoding. So a buyer who tapped «افتح» during an encode read
    | «قيد التجهيز» — and their refund window had just been closed for ever,
    | on a file that never opened. Asking for the money back afterwards answered
    | «فُتِح هذا الملف» about something nobody had read, with nothing to
    | sweep it back: the window is a clock and it had already run out.
    */
    $this->asset->forceFill(['status' => MediaAssetStatus::Processing])->save();

    $purchase = boughtAndPaid();

    expect(fn () => app(IssueStoreAccess::class)->handle($purchase->uuid, $this->buyer, $this->session))
        ->toThrow(DomainException::class);

    expect($purchase->refresh()->first_accessed_at)->toBeNull();

    // The whole point: the money is still refundable.
    $refunded = app(RefundStorePurchase::class)->handle($purchase->uuid, $this->buyer);

    expect($refunded->refunded_at)->not->toBeNull();
});
