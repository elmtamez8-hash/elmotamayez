<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Modules\Store\Models\StoreOrder;
use App\Modules\Store\Support\StoreSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One purchase, as the buyer reads it.
 *
 * ⚠️ THE MONEY KEY IS `total_minor`, AND THAT NAME IS LOAD-BEARING.
 * `ContextIsolationTest` exempts `amount_minor` for `Payments/` files alone and
 * sweeps every module for `net_minor` — so the store speaks its own vocabulary.
 * `commission_minor` and `teacher_net_minor` are absent entirely: what the
 * platform kept and what the teacher earned are not facts about the buyer.
 *
 * ⚠️ AND `is_refundable` IS SENT RATHER THAN RE-DERIVED IN TYPESCRIPT. The two
 * conditions are a clock and a flag, and a client that recomputes them shows an
 * enabled button the server then refuses — the two-spellings defect that made a
 * paid-for recording unreachable in spec 018.
 *
 * @mixin StoreOrder
 */
class StoreOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'discount_minor' => $this->discount_minor,
            'shipping_minor' => $this->shipping_minor,
            /*
             * Per LINE, and reconstructed from what the buyer agreed to rather
             * than read off the order: `orders.amount_minor` is Payments'
             * vocabulary and does not cross into this module's payload.
             *
             * ⚠️ POSTAGE INCLUDED, AND OMITTING IT WAS A REAL DEFECT. This is
             * the number somebody transfers: a printed purchase showed 50 while
             * the order was waiting for 65, so the buyer sent what the screen
             * told them and the callback answered `mismatch` — no delivery, no
             * refund, and a reconciliation case over our own arithmetic.
             */
            'total_minor' => $this->unit_price_minor * $this->quantity
                - $this->discount_minor
                + $this->shipping_minor,
            'currency' => $this->currency,
            'is_fulfilled' => $this->fulfilled_at !== null,
            'opened_at' => $this->first_accessed_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'is_refundable' => $this->refunded_at === null
                && $this->first_accessed_at === null
                && $this->refundDeadlineHasNotPassed(),
            'purchased_at' => $this->created_at?->toIso8601String(),
            'item' => new StoreItemResource($this->whenLoaded('item')),
            'shipment' => new ShipmentResource($this->whenLoaded('shipment')),
        ];
    }

    private function refundDeadlineHasNotPassed(): bool
    {
        $deadline = $this->created_at?->addHours(
            StoreSettings::refundWindowHours(),
        );

        return $deadline !== null && $deadline->isFuture();
    }
}
