<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\AttemptItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question on a room's paper, as it stands for THIS participant.
 *
 * ⚠️ NO `correct_option_ids` AND NO `explanation` FOR A QUESTION NOT YET
 * ANSWERED. The frozen snapshot carries both — serialising it, which is the
 * obvious way to write this, hands the whole mark scheme to the browser inside
 * the payload that opens the room. The rule was written on the adaptive path and
 * `AssessmentFieldAllowlist::forbiddenDuringAttempt()` is what fails the build if
 * either appears too early.
 *
 * ⚠️ AND THEY ARE PRESENT ONCE IT IS ANSWERED, WHICH IS THE FEATURE RATHER THAN
 * AN EXCEPTION. That is what makes the resume a row read (FR-015): somebody who
 * dropped out at question six comes back and sees the five they got and how, with
 * nothing recovered from a browser.
 *
 * ⚠️ AND THE OPTION IS `{id, content}` — the two fields
 * `AssessmentFieldAllowlist::sitOptionFields()` names. A third one is the answer
 * key, because `QuestionOption` carries `is_correct`.
 *
 * @mixin AttemptItem
 */
class StudyRoomQuestionResource extends JsonResource
{
    public function __construct(AttemptItem $item, private readonly ?Answer $answer = null)
    {
        parent::__construct($item);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot;
        $answer = $this->answer;

        $payload = [
            // Addressed by `question_id`: `order` is a display number and the
            // uniqueness that refuses a second answer is on the other column.
            'question_id' => (int) $this->question_id,
            'order' => (int) $this->order,
            'content' => $snapshot['content'] ?? '',
            'points' => (int) $this->points,
            'difficulty' => $snapshot['difficulty'] ?? null,
            'options' => array_map(
                static fn (array $option): array => [
                    'id' => (int) $option['id'],
                    'content' => (string) $option['content'],
                ],
                is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [],
            ),
            'answered' => $answer !== null,
        ];

        if ($answer === null) {
            return $payload;
        }

        return $payload + [
            'selected_option_ids' => $answer->selected_option_ids ?? [],
            'is_correct' => (bool) $answer->is_correct,
            'correct_option_ids' => $this->correctOptionIds(),
            'explanation' => is_string($snapshot['explanation'] ?? null) ? $snapshot['explanation'] : null,
        ];
    }
}
