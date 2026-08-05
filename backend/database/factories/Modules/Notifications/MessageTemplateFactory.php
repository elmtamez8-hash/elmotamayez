<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Notifications;

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    protected $model = MessageTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $type = NotificationType::EnrollmentCreated;
        $channel = NotificationChannel::InApp;

        return [
            'uuid' => Str::uuid(),
            'key' => MessageTemplate::keyFor($type, $channel),
            'type' => $type->value,
            'channel' => $channel->value,
            'title_ar' => $type->label(),
            'body_ar' => 'مرحباً {{ name }}.',
            'variables' => ['name'],
            'provider_approval_status' => MessageTemplate::APPROVAL_NOT_REQUIRED,
            'is_active' => true,
        ];
    }

    /** Named forType() rather than for(): Factory::for() already means "belongs to a relation". */
    public function forType(NotificationType $type, NotificationChannel $channel): static
    {
        return $this->state(fn (array $attributes) => [
            'key' => MessageTemplate::keyFor($type, $channel),
            'type' => $type->value,
            'channel' => $channel->value,
            'title_ar' => $type->label(),
        ]);
    }

    public function awaitingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_approval_status' => MessageTemplate::APPROVAL_PENDING,
        ]);
    }
}
