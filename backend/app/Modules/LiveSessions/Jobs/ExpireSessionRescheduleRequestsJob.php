<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Jobs;

use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Modules\LiveSessions\Support\PendingRescheduleRequest;
use App\Shared\Traits\RunsAlone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A postponement nobody answered in time stops waiting.
 *
 * ⚠️ THE DEADLINE IS min(to_starts_at, from_starts_at), AND NOTHING ELSE MARKED
 * IT. A reschedule request had no expiry of any kind, so one the teacher never
 * answered sat `pending` for ever — and it holds `srr_pending_unique`, so every
 * later ask about the same lesson was refused with «هناك طلب تأجيل قائم» over a
 * request whose proposed Sunday had already gone. Past the proposed hour it can
 * never be approved (`DecideSessionRescheduleRequest` refuses it); past the
 * lesson's own start there is nothing left to move.
 *
 * ⚠️ IT MOVES NOTHING. The lesson keeps its hour and every seat keeps its holder,
 * exactly as while the request was pending — expiring is a status and the slot
 * released, never a change to the timetable.
 *
 * ⚠️ THE SETTLE IS THE SAME CONDITIONAL UPDATE THE TEACHER'S ANSWER USES, so a
 * decision pressed one second before this pass reaches the row wins and this
 * pass matches nothing.
 *
 * `RunsAlone` for the reason every scheduled sweep carries it: the dispatch lock
 * guards the push, not the run. The per-row `try/catch` keeps one bad row from
 * killing the pass that exists to catch the others.
 */
class ExpireSessionRescheduleRequestsJob implements ShouldQueue
{
    use Queueable, RunsAlone;

    public function handle(): void
    {
        SessionRescheduleRequest::query()
            ->withoutWorkspaceScope()
            ->overdue()
            ->orderBy('id')
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    try {
                        PendingRescheduleRequest::settle($request, SessionRescheduleRequest::EXPIRED, null);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
    }
}
