<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Modules\Gamification\Models\Level;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Level> */
class LevelFactory extends Factory
{
    protected $model = Level::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $level = fake()->unique()->numberBetween(1, 60);

        return [
            'level' => $level,
            'name' => 'مستوى '.$level,
            'xp_threshold' => $level * 100,
        ];
    }
}
