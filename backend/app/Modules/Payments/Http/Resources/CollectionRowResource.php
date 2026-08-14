<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\CreditPurchase;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One collected payment, with the snapshot of what its price was made of (FR-031).
 *
 * ⚠️ NEITHER `payload` NOR `reference` LEAVES THIS RESOURCE. `payload` is a free
 * column written by the other party — the whole reason `CallbackPayloadSanitizer`
 * exists — and a report that dumped it would carry whatever the sanitiser only
 * just finished redacting. FR-030 forbids a payment-instrument detail in the
 * record AND in its exports, and `PaymentFieldAllowlist` walks this payload for
 * both the key names and the values.
 *
 * ⚠️ AND `teacher_rate_minor` IS NOT IN THE SNAPSHOT, THOUGH THE COLUMN IS RIGHT
 * THERE. FR-035 forbids a teacher's settlement rate in ANY payload of this
 * phase, and the field is that rate under its own name — that the row lives on
 * `credit_purchases`, a table Payments owns, changes what it is stored in and
 * not what it is.
 *
 * ⚠️ WHICH DOES NOT CLOSE THE INFERENCE, and pretending otherwise would be the
 * lie. `total = credits × (rate + operating) + gateway`, so any three of the
 * four solve for the fourth: a reader holding the total, the credits and the two
 * platform fees can compute the rate whatever this class emits. The line is
 * still worth drawing — a computed number is a step somebody takes deliberately,
 * a named column is one they copy into a spreadsheet — but the guards that
 * actually hold are elsewhere and are real: FR-033 keeps every teacher and
 * assistant off this endpoint entirely (`CollectionAccessTest`), and
 * `ContextIsolationTest` fails the build if a query here reaches a Settlement
 * table. `StudentBalanceAllowlist` carries the same admission for the same
 * reason.
 *
 * @mixin PaymentTransaction
 */
class CollectionRowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ?Order $order */
        $order = $this->resource->getRelationValue('order');

        /** @var ?CreditPurchase $purchase */
        $purchase = $this->resource->getRelationValue('purchase');

        return [
            'uuid' => $this->resource->uuid,
            'occurred_at' => $this->resource->created_at?->toIso8601String(),
            'settled_at' => $this->resource->settled_at?->toIso8601String(),
            'status' => $this->resource->status->value,
            'method' => $this->resource->method?->value,
            'provider' => $this->resource->provider,
            'amount_minor' => $this->resource->amount_minor,
            'currency' => $this->resource->currency,
            'order_uuid' => $order?->uuid,
            // What the money bought — the report's third dimension, per row.
            'source' => $order?->kind->value,
            'student_uuid' => $order?->user?->uuid,
            'student_name' => $order?->user?->name,
            'pricing' => $purchase === null ? null : [
                'credits' => $purchase->credits,
                'operating_fee_minor' => $purchase->operating_fee_minor,
                'gateway_fee_minor' => $purchase->gateway_fee_minor,
                'total_minor' => $purchase->total_minor,
            ],
        ];
    }
}
