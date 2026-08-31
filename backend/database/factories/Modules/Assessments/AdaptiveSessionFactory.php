<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Enums\AdaptiveStatus;
use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Models\AdaptiveSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdaptiveSession>
 */
class AdaptiveSessionFactory extends Factory
{
    protected $model = AdaptiveSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'student_user_id' => 1,
            'concept_id' => 1,
            'attempt_id' => 1,
            'current_difficulty' => Difficulty::Easy,
            'ceiling_difficulty' => Difficulty::Hard,
            'correct_streak' => 0,
            'served_count' => 0,
            'status' => AdaptiveStatus::Running,
            // ⚠️ NOT null by default: the whole point of the column is that a
            // running session holds a claim, and a fixture that leaves it empty
            // makes every "two starts collide" assertion pass vacuously.
            'running_key' => fn (array $attributes): string => AdaptiveSession::runningKeyFor(
                (int) $attributes['student_user_id'],
                (int) $attributes['concept_id'],
            ),
        ];
    }

    public function ended(): self
    {
        return $this->state(fn (): array => [
            'status' => AdaptiveStatus::Ended,
            'running_key' => null,
            'ended_at' => now(),
        ]);
    }
}
