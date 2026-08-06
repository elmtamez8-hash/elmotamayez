<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Support\SessionSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClassSession
 *
 * What crosses the wire. Three things deliberately do NOT:
 *
 *  - `broadcast_provider` and `broadcast_room_id` (FR-019). Naming the provider
 *    in a payload is naming it in the frontend bundle.
 *  - the autoincrement id (Constitution VI).
 *  - `billable_seats`, unless the reader manages the session. It is an
 *    accounting figure; showing it to a student invites a question about an
 *    invoice that has not been sent.
 */
class ClassSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $booking = $this->bookingFor($viewer?->getKey());

        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            // Declared with the time rather than assumed by the client, so a
            // countdown cannot render a moment in the wrong zone (SC-016).
            'timezone' => app(SessionSettings::class)->timezone(),
            'seats' => [
                'total' => $this->seats_total,
                'taken' => $this->seats_taken,
                'available' => $this->seatsAvailable(),
            ],
            'my_booking' => $booking === null ? null : [
                'uuid' => $booking->uuid,
                'status' => $booking->status->value,
                'status_label' => $booking->status->label(),
                'may_cancel_until' => $this->cancellationDeadline()->toIso8601String(),
            ],
            // A session may have no course at all — it can hang off a subject
            // alone — so the loaded relation is legitimately null.
            'course' => $this->whenLoaded('course', fn (): ?array => $this->course === null ? null : [
                'uuid' => $this->course->uuid,
                'title' => $this->course->title,
            ]),
            'recording' => $this->recording_status === null ? null : [
                'status' => $this->recording_status,
                // The uuid is the route in. Publishing a lesson and not saying
                // where it is has already cost this product a whole phase.
                'lesson_uuid' => $this->recordingLessonUuid(),
            ],
        ];
    }

    /** The lesson a published recording became, if it has been published. */
    private function recordingLessonUuid(): ?string
    {
        if ($this->recording_status !== 'published') {
            return null;
        }

        $uuid = Lesson::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $this->getKey())
            ->value('uuid');

        return $uuid === null ? null : (string) $uuid;
    }

    private function bookingFor(mixed $userId): ?SessionBooking
    {
        if ($userId === null || ! $this->relationLoaded('bookings')) {
            return null;
        }

        return $this->bookings->firstWhere('student_user_id', $userId);
    }
}
