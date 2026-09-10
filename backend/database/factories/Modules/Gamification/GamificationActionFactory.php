<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Modules\Gamification\Models\GamificationAction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GamificationAction> */
class GamificationActionFactory extends Factory
{
    protected $model = GamificationAction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'act_'.Str::lower(Str::random(10)),
            'name' => 'فعل تجريبي',
            'xp' => 10,
            'coins' => 5,
            'daily_cap' => null,
            'is_active' => true,
        ];
    }

    public function capped(int $cap): self
    {
        return $this->state(fn (): array => ['daily_cap' => $cap]);
    }

    /** A penalty. Signed columns are what let this be a row rather than a branch. */
    public function penalty(int $xp = -20): self
    {
        return $this->state(fn (): array => ['xp' => $xp, 'coins' => 0]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
