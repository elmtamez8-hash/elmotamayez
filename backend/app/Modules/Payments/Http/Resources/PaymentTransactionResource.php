<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment, as its payer may see it.
 *
 * ⚠️ NO `payload`, EVER. It is a free column the other side writes; sending it
 * back would export whatever a provider chose to echo — the one field both
 * sanitizers exist to keep out of storage, handed to the browser.
 *
 * @mixin PaymentTransaction
 */
class PaymentTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'method' => $this->method?->value,
            'method_label' => $this->method?->label(),
            // Minor units, unformatted: the API never sends money as text, so
            // the client can add it up without parsing a string back.
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            // FR-008 — a sentence the student can act on, never the provider's
            // raw error, which names systems they have no relationship with.
            'failure_reason' => $this->failure_reason,
            'settled_at' => $this->settled_at,
            'created_at' => $this->created_at,
        ];
    }
}
