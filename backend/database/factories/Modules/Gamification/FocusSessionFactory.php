<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Gamification;

use App\Models\User;
use App\Modules\Gamification\Enums\FocusSessionStatus;
use App\Modules\Gamification\Models\FocusSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FocusSession> */
class FocusSessionFactory extends Factory
{
    protected $model = FocusSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'planned_minutes' => 25,
            'started_at' => now(),
            'ended_at' => null,
            'status' => FocusSessionStatus::Running,
        ];
    }
}
