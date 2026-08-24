<?php

declare(strict_types=1);

namespace App\Modules\Community\Data;

use App\Shared\Data\DataTransferObject;

/**
 * One periodic assessment as the teacher's screen sends it.
 *
 * ⚠️ THE STUDENT TRAVELS AS A UUID AND IS RESOLVED INSIDE THE ACTION, after the
 * enrolment check. `exists:users,uuid` answers a different question — a bare uuid
 * is an identity probe under NFR-001أ, and a response that comes back carrying a
 * name has already told a teacher about somebody who is not their student.
 */
final class PeriodicReviewData extends DataTransferObject
{
    public function __construct(
        public readonly string $studentUuid,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly int $commitment,
        public readonly int $participation,
        public readonly int $homework,
        public readonly int $improvement,
        public readonly ?string $note = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $note = $data['note'] ?? null;

        return new self(
            studentUuid: (string) ($data['student_uuid'] ?? ''),
            periodStart: (string) ($data['period_start'] ?? ''),
            periodEnd: (string) ($data['period_end'] ?? ''),
            commitment: (int) ($data['commitment'] ?? 0),
            participation: (int) ($data['participation'] ?? 0),
            homework: (int) ($data['homework'] ?? 0),
            improvement: (int) ($data['improvement'] ?? 0),
            note: is_string($note) && trim($note) !== '' ? trim($note) : null,
        );
    }
}
