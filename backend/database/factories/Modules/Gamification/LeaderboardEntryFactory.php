<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Models\LeaderboardEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaderboardEntry> */
class LeaderboardEntryFactory extends Factory
{
    protected $model = LeaderboardEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope_key' => 'platform',
            'period_key' => 'w:2026-W34',
            'user_id' => User::factory(),
            'points' => fake()->numberBetween(0, 500),
            'level_band' => 0,
            'rank' => 0,
            'run_stamp' => '',
        ];
    }
}
