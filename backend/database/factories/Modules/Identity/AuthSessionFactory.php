<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuthSession> */
class AuthSessionFactory extends Factory
{
    protected $model = AuthSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => Device::factory(),
            'status' => AuthSession::STATUS_ACTIVE,
            'last_active_at' => now(),
        ];
    }

    public function ended(): self
    {
        return $this->state(fn (): array => [
            'status' => AuthSession::STATUS_ENDED,
            'ended_at' => now(),
        ]);
    }
}
