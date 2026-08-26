<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Actions\AbandonClassSession;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The safety net under the delayed close.
 *
 * Every session already has a CloseClassSessionJob scheduled for its end, so in
 * the ordinary case this finds nothing. It exists for the case where that job
 * was lost — a worker restarted mid-flight, a queue drained during a deploy —
 * because a session stuck in `live` is not a cosmetic problem: no register is
 * completed, no report reaches a guardian, and no delivery is recorded for a
 * lesson that was actually taught.
 *
 * Hours after the end, not minutes: a session running over its scheduled time is
 * ordinary teaching (FR-057 caps the credited stay, it does not cut the room),
 * and a sweeper that closes an overrun is worse than one that never runs.
 */
class CloseStaleSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Hours past the scheduled end before a live session counts as stuck. */
    private const STALE_AFTER_HOURS = 6;

    public function handle(WorkspaceContext $context, CloseClassSession $close, AbandonClassSession $abandon): void
    {
        $stale = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereIn('status', [
                ClassSessionStatus::Live,
                ClassSessionStatus::Interrupted,
                /*
                 * ⚠️ AND THIS ONE IS THE TEACHER WHO NEVER CAME.
                 *
                 * Every job that ends a session — the absentee sweep and the
                 * delayed close — is dispatched from `OpenBroadcastRoom`. So a
                 * session nobody opened had NOTHING scheduled against it, and
                 * `scheduled` was not swept: the seats stayed held, no register
                 * existed, no guardian heard anything, and the teacher's
                 * attendance rate was untouched, for ever. Missing the lesson
                 * entirely was cheaper than teaching half of it.
                 *
                 * They go to `AbandonClassSession`, not to `CloseClassSession`:
                 * the second one closes a room that was never opened and marks
                 * every seat holder absent from a lesson nobody held.
                 *
                 * ⚠️ THIS IS THE ONLY CALLER THAT CAN SEE ONE. The delayed close
                 * is dispatched by the room opening, so it only ever meets `Live`
                 * — which is why the two Actions can stay apart instead of one
                 * growing a branch every caller then has to reason about.
                 */
                ClassSessionStatus::Scheduled,
            ])
            ->where('ends_at', '<', now()->subHours(self::STALE_AFTER_HOURS))
            ->get();

        foreach ($stale as $session) {
            try {
                // forWorkspace, never set(): a singleton that caches its answer
                // would leak this workspace into the next job the same worker
                // picks up.
                $context->forWorkspace(
                    (int) $session->workspace_id,
                    fn () => $session->status === ClassSessionStatus::Scheduled
                        ? $abandon->handle($session)
                        : $close->handle($session),
                );
            } catch (Throwable $e) {
                /*
                 * ⚠️ PER ROW, BECAUSE THE LOOP IS THE SAFETY NET ITSELF.
                 *
                 * The close begins with a provider call, and one session whose
                 * provider is unreachable — or whose `broadcast_provider` names a
                 * provider no longer in the map, which the resolver turns into a
                 * throw — used to kill the whole sweep. Hourly, for ever, with
                 * every stale session behind it in the list staying `live`: the
                 * net that exists to catch a lost close, lost to one bad row.
                 */
                report($e);
            }
        }
    }
}
