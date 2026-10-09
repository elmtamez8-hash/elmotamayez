<?php

declare(strict_types=1);

namespace App\Modules\Courses\DTOs;

use App\Shared\Data\DataTransferObject;

/**
 * Marking or clearing a course's «حصة تجريبية» (spec 040).
 *
 * `lesson` null means clear — and then `replacing` names the lesson the reader
 * saw as the trial, so a stale tab cannot wipe a newer pick (FR-007).
 */
final class SetTrialLessonData extends DataTransferObject
{
    public function __construct(
        public readonly ?string $lesson,
        public readonly ?string $replacing = null,
    ) {}

    /** @param array{lesson?: string|null, replacing?: string|null} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['lesson'] ?? null, $data['replacing'] ?? null);
    }
}
