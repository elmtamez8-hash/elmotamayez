<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Models\User;
use App\Modules\Assessments\Models\GradingRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingRecord>
 */
class GradingRecordFactory extends Factory
{
    protected $model = GradingRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            // No Answer::factory() default, on the precedent of AttemptItem:
            // Answer has no factory, and a grading record with no answer behind
            // it is a mark on nothing. The caller supplies it.
            'answer_id' => null,
            'rubric_criterion_id' => null,
            'points' => 1.0,
            'comment' => null,
            'graded_by' => User::factory(),
            'revision_of' => null,
            'revision_reason' => null,
            'grading_version' => 0,
            'created_at' => now(),
        ];
    }
}
