<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Jobs;

use App\Modules\Media\Actions\CopyLocalMediaAsset;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Actions\DeleteBoard;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fill a hidden copy (`building`) with the original's pages and pictures (US3).
 *
 * 1. every READY picture of the original is copied on our disk to the copy
 *    (`CopyLocalMediaAsset`) — the copy never points at the original's files, so
 *    deleting one board never empties the other;
 * 2. every page is written in ONE transaction, its scene's frame and file ids
 *    rewritten to the new uuids (a uuid is unique, so a text replace is exact);
 * 3. `building` is cleared and the original released.
 *
 * Any failure deletes the hidden copy with its files (`failed()`).
 *
 * ⚠️ NO WORKSPACE CONTEXT IS SET: every read bypasses the scope by name and every
 * write stamps `workspace_id` itself.
 */
class DuplicateBoardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $originalId, public readonly int $copyId)
    {
        $this->onQueue('whiteboard-ops');
    }

    public function handle(CopyLocalMediaAsset $copyAsset): void
    {
        $original = Board::query()->withoutWorkspaceScope()->find($this->originalId);
        $copy = Board::query()->withoutWorkspaceScope()->find($this->copyId);

        if ($original === null || $copy === null || $copy->pending_operation !== BoardPendingOperation::Building) {
            $this->releaseOriginal();

            return;
        }

        /** @var array<int, MediaAsset> $copied old asset id → its copy */
        $copied = [];
        MediaAsset::query()->withoutWorkspaceScope()
            ->where('owner_type', Board::class)
            ->where('owner_id', $original->id)
            ->where('status', MediaAssetStatus::Ready)
            ->lazyById()
            ->each(function (MediaAsset $asset) use (&$copied, $copyAsset, $copy): void {
                $copied[(int) $asset->id] = $copyAsset->handle($asset, $copy, (int) $copy->workspace_id);
            });

        $uuidMap = [];
        foreach ($copied as $oldId => $new) {
            $old = MediaAsset::query()->withoutWorkspaceScope()->find($oldId);
            if ($old !== null) {
                $uuidMap[(string) $old->uuid] = (string) $new->uuid;
            }
        }

        DB::transaction(function () use ($original, $copy, $copied, $uuidMap): void {
            $count = 0;
            BoardPage::query()->withoutWorkspaceScope()
                ->where('board_id', $original->id)
                ->orderBy('position')
                ->get()
                ->each(function (BoardPage $page) use ($copy, $copied, $uuidMap, &$count): void {
                    $uuid = (string) Str::orderedUuid();
                    $scene = strtr($page->scene, ['"frame:'.$page->uuid.'"' => '"frame:'.$uuid.'"', ...$uuidMap]);

                    $new = new BoardPage([
                        'workspace_id' => $copy->workspace_id,
                        'board_id' => $copy->id,
                        'position' => $page->position,
                        'scene' => $scene,
                        'scene_bytes' => strlen($scene),
                    ]);
                    $new->uuid = $uuid;
                    $new->background_asset_id = $page->background_asset_id === null ? null : ($copied[$page->background_asset_id]->id ?? null);
                    $new->save();
                    $count++;
                });

            $copy->forceFill(['pages_count' => $count, 'pending_operation' => null])->save();
        });

        $this->releaseOriginal();
    }

    public function failed(?Throwable $exception = null): void
    {
        $this->releaseOriginal();

        // The hidden copy goes, with whatever files were already copied into it.
        $copy = Board::query()->withoutWorkspaceScope()->find($this->copyId);
        if ($copy !== null && $copy->pending_operation === BoardPendingOperation::Building) {
            DB::update('UPDATE boards SET pending_operation = NULL WHERE id = ?', [$copy->id]);
            app(DeleteBoard::class)->handle($copy->refresh());
        }
    }

    private function releaseOriginal(): void
    {
        DB::update(
            'UPDATE boards SET pending_operation = NULL WHERE id = ? AND pending_operation = ?',
            [$this->originalId, BoardPendingOperation::Duplicating->value],
        );
    }
}
