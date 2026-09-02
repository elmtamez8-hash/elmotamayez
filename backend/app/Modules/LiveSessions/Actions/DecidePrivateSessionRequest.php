<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Events\PrivateSessionDecided;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Support\PendingPrivateRequest;
use App\Shared\Actions\Action;
use App\Shared\Contracts\CohortDirectory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The teacher's answer — and the only moment anything is created (FR-019 · FR-020).
 *
 * ⚠️ ALL FOUR EFFECTS OR NONE. A group, a session, a seat and a settled request:
 * one transaction, because every partial outcome is worse than a refusal. A
 * session with no seat is an hour the student is not in; a settled request with
 * no session is an acceptance the student can point at and nobody can honour.
 *
 * ⚠️ AND AN ACCEPTANCE CAN FAIL, ON PURPOSE. A request perfectly valid on Monday
 * is refused on Wednesday if the student ran out of credit or the teacher booked
 * that hour elsewhere (FR-021) — which is safe precisely because submitting held
 * nothing: the student loses a request, not a seat and not a payment.
 *
 * ⚠️ THE ORDER IS: EVERYTHING THAT CAN REFUSE, THEN THE SETTLE. Settling first
 * would leave an `accepted` row standing over an hour that was never scheduled —
 * the `DecideTransferRequest` reasoning, reached from the same direction.
 */
class DecidePrivateSessionRequest extends Action
{
    public function __construct(
        private readonly ScheduleClassSession $schedule,
        private readonly BookSeat $seats,
        private readonly CohortDirectory $cohorts,
    ) {}

    public function handle(
        PrivateSessionRequest $request,
        User $decider,
        bool $accept,
        ?string $reason = null,
    ): PrivateSessionRequest {
        if (! $request->isPending()) {
            throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
        }

        return $accept
            ? $this->accept($request, $decider)
            : $this->reject($request, $decider, $reason);
    }

    /**
     * A refusal carries the teacher's own words, and the demand for them is
     * raised before anything is written.
     *
     * A silent refusal is indistinguishable from a request still waiting, so it
     * is asked for again — which is a queue the teacher then clears twice.
     */
    private function reject(PrivateSessionRequest $request, User $decider, ?string $reason): PrivateSessionRequest
    {
        if (trim((string) $reason) === '') {
            throw new DomainException('سبب الرفض مطلوب — الطالب يقرؤه.');
        }

        if (! PendingPrivateRequest::settle($request, PrivateSessionRequest::REJECTED, $decider, $reason)) {
            throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
        }

        $request->refresh();

        PrivateSessionDecided::dispatch($request, false);

        return $request;
    }

    private function accept(PrivateSessionRequest $request, User $decider): PrivateSessionRequest
    {
        $course = Course::query()->withoutWorkspaceScope()->find($request->course_id);
        $student = $request->student;

        if ($course === null || $student === null) {
            // Both are foreign keys that always resolve in practice; a null here
            // means the row is corrupt, and scheduling an hour for "nobody"
            // writes a session no one can ever be in.
            throw new DomainException('بيانات الطلب لم تعد مكتملة.');
        }

        DB::transaction(function () use ($request, $course, $student, $decider): void {
            /*
            | The student's own one-seat group, created once and reused for every
            | private hour afterwards (FR-019ج · FR-019د).
            |
            | ⚠️ ASKED THROUGH THE CONTRACT, NEVER BY REACHING FOR `Cohort`.
            | `CohortSessionVisibility` says in as many words that this module
            | never imports that model, and one module borrowing another's once
            | is how the boundary stops being one. The idempotence is a unique
            | index inside Learning, not a read here followed by a write.
            */
            $cohortId = $this->cohorts->ensureIndividualCohort(
                (int) $course->getKey(),
                (int) $course->workspace_id,
                $student,
                $decider,
            );

            $session = $this->schedule->handle(new ScheduleSessionData(
                teacherProfileId: (int) $request->teacher_profile_id,
                title: 'حصة خاصة — '.$course->title,
                type: ClassSessionType::Individual,
                startsAt: CarbonImmutable::instance($request->starts_at)->utc(),
                durationMinutes: $request->duration_minutes,
                // The type declares it and the seat count agrees: one chair, so
                // nobody else can ever be offered this hour.
                seatsTotal: 1,
                courseId: (int) $course->getKey(),
                cohortId: $cohortId,
            ), $decider);

            /*
            | ⚠️ `claimGrantedSeat`, NOT `handle`. The teacher has decided; the
            | conditions still asked are the ones they cannot waive — enrolment,
            | a freeze, and the balance (FR-021). The homework gate is not one of
            | them: a private hour is what a student asks for BECAUSE they are
            | behind, and refusing it here would refuse exactly the person the
            | feature exists for, at the moment their teacher said yes.
            */
            $this->seats->claimGrantedSeat($session, $student);

            $settled = PendingPrivateRequest::settle(
                $request,
                PrivateSessionRequest::ACCEPTED,
                $decider,
                null,
                (int) $session->getKey(),
            );

            /*
            | ⚠️ THROWN FROM INSIDE, NEVER RETURNED AS `false` AND CHECKED AFTER.
            | Another decider settled this request between our read at the top
            | and this write — and the session, the group and the seat above are
            | already written. Only raising here rolls them back; reporting the
            | loss to a caller outside the closure would leave a lesson on the
            | teacher's calendar behind a request somebody else refused, with a
            | seat taken out of the student's balance for it.
            */
            if (! $settled) {
                throw new DomainException('تم البتّ في هذا الطلب بالفعل.');
            }
        });

        $request->refresh();

        PrivateSessionDecided::dispatch($request, true);

        return $request;
    }
}
