<?php

declare(strict_types=1);

namespace App\Modules\Store\Actions;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Store\Enums\ShipmentStatus;
use App\Modules\Store\Models\Shipment;
use App\Shared\Actions\Action;
use DomainException;

/**
 * Move a parcel one step (spec 011 · US1 · FR-008).
 *
 * ⚠️ A CONDITIONAL TRANSITION, `WHERE status = :expected`. Every change notifies
 * the buyer, so a read-then-write from two workers tells them twice — or moves
 * the parcel backwards and sends «في الطريق» after «وصل», which is a message
 * nobody can un-send. The `WHERE` is both the check and the claim, and the
 * notification is dispatched only by the runner that won it.
 *
 * ⚠️ AND THE LADDER IS ASKED FIRST. `ShipmentStatus::next()` is a list rather
 * than a number, because `Returned` follows a delivery attempt and nothing else
 * — modelled as an ordered ladder it is either unreachable or reachable from
 * everywhere.
 */
class AdvanceShipment extends Action
{
    public function __construct(private readonly DispatchNotification $notify) {}

    public function handle(Shipment $shipment, ShipmentStatus $to, ?string $trackingRef = null): Shipment
    {
        $from = $shipment->status;

        if (! in_array($to, $from->next(), true)) {
            throw new DomainException("لا يمكن نقل الشحنة من «{$from->label()}» إلى «{$to->label()}».");
        }

        $changes = [
            'status' => $to->value,
            // Stamped inside the same statement that moves the status. Derived
            // from `updated_at` it would move for every unrelated write to the
            // row — the reason `notified_dormant_at` exists.
            'status_changed_at' => now(),
        ];

        if ($trackingRef !== null) {
            $changes['tracking_ref'] = $trackingRef;
        }

        $moved = Shipment::query()
            ->withoutWorkspaceScope()
            ->whereKey($shipment->getKey())
            ->where('status', $from->value)
            ->update($changes);

        if ($moved === 0) {
            // Somebody else moved it between the read and the write. Not an
            // error — the parcel is where it is — but nothing may be sent from
            // here, or the buyer hears about one step twice.
            $shipment->refresh();

            return $shipment;
        }

        $shipment->refresh();

        $this->announce($shipment, $to);

        return $shipment;
    }

    private function announce(Shipment $shipment, ShipmentStatus $to): void
    {
        $purchase = $shipment->storeOrder()->withoutWorkspaceScope()->first();
        $buyer = $purchase?->buyer()->first();
        $item = $purchase?->item()->withoutWorkspaceScope()->first();

        if ($buyer === null || $item === null) {
            return;
        }

        $this->notify->handle(new NotificationRequest(
            recipient: $buyer,
            type: NotificationType::ShipmentStatusChanged,
            /*
             * ⚠️ THE ORDER OF THIS ARRAY IS THE MESSAGE. What reaches the
             * provider is the template NAME and an ORDERED parameter list, so a
             * list assembled by walking a payload puts the status where the title
             * belongs — on a parent's phone, with no error anywhere.
             *
             * And `status` is a LABEL from the enum, never free text: a variable
             * somebody can type into is a variable inside an approved template,
             * which is the one thing a provider does not allow.
             */
            variables: [
                'item_title' => (string) $item->title,
                'status' => $to->label(),
            ],
            actionUrl: '/store/purchases',
            workspaceId: (int) $shipment->workspace_id,
            sourceType: Shipment::class,
            sourceId: (int) $shipment->getKey(),
        ));
    }
}
