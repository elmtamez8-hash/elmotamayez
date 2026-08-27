<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Learning;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cohort>
 */
class CohortFactory extends Factory
{
    protected $model = Cohort::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'uuid' => Str::uuid(),
            'course_id' => Course::factory(),
            'name' => 'مجموعة '.fake()->unique()->numberBetween(1, 100000),
            'description' => null,
            // No ceiling by default: a fixture that has to prove something about
            // capacity says so, and every other fixture stays out of the way of a
            // «cohort_full» refusal it never meant to provoke.
            'capacity' => null,
            'members_count' => 0,
            'status' => Cohort::OPEN,
            'archived_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes): array => [
            'capacity' => 1,
            'members_count' => 1,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => Cohort::CLOSED]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Cohort::ARCHIVED,
            'archived_at' => now(),
        ]);
    }
}
