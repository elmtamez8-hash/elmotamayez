<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Media\Data\UploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardImport;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Modules\Whiteboard\Support\WhiteboardSettings;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Start a PDF import (story 4): an upload ticket for the file, and an import row
 * born `uploading`. Only the lock holder imports. The caller asked
 * `BoardPolicy::update()`.
 *
 * ⚠️ ONE IMPORT PER TEACHER AT A TIME, decided under a lock on the teacher's
 * own row (`UPDATE users SET id = id`), then «is one running?», then the insert —
 * never «count, then insert» without it, and never `INSERT … WHERE NOT EXISTS`,
 * which MySQL does not make atomic when there is no row to contend on.
 */
final class RequestBoardImport extends Action
{
    private const ACTIVE = [BoardImportStatus::Uploading, BoardImportStatus::Queued, BoardImportStatus::Converting];

    public function __construct(
        private readonly MediaProviderResolver $providers,
        private readonly BoardLock $lock,
    ) {}

    /** @return array{import: BoardImport, ticket: UploadTicket} */
    public function handle(User $teacher, Board $board, string $tab, string $filename, int $sizeBytes, ?BoardPage $after): array
    {
        $this->lock->assertHeldBy($board, (int) $teacher->getKey(), $tab);

        if ($sizeBytes > WhiteboardSettings::importMaxBytes()) {
            throw new WhiteboardRefusal('too_large');
        }

        $provider = $this->providers->forKind(MediaKind::Document);

        return DB::transaction(function () use ($teacher, $board, $filename, $sizeBytes, $after, $provider): array {
            DB::update('UPDATE users SET id = id WHERE id = ?', [$teacher->getKey()]);

            $running = BoardImport::query()->withoutWorkspaceScope()
                ->where('user_id', $teacher->getKey())
                ->whereIn('status', self::ACTIVE)
                ->exists();
            if ($running) {
                throw new WhiteboardRefusal('import_in_progress');
            }

            $asset = new MediaAsset([
                'workspace_id' => $board->workspace_id,
                'owner_type' => Board::class,
                'owner_id' => $board->getKey(),
                'uploaded_by_user_id' => $teacher->getKey(),
                'provider' => $provider->identifier(),
                'kind' => MediaKind::Document,
                'role' => MediaRole::Attachment,
                'is_downloadable' => false,
                'status' => MediaAssetStatus::Pending,
                'original_filename' => $filename,
                'size_bytes' => $sizeBytes,
            ]);
            $asset->save();

            $import = new BoardImport([
                'workspace_id' => $board->workspace_id,
                'board_id' => $board->getKey(),
                'user_id' => $teacher->getKey(),
                'source_asset_id' => $asset->getKey(),
                'insert_after_page_id' => $after?->getKey(),
            ]);
            $import->save();

            return ['import' => $import->refresh(), 'ticket' => $provider->createUploadTicket($asset)];
        });
    }
}
