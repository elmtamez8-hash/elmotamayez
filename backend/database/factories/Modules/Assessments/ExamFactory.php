<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exam>
 */
class ExamFactory extends Factory
{
    protected $model = Exam::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'uuid' => Str::uuid(),
            'course_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'duration_minutes' => fake()->numberBetween(15, 90),
            'passing_score' => fake()->numberBetween(50, 80),
            'max_attempts' => 3,
            'shuffle_questions' => false,
            'shuffle_answers' => false,
            'status' => 'draft',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'published',
        ]);
    }
}
