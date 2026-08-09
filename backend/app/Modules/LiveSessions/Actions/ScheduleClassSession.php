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
    public function handle(ScheduleSessionData $data, User $actor): ClassSession
    {
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

        // Fired at the cancellation deadline, so the billable seat count is
        // settled at the moment it stops being able to change (FR-059).
        FreezeBillableSeatsJob::dispatch((int) $session->getKey())
            ->delay($session->cancellationDeadline());

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
            throw new DomainException('الكورس غير موجود في مساحة العمل هذه.');
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
}
