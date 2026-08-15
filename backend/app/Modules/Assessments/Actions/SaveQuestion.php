<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Actions;

use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use DomainException;
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
            $workspaceId = app(WorkspaceContext::class)->id() ?? $exam->workspace_id;

            $question = $this->createInBank($workspaceId, $attributes, $options);

            // The question belongs to the bank; the exam merely includes it. This
            // is the one call that used to be implicit in `$exam->questions()`.
            $this->includeInExam($exam, $question);

            return $question->load('options');
        });
    }

    /**
     * Create a question that belongs to the bank and to no exam yet.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array{content: string, is_correct?: bool, order?: int}>  $options
     */
    public function createInBank(int $workspaceId, array $attributes, array $options = []): Question
    {
        return DB::transaction(function () use ($workspaceId, $attributes, $options): Question {
            $this->guardTags($attributes);

            $content = (string) ($attributes['content'] ?? '');

            $question = Question::create(array_merge($attributes, [
                'workspace_id' => $workspaceId,
                // Derived here and never accepted from a caller: a hash the client
                // supplies is a hash the client can make collide or miss.
                'content_hash' => Question::hashOf($content),
                'is_active' => $attributes['is_active'] ?? true,
            ]));

            $this->syncOptions($question, $options);

            return $question->load('options');
        });
    }

    /**
     * Add a bank question to an exam, at the end, once.
     */
    public function includeInExam(Exam $exam, Question $question, ?int $pointsOverride = null): ExamItem
    {
        /** @var ExamItem|null $existing */
        $existing = ExamItem::query()
            ->where('exam_id', $exam->getKey())
            ->where('question_id', $question->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ExamItem::create([
            'workspace_id' => $exam->workspace_id,
            'exam_id' => $exam->getKey(),
            'question_id' => $question->getKey(),
            'order' => (int) ExamItem::query()->where('exam_id', $exam->getKey())->max('order') + 1,
            'points_override' => $pointsOverride,
        ]);
    }

    /**
     * FR-002: all four tags, or the question is not saved.
     *
     * ⚠️ ENFORCED HERE AND NOT ONLY IN THE FORM REQUEST, because the Action is
     * the single door the API, the importer and the seeder all come through. A
     * rule that lives in validation is a rule the importer does not have.
     *
     * `lesson_id` is deliberately absent from this check: a question can belong
     * to a concept without belonging to any one lesson, and that is the shape of
     * the fourth tag rather than a hole in it.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws DomainException
     */
    private function guardTags(array $attributes): void
    {
        foreach (['concept_id', 'difficulty', 'bloom_level'] as $tag) {
            if (($attributes[$tag] ?? null) === null || $attributes[$tag] === '') {
                throw new DomainException("A bank question needs its {$tag}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes  question attributes (without `options`)
     * @param  array<int, array{content: string, is_correct?: bool, order?: int}>|null  $options  null leaves the existing options untouched
     */
    public function update(Question $question, array $attributes, ?array $options = null): Question
    {
        return DB::transaction(function () use ($question, $attributes, $options): Question {
            // The hash follows the text, always. Leaving it stale would make the
            // importer skip a row that no longer matches anything.
            if (array_key_exists('content', $attributes)) {
                $attributes['content_hash'] = Question::hashOf((string) $attributes['content']);
            }

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
