<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Models\StoreOrder;
use App\Shared\Support\WorkspaceContext;

/*
| ⚠️ THE BUYER IS BUILT WITH NO SEEDER AND NO `setCurrentWorkspace()`, AND THAT
| IS THE ENTIRE TEST.
|
| `BelongsToWorkspace` fills `workspace_id` only `if ($workspaceId !== null)`,
| and nothing on a student's path ever writes `users.last_workspace_id` — its
| only writers are `CreateWorkspace` and `WorkspaceContext::set()`, both about
| workspace MEMBERS. So in production the context is null for every buyer, the
| trait writes nothing, and the column lands EMPTY on every row, silently.
|
| A fixture that calls `setCurrentWorkspace()` gives the test buyer a context
| production never gives them, and `addWorkspaceMember()` additionally stamps the
| column — so either one measures a person who does not exist and the defect
| ships green. This file resets the singleton and leaves the column NULL.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->digital = StoreItem::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->printed = StoreItem::factory()->physical(3)->create(['workspace_id' => $this->workspace->getKey()]);

    $this->buyer = User::factory()->create();

    expect($this->buyer->last_workspace_id)->toBeNull();

    // The context was resolved for the owner in the lines above and is CACHED —
    // it is an application-wide singleton. Without this the buyer inherits the
    // teacher's workspace and the whole file passes for the wrong reason.
    app()->forgetInstance(WorkspaceContext::class);
    app(WorkspaceContext::class)->forget();
});

it('writes the workspace onto a purchase made by somebody who belongs to none', function (): void {
    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->digital->uuid,
    ]));

    expect(app(WorkspaceContext::class)->id())->toBeNull()
        ->and((int) $purchase->workspace_id)->toBe((int) $this->workspace->getKey());
});

it('writes it onto the shipment too', function (): void {
    $purchase = app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->printed->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    $shipment = Shipment::query()
        ->withoutWorkspaceScope()
        ->where('store_order_id', $purchase->getKey())
        ->firstOrFail();

    expect((int) $shipment->workspace_id)->toBe((int) $this->workspace->getKey());
});

it('leaves no store row with an empty workspace', function (): void {
    app(PurchaseStoreItem::class)->handle($this->buyer, PurchaseData::fromArray([
        'item_uuid' => $this->printed->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    // The failure mode is a ZERO or a NULL, not a wrong number — which is why
    // this asserts on the whole table rather than on one row's value.
    expect(StoreOrder::query()->withoutWorkspaceScope()->where('workspace_id', '<=', 0)->count())->toBe(0)
        ->and(Shipment::query()->withoutWorkspaceScope()->where('workspace_id', '<=', 0)->count())->toBe(0);
});
