<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Models\User;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Models\NotificationPreference;

/**
 * Decides which channels one notification actually goes out on.
 *
 * This is where FR-028 is enforced — no send path may skip it — and where the two
 * overrides live:
 *
 *  - a mandatory type ignores the user's choice entirely (FR-029). Not "warns",
 *    not "defaults to on": ignores. A suspended enrollment or a login from a new
 *    device is not something a preference should be able to silence.
 *  - an unimplemented channel is dropped (FR-030), even if a stored preference
 *    names it. Preferences refuse those on the way in, but a channel could also
 *    be removed after a row was written.
 */
class PreferenceResolver
{
    public function __construct(
        private readonly ChannelRegistry $registry,
    ) {}

    /**
     * @return list<NotificationChannel>
     */
    public function channelsFor(User $user, NotificationType $type): array
    {
        $stored = $this->stored($user, $type);
        $chosen = $stored ?? $type->defaultChannels();

        if ($type->isMandatory()) {
            // "Cannot be switched off" is not "cannot be added to" (FR-029). The
            // defaults are a floor, not a fixed list: a user who asks for security
            // alerts on WhatsApp as well gets them there, and a user who tries to
            // clear the list still gets them in-app.
            $chosen = array_values(array_unique(
                array_merge($type->defaultChannels(), $chosen),
                SORT_REGULAR,
            ));
        }

        // Not implemented means it does not exist (FR-030). A stored preference
        // could still name one if a channel were ever withdrawn.
        $implemented = $this->registry->implemented();

        return array_values(array_filter(
            $chosen,
            static fn (NotificationChannel $channel): bool => in_array($channel, $implemented, true),
        ));
    }

    public function digestWindowFor(User $user, NotificationType $type): ?int
    {
        if ($type->isMandatory()) {
            // Never batched (FR-035): a digest is a delay by another name.
            return null;
        }

        return $this->preference($user, $type)?->digest_window_minutes;
    }

    /**
     * Null when the user never expressed an opinion — which is different from an
     * empty array, meaning "I chose to receive this nowhere".
     *
     * @return list<NotificationChannel>|null
     */
    private function stored(User $user, NotificationType $type): ?array
    {
        return $this->preference($user, $type)?->selectedChannels();
    }

    private function preference(User $user, NotificationType $type): ?NotificationPreference
    {
        return NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type->value)
            ->first();
    }
}
