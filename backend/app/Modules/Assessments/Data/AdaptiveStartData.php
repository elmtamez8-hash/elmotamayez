<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Data;

use App\Shared\Data\DataTransferObject;

/**
 * What the student asked to practise (spec 012 · FR-001).
 *
 * ⚠️ BOTH ARE UUIDS AND BOTH ARE RESOLVED INSIDE THE ACTION, never by
 * route-model binding: `WorkspaceScope` adds no condition for a reader whose
 * context is null, and that is every student — so an implicit binding would
 * resolve any teacher's concept. The `RedeemReward` precedent.
 */
final class AdaptiveStartData extends DataTransferObject
{
    public function __construct(
        public readonly string $conceptUuid,
        public readonly string $teacherUuid,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            conceptUuid: (string) ($data['concept'] ?? ''),
            teacherUuid: (string) ($data['teacher'] ?? ''),
        );
    }
}
