<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Models\AwardDailyCounter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AwardDailyCounter> */
class AwardDailyCounterFactory extends Factory
{
    protected $model = AwardDailyCounter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_user_id' => User::factory(),
            'day_key' => now()->format('Y-m-d'),
            'action_key' => 'act_'.Str::lower(Str::random(10)),
            'count' => 0,
        ];
    }
}
