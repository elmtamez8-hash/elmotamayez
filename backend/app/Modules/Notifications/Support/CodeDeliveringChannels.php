<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Contracts\SendsVerificationCodes;

/**
 * The channels a verification code can REACH someone on — registered and able
 * to send a code. Not «switched on»: a number proved over WhatsApp last month
 * stays proved when the channel is paused today. Asked of the registry, so no channel is
 * named here (`ProviderAgnosticTest`).
 *
 * ⛔ WHY IT EXISTS (security scan 2026-10-10, F28): a verification may be OPENED
 * on any channel, but on one that sends nothing the number's owner never hears
 * of it, and the code can only be guessed. Such a «verified» number proves
 * nothing, so it never becomes a guardian match — neither through the
 * `ContactVerified` announcement nor through `GuardianContactResolver`.
 */
final class CodeDeliveringChannels
{
    /** @return list<string> the channel values */
    public static function values(): array
    {
        $registry = app(ChannelRegistry::class);
        $values = [];

        foreach (NotificationChannel::cases() as $channel) {
            if (! $registry->has($channel)) {
                continue;
            }

            if ($registry->get($channel) instanceof SendsVerificationCodes) {
                $values[] = $channel->value;
            }
        }

        return $values;
    }
}
