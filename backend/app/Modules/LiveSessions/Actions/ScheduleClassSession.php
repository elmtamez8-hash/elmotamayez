<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Actions;

use App\Models\User;
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
        $this->assertNoOverlap($data);
        $this->assertNotFrozen($data);

        $session = ClassSession::query()->create([
            'teacher_profile_id' => $data->teacherProfileId,
            'course_id' => $data->courseId,
            'subject_id' => $data->subjectId,
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
