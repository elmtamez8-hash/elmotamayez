<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Notifications;

use App\Models\User;
use App\Modules\Notifications\Models\PushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$this->faker->unique()->uuid();

        return [
            'uuid' => Str::uuid(),
            'user_id' => User::factory(),
            'endpoint' => $endpoint,
            // Derived, never invented: a fixture whose hash disagrees with its
            // endpoint would pass a uniqueness test that production fails.
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'p256dh' => Str::random(87),
            'auth' => Str::random(22),
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14)',
            'last_used_at' => null,
        ];
    }

    /** The same device, so a second account on it collides on `endpoint_hash`. */
    public function at(string $endpoint): static
    {
        return $this->state(fn (array $attributes): array => [
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
        ]);
    }
}
