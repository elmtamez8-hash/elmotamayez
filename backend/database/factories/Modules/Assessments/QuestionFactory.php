<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'exam_id' => Exam::factory(),
            'type' => 'mcq',
            'difficulty' => fake()->randomElement(['easy', 'medium', 'hard']),
            'content' => fake()->sentence().'?',
            'points' => 1,
            'explanation' => fake()->optional()->paragraph(),
        ];
    }
}
