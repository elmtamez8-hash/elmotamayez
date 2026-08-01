<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Data;

use App\Shared\Data\DataTransferObject;

/**
 * Step 3 — documents.
 *
 * An acknowledgement, not an upload. Real identity verification is a separate
 * feature with its own security review, and this DTO carrying a single boolean is
 * the point: there is nowhere here for a file to land (FR-071).
 */
class TeacherStepThreeData extends DataTransferObject
{
    public function __construct(
        public readonly bool $documentsAcknowledged,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            documentsAcknowledged: (bool) $data['documents_acknowledged'],
        );
    }
}
