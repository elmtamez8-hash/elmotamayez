<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Data;

use App\Shared\Data\DataTransferObject;

/**
 * One answer inside a running session.
 *
 * ⚠️ THE QUESTION IS ADDRESSED BY `question_id`, NOT BY `order`. The uniqueness
 * that stops a double answer is `unique(attempt_id, question_id)` and nothing
 * else; `order` is assigned by reading `max(order) + 1`, so two rows can carry
 * the same number and `{"order": 3}` would mark the student's answer against a
 * different question — with the unique index not seeing it, because the two
 * questions differ. The existing exam route already addresses by `question_id`.
 */
final class AdaptiveAnswerData extends DataTransferObject
{
    public function __construct(
        public readonly int $questionId,
        /** @var list<int> */
        public readonly array $optionIds,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $options = is_array($data['option_ids'] ?? null) ? $data['option_ids'] : [];

        return new self(
            questionId: (int) ($data['question_id'] ?? 0),
            optionIds: array_values(array_map('intval', $options)),
        );
    }
}
