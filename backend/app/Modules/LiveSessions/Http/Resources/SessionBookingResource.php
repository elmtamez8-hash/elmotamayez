<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\SessionBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SessionBooking */
class SessionBookingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'booked_at' => $this->booked_at->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'session' => ClassSessionResource::make($this->whenLoaded('classSession')),
        ];
    }
}
