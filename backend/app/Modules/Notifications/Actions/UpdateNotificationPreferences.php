<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Actions;

use App\Models\User;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Data\UpdatePreferencesData;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Both rules are enforced here rather than only in the FormRequest, because the
 * Action is the shared entrance for the API, Filament and seeders (Constitution
 * II). A rule that lives only in validation is a rule the admin panel walks past.
 */
class UpdateNotificationPreferences extends Action
{
    public function __construct(
        private readonly ChannelRegistry $registry,
    ) {}

    /**
     * @return Collection<int, NotificationPreference>
     */
    public function handle(User $user, UpdatePreferencesData $data): Collection
    {
        foreach ($data->preferences as $preference) {
            /** @var NotificationType $type */
            $type = $preference['type'];
            /** @var list<NotificationChannel> $channels */
            $channels = $preference['channels'];

            if ($type->isMandatory() && $channels === []) {
                throw new DomainException("«{$type->label()}» إشعار إلزامي ولا يمكن إيقافه.");
            }

            foreach ($channels as $channel) {
                if (! $this->registry->has($channel)) {
                    throw new DomainException("القناة «{$channel->label()}» غير متاحة بعد.");
                }
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'type' => $type->value],
                [
                    'channels' => array_map(
                        static fn (NotificationChannel $channel): string => $channel->value,
                        $channels,
                    ),
                    'digest_window_minutes' => $preference['digest'],
                ],
            );
        }

        return $this->all($user);
    }

    /**
     * @return Collection<int, NotificationPreference>
     */
    public function all(User $user): Collection
    {
        return NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->orderBy('type')
            ->get();
    }
}
