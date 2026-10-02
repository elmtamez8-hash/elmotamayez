<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Jobs;

use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Models\Board;
use App\Shared\Traits\RunsAlone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every five minutes: finish what a crashed whiteboard job left behind (US3).
 *  - a board stuck `deleting` gets its delete job again (it is idempotent);
 *  - a copy stuck `building` (its copy job died) is deleted, and its original
 *    released from `duplicating`.
 *
 * «Stuck» = untouched for longer than the jobs' 300-second timeout, with room,
 * so a copy being built right now is never swept from under its job.
 */
class SweepWhiteboardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsAlone;

    public const STUCK_AFTER_MINUTES = 15;

    public function handle(): void
    {
        $before = Carbon::now()->subMinutes(self::STUCK_AFTER_MINUTES);

        Board::query()->withoutWorkspaceScope()
            ->where('pending_operation', BoardPendingOperation::Deleting)
            ->where('updated_at', '<', $before)
            ->lazyById()
            ->each(fn (Board $board) => DeleteBoardJob::dispatch((int) $board->id));

        Board::query()->withoutWorkspaceScope()
            ->where('pending_operation', BoardPendingOperation::Building)
            ->where('updated_at', '<', $before)
            ->lazyById()
            ->each(function (Board $board): void {
                DB::update('UPDATE boards SET pending_operation = ?, updated_at = ? WHERE id = ?', [
                    BoardPendingOperation::Deleting->value, Carbon::now()->format('Y-m-d H:i:s'), $board->id,
                ]);
                DeleteBoardJob::dispatch((int) $board->id);
            });

        // An original whose copy job died is released. Plain WHERE on the row
        // itself: MySQL refuses an UPDATE that reads its own table in a subquery
        // (ERROR 1093), which SQLite allows — green here, broken in production.
        DB::update(
            'UPDATE boards SET pending_operation = NULL WHERE pending_operation = ? AND updated_at < ?',
            [BoardPendingOperation::Duplicating->value, $before->format('Y-m-d H:i:s')],
        );
    }
}
