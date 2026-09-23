<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The ten minutes between "the password was right" and "the code was right".
 *
 * In the cache, not a table: nothing reads one of these rows a minute after it
 * is written, and a table for data that expires in ten minutes buys a migration,
 * an index and a cleanup job to hold rubbish (research §R10).
 *
 * A challenge is deliberately NOT a token. Issuing a Sanctum token and then
 * restricting it would mean any flaw in the restriction is a full sign-in with
 * the password alone — the exact thing the second factor exists to prevent.
 */
final class TwoFactorChallenges
{
    private const PREFIX = '2fa:challenge:';

    private const TTL_MINUTES = 10;

    public static function issue(User $user): string
    {
        $challenge = (string) Str::orderedUuid();

        Cache::put(self::PREFIX.$challenge, $user->getKey(), now()->addMinutes(self::TTL_MINUTES));

        return $challenge;
    }

    public static function userId(string $challenge): ?int
    {
        $id = Cache::get(self::PREFIX.$challenge);

        return $id === null ? null : (int) $id;
    }

    /**
     * Wrong answers one challenge survives. The fifth spends it.
     *
     * Mistyping six digits is ordinary and must not send anybody back to the
     * password form — but a challenge that outlives every wrong answer lets the
     * guessing run for its whole ten minutes, bounded only by how many
     * addresses the guesser has. Five is the same number the throttle allows in
     * a minute, so an honest person never meets it and a guesser meets it fast.
     */
    public const MAX_FAILURES = 5;

    /**
     * Counts one wrong answer and says whether the challenge is now spent.
     *
     * Stored beside the challenge with the same lifetime, so the counter can
     * never outlive the thing it counts. `add()` then `increment()` rather than
     * a read and a write: two guesses in flight at once must both be counted.
     */
    public static function recordFailure(string $challenge): bool
    {
        $key = self::PREFIX.$challenge.':failures';

        Cache::add($key, 0, now()->addMinutes(self::TTL_MINUTES));

        if ((int) Cache::increment($key) < self::MAX_FAILURES) {
            return false;
        }

        self::forget($challenge);

        return true;
    }

    /** Spent on the first successful code, so a replay finds nothing. */
    public static function forget(string $challenge): void
    {
        Cache::forget(self::PREFIX.$challenge);
        Cache::forget(self::PREFIX.$challenge.':failures');
    }
}
