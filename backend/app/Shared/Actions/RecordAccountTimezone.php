<?php

declare(strict_types=1);

namespace App\Shared\Actions;

use App\Models\User;
use App\Shared\Support\UserClock;
use DomainException;

/**
 * The clock this account reads — `users.timezone` — and who set it.
 *
 * ⚠️ WHY IT IS WRITTEN AT ALL (owner decision 2026-09-25): the product has
 * users in Qatar and in Egypt, and every time the SERVER prints is formatted in
 * the recipient's own zone ({@see UserClock}).
 *
 * ⚠️ TWO WRITERS, AND THE PERSON WINS (owner decision 2026-09-26).
 * - `manual` — chosen in account settings. The source of truth; nothing else
 *   overwrites it.
 * - `browser` — reported by the browser on sign-in (and by the quiet-hours
 *   form). It follows the browser while the person has not chosen, and never
 *   touches a `manual` row.
 *
 * ⚠️ THE GUARD IS A CONDITIONAL UPDATE, NOT A READ THEN A WRITE: two tabs
 * signing in while the person saves a choice in a third must not race.
 */
class RecordAccountTimezone extends Action
{
    public const MANUAL = 'manual';

    public const BROWSER = 'browser';

    public function handle(User $user, string $timezone, string $source = self::MANUAL): User
    {
        if (! UserClock::isValid($timezone)) {
            throw new DomainException('المنطقة الزمنية غير معروفة.');
        }

        if (! in_array($source, [self::MANUAL, self::BROWSER], true)) {
            throw new DomainException('مصدر المنطقة الزمنية غير معروف.');
        }

        User::query()
            ->whereKey($user->getKey())
            ->when($source === self::BROWSER, fn ($query) => $query->where(
                fn ($q) => $q->whereNull('timezone_source')->orWhere('timezone_source', '!=', self::MANUAL),
            ))
            ->update(['timezone' => $timezone, 'timezone_source' => $source]);

        return $user->refresh();
    }
}
