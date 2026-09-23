<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Http\Middleware\TouchPanelSession;
use App\Modules\Identity\Jobs\EndIdleAuthSessionsJob;
use App\Modules\Identity\Models\AuthSession;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * «Is this `/admin` sign-in still alive?» — one answer, read by the middleware
 * that records activity and by the sweep that ends what went quiet.
 *
 * ⛔ A PANEL ROW USED TO STAY `active` FOR EVER. `RecordPanelSession` writes it at
 * sign-in and nothing wrote it again, while the web session behind it died on its
 * own after `session.lifetime` minutes of silence (the redis key simply expires).
 * So the devices screen listed a sign-in nobody could use, and
 * `DeviceRegistry::enforceLimit()` counted it as a device — for ever.
 *
 * ⚠️ THE TWO NUMBERS ARE ONE RULE. `last_active_at` lags real activity by up to
 * {@see self::TOUCH_EVERY_SECONDS}, so the sweep's margin must be at least that
 * interval or it ends an operator who is using the panel right now — and ending
 * one DESTROYS their live session. Both live here so they cannot drift apart
 * ({@see TouchPanelSession}, {@see EndIdleAuthSessionsJob}).
 */
final class PanelSessionActivity
{
    /** At most one write per session per five minutes. */
    public const TOUCH_EVERY_SECONDS = 300;

    /**
     * Kept in the session itself rather than the cache: `StartSession` has already
     * loaded it, so the throttled path costs no I/O at all.
     */
    private const SESSION_KEY = 'auth_session.panel_touched_at';

    public function touch(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $last = $session->get(self::SESSION_KEY);

        if (is_int($last) && $now - $last < self::TOUCH_EVERY_SECONDS) {
            return;
        }

        $session->put(self::SESSION_KEY, $now);

        // Conditional and indexed (`session_id`): an ended row is never revived.
        AuthSession::query()
            ->active()
            ->whereNull('token_id')
            ->where('session_id', $session->getId())
            ->update(['last_active_at' => now()]);
    }

    /**
     * A panel row whose `last_active_at` is older than this has outlived the web
     * session it rests on: the lifetime, plus twice the touch interval of slack.
     */
    public function expiryCutoff(): CarbonInterface
    {
        $lifetimeMinutes = max(1, (int) config('session.lifetime', 120));

        return now()
            ->subMinutes($lifetimeMinutes)
            ->subSeconds(2 * self::TOUCH_EVERY_SECONDS);
    }
}
