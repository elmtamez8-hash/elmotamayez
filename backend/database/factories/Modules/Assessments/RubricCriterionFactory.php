<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\RubricCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RubricCriterion>
 */
class RubricCriterionFactory extends Factory
{
    protected $model = RubricCriterion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'question_id' => Question::factory(),
            'label' => 'المحتوى',
            'max_points' => 2.5,
            'order' => 0,
        ];
    }
}
