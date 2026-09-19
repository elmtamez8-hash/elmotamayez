<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Learning;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
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

    /**
     * A group a live price reaches (٠٣٦ · T102 · FR-003).
     *
     * ⛔ **ASKED FOR, NEVER THE DEFAULT — AND THAT IS THE WHOLE POINT OF THE
     * STATE.** Sixty-seven fixtures build a cohort, and making them covered by
     * default would hand every one of them a price they never meant to have: the
     * gate this spec exists to install would then be green in every test that
     * touches a group, including the ones written to prove it BITES. A guard
     * silenced by its own fixtures is the shape this tree has paid for before.
     *
     * ⚠️ WORKSPACE COVERAGE, WHICH IS THE WIDEST THING A FIXTURE CAN MEAN.
     * The narrow forms carry their own meaning — a plan naming ONE group stops
     * every other group of the course inheriting, and a plan naming the course
     * does not — so a fixture that only wants «this group is on sale» should not
     * accidentally be saying «and its siblings are not».
     *
     * ⚠️ AND `group()`: a room size is part of a price. An `individual` plan
     * lists no group at all, so the covered state would cover nothing.
     */
    public function covered(): static
    {
        return $this->afterCreating(function (Cohort $cohort): void {
            Plan::factory()->create([
                'workspace_id' => $cohort->workspace_id,
                'session_type' => ClassSessionType::Group,
                'coverage_type' => PlanCoverage::Workspace,
                'coverage_uuid' => null,
            ]);
        });
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
