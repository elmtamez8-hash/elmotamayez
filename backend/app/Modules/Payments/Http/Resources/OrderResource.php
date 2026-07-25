<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at,
            'course_title' => $this->course?->title,
            'has_receipt' => $this->hasMedia('receipt'),
            // Lets the buyer's client tell "upload your receipt" apart from a
            // staff member looking at someone else's order.
            'is_mine' => $this->user_id === $request->user()?->getKey(),
            'receipt_url' => $this->hasMedia('receipt')
                ? $this->getFirstMediaUrl('receipt')
                : null,
            'created_at' => $this->created_at,
        ];
    }
}
