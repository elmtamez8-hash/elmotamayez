<?php

declare(strict_types=1);

namespace App\Modules\Courses\DTOs;

use App\Modules\Courses\Enums\ExamGate;
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
        /** Read only when the type is `exam`; ignored, not stored, elsewhere. */
        public readonly ?ExamGate $examGate = null,
        public readonly ?int $durationSeconds = null,
        /**
         * Nullable, and that is the fix rather than a nicety.
         *
         * As `bool $isPreview = false` an omitted key was indistinguishable from
         * an explicit `false`, and `ManageLessons::update` wrote both flags
         * unconditionally — so every partial update cleared them. Live in the
         * shipped UI: each checkbox PUTs only its own field, so ticking "متاح بلا
         * تسجيل" cleared "بلا مقابل داخل الكورس", and saving an article body
         * cleared both. `is_preview` is real access (FR-021), not a badge, so a
         * free preview lesson stopped being reachable by visitors on the next
         * body edit.
         */
        public readonly ?bool $isPreview = null,
        public readonly ?bool $isFree = null,
        /**
         * High value — worth withholding from a student who owes (FR-041).
         *
         * The teacher's own classification: a revision paper, a mark scheme, a
         * predicted-question bank. Spec 006 withholds these from a negative
         * balance while leaving the sessions themselves open, on the reasoning
         * that cutting off a student's lesson punishes their learning, while
         * handing them the answer key removes the last reason to settle up.
         *
         * Nullable for the same reason the two above are: an omitted key is not
         * an instruction to turn the flag off.
         */
        public readonly ?bool $isHighValue = null,
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
            examGate: match (true) {
                ! isset($data['exam_gate']) => null,
                $data['exam_gate'] instanceof ExamGate => $data['exam_gate'],
                default => ExamGate::from((string) $data['exam_gate']),
            },
            durationSeconds: isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null,
            // `array_key_exists`, not `??`: an explicit false is the teacher
            // turning the flag off, which is a different instruction from not
            // mentioning it.
            isPreview: array_key_exists('is_preview', $data) ? (bool) $data['is_preview'] : null,
            isFree: array_key_exists('is_free', $data) ? (bool) $data['is_free'] : null,
            isHighValue: array_key_exists('is_high_value', $data) ? (bool) $data['is_high_value'] : null,
        );
    }
}
