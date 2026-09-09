<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\LiveSessions\Events\SessionRescheduleRequested;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Shared\Actions\Action;
use App\Shared\Contracts\SessionAttendanceDirectory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * «أعتذر عن سبت هذا الأسبوع — هل يمكن الأحد ٦م؟»
 *
 * ⚠️ NOTHING MOVES AND NOTHING IS HELD. The lesson keeps its hour, every seat
 * keeps its holder, and the teacher's calendar is untouched until somebody
 * presses a button. That is what makes a refusal free for both sides, and
 * therefore what makes «موافقة المدرّس» a real decision rather than a formality
 * after the timetable has already changed.
 *
 * ⚠️ AND THE ASKER IS ASKED FOR A SEAT, NOT FOR A COHORT TYPE. A private hour's
 * one student and a group's ten reach this through the same door: a seat in THIS
 * session is the entire predicate, so nothing here branches on whether the group
 * is individual or not — and the day somebody adds a third kind of cohort this
 * action needs no edit.
 *
 * ⚠️ THE CLASH AND THE FREEZE ARE NOT CHECKED HERE, ON PURPOSE. They are true at
 * the moment of the MOVE, not at the moment of the ask — a Sunday that is free
 * today may be taken by Thursday — so re-asking them here would refuse a request
 * over a condition that has to hold at a different moment.
 * {@see DecideSessionRescheduleRequest} is where the calendar is actually
 * written, and it is where they belong.
 */
class RequestSessionReschedule extends Action
{
    public function __construct(
        private readonly SessionAttendanceDirectory $attendance,
    ) {}

    public function handle(
        ClassSession $session,
        User $student,
        CarbonImmutable $to,
        ?string $reason = null,
    ): SessionRescheduleRequest {
        if ($session->status->isTerminal()) {
            throw new DomainException('هذه الحصة انتهت أو أُلغيت.');
        }

        if (CarbonImmutable::instance($session->starts_at)->isPast()) {
            // An hour that has begun cannot be moved — and asking is the student
            // telling the teacher they will not attend, which is a different
            // conversation with a different answer.
            throw new DomainException('لا يمكن طلب تأجيل حصة بدأت بالفعل.');
        }

        // The whole guard. `hasSeatInSession` is the same question every other
        // door in this module asks, so a student cannot ask about a lesson they
        // are not in — including one they can see on a public course page.
        if (! $this->attendance->hasSeatInSession($student, (int) $session->getKey())) {
            throw new DomainException('ليس لك مقعد في هذه الحصة.');
        }

        if ($to->isPast()) {
            throw new DomainException('لا يمكن اقتراح موعد قد مضى.');
        }

        if ($to->equalTo(CarbonImmutable::instance($session->starts_at))) {
            // It cannot become true later, so a queue carrying it is a queue with
            // a row in it that has no possible decision — the `same_cohort`
            // reasoning in `RequestTransfer`.
            throw new DomainException('هذا هو موعد الحصة الحالي.');
        }

        try {
            $request = SessionRescheduleRequest::query()->create([
                'workspace_id' => $session->workspace_id,
                'class_session_id' => $session->getKey(),
                'student_user_id' => $student->getKey(),
                'from_starts_at' => CarbonImmutable::instance($session->starts_at)->utc(),
                'to_starts_at' => $to->utc(),
                'student_reason' => $reason,
            ]);
        } catch (UniqueConstraintViolationException) {
            // `unique(class_session_id, pending_slot)` with its zero sentinel is
            // what makes «طلب واحد قائم لكل حصة» true under concurrency. Two
            // classmates proposing two different Sundays would otherwise leave
            // the teacher two buttons that move the same lesson twice.
            throw new DomainException('هناك طلب تأجيل قائم على هذه الحصة بانتظار ردّ المدرّس.');
        }

        // ⚠️ REFRESHED, BECAUSE `status` AND `pending_slot` ARE NOT FILLABLE.
        // Their values come from the column defaults, so the instance `create()`
        // hands back carries NULL for both — and the Resource would send
        // `status: null` while the row says `pending`.
        $request->refresh();

        SessionRescheduleRequested::dispatch($request);

        return $request;
    }
}
