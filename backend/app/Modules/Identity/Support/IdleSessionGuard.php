<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Actions\TerminateAuthSession;
use App\Modules\Identity\Jobs\EndIdleAuthSessionsJob;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Tenancy\Support\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ends a bearer-token session nobody has used for `auth.session_idle_days`.
 *
 * ⛔ UNTIL 2026-09-23 A TOKEN NEVER EXPIRED. `sanctum.expiration` is null and
 * `AuthSession::active()` asks the status alone, so a token copied off a shared
 * computer opened the account for as long as the account existed. The owner's
 * decision is IDLE expiry — thirty days without use — so somebody who opens the
 * product every week is never signed out, and a token nobody is using dies.
 *
 * Wired as Sanctum's `authenticateAccessTokensUsing` callback, which runs on
 * every bearer-token request BEFORE Sanctum stamps `last_used_at` — so the value
 * read here is the PREVIOUS use, already loaded, and the check costs no query.
 *
 * ⚠️ THE EVICTION GOES THROUGH {@see TerminateAuthSession}, NOT A BARE DELETE.
 * The row is what `/auth/sessions/{uuid}/end-reason` answers from, and that is
 * what turns the 401 into «انتهت الجلسة لأنها لم تُستخدم مدّة طويلة» on the
 * sign-in screen rather than an unexplained logout. A token minted before
 * sessions existed has no row, and is deleted directly — `logout()`'s two
 * branches, reached from here.
 *
 * ⚠️ `Sanctum::actingAs()` NEVER RUNS THIS. A test of it must sign in for real
 * and send the token, or it proves nothing.
 */
final class IdleSessionGuard
{
    /**
     * How often «last active» is written. The column feeds the devices screen
     * and `DeviceRegistry`'s «was it in use a moment ago» (a 30-minute window),
     * so it must move well inside that — and not on every request, which would
     * be a write per poll of the notification bell for every open tab.
     */
    private const TOUCH_EVERY_SECONDS = 300;

    public function __construct(private readonly TerminateAuthSession $terminate) {}

    public function allows(PersonalAccessToken $token, bool $isValid): bool
    {
        if (! $isValid) {
            return false;
        }

        if ($this->isIdle($token)) {
            $this->end($token);

            return false;
        }

        $this->touch($token);

        return true;
    }

    /**
     * The moment before which a token counts as abandoned, or null when the rule
     * is switched off.
     *
     * ⚠️ PUBLIC BECAUSE {@see EndIdleAuthSessionsJob} ASKS IT TOO. The request-time
     * check and the nightly sweep are one rule, so they read one number from one
     * place — two spellings of «idle» would agree until an operator changed the
     * setting and only one of them noticed.
     */
    public function cutoff(): ?CarbonInterface
    {
        $days = (int) PlatformSettings::get('auth.session_idle_days', config('auth_sessions.idle_days'));

        // Zero or less is «no limit»: an operator who empties the field means to
        // switch the rule off, not to sign everybody out.
        if ($days <= 0) {
            return null;
        }

        return now()->subDays($days);
    }

    private function isIdle(PersonalAccessToken $token): bool
    {
        $cutoff = $this->cutoff();

        if ($cutoff === null) {
            return false;
        }

        $lastUse = $token->last_used_at ?? $token->created_at;

        return $lastUse !== null && $lastUse->lt($cutoff);
    }

    /**
     * End an idle token's session with the reason the sign-in screen prints, or
     * delete the token outright when it predates sessions and has no row.
     */
    public function end(PersonalAccessToken $token): void
    {
        $session = AuthSession::query()
            ->active()
            ->where('token_id', $token->getKey())
            ->first();

        if ($session !== null) {
            $this->terminate->handle($session, SessionEndReason::Idle);

            return;
        }

        $token->delete();
    }

    /**
     * One conditional UPDATE at most every five minutes per token; the cache key
     * is what spares every other request the write.
     */
    private function touch(PersonalAccessToken $token): void
    {
        if (! Cache::add('auth-session-touch:'.$token->getKey(), true, self::TOUCH_EVERY_SECONDS)) {
            return;
        }

        AuthSession::query()
            ->active()
            ->where('token_id', $token->getKey())
            ->update(['last_active_at' => now()]);
    }
}
