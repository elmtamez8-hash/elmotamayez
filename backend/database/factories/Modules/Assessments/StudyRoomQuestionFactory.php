<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\StudyRoomQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyRoomQuestion>
 */
class StudyRoomQuestionFactory extends Factory
{
    protected $model = StudyRoomQuestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'study_room_id' => 1,
            'question_id' => 1,
            'order' => 1,
            'points' => 1,
            // The shape `QuestionSnapshot::of()` writes. A fixture with a
            // different shape marks every answer wrong and says nothing.
            'snapshot' => [
                'type' => 'mcq',
                'difficulty' => 'easy',
                'content' => 'سؤال',
                'explanation' => null,
                'options' => [],
                'correct_option_ids' => [],
            ],
        ];
    }
}
