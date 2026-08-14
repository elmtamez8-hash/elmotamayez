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
 * ⚠️ THE PRICE SNAPSHOT INCLUDES THE TEACHER'S RATE, AND OMITTING IT WOULD BE
 * FALSE COMFORT. `total = credits × (rate + operating) + gateway`, so any three
 * of the four solve for the fourth: a reader given the total, the credits and
 * the two platform fees already has the rate, whatever this class does. The two
 * guards that actually hold are elsewhere and are real — FR-033 keeps every
 * teacher and assistant off this endpoint entirely (`CollectionAccessTest`), and
 * `ContextIsolationTest` fails the build if any query here reaches a Settlement
 * table. What is stored on `credit_purchases` is Payments' own snapshot, written
 * by Payments at purchase time; FR-035's line is a cross-context READ, not a
 * number that happens to also exist on the other side of it.
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
                'teacher_rate_minor' => $purchase->teacher_rate_minor,
                'operating_fee_minor' => $purchase->operating_fee_minor,
                'gateway_fee_minor' => $purchase->gateway_fee_minor,
                'total_minor' => $purchase->total_minor,
            ],
        ];
    }
}
