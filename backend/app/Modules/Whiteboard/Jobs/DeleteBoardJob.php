<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Jobs;

use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Models\Board;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Remove a board marked `deleting`, with every file it owns (US3).
 *
 * ⚠️ FILES FIRST, THE ROW LAST. The pages cascade with the row; were the row
 * deleted first, a crash in the middle would leave files nothing points at and
 * nothing will ever sweep. This way a crash leaves the board still `deleting`,
 * and `SweepWhiteboardJob` sends this job again — which picks up where it
 * stopped: arriving twice, or for a board already gone, is a no-op.
 *
 * ⚠️ `failed()` deliberately does NOT clear `deleting` (the copy job's does):
 * a half-deleted board brought back into the lists would show pages whose
 * pictures are gone. The sweep finishes it instead.
 */
class DeleteBoardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $boardId)
    {
        $this->onQueue('whiteboard-ops');
    }

    public function handle(DeleteMediaAsset $deleteAsset): void
    {
        $board = Board::query()->withoutWorkspaceScope()->find($this->boardId);

        if ($board === null || $board->pending_operation !== BoardPendingOperation::Deleting) {
            return;
        }

        MediaAsset::query()->withoutWorkspaceScope()
            ->where('owner_type', Board::class)
            ->where('owner_id', $board->id)
            ->lazyById()
            ->each(fn (MediaAsset $asset) => $deleteAsset->handle($asset));

        $board->delete();
    }
}
