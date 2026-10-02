<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Jobs;

use App\Modules\Whiteboard\Actions\ConvertBoardImport;
use App\Modules\Whiteboard\Enums\BoardImportFailure;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Modules\Whiteboard\Models\BoardImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One PDF import (story 4) on its own queue — one at a time across the platform
 * (`supervisor-whiteboard-import`), so a second teacher waits their turn and the
 * dialog shows the place. Never released or retried: a crashed run is the
 * sweep's to fail (`SweepWhiteboardJob`), and `failed()` marks it now if it can.
 */
class ConvertBoardImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('whiteboard-import');
    }

    public function handle(ConvertBoardImport $convert): void
    {
        $convert->handle($this->importId);
    }

    public function failed(?Throwable $exception = null): void
    {
        // Only a run still converting: never over `done`, or a failure already recorded.
        DB::update(
            'UPDATE board_imports SET status = ?, failure_reason = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status = ?',
            [BoardImportStatus::Failed->value, BoardImportFailure::Timeout->value, now()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s'), $this->importId, BoardImportStatus::Converting->value],
        );

        // Killed mid-way, its `finally` never ran: the PDF and any stored pictures go now.
        $import = BoardImport::query()->withoutWorkspaceScope()->find($this->importId);
        if ($import !== null && $import->status === BoardImportStatus::Failed) {
            ConvertBoardImport::abandon($import);
        }
    }
}
