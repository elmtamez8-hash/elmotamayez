<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\AttemptItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A marked practice paper, with the explanations (FR-024).
 *
 * ⚠️ THIS IS THE OTHER HALF OF "MARKED INSTANTLY", and without it the feature is
 * a percentage. A student who is told 60% and not which four they missed has
 * learned their score and nothing else — the explanation is the entire reason
 * the exam that produced it was worth sitting.
 *
 * ⚠️ AND IT READS THE SNAPSHOT, NOT THE LIVE QUESTION. A teacher who fixes a
 * typo in an answer between the sitting and the review would otherwise be shown
 * to have marked the student against text nobody saw (FR-004).
 *
 * ⚠️ IT SERVES A PRACTICE ATTEMPT ONLY. Handing the same payload back for a real
 * exam would publish the answer key of a paper other students have not sat yet —
 * the caller enforces that, and it is the first line of the controller.
 *
 * @mixin Attempt
 */
class PracticeResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $answers = $this->answers()->get()->keyBy(fn (Answer $answer): int => (int) $answer->question_id);
        $questions = [];

        foreach ($this->items()->orderBy('order')->get() as $item) {
            $questions[] = $this->review($item, $answers->get((int) $item->question_id));
        }

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'score' => (float) $this->score,
            'questions' => $questions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function review(AttemptItem $item, ?Answer $answer): array
    {
        $snapshot = $item->snapshot;
        $options = is_array($snapshot['options'] ?? null) ? $snapshot['options'] : [];
        $correctIds = array_map('intval', is_array($snapshot['correct_option_ids'] ?? null) ? $snapshot['correct_option_ids'] : []);
        $selected = is_array($answer?->selected_option_ids) ? array_map('intval', $answer->selected_option_ids) : [];

        $label = static function (array $ids) use ($options): array {
            $labels = [];

            foreach ($options as $option) {
                if (in_array((int) $option['id'], $ids, true)) {
                    $labels[] = (string) $option['content'];
                }
            }

            return $labels;
        };

        return [
            'id' => (int) $item->question_id,
            'content' => $snapshot['content'] ?? '',
            // Unanswered is not the same as wrong, and the screen says which.
            'was_answered' => $selected !== [],
            'is_correct' => (bool) $answer?->is_correct,
            'your_answer' => $label($selected),
            'correct_answer' => $label($correctIds),
            // Optional on the bank, so the review renders without it rather than
            // going blank on every question a teacher imported in bulk.
            'explanation' => $snapshot['explanation'] ?? null,
        ];
    }
}
