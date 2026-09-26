<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The clock a PERSON reads — as opposed to the platform's clock, which is where
 * a day begins and ends (`SessionSettings::timezone()`).
 *
 * ⚠️ THE PRODUCT HAS USERS IN QATAR AND IN EGYPT, AND EGYPT OBSERVES DAYLIGHT
 * SAVING. Qatar does not. So for half the year a Cairo family and a Doha teacher
 * are an hour apart, and a message that prints one bare «17:00» is true for only
 * one of them. Every server-rendered time goes through here: the recipient's own
 * stored zone (`users.timezone`, which the browser stamps), else the platform's.
 *
 * In `Shared` rather than on `SessionSettings` because a policy in `Community`
 * prints a time too, and `ContextIsolationTest` keeps modules out of each
 * other's classes.
 */
final class UserClock
{
    /** The platform zone — `SESSIONS_TIMEZONE`, the one source (2026-09-25). */
    public static function platformZone(): string
    {
        return (string) config('sessions.timezone', 'Asia/Qatar');
    }

    /**
     * This person's zone, or the platform's when they have none — or one PHP does
     * not recognise: a bad string would throw inside a notification listener and
     * lose the message, which is worse than an hour's difference.
     */
    public static function zoneFor(?User $user): string
    {
        $zone = $user?->timezone;

        if (is_string($zone) && self::isValid($zone)) {
            return $zone;
        }

        return self::platformZone();
    }

    public static function isValid(string $zone): bool
    {
        return $zone !== '' && in_array($zone, timezone_identifiers_list(), true);
    }

    /**
     * The reader's clock AND the other party's, when the two differ at that
     * instant: «2026-11-03 17:00 بتوقيتك · 18:00 بتوقيت المدرّس» (owner decision
     * 2026-09-26). For the messages two people in two countries negotiate over
     * — a private-session request and its answer, a reschedule — so neither
     * has to work out the other's hour.
     *
     * ⚠️ «DIFFER» IS THE OFFSET AT THAT INSTANT, NOT THE ZONE NAME. Cairo and
     * Doha are both UTC+3 until 2026-10-29: two names, one hour, and a second
     * clock there would print the same number twice. Equal offsets fall back to
     * {@see format()}, zone named.
     *
     * The other party's date is printed only when it is a different day there.
     *
     * @param  string  $counterpartRole  «المدرّس» or «الطالب» — whose clock the second time is
     */
    public static function formatBoth(?User $reader, ?User $counterpart, DateTimeInterface $at, string $counterpartRole): string
    {
        $mine = CarbonImmutable::instance($at)->setTimezone(self::zoneFor($reader));
        $theirs = CarbonImmutable::instance($at)->setTimezone(self::zoneFor($counterpart));

        if ($counterpart === null || $mine->getOffset() === $theirs->getOffset()) {
            return self::format($reader, $at);
        }

        $other = $theirs->toDateString() === $mine->toDateString()
            ? $theirs->format('H:i')
            : $theirs->format('Y-m-d H:i');

        return $mine->format('Y-m-d H:i').' بتوقيتك · '.$other.' بتوقيت '.$counterpartRole;
    }

    /** «2026-10-30 17:00 (توقيت مصر)» — the reader's clock, with the clock named. */
    public static function format(?User $user, DateTimeInterface $at): string
    {
        $zone = self::zoneFor($user);

        return CarbonImmutable::instance($at)->setTimezone($zone)->format('Y-m-d H:i')
            .' ('.TimezoneLabel::for($zone).')';
    }
}
