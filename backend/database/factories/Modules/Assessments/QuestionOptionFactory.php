<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'question_id' => Question::factory(),
            'content' => fake()->sentence(2),
            'is_correct' => false,
            'order' => fake()->numberBetween(1, 5),
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_correct' => true,
        ]);
    }
}
