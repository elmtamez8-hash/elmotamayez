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

    /** Spent on the first successful code, so a replay finds nothing. */
    public static function forget(string $challenge): void
    {
        Cache::forget(self::PREFIX.$challenge);
    }
}
