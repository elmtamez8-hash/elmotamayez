<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Enums\RedemptionStatus;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Gamification\Models\Reward;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Redemption> */
class RedemptionFactory extends Factory
{
    protected $model = Redemption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'reward_id' => Reward::factory(),
            'coins_spent' => 100,
            'claimed_month_key' => now()->format('Y-m'),
            'status' => RedemptionStatus::Pending,
            'decided_by' => null,
            'decided_at' => null,
        ];
    }
}
