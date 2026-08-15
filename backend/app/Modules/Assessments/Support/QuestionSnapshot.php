<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Modules\Assessments\Models\Question;

/**
 * What the student was shown, frozen at the moment the paper was handed over.
 *
 * ⚠️ ONE BUILDER, BECAUSE TWO READERS DEPEND ON THE EXACT KEYS. `GradeAttempt`
 * marks by comparing against `correct_option_ids`, and the attempt screen renders
 * `options`. A second copy of this array written elsewhere does not fail loudly
 * when a key drifts — it marks every answer wrong, silently, on whichever path
 * grew the second copy.
 *
 * It is written once per attempt item and never updated: an edit landing
 * mid-attempt does not change the paper somebody is already holding (FR-004).
 */
class QuestionSnapshot
{
    /**
     * @return array{type: string, content: string, explanation: string|null, options: list<array{id: int, content: string, order: int}>, correct_option_ids: list<int>}
     */
    public static function of(Question $question): array
    {
        $options = [];
        $correct = [];

        foreach ($question->options as $option) {
            $options[] = [
                'id' => (int) $option->getKey(),
                'content' => $option->content,
                'order' => (int) $option->order,
            ];

            if ($option->is_correct) {
                $correct[] = (int) $option->getKey();
            }
        }

        return [
            'type' => $question->type,
            'content' => $question->content,
            'explanation' => $question->explanation,
            'options' => $options,
            'correct_option_ids' => $correct,
        ];
    }
}
