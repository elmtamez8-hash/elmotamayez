<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Payments;

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => 'اشتراك شهري',
            'duration_days' => 30,
            'session_type' => ClassSessionType::Individual,
            'coverage_type' => PlanCoverage::Workspace,
            'coverage_uuid' => null,
            // ⚠️ PRICED BY DEFAULT, and the unpriced state has to be asked for.
            // The opposite default would make «a plan nobody can buy» the shape
            // every test silently uses, so the sellable path — the one the whole
            // phase is about — would be exercised only where somebody remembered
            // to price it.
            'price_minor' => 30_000,
            'currency' => 'QAR',
            'is_active' => true,
        ];
    }

    /** A plan the teacher created and no officer has priced yet. */
    public function unpriced(): self
    {
        return $this->state(fn (): array => ['price_minor' => null]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function forCourse(string $courseUuid): self
    {
        return $this->state(fn (): array => [
            'coverage_type' => PlanCoverage::Course,
            'coverage_uuid' => $courseUuid,
        ]);
    }

    public function group(): self
    {
        return $this->state(fn (): array => ['session_type' => ClassSessionType::Group]);
    }
}
