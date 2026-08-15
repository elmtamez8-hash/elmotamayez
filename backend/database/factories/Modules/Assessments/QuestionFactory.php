<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $content = fake()->sentence().'?';

        return [
            'workspace_id' => 1,
            // A question belongs to the BANK, not to an exam. `exam_id` is gone
            // from this definition on purpose — inclusion is `exam_items`, and a
            // factory that keeps setting the old column would keep every test
            // written against a model the product no longer has.
            'concept_id' => Concept::factory(),
            'lesson_id' => null,
            'type' => 'mcq',
            'difficulty' => fake()->randomElement(['easy', 'medium', 'hard']),
            'bloom_level' => 'unclassified',
            'content' => $content,
            'content_hash' => Question::hashOf($content),
            'points' => 1,
            'explanation' => fake()->optional()->paragraph(),
            'is_active' => true,
        ];
    }

    /**
     * Put this question in an exam, at the end.
     *
     * The replacement for `['exam_id' => $exam->id]`, and it says out loud what
     * that used to hide: including a question in an exam is a row of its own, so
     * the same question can go into a second exam without being copied.
     */
    public function forExam(Exam $exam, int $order = 0, ?int $pointsOverride = null): static
    {
        return $this->state(fn () => ['workspace_id' => $exam->workspace_id])
            ->afterCreating(function (Question $question) use ($exam, $order, $pointsOverride): void {
                ExamItem::create([
                    'workspace_id' => $exam->workspace_id,
                    'exam_id' => $exam->getKey(),
                    'question_id' => $question->getKey(),
                    'order' => $order !== 0
                        ? $order
                        : (int) ExamItem::query()->where('exam_id', $exam->getKey())->max('order') + 1,
                    'points_override' => $pointsOverride,
                ]);
            });
    }

    /** An essay: no correct set, graded by a person (FR-034). */
    public function essay(): static
    {
        return $this->state(fn () => ['type' => 'essay']);
    }
}
