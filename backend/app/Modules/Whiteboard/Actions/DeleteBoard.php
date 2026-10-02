<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Jobs\DeleteBoardJob;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Delete a board (US3): hidden from every list and door at once (`deleting`),
 * removed with its files by a queued job. A board being copied answers 409.
 */
final class DeleteBoard extends Action
{
    public function handle(Board $board): void
    {
        DB::transaction(function () use ($board): void {
            $claimed = DB::update(
                // `updated_at` too: the sweep reads it to tell a stuck job from a running one.
                'UPDATE boards SET pending_operation = ?, updated_at = ? WHERE id = ? AND pending_operation IS NULL',
                [BoardPendingOperation::Deleting->value, now()->format('Y-m-d H:i:s'), $board->id],
            );

            if ($claimed !== 1) {
                throw new WhiteboardRefusal('operation_pending');
            }

            DB::afterCommit(fn () => DeleteBoardJob::dispatch((int) $board->id));
        });
    }
}
