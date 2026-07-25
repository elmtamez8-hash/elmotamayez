<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a question together with its options.
 *
 * Options are reconciled in place rather than deleted and recreated: option ids are
 * referenced by `exam_answers.selected_option_ids`, so replacing the rows would
 * orphan every already-graded attempt.
 */
class SaveQuestion extends Action
{
    /**
     * @param  array<string, mixed>  $attributes  question attributes (without `options`)
     * @param  array<int, array{content: string, is_correct?: bool, order?: int}>  $options
     */
    public function create(Exam $exam, array $attributes, array $options = []): Question
    {
        return DB::transaction(function () use ($exam, $attributes, $options): Question {
            $question = $exam->questions()->create(array_merge($attributes, [
                'workspace_id' => app(WorkspaceContext::class)->id() ?? $exam->workspace_id,
            ]));

            $this->syncOptions($question, $options);

            return $question->load('options');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  question attributes (without `options`)
     * @param  array<int, array{content: string, is_correct?: bool, order?: int}>|null  $options  null leaves the existing options untouched
     */
    public function update(Question $question, array $attributes, ?array $options = null): Question
    {
        return DB::transaction(function () use ($question, $attributes, $options): Question {
            $question->update($attributes);

            if ($options !== null) {
                $this->syncOptions($question, $options);
            }

            return $question->load('options');
        });
    }

    /**
     * Reconcile the question's options with the given payload, reusing existing rows
     * positionally so surviving options keep their ids.
     *
     * @param  array<int, array{content: string, is_correct?: bool, order?: int}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        $existing = $question->options()->orderBy('id')->get()->values();

        foreach (array_values($options) as $index => $option) {
            $attributes = array_merge($option, [
                'workspace_id' => $question->workspace_id,
                'order' => $option['order'] ?? $index + 1,
            ]);

            /** @var QuestionOption|null $current */
            $current = $existing->get($index);

            if ($current === null) {
                $question->options()->create($attributes);

                continue;
            }

            $current->update($attributes);
        }

        // Drop the tail the new payload no longer contains.
        $surplus = $existing->slice(count($options));

        if ($surplus->isNotEmpty()) {
            $question->options()->whereIn('id', $surplus->pluck('id'))->delete();
        }
    }
}
