<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Notifications;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'notification_id' => Notification::factory(),
            'channel' => NotificationChannel::InApp->value,
            'template_id' => null,
            'status' => DeliveryStatus::Pending->value,
            'attempts' => 0,
            'failure_reason' => null,
            'deferred_until' => null,
            'last_attempted_at' => null,
            'delivered_at' => null,
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliveryStatus::Delivered->value,
            'attempts' => 1,
            'delivered_at' => now(),
            'last_attempted_at' => now(),
        ]);
    }

    public function failed(string $reason = 'تعذّر التسليم'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliveryStatus::Failed->value,
            'attempts' => 5,
            'failure_reason' => $reason,
            'last_attempted_at' => now(),
        ]);
    }
}
