<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Shared\Actions\Action;
use App\Shared\Support\UserClock;
use DomainException;

/**
 * The clock this account reads — `users.timezone`.
 *
 * ⚠️ WHY IT IS WRITTEN AT ALL (owner decision 2026-09-25): the product has
 * users in Qatar and in Egypt, and every time the SERVER prints — a reminder, a
 * cancellation, a private-session answer — is formatted in the recipient's own
 * zone ({@see UserClock}). Until now the column was written only by the quiet-hours
 * form, so almost every account held null and read Doha's clock, an hour wrong
 * for a Cairo family for half the year.
 *
 * ⚠️ `$onlyIfUnset` IS A CONDITIONAL UPDATE, NOT A READ THEN A WRITE. The browser
 * stamps its own zone on sign-in when the account has none; it must never
 * overwrite a zone the person chose (or the quiet-hours form saved), and two tabs
 * signing in at once must not race. `WHERE timezone IS NULL` decides both.
 */
class RecordAccountTimezone extends Action
{
    public function handle(User $user, string $timezone, bool $onlyIfUnset = false): User
    {
        if (! UserClock::isValid($timezone)) {
            throw new DomainException('المنطقة الزمنية غير معروفة.');
        }

        User::query()
            ->whereKey($user->getKey())
            ->when($onlyIfUnset, fn ($query) => $query->whereNull('timezone'))
            ->update(['timezone' => $timezone]);

        return $user->refresh();
    }
}
