<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Whiteboard\Enums\BoardImportFailure;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Modules\Whiteboard\Jobs\ConvertBoardImportJob;
use App\Modules\Whiteboard\Models\BoardImport;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Modules\Whiteboard\Support\WhiteboardSettings;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * The PDF has arrived: check it is one (from its BYTES, not its name) and of its
 * ACTUAL size — the size the ticket was asked for is the browser's word — then
 * queue the conversion. `uploading → queued` is a conditional UPDATE, so a
 * second «complete» queues nothing twice.
 */
final class CompleteBoardImport extends Action
{
    public const MIMES = ['application/pdf'];

    public function __construct(
        private readonly CompleteMediaUpload $complete,
        private readonly DeleteMediaAsset $delete,
    ) {}

    public function handle(BoardImport $import): BoardImport
    {
        $asset = $import->source_asset_id === null ? null : MediaAsset::query()->withoutWorkspaceScope()->find($import->source_asset_id);
        if (! $asset instanceof MediaAsset) {
            self::fail($import, BoardImportFailure::Corrupt);
            throw new WhiteboardRefusal('import_failed', ['failure_reason' => BoardImportFailure::Corrupt->value]);
        }

        if ($asset->status !== MediaAssetStatus::Ready) {
            $asset = $this->complete->handle($asset, self::MIMES);
        }

        if ($asset->status !== MediaAssetStatus::Ready) {
            self::fail($import, BoardImportFailure::Unsupported);
            throw new WhiteboardRefusal('import_failed', ['failure_reason' => BoardImportFailure::Unsupported->value]);
        }

        if ((int) $asset->size_bytes > WhiteboardSettings::importMaxBytes()) {
            $this->delete->handle($asset);
            self::fail($import, null);
            throw new WhiteboardRefusal('too_large');
        }

        DB::transaction(function () use ($import): void {
            $queued = DB::update(
                'UPDATE board_imports SET status = ?, dispatched_at = ?, dispatch_attempts = dispatch_attempts + 1, updated_at = ? WHERE id = ? AND status = ?',
                [BoardImportStatus::Queued->value, now()->format('Y-m-d H:i:s.v'), now()->format('Y-m-d H:i:s'), $import->getKey(), BoardImportStatus::Uploading->value],
            );
            if ($queued === 1) {
                DB::afterCommit(fn () => ConvertBoardImportJob::dispatch((int) $import->getKey()));
            }
        });

        return $import->refresh();
    }

    /** `uploading` or `queued` → `failed`, and never over a later state. */
    public static function fail(BoardImport $import, ?BoardImportFailure $reason): void
    {
        DB::update(
            'UPDATE board_imports SET status = ?, failure_reason = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status IN (?, ?, ?)',
            [
                BoardImportStatus::Failed->value, $reason?->value, now()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s'), $import->getKey(),
                BoardImportStatus::Uploading->value, BoardImportStatus::Queued->value, BoardImportStatus::Converting->value,
            ],
        );
    }
}
