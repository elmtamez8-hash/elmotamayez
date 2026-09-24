<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Payments\Models\Order;
use App\Modules\Store\Actions\AdvanceShipment;
use App\Modules\Store\Actions\FulfilStorePurchase;
use App\Modules\Store\Actions\PurchaseStoreItem;
use App\Modules\Store\Data\PurchaseData;
use App\Modules\Store\Enums\ShipmentStatus;
use App\Modules\Store\Models\Shipment;
use App\Modules\Store\Models\StoreItem;
use App\Shared\Support\GuardianPermission;

/*
| `shipment_status_changed` from a parcel actually moving
| (`EveryNotificationTypeIsTestedTest`).
|
| A parcel is a fact about a purchase, and the guardian is usually the person who
| made it — so the one entitled to PAYMENTS hears each step and one entitled only
| to attendance does not. And a step claimed by somebody else between the read
| and the write sends nothing: the buyer hears about each step once.
*/

it('tells the buyer and the paying guardian each step the parcel takes', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $item = StoreItem::factory()->physical(stock: 3)->create(['workspace_id' => $workspace->getKey()]);

    $buyer = User::factory()->create();
    $payer = guardianOf($buyer, [GuardianPermission::Payments]);
    $attendanceOnly = guardianOf($buyer, [GuardianPermission::Attendance]);

    $purchase = app(PurchaseStoreItem::class)->handle($buyer, PurchaseData::fromArray([
        'item_uuid' => $item->uuid,
        'recipient_name' => 'نورة',
        'phone' => '+97455512345',
        'address_line' => 'الدوحة',
    ]));

    app(FulfilStorePurchase::class)->handle(Order::query()->whereKey($purchase->order_id)->firstOrFail());

    $shipment = Shipment::query()->withoutWorkspaceScope()->where('store_order_id', $purchase->getKey())->sole();

    // A second officer's copy, read before the first one moved the parcel.
    $stale = clone $shipment;

    app(AdvanceShipment::class)->handle($shipment, ShipmentStatus::Packed);

    assertNotifiedOnce($buyer, NotificationType::ShipmentStatusChanged);
    assertNotifiedOnce($payer, NotificationType::ShipmentStatusChanged);

    expect(wasNotified($attendanceOnly, NotificationType::ShipmentStatusChanged))->toBeFalse();

    // The same step again, from a stale copy of the row: the conditional UPDATE
    // matches nothing, and nothing is sent twice.
    app(AdvanceShipment::class)->handle($stale, ShipmentStatus::Packed);

    assertNotifiedOnce($buyer, NotificationType::ShipmentStatusChanged);
});
