<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\StudyRoomParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyRoomParticipant>
 */
class StudyRoomParticipantFactory extends Factory
{
    protected $model = StudyRoomParticipant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'study_room_id' => 1,
            'user_id' => 1,
            'attempt_id' => 1,
            'score' => 0,
            'answered_count' => 0,
            'joined_at' => now(),
        ];
    }
}
