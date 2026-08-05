<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Notifications;

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'recipient_user_id' => User::factory(),
            'workspace_id' => null,
            'type' => NotificationType::EnrollmentCreated->value,
            'subject_user_id' => null,
            'payload' => [],
            'title_ar' => 'عنوان الإشعار',
            'body_ar' => 'نصّ الإشعار.',
            'action_url' => null,
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => ['read_at' => now()]);
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type->value,
            'title_ar' => $type->label(),
        ]);
    }
}
