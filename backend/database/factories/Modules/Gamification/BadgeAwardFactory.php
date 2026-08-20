<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Models\BadgeAward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BadgeAward> */
class BadgeAwardFactory extends Factory
{
    protected $model = BadgeAward::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'badge_key' => 'badge_'.Str::lower(Str::random(10)),
            'awarded_at' => now(),
        ];
    }
}
