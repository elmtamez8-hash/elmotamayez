<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What a student asked for when they opened a study room (FR-013).
 *
 * ⚠️ BOTH UUIDS ARE RESOLVED INSIDE THE ACTION, never by route-model binding:
 * `WorkspaceScope` adds no condition for a reader whose context is null, and that
 * is every student. The `RedeemReward` precedent.
 *
 * `conceptUuid` and `difficulty` are nullable, which WIDENS FR-013 rather than
 * narrowing it: whoever names them gets the paper the spec describes, whoever
 * leaves them out gets a wider draw from their own pool.
 */
final class StudyRoomDraftData extends DataTransferObject
{
    public function __construct(
        public readonly string $teacherUuid,
        public readonly ?string $conceptUuid,
        public readonly ?string $difficulty,
        public readonly int $questionCount,
        public readonly int $maxParticipants,
        public readonly int $durationMinutes,
        public readonly int $startsInMinutes,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $concept = $data['concept'] ?? null;
        $difficulty = $data['difficulty'] ?? null;

        return new self(
            teacherUuid: (string) ($data['teacher'] ?? ''),
            conceptUuid: is_string($concept) && $concept !== '' ? $concept : null,
            difficulty: is_string($difficulty) && $difficulty !== '' ? $difficulty : null,
            questionCount: (int) ($data['question_count'] ?? 10),
            maxParticipants: (int) ($data['max_participants'] ?? 10),
            durationMinutes: (int) ($data['duration_minutes'] ?? 15),
            startsInMinutes: (int) ($data['starts_in_minutes'] ?? 0),
        );
    }
}
