<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;

/*
| FR-006ب — the last copy went while the bank transfer was clearing.
|
| ⚠️ THIS IS NOT AN EDGE CASE. Days separate a purchase from the approval of a
| manual transfer, which is the only payment method in the MVP. «Zero rows, you
| lost, nothing follows» is the wrong last word about an order whose money has
| been taken: the buyer is left paid up, holding nothing, with no message saying
| why.
|
| ⚠️ AND THE FULFILMENT STAMP IS RELEASED AGAIN. It was claimed to make the
| delivery run once; left set on an order that delivered nothing it tells
| `IssueStoreAccess` to open a file nobody has a copy of.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->physical(stock: 1)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->first = User::factory()->create();
    $this->second = User::factory()->create();
});

/**
 * The bridge row and its order, together. A Pest `test()` proxy cannot be
 * written into by index, so the pair is returned rather than stashed.
 *
 * @return array{StoreOrder, Order}
 */
function purchaseForBuyer(User $buyer): array
{
    $purchase = app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => test()->item->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    return [$purchase, Order::query()->whereKey($purchase->order_id)->firstOrFail()];
}

it('marks the order refund_due and tells the buyer', function (): void {
    [, $firstOrder] = purchaseForBuyer($this->first);
    [$secondPurchase, $secondOrder] = purchaseForBuyer($this->second);

    app(FulfilStorePurchase::class)->handle($firstOrder);
    app(FulfilStorePurchase::class)->handle($secondOrder);

    expect($secondOrder->refresh()->status)->toBe('refund_due')
        // Released, so nothing downstream reads this order as delivered.
        ->and($secondPurchase->refresh()->fulfilled_at)->toBeNull();

    $told = Notification::query()
        ->where('recipient_user_id', $this->second->getKey())
        ->where('type', NotificationType::StorePurchaseUnavailable->value)
        ->exists();

    expect($told)->toBeTrue();
});

it('leaves the winner untouched', function (): void {
    [$firstPurchase, $firstOrder] = purchaseForBuyer($this->first);
    [, $secondOrder] = purchaseForBuyer($this->second);

    app(FulfilStorePurchase::class)->handle($firstOrder);
    app(FulfilStorePurchase::class)->handle($secondOrder);

    // The positive control. Without it a branch that refunded EVERYBODY would
    // satisfy the case above.
    expect($firstOrder->refresh()->status)->not->toBe('refund_due')
        ->and($firstPurchase->refresh()->fulfilled_at)->not->toBeNull();
});

it('sends a message with no price in it', function (): void {
    [, $firstOrder] = purchaseForBuyer($this->first);
    [, $secondOrder] = purchaseForBuyer($this->second);

    app(FulfilStorePurchase::class)->handle($firstOrder);
    app(FulfilStorePurchase::class)->handle($secondOrder);

    $notification = Notification::query()
        ->where('recipient_user_id', $this->second->getKey())
        ->where('type', NotificationType::StorePurchaseUnavailable->value)
        ->firstOrFail();

    // A store total minus the published commission IS the teacher's net, so a
    // number here is one subtraction away from the other side's rate.
    $body = json_encode($notification->getAttributes(), JSON_UNESCAPED_UNICODE);

    expect($body)->not->toContain((string) $this->item->price_minor);
});

it('tells the buyer once however many times the approval is redelivered', function (): void {
    /*
    | ⚠️ `fulfilled_at` IS RELEASED ON THIS PATH, so a redelivered
    | `PaymentApproved` re-claims it, fails on the shelf again, and reaches the
    | refund branch again. Without a conditional transition on `orders.status`
    | the buyer is told their order is unavailable once per queue retry — about
    | one order, one refund, and nothing that changed.
    */
    [, $firstOrder] = purchaseForBuyer($this->first);
    [, $secondOrder] = purchaseForBuyer($this->second);

    app(FulfilStorePurchase::class)->handle($firstOrder);
    app(FulfilStorePurchase::class)->handle($secondOrder);
    app(FulfilStorePurchase::class)->handle($secondOrder);
    app(FulfilStorePurchase::class)->handle($secondOrder);

    $told = Notification::query()
        ->where('recipient_user_id', $this->second->getKey())
        ->where('type', NotificationType::StorePurchaseUnavailable->value)
        ->count();

    expect($told)->toBe(1);
});
