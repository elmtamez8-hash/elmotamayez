<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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

    public function handle(WorkspaceContext $context, CloseClassSession $close): void
    {
        $stale = ClassSession::query()
            ->withoutWorkspaceScope()
            ->whereIn('status', [ClassSessionStatus::Live, ClassSessionStatus::Interrupted])
            ->where('ends_at', '<', now()->subHours(self::STALE_AFTER_HOURS))
            ->get();

        foreach ($stale as $session) {
            // forWorkspace, never set(): a singleton that caches its answer would
            // leak this workspace into the next job the same worker picks up.
            $context->forWorkspace(
                (int) $session->workspace_id,
                fn () => $close->handle($session),
            );
        }
    }
}
