<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Shared\Contracts\SessionCreditHolds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * ٠٣٥ — حجزُ رصيدٍ بقيَ معلَّقاً بعدَ أن انتهت حصّتُه. الشبكةُ الأخيرة.
 *
 * ⚠️ WITHOUT THIS JOB THE FROZEN CREDIT IS LOST FOR EVER, AND NOTHING NOTICES.
 * Three doors produce an unsettled hold on a session that is over and will
 * never be judged again:
 *
 *  · the teacher opened the room and left early — the session goes `completed`
 *    with `delivered_at` null, so `SessionDelivered` never fires, nothing
 *    charges and nothing settles;
 *  · the teacher never opened the room at all — `AbandonClassSession`, which
 *    deliberately dispatches no delivery event;
 *  · any release path that threw between taking the seat back and settling.
 *
 * And the nightly invariant is GREEN over all three, because the row really is
 * unsettled: `held_credits` matches the count of live holds exactly. The drift
 * is that the holds are live for a session that ended last month.
 *
 * ⚠️ SIX HOURS AFTER `ends_at`, and the number is a margin rather than a
 * guess. `CloseClassSessionJob` runs at `ends_at` plus the join window, the
 * unbilled-delivery sweep runs every fifteen minutes, and the charge listener
 * settles the hold itself — so anything still unsettled six hours later is not
 * waiting on a worker. And it cannot reach a live session at any speed.
 *
 * ⚠️ RELEASING A HOLD THIS JOB SHOULD NOT HAVE TOUCHED IS HARMLESS AND THE
 * REVERSE IS NOT. A charge that arrives afterwards still writes its ledger
 * entry; its own settle-claim simply matches zero rows, which is what the
 * conditional UPDATE is for. A hold never released is a credit the student
 * bought and can never spend.
 *
 * ⚠️ JOB MIDDLEWARE WITH AN EXPLICIT `expireAfter()`, and the EXPIRY is the
 * load-bearing half — the same departure `RunRetentionSweepJob` documents. The
 * scheduler's `withoutOverlapping()` wraps `dispatchToQueue()`, which for a
 * queued job is milliseconds around the push; and a middleware lock with no
 * expiry means one killed worker stops this sweep for ever, silently.
 */
class SweepStaleCreditHoldsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Sessions per pass. What is left is picked up in fifteen minutes. */
    private const MAX_SESSIONS = 500;

    private const GRACE_HOURS = 6;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('payments:stale-credit-holds'))
            ->expireAfter($this->timeout + 60)
            ->dontRelease()];
    }

    public function handle(SessionCreditHolds $holds): void
    {
        $sessionIds = DB::table('credit_holds')
            ->join('class_sessions', 'class_sessions.id', '=', 'credit_holds.class_session_id')
            ->whereNull('credit_holds.settled_at')
            ->where('class_sessions.ends_at', '<', now()->subHours(self::GRACE_HOURS))
            ->distinct()
            ->limit(self::MAX_SESSIONS)
            ->pluck('credit_holds.class_session_id');

        foreach ($sessionIds as $sessionId) {
            $holds->release((int) $sessionId);
        }
    }
}
