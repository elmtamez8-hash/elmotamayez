<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'fingerprint_hash' => hash('sha256', (string) Str::uuid()),
            'label' => 'Chrome على ويندوز',
            'last_seen_at' => now(),
        ];
    }
}
