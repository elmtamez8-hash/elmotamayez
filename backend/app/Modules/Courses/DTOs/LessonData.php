<?php

declare(strict_types=1);

namespace App\Modules\Courses\DTOs;

use App\Modules\Courses\Enums\LessonType;
use App\Shared\Data\DataTransferObject;

/**
 * What a content item carries, whatever its type.
 *
 * One shape for all ten types rather than one per family: the fields a type does
 * not use stay null, and `LessonTypeRegistry` decides which of them that type
 * must have before it may be published. Ten DTOs would put that same decision in
 * ten places and let them drift.
 *
 * `status` is absent — a new item is a draft and publishing is its own endpoint.
 * `order` is absent — position is written only by the reorder path.
 */
class LessonData extends DataTransferObject
{
    public function __construct(
        public readonly string $title,
        public readonly LessonType $type,
        public readonly ?string $content = null,
        public readonly ?string $externalUrl = null,
        public readonly ?string $referenceUuid = null,
        public readonly ?int $durationSeconds = null,
        public readonly bool $isPreview = false,
        public readonly bool $isFree = false,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) $data['title'],
            type: $data['type'] instanceof LessonType
                ? $data['type']
                : LessonType::from((string) $data['type']),
            content: isset($data['content']) ? (string) $data['content'] : null,
            externalUrl: isset($data['external_url']) ? (string) $data['external_url'] : null,
            referenceUuid: isset($data['reference_uuid']) ? (string) $data['reference_uuid'] : null,
            durationSeconds: isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            isPreview: (bool) ($data['is_preview'] ?? false),
            isFree: (bool) ($data['is_free'] ?? false),
        );
    }
}
