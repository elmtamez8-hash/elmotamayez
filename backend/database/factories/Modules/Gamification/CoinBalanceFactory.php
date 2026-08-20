<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CoinBalance> */
class CoinBalanceFactory extends Factory
{
    protected $model = CoinBalance::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'coins' => 0,
        ];
    }
}
