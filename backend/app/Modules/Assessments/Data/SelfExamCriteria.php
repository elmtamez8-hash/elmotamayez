<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What the student asked for when they generated a paper (FR-021).
 *
 * ⚠️ `count` IS A REQUEST, NOT A PROMISE. The bank may not hold that many
 * questions the student is entitled to, and FR-023 says build with what is there
 * and SAY SO — a generator that silently returns four questions for a request of
 * ten teaches the reader that the number they typed does nothing.
 *
 * ⚠️ AND `durationMinutes` IS NOT ENFORCED BY THE SERVER, deliberately. A
 * practice run has no official standing: it spends no attempt, enters no grade
 * report and issues no certificate. The only person a timer protects here is the
 * student from themselves, so it is a clock the client runs — a column that no
 * job ever reads and no request ever checks would be a guarantee in name only.
 */
final class SelfExamCriteria extends DataTransferObject
{
    public function __construct(
        public readonly int $count,
        public readonly int $durationMinutes,
        public readonly ?string $conceptUuid = null,
        public readonly ?string $difficulty = null,
        /** One course out of the several a student may study with one teacher. */
        public readonly ?string $courseUuid = null,
        /** One subject, which may span several of this teacher's courses. */
        public readonly ?string $subjectUuid = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $concept = $data['concept_id'] ?? null;
        $difficulty = $data['difficulty'] ?? null;
        $course = $data['course'] ?? null;
        $subject = $data['subject'] ?? null;

        return new self(
            count: (int) ($data['count'] ?? 10),
            durationMinutes: (int) ($data['duration_minutes'] ?? 15),
            conceptUuid: is_string($concept) && $concept !== '' ? $concept : null,
            difficulty: is_string($difficulty) && $difficulty !== '' ? $difficulty : null,
            courseUuid: is_string($course) && $course !== '' ? $course : null,
            subjectUuid: is_string($subject) && $subject !== '' ? $subject : null,
        );
    }
}
