<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Assessments;

use App\Modules\Assessments\Models\StudyRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyRoom>
 */
class StudyRoomFactory extends Factory
{
    protected $model = StudyRoom::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'host_user_id' => 1,
            'concept_id' => null,
            'difficulty' => null,
            'question_count' => 5,
            'max_participants' => 10,
            'duration_minutes' => 15,
            // ⚠️ OPEN BY DEFAULT. A fixture that is born closed makes every
            // «joining works» assertion pass or fail for the wrong reason, and
            // closure here is a comparison against the clock rather than a flag
            // a test can see at a glance.
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMinutes(15),
        ];
    }

    /** A room whose hour has passed — closed by the clock, with no job involved. */
    public function closed(): self
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinutes(45),
        ]);
    }
}
