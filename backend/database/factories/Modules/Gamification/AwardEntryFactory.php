<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Models\AwardEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AwardEntry> */
class AwardEntryFactory extends Factory
{
    protected $model = AwardEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_user_id' => User::factory(),
            'action_key' => 'act_'.Str::lower(Str::random(10)),
            'xp' => 10,
            'coins' => 5,
            'workspace_id' => null,
            'course_id' => null,
            'lesson_id' => null,
            'level_band' => 0,
            'source_type' => 'test',
            'source_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'reversal_of_id' => 0,
        ];
    }
}
