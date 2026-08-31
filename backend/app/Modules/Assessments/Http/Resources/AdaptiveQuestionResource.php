<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\AttemptItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question as it is put to the student, and nothing more.
 *
 * ⚠️ THE SNAPSHOT HOLDS `correct_option_ids` AND `explanation`, AND NEITHER IS
 * COPIED OUT HERE. Serialising the snapshot — the obvious way to write this —
 * hands the mark scheme to the browser inside the response that asks the
 * question. Both are legitimate in the ANSWER response, one request later, and
 * `AssessmentFieldAllowlist::forbiddenDuringAttempt()` is what fails the build if
 * either ever appears in this one.
 *
 * ⚠️ AND THE OPTION IS `{id, content}` — the two fields
 * `AssessmentFieldAllowlist::sitOptionFields()` names. `QuestionOption` carries
 * `is_correct`, so a third field here is the answer key.
 *
 * @mixin AttemptItem
 */
class AdaptiveQuestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot;

        return [
            // Addressed by `question_id`, which is the column the uniqueness that
            // refuses a second answer is on. `order` is a display number and two
            // rows can share it.
            'question_id' => (int) $this->question_id,
            'order' => (int) $this->order,
            'content' => $snapshot['content'] ?? '',
            'points' => (int) $this->points,
            // Absent on a snapshot written before spec 012; the screen falls back
            // to the session's own level rather than rendering nothing.
            'difficulty' => $snapshot['difficulty'] ?? null,
            'options' => array_map(
                static fn (array $option): array => [
                    'id' => (int) $option['id'],
                    'content' => (string) $option['content'],
                ],
                is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [],
            ),
        ];
    }
}
