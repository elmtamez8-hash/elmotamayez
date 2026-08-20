<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Models\StudentProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentProgress> */
class StudentProgressFactory extends Factory
{
    protected $model = StudentProgress::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'xp' => 0,
            'level' => 1,
            'current_streak' => 0,
            'best_streak' => 0,
            'last_active_day' => null,
            'streak_evaluated_day' => null,
            'shield_count' => 0,
            'notified_level' => 1,
        ];
    }
}
