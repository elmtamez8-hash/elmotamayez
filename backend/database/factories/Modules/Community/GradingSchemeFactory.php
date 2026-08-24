<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Community;

use App\Modules\Community\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GradingScheme> */
class GradingSchemeFactory extends Factory
{
    protected $model = GradingScheme::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // No `workspace_id`: `BelongsToWorkspace` fills it from the current
        // context. See `PeriodicReviewFactory`.
        return [
            'course_id' => GradingScheme::ALL_COURSES,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'weights' => GradingScheme::EQUAL_WEIGHTS,
        ];
    }
}
