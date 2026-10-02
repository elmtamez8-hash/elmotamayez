<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Jobs;

use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Actions\CompleteBoardImport;
use App\Modules\Whiteboard\Enums\BoardImportFailure;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Modules\Whiteboard\Enums\BoardPendingOperation;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardImport;
use App\Shared\Traits\RunsAlone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Every five minutes: finish what a crashed whiteboard job left behind (US3).
 *  - a board stuck `deleting` gets its delete job again (it is idempotent);
 *  - a copy stuck `building` (its copy job died) is deleted, and its original
 *    released from `duplicating`;
 *  - a PDF import (story 4) stuck `converting` past the job's timeout fails
 *    `timeout`; one stuck `uploading` (the browser never said «done») fails and
 *    its upload goes; one `queued` whose job never ran is sent again, up to
 *    three times — the conditional `queued → converting` makes a duplicate a no-op.
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

        $this->sweepImports();

        // An original whose copy job died is released. Plain WHERE on the row
        // itself: MySQL refuses an UPDATE that reads its own table in a subquery
        // (ERROR 1093), which SQLite allows — green here, broken in production.
        DB::update(
            'UPDATE boards SET pending_operation = NULL WHERE pending_operation = ? AND updated_at < ?',
            [BoardPendingOperation::Duplicating->value, $before->format('Y-m-d H:i:s')],
        );
    }

    /** A conversion runs at most 600 s; an upload or a wait gets far longer. */
    public const IMPORT_CONVERTING_MINUTES = 15;

    public const IMPORT_UPLOADING_MINUTES = 60;

    public const IMPORT_QUEUED_MINUTES = 30;

    public const IMPORT_MAX_DISPATCHES = 3;

    private function sweepImports(): void
    {
        $now = Carbon::now();

        BoardImport::query()->withoutWorkspaceScope()
            ->where('status', BoardImportStatus::Converting)
            ->where('started_at', '<', $now->copy()->subMinutes(self::IMPORT_CONVERTING_MINUTES))
            ->lazyById()
            ->each(function (BoardImport $import) use ($now): void {
                DB::update('UPDATE board_imports SET status = ?, failure_reason = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status = ?', [
                    BoardImportStatus::Failed->value, BoardImportFailure::Timeout->value, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'),
                    $import->id, BoardImportStatus::Converting->value,
                ]);
                File::deleteDirectory(storage_path('app/whiteboard-imports/'.$import->uuid));
            });

        BoardImport::query()->withoutWorkspaceScope()
            ->where('status', BoardImportStatus::Uploading)
            ->where('created_at', '<', $now->copy()->subMinutes(self::IMPORT_UPLOADING_MINUTES))
            ->lazyById()
            ->each(function (BoardImport $import): void {
                CompleteBoardImport::fail($import, null);
                $source = $import->source_asset_id === null ? null : MediaAsset::query()->withoutWorkspaceScope()->find($import->source_asset_id);
                if ($source !== null) {
                    app(DeleteMediaAsset::class)->handle($source);
                }
            });

        BoardImport::query()->withoutWorkspaceScope()
            ->where('status', BoardImportStatus::Queued)
            ->where('dispatched_at', '<', $now->copy()->subMinutes(self::IMPORT_QUEUED_MINUTES))
            ->lazyById()
            ->each(function (BoardImport $import) use ($now): void {
                if ($import->dispatch_attempts >= self::IMPORT_MAX_DISPATCHES) {
                    DB::update('UPDATE board_imports SET status = ?, failure_reason = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status = ?', [
                        BoardImportStatus::Failed->value, BoardImportFailure::Timeout->value, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'),
                        $import->id, BoardImportStatus::Queued->value,
                    ]);

                    return;
                }
                $sent = DB::update('UPDATE board_imports SET dispatched_at = ?, dispatch_attempts = dispatch_attempts + 1, updated_at = ? WHERE id = ? AND status = ?', [
                    $now->format('Y-m-d H:i:s.v'), $now->format('Y-m-d H:i:s'), $import->id, BoardImportStatus::Queued->value,
                ]);
                if ($sent === 1) {
                    ConvertBoardImportJob::dispatch((int) $import->id);
                }
            });
    }
}
