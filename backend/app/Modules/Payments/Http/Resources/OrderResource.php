<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'amount_minor' => $this->amount_minor,
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
            // NOT getFirstMediaUrl(): the receipt collection uses the `local`
            // disk, which has no `url` in config/filesystems.php, so spatie fell
            // back to the conventional /storage/{id}/{file} path — a path that
            // serves the *public* disk. Every receipt link 403'd, and any that
            // had worked would have been a financial document on a public path.
            'receipt_url' => $this->hasMedia('receipt')
                ? URL::temporarySignedRoute(
                    'orders.receipt',
                    now()->addMinutes(15),
                    ['order' => $this->uuid],
                )
                : null,
            'created_at' => $this->created_at,
        ];
    }
}
