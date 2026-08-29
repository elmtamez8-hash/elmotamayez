<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\StoreItem;

/*
| One sale, one copy off the shelf — however many times the event arrives.
|
| `PaymentApproved` is redelivered by any queue retry, and the duplicate is
| invisible until somebody counts the books: no error, no log, a second copy
| simply gone.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->item = StoreItem::factory()->physical(stock: 5)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->buyer = User::factory()->create();

    $this->purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->item->uuid,
        'quantity' => 2,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    $this->order = Order::query()->whereKey($this->purchase->order_id)->firstOrFail();
});

it('takes the copies off the shelf exactly once for two deliveries of one approval', function (): void {
    app(FulfilStorePurchase::class)->handle($this->order);
    app(FulfilStorePurchase::class)->handle($this->order);

    // Five minus two, not five minus four.
    expect((int) $this->item->refresh()->stock)->toBe(3);
});

it('leaves the fulfilment stamp where the first delivery put it', function (): void {
    app(FulfilStorePurchase::class)->handle($this->order);

    $first = $this->purchase->refresh()->fulfilled_at;

    $this->travel(2)->hours();

    app(FulfilStorePurchase::class)->handle($this->order);

    // A re-stamp would move a fact about a moment that has passed — and here it
    // would also restart the buyer's refund window from the retry.
    expect($this->purchase->refresh()->fulfilled_at?->toIso8601String())
        ->toBe($first?->toIso8601String());
});

it('does nothing at all when the order buys something other than the store', function (): void {
    // `FulfilStorePurchase` is reached only through the listener's `kind` split,
    // but the Action must be safe on its own: a course order has no bridge row,
    // and an Action that assumed one would fatal on a null.
    $courseOrder = Order::create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->buyer->getKey(),
        'kind' => 'course',
        'amount_minor' => 1000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'approved',
    ]);

    app(FulfilStorePurchase::class)->handle($courseOrder);

    expect((int) $this->item->refresh()->stock)->toBe(5);
});
