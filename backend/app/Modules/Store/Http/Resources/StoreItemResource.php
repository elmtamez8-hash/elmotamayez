<?php

declare(strict_types=1);

namespace App\Modules\Store\Http\Resources;

use App\Modules\Store\Models\StoreItem;
use App\Modules\Store\Support\StoreSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product, as the teacher's screen and the student's screen both read it.
 *
 * ⚠️ NO `teacher_net_minor` AND NO `amount_minor`. `ContextIsolationTest` sweeps
 * every module's `Resources/` with `str_contains` for `net_minor`, and exempts
 * `amount_minor` for `Payments/` alone — so the money key here is `price_minor`.
 * The teacher's share is not sent at all: it is `price − commission`, which the
 * screen computes from the two numbers below, and a stored net travelling to a
 * student is one subtraction away from the platform's cut.
 *
 * @mixin StoreItem
 */
class StoreItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'description' => $this->description,
            'price_minor' => $this->price_minor,
            'currency' => $this->currency,
            'shipping_fee_minor' => $this->shipping_fee_minor,
            // `null` for a digital item, which is the value and not a missing
            // one: the screen renders «غير محدود» rather than a zero that reads
            // as sold out.
            'stock' => $this->stock,
            'is_active' => $this->is_active,
            // The published rate, so the teacher's screen can show what they
            // will keep without the server sending a number that also answers
            // the platform's side of the price.
            'commission_bps' => StoreSettings::commissionBps(),
            'course' => $this->whenLoaded('course', fn (): array => [
                'uuid' => $this->course?->uuid,
                'title' => $this->course?->title,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
