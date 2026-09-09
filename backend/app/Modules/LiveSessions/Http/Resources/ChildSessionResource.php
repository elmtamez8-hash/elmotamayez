<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Http\Resources;

use App\Modules\LiveSessions\Models\SessionBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One booked hour of a child's week, for the GUARDIAN reading it.
 *
 * @mixin SessionBooking
 *
 * ⚠️ A NARROW RESOURCE RATHER THAN {@see ClassSessionResource}, AND THE REASON IS
 * THAT HALF OF THAT ONE IS COMPUTED **ABOUT THE READER**. Reused here it would
 * tell a guardian:
 *
 *  - `my_booking: null` about a session their child holds a seat in — the
 *    guardian has no booking, and the field asks about whoever is asking;
 *  - `join_open: true`, an invitation into a room they cannot enter: a guardian
 *    holds no seat, so `IssueJoinTicket` refuses them at the door;
 *  - `recording.lesson_uuid`, a link that answers `404` — playback needs an
 *    enrolment or workspace membership, and a guardian has neither;
 *  - `seats` and `unlock_open`/`unlock_reason`, which are the student's own
 *    booking conditions and say nothing to the person reading the timetable.
 *
 * Every one of those is a screen offering an action that cannot succeed, which
 * is the defect this repository has shipped from both directions already. So the
 * fields below are the whole payload, and adding one from the general resource
 * needs a reason written beside it.
 *
 * `status_label` is the one addition to the contract's list, and it is server
 * wording rather than a reader-relative computation: the alternative is a second
 * Arabic spelling of the four statuses in TypeScript, which is the two-spellings
 * defect wearing a label.
 */
class ChildSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $session = $this->classSession;

        return [
            'uuid' => $this->uuid,
            // Never null in practice — the Action selects bookings by an upcoming
            // session id — but the relation is nullable on the model, and a
            // Resource that assumes otherwise fails on the row nobody expected.
            'class_session' => $session === null ? null : [
                'uuid' => $session->uuid,
                'title' => $session->title,
                'starts_at' => $session->starts_at->toIso8601String(),
                'ends_at' => $session->ends_at->toIso8601String(),
                'status' => $session->status->value,
                'status_label' => $session->status->label(),
                /*
                 | ⚠️ OUTRANKS THE STATUS ON THE BADGE. `CloseClassSessionJob`
                 | runs at `ends_at` plus the join window, so a lesson the teacher
                 | ended early stays `live` in the meantime — and a card that
                 | reads the status alone tells a parent the lesson is running an
                 | hour after it finished.
                 */
                'room_closed' => $session->room_closed_at !== null,
                // A session can hang off a subject with no course at all, so the
                // loaded relation is legitimately null.
                'course' => $session->course === null ? null : [
                    'uuid' => $session->course->uuid,
                    'title' => $session->course->title,
                ],
                // The name the family knows the teacher by. Read off the loaded
                // relation — a Resource runs once per row, so a lookup here is an
                // N+1 by construction.
                'teacher_name' => $session->teacherProfile?->user?->name,
            ],
        ];
    }
}
