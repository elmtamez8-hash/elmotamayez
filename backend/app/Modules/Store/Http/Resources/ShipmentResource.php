<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Modules\Store\Models\Shipment;
use App\Modules\Tenancy\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A parcel, for the teacher's fulfilment queue and for the buyer's own order.
 *
 * ⚠️ THE ADDRESS IS GATED ON THE PACKING PERMISSION, ASKED HERE RATHER THAN SET
 * BY EACH CALLER. Two audiences reach this class — a buyer reading their own
 * parcel and a teacher posting it — and an opt-in flag would have to be
 * remembered at every call site, including the next one somebody adds. That is
 * the shape of guard this repository has watched fail: `OrderResource` declared
 * no `canViewAny()` and an assistant read every student's email.
 *
 * `store.shipments.manage` is precisely the permission that says «you may read a
 * child's home address», which is why it and not `store.items.manage` is the
 * question. The buyer's own copy comes back without it — they typed it, and it
 * is on the screen they typed it into.
 *
 * ⚠️ AND THE ANSWER IS MEMOISED PER REQUEST. A Resource runs once per row, so a
 * permission check inside it is an N+1 by construction — the `ClassSessionResource`
 * defect reached from a new direction. `$request->attributes` is the one bag
 * that lives exactly as long as the answer is valid.
 *
 * @mixin Shipment
 */
class ShipmentResource extends JsonResource
{
    private const MEMO = 'store.may_read_address';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payload = [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // Sent rather than re-derived in TypeScript: the ladder is a list,
            // not an order, and a client that guessed it would offer «مُرتجَع»
            // after delivery — two spellings of one rule, one on the screen and
            // one at the door.
            'next_statuses' => array_map(
                fn ($next): array => ['value' => $next->value, 'label' => $next->label()],
                $this->status->next(),
            ),
            'tracking_ref' => $this->tracking_ref,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
        ];

        if ($this->mayReadAddress($request)) {
            $payload['recipient_name'] = $this->recipient_name;
            $payload['phone'] = $this->phone;
            $payload['address_line'] = $this->address_line;
            $payload['notes'] = $this->notes;
        }

        return $payload;
    }

    private function mayReadAddress(Request $request): bool
    {
        $memo = $request->attributes->get(self::MEMO);

        if ($memo === null) {
            $memo = (bool) $request->user()?->can(Permissions::STORE_SHIPMENTS_MANAGE);
            $request->attributes->set(self::MEMO, $memo);
        }

        return (bool) $memo;
    }
}
