<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Modules\Gamification\Enums\RewardType;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reward> */
class RewardFactory extends Factory
{
    protected $model = Reward::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => 'مكافأة تجريبية',
            'price_coins' => 100,
            'stock' => 10,
            // Not money-valued by default, so a fixture needs no cap to be legal.
            'type' => RewardType::StreakShield,
            'monthly_cap' => null,
            'is_active' => true,
        ];
    }

    public function moneyValued(int $monthlyCap = 5): self
    {
        return $this->state(fn (): array => [
            'type' => RewardType::Discount,
            'monthly_cap' => $monthlyCap,
        ]);
    }
}
