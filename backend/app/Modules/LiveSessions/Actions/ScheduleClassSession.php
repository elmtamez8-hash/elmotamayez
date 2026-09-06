<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Events\SessionScheduled;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Support\SchedulableTeachers;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Puts one session on the calendar.
 *
 * Both rules it enforces — no overlap, nothing inside a freeze — live here and
 * not in the FormRequest, because the generator, the seeders and the panel all
 * come through this door and a rule checked only in validation is a rule the
 * panel walks around (Constitution II).
 */
class ScheduleClassSession extends Action
{
    public function __construct(private readonly SchedulableTeachers $schedulable) {}

    public function handle(ScheduleSessionData $data, User $actor): ClassSession
    {
        /*
        | ⚠️ FIRST, AND IN THIS ACTION ALONE — `GenerateSessionsFromAvailability`
        | comes through this same door with the same actor, so a second guard
        | there would be a second answer to one question. The bulk path is covered
        | by construction.
        |
        | It stands ABOVE the other three checks deliberately: an overlap or a
        | freeze-period refusal names another teacher's calendar, and telling
        | somebody «that slot is taken» about a person they may not schedule for
        | answers a question they were not entitled to ask.
        */
        $this->assertMayScheduleForTheNamedTeacher($data, $actor);

        $this->assertTypeMatchesSeats($data);
        $course = $this->requireCourse($data);
        $this->assertNoOverlap($data);
        $this->assertNotFrozen($data);

        $session = ClassSession::query()->create([
            'teacher_profile_id' => $data->teacherProfileId,
            'course_id' => $data->courseId,
            // Copied FROM THE COURSE, and the caller's subject_id is only the
            // fallback. Both sides of the money — the purchase price in 006 and
            // the settlement rate in 014 — resolve from these two values, so
            // they have to come from one place. Copied rather than read through
            // the course, so a course reclassified next term does not reprice
            // sessions already taught.
            'subject_id' => $course->subject_id ?? $data->subjectId,
            'grade_level' => $course->grade_level,
            'title' => $data->title,
            'type' => $data->type,
            'status' => ClassSessionStatus::Scheduled,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt(),
            'duration_minutes' => $data->durationMinutes,
            'seats_total' => $data->seatsTotal,
            'seats_taken' => 0,
            'created_by' => $actor->getKey(),
        ]);

        if ($data->cohortId !== null) {
            /*
            | ⚠️ ASSIGNED DIRECTLY, BECAUSE `cohort_id` IS DELIBERATELY NOT
            | `$fillable`. Which group a session belongs to decides who is
            | offered it, so a PATCH body carrying the column would be a write to
            | access rights wearing the shape of a display field; the only other
            | writer is `AssignSessionsToCohort`'s bulk UPDATE. `forceFill`
            | writes it deliberately here without widening mass assignment for
            | everybody — the same spelling `ensureIndividualCohort` uses for
            | `status`.
            */
            $session->forceFill(['cohort_id' => $data->cohortId])->save();
        }

        // Fired at the cancellation deadline, so the billable seat count is
        // settled at the moment it stops being able to change (FR-059) — or at
        // the session's start when that deadline is already behind us, which is
        // 027 · FR-039ب. `billableSeatsFreezeAt()` carries the reason.
        FreezeBillableSeatsJob::dispatch((int) $session->getKey())
            ->delay($session->billableSeatsFreezeAt());

        SessionScheduled::dispatch($session);

        return $session;
    }

    private function assertTypeMatchesSeats(ScheduleSessionData $data): void
    {
        if (! $data->type->allowsSeats($data->seatsTotal)) {
            throw new DomainException('عدد المقاعد لا يناسب نوع الحصة.');
        }
    }

    /**
     * A schedulable session must belong to a course.
     *
     * `class_sessions.course_id` stays nullable in the schema — historic rows
     * predate Q-7 and inventing courses for them would be a lie in the data —
     * so the rule is enforced HERE, at the one door every scheduler comes
     * through. A session with no course is a session with no price, and it could
     * never consume a credit.
     */
    private function requireCourse(ScheduleSessionData $data): Course
    {
        if ($data->courseId === null) {
            throw new DomainException('الحصة يجب أن تكون ضمن كورس — سعر الحصة خاصية الكورس.');
        }

        $course = Course::query()->find($data->courseId);

        if ($course === null) {
            throw new DomainException('الكورس غير موجود عندك.');
        }

        return $course;
    }

    /**
     * FR-003. Half-open interval on purpose: a session ending at 15:00 and one
     * starting at 15:00 do not overlap, and treating them as a clash would block
     * back-to-back teaching, which is how a full day is actually taught.
     */
    private function assertNoOverlap(ScheduleSessionData $data): void
    {
        $clash = ClassSession::query()
            ->where('teacher_profile_id', $data->teacherProfileId)
            ->whereNotIn('status', [ClassSessionStatus::Cancelled, ClassSessionStatus::Suspended])
            ->where('starts_at', '<', $data->endsAt())
            ->where('ends_at', '>', $data->startsAt)
            ->exists();

        if ($clash) {
            throw new DomainException('لديك حصة أخرى في هذا الوقت.');
        }
    }

    private function assertNotFrozen(ScheduleSessionData $data): void
    {
        $frozen = FreezePeriod::query()
            ->covering(CarbonImmutable::instance($data->startsAt))
            ->exists();

        if ($frozen) {
            throw new DomainException('لا يمكن جدولة حصة داخل فترة تجميد.');
        }
    }

    /**
     * ⚠️ THE PROFILE IS RESOLVED HERE RATHER THAN TAKEN FROM THE REQUEST.
     *
     * `ScheduleSessionData` is a dumb carrier of integers that seeders, the panel
     * and forty Action tests build by hand — so the id is what arrives, and the
     * row it names is what has to be judged. `withoutGlobalScopes()` because the
     * judgement is about ownership, not visibility: a profile the scope would hide
     * must be REFUSED, never silently treated as absent.
     */
    private function assertMayScheduleForTheNamedTeacher(ScheduleSessionData $data, User $actor): void
    {
        $profile = TeacherProfile::query()
            ->withoutGlobalScopes()
            ->find($data->teacherProfileId);

        if (! $profile instanceof TeacherProfile) {
            throw new DomainException('لم يُعثر على ملفّ المدرّس.');
        }

        $this->schedulable->assert($actor, $profile);
    }
}
