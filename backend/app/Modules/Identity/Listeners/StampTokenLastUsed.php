<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Identity\Support\IdleSessionGuard;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Writes `personal_access_tokens.last_used_at` — at most once per
 * {@see IdleSessionGuard::TOUCH_EVERY_SECONDS} per token, never on every request.
 *
 * ⚠️ SANCTUM'S OWN STAMP IS SWITCHED OFF (`sanctum.last_used_at => false`), AND
 * THIS IS WHAT REPLACES IT. Left on, the guard writes the row on EVERY bearer
 * request — the bell's poll, the presence heartbeat — one UPDATE per request per
 * open tab, for a column nothing reads at that precision. The config key and the
 * `TokenAuthenticated` event are both Sanctum's own, public mechanism (vendor
 * `Guard::__invoke()`): the event fires after the token is validated and before
 * the stamp Sanctum would have made.
 *
 * Who reads the column, and why five minutes loses them nothing:
 *  - {@see IdleSessionGuard::isIdle()} and `EndIdleAuthSessionsJob` compare it
 *    against a cutoff measured in DAYS (`auth.session_idle_days`, 30).
 *  - The devices screen and `DeviceRegistry`'s «in use a moment ago» read
 *    `auth_sessions.last_active_at`, which `IdleSessionGuard::touch()` already
 *    writes on this same five-minute cadence — not this column.
 *
 * The comparison is on the value ALREADY LOADED with the token, so a request
 * inside the window costs no query at all.
 */
final class StampTokenLastUsed
{
    public function handle(TokenAuthenticated $event): void
    {
        /** @var PersonalAccessToken $token */
        $token = $event->token;

        $last = $token->last_used_at;

        if ($last !== null && $last->gt(now()->subSeconds(IdleSessionGuard::TOUCH_EVERY_SECONDS))) {
            return;
        }

        /*
        | Sanctum preserves the connection's «records modified» flag around its
        | own stamp, so a read-only request does not become sticky to the write
        | connection just for this bookkeeping write. Kept the same here.
        */
        $connection = $token->getConnection();
        $modified = $connection->hasModifiedRecords();

        $token->forceFill(['last_used_at' => now()])->save();

        $connection->setRecordModificationState($modified);
    }
}
