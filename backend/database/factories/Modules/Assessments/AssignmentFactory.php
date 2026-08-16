<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'course_id' => null,
            'lesson_id' => null,
            'class_session_id' => null,
            'title' => 'واجب '.fake()->word(),
            'description' => null,
            'points' => 10,
            'due_at' => now()->addDays(3),
            'submission_type' => Assignment::TYPE_TEXT,
            'late_policy' => Assignment::LATE_ACCEPT,
            'late_penalty_pct_per_day' => 0,
            'late_penalty_cap_pct' => 100,
            // ⚠️ DRAFT BY DEFAULT, and deliberately so: FR-042 turns on an
            // unpublished assignment blocking nothing, and a factory that
            // published by default would make that requirement untestable by
            // accident in every fixture that forgot to say otherwise.
            'status' => Assignment::STATUS_DRAFT,
            'published_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function published(): self
    {
        return $this->state(fn (): array => [
            'status' => Assignment::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /** A percentage a day, with the cap FR-046أ demands. */
    public function penalised(float $perDay = 20, float $cap = 60): self
    {
        return $this->state(fn (): array => [
            'late_policy' => Assignment::LATE_PENALTY,
            'late_penalty_pct_per_day' => $perDay,
            'late_penalty_cap_pct' => $cap,
        ]);
    }
}
