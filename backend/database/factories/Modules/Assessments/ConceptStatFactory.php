<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\ConceptStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConceptStat>
 */
class ConceptStatFactory extends Factory
{
    protected $model = ConceptStat::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'concept_id' => Concept::factory(),
            // The default is the row every screen reads. See ConceptStat::OVERALL.
            'lesson_id' => ConceptStat::OVERALL,
            'attempts_count' => 10,
            'wrong_count' => 4,
            'wrong_pct' => 40.0,
            'computed_at' => now(),
        ];
    }
}
