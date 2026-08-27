<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Http\Resources;

use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in a student's mistake notebook: what they answered, and what was
 * right.
 *
 * ⚠️ THE ANSWER KEY IS DELIBERATE HERE, AND THAT IS THE DIFFERENCE FROM THE
 * ATTEMPT SCREEN. This is read after the paper is marked — showing the mistake
 * without the correction is a list of failures rather than a way to study.
 *
 * ⚠️ AND A QUESTION WITH NO EXPLANATION STILL RENDERS. `explanation` is optional
 * on the bank (FR-002 makes the four TAGS mandatory, not the prose), so a
 * notebook that required it would go blank on exactly the questions a teacher
 * imported in bulk.
 *
 * @mixin Answer
 */
class MistakeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $question = $this->question;
        $selected = is_array($this->selected_option_ids) ? array_map('intval', $this->selected_option_ids) : [];

        return [
            'uuid' => $this->uuid,
            'question' => $question === null ? null : [
                'uuid' => $question->uuid,
                'type' => $question->type,
                'content' => $question->content,
                'explanation' => $question->explanation,
                'concept' => $question->relationLoaded('concept')
                    ? ['uuid' => $question->concept->uuid, 'name' => $question->concept->name]
                    : null,
                'lesson' => $question->relationLoaded('lesson') && $question->lesson !== null
                    ? ['uuid' => $question->lesson->uuid, 'title' => $question->lesson->title]
                    : null,
            ],
            // What they put down. An essay carries text; everything else carries
            // the options they picked, rendered as words rather than as ids the
            // reader would have to look up.
            'your_answer' => $this->answer_text ?? $this->optionContents(
                fn (QuestionOption $option): bool => in_array((int) $option->getKey(), $selected, true),
            ),
            'correct_answer' => $this->optionContents(
                fn (QuestionOption $option): bool => (bool) $option->is_correct,
            ),
            // Derived by MistakeNotebook and set on the model there — a later
            // correct answer from the same student on the same question. Not a
            // column, deliberately: a stored status drifts at the first regrade.
            /*
            | ⚠️ THE TEACHER IS ON THE ROW, AND IT IS WHAT KEEPS FR-016أ TRUE.
            |
            | The notebook spans every teacher the reader studies with now — it
            | had to, or it stayed a `422` for every real student — and the
            | requirement's own words are that they must not be shown half their
            | mistakes called all of them, nor one teacher's question inside
            | another's context. A list that spans teachers and says whose each
            | row is satisfies both; one that spans them silently satisfies
            | neither. Stamped in bulk by `MistakeNotebook`, never a relation
            | read per row.
            */
            'teacher' => $this->getAttribute('teacher_uuid') === null ? null : [
                'uuid' => (string) $this->getAttribute('teacher_uuid'),
                'name' => (string) $this->getAttribute('teacher_name'),
            ],
            'is_resolved' => (bool) $this->getAttribute('is_resolved'),
            'times_wrong' => (int) $this->getAttribute('times_wrong'),
            'answered_at' => $this->created_at,
        ];
    }

    /**
     * @param  callable(QuestionOption): bool  $filter
     * @return list<string>
     */
    private function optionContents(callable $filter): array
    {
        $question = $this->question;

        if ($question === null || ! $question->relationLoaded('options')) {
            return [];
        }

        $contents = [];

        foreach ($question->options as $option) {
            if ($filter($option)) {
                $contents[] = $option->content;
            }
        }

        return $contents;
    }
}
