<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Assessments\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamItem>
 */
class ExamItemFactory extends Factory
{
    protected $model = ExamItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'exam_id' => Exam::factory(),
            'question_id' => Question::factory(),
            'order' => 0,
            'points_override' => null,
        ];
    }
}
