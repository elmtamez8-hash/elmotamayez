<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Data;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Shared\Data\DataTransferObject;
use Carbon\CarbonImmutable;

/** One session to create. Times arrive as UTC instants, never as wall clock. */
final class ScheduleSessionData extends DataTransferObject
{
    public function __construct(
        public readonly int $teacherProfileId,
        public readonly string $title,
        public readonly ClassSessionType $type,
        public readonly CarbonImmutable $startsAt,
        public readonly int $durationMinutes,
        public readonly int $seatsTotal,
        public readonly ?int $courseId = null,
        public readonly ?int $subjectId = null,
        /*
        | The group this session belongs to (021 · FR-019أ). Nullable because
        | every session predating cohorts carries none, and a private session
        | names the student's own one-seat group so «فردي» is not an exception
        | in every query and every screen.
        */
        public readonly ?int $cohortId = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            teacherProfileId: (int) $data['teacher_profile_id'],
            title: (string) $data['title'],
            type: ClassSessionType::from((string) $data['type']),
            startsAt: CarbonImmutable::parse((string) $data['starts_at'])->utc(),
            durationMinutes: (int) $data['duration_minutes'],
            seatsTotal: (int) $data['seats_total'],
            courseId: isset($data['course_id']) ? (int) $data['course_id'] : null,
            subjectId: isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            cohortId: isset($data['cohort_id']) ? (int) $data['cohort_id'] : null,
        );
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }
}
