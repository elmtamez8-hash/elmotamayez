<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * When a message must wait until morning (FR-032).
 *
 * Applies to external channels only. An in-app notification wakes nobody, so
 * deferring it would delay the feed for no benefit — and at launch that makes
 * this class inert in practice, since in-app is the only implemented channel. It
 * is written now because it is proven with a fake external channel (SC-014), and
 * because retrofitting it after the first real channel means revisiting every
 * send path instead of one.
 *
 * Mandatory types are never deferred (FR-035): the point of a 3am security alert
 * is that it arrives at 3am.
 */
class QuietHours
{
    /**
     * The moment delivery may resume, or null to send now.
     */
    public function deferUntil(User $user, NotificationType $type, NotificationChannel $channel): ?CarbonImmutable
    {
        if (! $channel->isExternal() || $type->isMandatory()) {
            return null;
        }

        $start = $user->quiet_hours_start;
        $end = $user->quiet_hours_end;

        if ($start === null || $end === null) {
            return null;
        }

        $timezone = $user->timezone ?? (string) config('notifications.default_timezone');
        $now = CarbonImmutable::now($timezone);

        $windowStart = $this->at($now, (string) $start);
        $windowEnd = $this->at($now, (string) $end);

        // A window like 22:00 → 07:00 crosses midnight, so "inside" cannot be a
        // simple between(): at 02:00 the start is in yesterday, and at 23:00 the
        // end is in tomorrow. Each case rolls the boundary a day.
        if ($windowStart->greaterThan($windowEnd)) {
            if ($now->greaterThanOrEqualTo($windowStart)) {
                return $windowEnd->addDay()->utc();
            }

            if ($now->lessThan($windowEnd)) {
                return $windowEnd->utc();
            }

            return null;
        }

        if ($now->greaterThanOrEqualTo($windowStart) && $now->lessThan($windowEnd)) {
            return $windowEnd->utc();
        }

        return null;
    }

    private function at(CarbonImmutable $reference, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $reference->setTime($hour, $minute);
    }
}
