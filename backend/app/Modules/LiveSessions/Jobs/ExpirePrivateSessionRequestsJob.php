<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Events\PrivateSessionExpired;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Support\PendingPrivateRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * A request nobody answered stops waiting (FR-023).
 *
 * ⚠️ IT MOVES NO MONEY AND CANCELS NOTHING. Nothing was held, so expiring costs
 * the student a row and their place in a queue — which is the whole point of
 * FR-017. The one thing it must do is SAY SO: an unanswered request that quietly
 * vanishes is indistinguishable from one still pending, and the student waits
 * for a week on an answer that can no longer come.
 *
 * ⚠️ `WithoutOverlapping` AS JOB MIDDLEWARE, NOT `Schedule::job()->withoutOverlapping()`.
 * That one guards the DISPATCH — milliseconds around the push, released long
 * before a worker starts — so last night's sweep still walking when tonight's
 * begins runs two copies over the same rows.
 *
 * ⚠️ AND `expireAfter()` IS THE LOAD-BEARING HALF. The middleware's lock does not
 * expire on its own, so a worker killed mid-sweep holds it for ever and the
 * expiry silently never runs again — the `RunRetentionSweepJob` lesson, and the
 * failure is invisible because the symptom is a queue of requests that stay
 * pending, which looks exactly like a quiet week.
 *
 * ⚠️ THE PER-ROW `try/catch` SHIPS WITH IT. One unresolvable row must not kill
 * the sweep that exists to catch the others — the `CloseStaleSessionsJob`
 * precedent, where a single bad `broadcast_provider` took out the whole hourly
 * pass on its first row.
 */
class ExpirePrivateSessionRequestsJob implements ShouldQueue
{
    use Queueable;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('private-session-expiry'))->expireAfter(600)];
    }

    public function handle(): void
    {
        PrivateSessionRequest::query()
            ->withoutWorkspaceScope()
            ->pending()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    try {
                        /*
                        | ⚠️ ONLY THE WINNER NOTIFIES. The teacher pressing accept
                        | one second before this pass reaches the row settles it
                        | first; the conditional UPDATE answers false and the
                        | student is not told their granted lesson expired. It is
                        | also «ولا يُعادُ إخبارُه» for free — a second pass over a
                        | row already `expired` matches nothing.
                        */
                        if (PendingPrivateRequest::settle($request, PrivateSessionRequest::EXPIRED)) {
                            PrivateSessionExpired::dispatch($request->refresh());
                        }
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
