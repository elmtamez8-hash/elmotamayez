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
            /*
             | ⚠️ STAMPED BY THE CALLER, NEVER ASKED HERE. A Resource runs once
             | per row, so resolving the unlock condition in this method is six
             | queries per session on a fifty-row page — the ClassSessionResource
             | defect this class already carries a fix for once. Null when the
             | caller did not stamp, which reads as "not asked" rather than
             | "open": a screen that needs the answer asks for it.
             */
            'unlock_open' => $this->getAttribute('unlock_open'),
            'unlock_reason' => $this->getAttribute('unlock_reason'),
            'uuid' => $this->uuid,
            'title' => $this->title,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            /*
             | ⚠️ `live` OUTLIVES THE ROOM BY UP TO THE JOIN WINDOW, AND THE SCREEN
             | HAS NO OTHER WAY TO KNOW.
             |
             | `CloseClassSessionJob` runs at `ends_at` + the join window so a
             | teacher who just shuts their laptop does not leave a session live
             | for ever. A teacher who ends the broadcast DELIBERATELY is in that
             | same gap: the room is deleted and unopenable, while the status is
             | still `live` — so the page badged a finished lesson «جارية» and
             | kept offering «دخول الغرفة», which answers «تعذّر الدخول».
             |
             | A boolean, not the timestamp: the client needs "is the door shut",
             | and the closing time is nobody's business on a card.
             */
            'room_closed' => $this->room_closed_at !== null,
            /*
             | ⚠️ ANSWERED HERE, NEVER BY THE BROWSER'S CLOCK (FR-015 · SC-016).
             |
             | The join window is a `platform_settings` row an operator tunes, and
             | the room's own closure sits inside it — so a client that computed
             | «is the door open» from `starts_at` and a constant would offer a
             | student a button the server answers «تعذّر الدخول», and would go on
             | offering it on a machine whose clock is wrong. It is the same
             | predicate `IssueJoinTicket` refuses on, asked one step earlier.
             |
             | It says nothing about ENTITLEMENT — a seat, a balance, a piece of
             | homework are all still asked at the door. It is the clock and the
             | door, and those two alone.
             */
            'join_open' => $this->joinWindowCovers(now()),
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

    /**
     * The lesson a published recording became, if it has been published.
     *
     * Read from the eager-loaded relation wherever sessions are listed. The
     * fallback query is deliberate and deliberately last: a Resource runs once
     * per row, so a month of sessions used to cost a month of single-row SELECTs
     * against `lessons` (QueryBudgetTest fails if that comes back). Dropping the
     * fallback entirely would be worse — a caller who forgot to eager-load would
     * silently publish a recording with no way in, which is a bug this product
     * has already shipped once.
     */
    private function recordingLessonUuid(): ?string
    {
        if ($this->recording_status !== 'published') {
            return null;
        }

        if ($this->relationLoaded('recordingLesson')) {
            return $this->recordingLesson?->uuid;
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
