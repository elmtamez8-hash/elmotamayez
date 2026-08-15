<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Data;

use App\Modules\Assessments\Actions\SaveQuestion;
use App\Modules\Assessments\Enums\BloomLevel;
use App\Shared\Data\DataTransferObject;

/**
 * One bank question as the API accepts it.
 *
 * Ids are internal and already resolved: the request validates a uuid, the
 * controller turns it into a row it has authorised, and only then does it build
 * this. A DTO that carried uuids would push a lookup into whatever consumed it,
 * and the importer — which has no uuids at all — would need a second shape.
 *
 * `contentHash` is absent on purpose. It is derived from the text inside
 * {@see SaveQuestion}, and a hash a caller can
 * supply is a hash a caller can make collide.
 */
final class SaveQuestionData extends DataTransferObject
{
    /** @param array<int, array{content: string, is_correct?: bool, order?: int}>|null $options */
    public function __construct(
        public readonly int $conceptId,
        public readonly string $type,
        public readonly string $difficulty,
        public readonly BloomLevel $bloomLevel,
        public readonly string $content,
        public readonly int $points,
        public readonly ?int $lessonId = null,
        public readonly ?string $explanation = null,
        public readonly bool $isActive = true,
        public readonly ?array $options = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // Rebuilt key by key rather than passed through: the payload arrives from
        // a request, and an option row carrying a `question_id` or a `workspace_id`
        // it chose itself would be mass-assigned straight into the table.
        $options = null;

        if (isset($data['options'])) {
            $options = [];

            foreach ((array) $data['options'] as $index => $option) {
                $option = (array) $option;

                $options[] = [
                    'content' => (string) ($option['content'] ?? ''),
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'order' => (int) ($option['order'] ?? $index + 1),
                ];
            }
        }

        return new self(
            conceptId: (int) $data['concept_id'],
            type: (string) $data['type'],
            difficulty: (string) $data['difficulty'],
            bloomLevel: BloomLevel::from((string) $data['bloom_level']),
            content: (string) $data['content'],
            points: (int) ($data['points'] ?? 1),
            lessonId: isset($data['lesson_id']) ? (int) $data['lesson_id'] : null,
            explanation: isset($data['explanation']) ? (string) $data['explanation'] : null,
            isActive: (bool) ($data['is_active'] ?? true),
            options: $options,
        );
    }

    /**
     * The column shape {@see SaveQuestion} writes.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'concept_id' => $this->conceptId,
            'lesson_id' => $this->lessonId,
            'type' => $this->type,
            'difficulty' => $this->difficulty,
            'bloom_level' => $this->bloomLevel->value,
            'content' => $this->content,
            'points' => $this->points,
            'explanation' => $this->explanation,
            'is_active' => $this->isActive,
        ];
    }
}
