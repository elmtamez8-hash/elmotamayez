<?php

declare(strict_types=1);

namespace App\Modules\Community\Data;

use App\Shared\Data\DataTransferObject;

/**
 * Which private conversation to open, named the way both sides name it.
 *
 * The workspace is always present — a private conversation is with ONE teacher's
 * side — and the student is optional, defaulting to the caller. That covers both
 * directions with one request shape: a student opening theirs, and a teacher or
 * assistant opening the one for a named student.
 */
final class StartConversationData extends DataTransferObject
{
    public function __construct(
        public readonly string $workspaceUuid,
        public readonly ?string $studentUuid = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $student = $data['student'] ?? null;

        return new self(
            workspaceUuid: (string) ($data['workspace'] ?? ''),
            studentUuid: is_string($student) && $student !== '' ? $student : null,
        );
    }
}
