<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\PaymentReconciliationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentReconciliationRun */
class PaymentReconciliationRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'ran_at' => $this->ran_at->toIso8601String(),
            // The window, because "found nothing" is only meaningful beside what
            // was looked at. A run covering four minutes and a run covering four
            // days both report zero the same way.
            'window_from' => $this->window_from->toIso8601String(),
            'window_to' => $this->window_to->toIso8601String(),
            'checked_count' => $this->checked_count,
            'corrected_count' => $this->corrected_count,
            'unresolved_count' => $this->unresolved_count,
            /*
             * ⚠️ THE SAMPLE, AND THE COUNT ABOVE IS THE TRUTH. A screen showing
             * 200 rows under a count of 9,000 is telling the truth about both;
             * one that showed 200 and called it the total would report a broken
             * deploy as a slow morning.
             *
             * Findings carry uuids and references only — never an amount, a
             * payer's name or a payload. SC-012 forbids a payment-method detail
             * in any response of this phase, and a reconciliation screen is the
             * one most tempted to dump the provider's body onto it.
             */
            'findings' => $this->findings ?? [],
        ];
    }
}
