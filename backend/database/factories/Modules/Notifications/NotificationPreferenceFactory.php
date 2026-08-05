<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Notifications;

use App\Models\User;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'user_id' => User::factory(),
            'type' => NotificationType::EnrollmentCreated->value,
            'channels' => [NotificationChannel::InApp->value],
            'digest_window_minutes' => null,
        ];
    }

    public function mutedFor(NotificationType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type->value,
            'channels' => [],
        ]);
    }
}
