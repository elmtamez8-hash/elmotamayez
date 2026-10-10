<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\AdvanceShipment;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Enums\ShipmentStatus;
use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreItem;
use Laravel\Sanctum\Sanctum;

/*
| Security scan 2026-10-10, F12 — the parcel row is written when an UNPAID order
| is placed, and the queue listed it beside the paid ones and let it move.
*/

function placedPurchase(object $test, object $workspace): object
{
    $item = StoreItem::factory()->physical(stock: 3)->create(['workspace_id' => $workspace->getKey()]);

    return app(PurchaseStoreItem::class)->handle(User::factory()->create(), PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));
}

it('keeps an unpaid purchase out of the queue and refuses to move its parcel', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $unpaid = placedPurchase($this, $workspace);
    $paid = placedPurchase($this, $workspace);
    app(FulfilStorePurchase::class)->handle(Order::query()->whereKey($paid->order_id)->firstOrFail());

    Sanctum::actingAs($owner);
    $listed = collect($this->getJson('/api/v1/store/shipments')->assertOk()->json('data'))->pluck('uuid');

    $unpaidShipment = Shipment::query()->withoutWorkspaceScope()->where('store_order_id', $unpaid->getKey())->sole();
    $paidShipment = Shipment::query()->withoutWorkspaceScope()->where('store_order_id', $paid->getKey())->sole();

    expect($listed)->toContain($paidShipment->uuid)->not->toContain($unpaidShipment->uuid);

    expect(fn () => app(AdvanceShipment::class)->handle($unpaidShipment, ShipmentStatus::Packed))
        ->toThrow(DomainException::class);
    expect($unpaidShipment->fresh()->status)->toBe(ShipmentStatus::Pending);
});
