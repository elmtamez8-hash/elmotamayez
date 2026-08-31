<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Enums\Difficulty;
use App\Modules\Assessments\Models\ConceptMastery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConceptMastery>
 */
class ConceptMasteryFactory extends Factory
{
    protected $model = ConceptMastery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'student_user_id' => 1,
            'concept_id' => 1,
            'mastered_at' => now(),
            'threshold_correct' => 3,
            'threshold_difficulty' => Difficulty::Hard,
            'source_session_id' => null,
        ];
    }
}
