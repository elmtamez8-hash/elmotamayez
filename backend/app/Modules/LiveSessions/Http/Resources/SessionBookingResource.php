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
            /*
             | The free-cancellation deadline, beside the booking it prices.
             |
             | ⚠️ The nested `session` cannot carry it: `ClassSessionResource`
             | puts it inside `my_booking`, which it reads from a `bookings`
             | relation nobody loads on this path — so a seat booked a second ago,
             | or listed on the timetable, had no deadline to show before the
             | student pressed «إلغاء الحجز». Only when the session is loaded, so
             | a list that does not load it pays no query per row.
             */
            'may_cancel_until' => $this->whenLoaded(
                'classSession',
                fn (): ?string => $this->classSession?->cancellationDeadline()->toIso8601String(),
            ),
            'session' => ClassSessionResource::make($this->whenLoaded('classSession')),
        ];
    }
}
