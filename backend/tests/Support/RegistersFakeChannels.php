<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Channels\InAppChannel;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

trait RegistersFakeChannels
{
    /**
     * Register a fake channel exactly the way a real one is registered.
     *
     * The whole point of SC-001 is that this is all it takes: rebind the registry
     * with one more implementation and ship templates for it. No listener, no
     * action, and no notification type is touched — if any of them needed
     * changing, this helper could not work.
     */
    protected function registerChannel(NotificationChannel $channel = NotificationChannel::Email): FakeChannel
    {
        $fake = new FakeChannel($channel);

        $this->app->singleton(
            ChannelRegistry::class,
            fn () => new ChannelRegistry([new InAppChannel, $fake]),
        );

        $this->seedTemplatesFor($channel);

        return $fake;
    }

    /**
     * A channel with no template for a type cannot deliver it (FR-037), so a fake
     * channel needs the same data a real one would ship.
     */
    protected function seedTemplatesFor(NotificationChannel $channel): void
    {
        foreach (NotificationType::cases() as $type) {
            $inApp = MessageTemplate::query()
                ->where('type', $type->value)
                ->where('channel', NotificationChannel::InApp->value)
                ->first();

            MessageTemplate::query()->updateOrCreate(
                ['type' => $type->value, 'channel' => $channel->value],
                [
                    'key' => MessageTemplate::keyFor($type, $channel),
                    'title_ar' => $inApp?->title_ar ?? $type->label(),
                    'body_ar' => $inApp?->body_ar ?? $type->label(),
                    'variables' => $inApp?->variables ?? [],
                    'provider_approval_status' => MessageTemplate::APPROVAL_NOT_REQUIRED,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Opt every notification type into an extra channel for one user, since the
     * defaults name in-app only.
     */
    protected function optIn(User $user, NotificationChannel $channel): void
    {
        foreach (NotificationType::cases() as $type) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'type' => $type->value],
                ['channels' => [NotificationChannel::InApp->value, $channel->value]],
            );
        }
    }
}
