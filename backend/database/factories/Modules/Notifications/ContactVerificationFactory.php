<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Notifications;

use App\Models\User;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Support\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContactVerification>
 */
class ContactVerificationFactory extends Factory
{
    protected $model = ContactVerification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'user_id' => User::factory(),
            'channel' => NotificationChannel::WhatsApp->value,
            'contact_value' => '+9745'.$this->faker->numerify('#######'),
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'verified_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => ['verified_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }
}
