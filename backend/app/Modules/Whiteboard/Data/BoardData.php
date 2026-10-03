<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Data;

use App\Modules\Whiteboard\Enums\BoardBackground;
use App\Shared\Data\DataTransferObject;

/**
 * A board's metadata as the teacher sends it. On an update every field is optional,
 * and `courseGiven`/`lessonGiven` tell «leave it» apart from «clear it» (null).
 */
final class BoardData extends DataTransferObject
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?BoardBackground $background = null,
        public readonly ?string $courseUuid = null,
        public readonly ?string $lessonUuid = null,
        public readonly bool $courseGiven = false,
        public readonly bool $lessonGiven = false,
        public readonly ?string $sessionUuid = null,
        public readonly bool $sessionGiven = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: isset($data['title']) ? trim((string) $data['title']) : null,
            background: isset($data['background']) ? BoardBackground::from((string) $data['background']) : null,
            courseUuid: isset($data['course']) ? (string) $data['course'] : null,
            lessonUuid: isset($data['lesson']) ? (string) $data['lesson'] : null,
            courseGiven: array_key_exists('course', $data),
            lessonGiven: array_key_exists('lesson', $data),
            sessionUuid: isset($data['class_session']) ? (string) $data['class_session'] : null,
            sessionGiven: array_key_exists('class_session', $data),
        );
    }
}
