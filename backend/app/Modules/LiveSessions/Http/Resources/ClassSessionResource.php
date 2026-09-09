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
            /*
             | ⚠️ AND HOW LONG UNTIL IT DOES — `0` open now, `null` never again.
             |
             | `join_open` above is answered ONCE, at fetch, so a student who
             | opens their timetable twenty minutes early watches the countdown
             | reach «بدأت الآن» while the door stays shut until they reload. The
             | browser may tick this number down; it may never DERIVE one from
             | `starts_at` minus a constant, which is the thing the block above
             | forbids and for the same two reasons — the window is a
             | `platform_settings` row an operator tunes, and the machine's clock
             | may be an hour out (SC-016).
             |
             | One spelling: the model answers it, and `ScheduleController`'s
             | course header reads the same method.
             */
            'seconds_until_join_open' => $this->secondsUntilJoinOpen(now()),
            /*
             | ⚠️ AND THE COUNTDOWN IS SEEDED HERE TOO, for the half of SC-016
             | the field above does not cover: a browser that derived «starts in»
             | from `starts_at` minus `Date.now()` counts down to a moment that
             | does not exist on a machine an hour out, and the student arrives
             | late believing they were early. `/schedule/next` and the course
             | header keep their own top-level key — same number, and their
             | callers read it there.
             */
            'seconds_until_start' => max(0, (int) now()->diffInSeconds($this->starts_at, false)),
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
            /*
             | The group this lesson belongs to, by NAME.
             |
             | ⚠️ NOT `whenLoaded`, BECAUSE THERE IS NO RELATION TO LOAD. There is
             | no `cohort()` on `ClassSession` and there must not be — the cohort
             | is Learning's model and this module never imports one — so the
             | name is STAMPED in bulk by `CohortNames::stamp()` at the call site,
             | the same shape `UnlockReader::stamp()` has. A lookup here would run
             | once per row, which is fifty queries on a month of calendar.
             |
             | ⚠️ AND `null` IS AN ANSWER, NOT AN ABSENCE. Every row that went
             | through the stamp has one, so null means «no group» — the state
             | every session in this database was born in, before groups existed.
             | A caller that forgets to stamp gets null too, which is why the
             | stamp sits beside the query rather than being left to each screen.
             */
            'cohort_name' => $this->getAttribute('cohort_name'),
            /*
             | ⚠️ ONLY WHEN THE RELATION WAS LOADED — absent, never guessed. A
             | Resource runs once per row, so `$this->teacherProfile?->user` read
             | unconditionally is two queries per session on every calendar in the
             | product. `whenLoaded` drops the key for a caller that did not ask,
             | which reads as «not fetched» rather than «no teacher».
             */
            'teacher_name' => $this->whenLoaded(
                'teacherProfile',
                fn (): ?string => $this->teacherProfile?->user?->name,
            ),
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
