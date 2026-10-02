<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Actions\StoreLocalMediaFile;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use App\Modules\Whiteboard\Enums\BoardImportFailure;
use App\Modules\Whiteboard\Enums\BoardImportStatus;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardImport;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardPageGate;
use App\Modules\Whiteboard\Support\BoardScene;
use App\Modules\Whiteboard\Support\ImportFailed;
use App\Modules\Whiteboard\Support\PdfPages;
use App\Modules\Whiteboard\Support\PdfUnreadable;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Modules\Whiteboard\Support\WhiteboardSettings;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turn an imported PDF into pages (story 4), run by `ConvertBoardImportJob`.
 *
 * 1. `queued → converting`, conditionally: a job sent twice converts once.
 * 2. Count the pages; more than `import_max_pages` fails it whole.
 * 3. Draw every page as a JPEG 1920 wide (in a folder named by the import's
 *    uuid), count again, and store each as a READY picture of the board.
 * 4. ONE transaction, the gate its first statement: the pages go in after the
 *    page asked for (or at the end if it was deleted meanwhile), each a locked
 *    picture under a frame as tall as the picture needs, and the import moves
 *    `converting → done` — zero rows there rolls the whole thing back.
 *
 * Any failure leaves no page and no picture behind. The source PDF is deleted
 * at the end either way: the pages are the import's result, and 50 MB per
 * import would otherwise stay on the disk for nothing.
 *
 * ⚠️ NO WORKSPACE CONTEXT IS SET: reads bypass the scope by name and writes stamp
 * `workspace_id` themselves.
 */
final class ConvertBoardImport extends Action
{
    public function __construct(
        private readonly PdfPages $pdf,
        private readonly MediaProviderResolver $providers,
        private readonly StoreLocalMediaFile $store,
        private readonly DeleteMediaAsset $delete,
        private readonly BoardPageGate $gate,
    ) {}

    public function handle(int $importId): void
    {
        $started = DB::update(
            'UPDATE board_imports SET status = ?, started_at = ?, updated_at = ? WHERE id = ? AND status = ?',
            [BoardImportStatus::Converting->value, now()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s'), $importId, BoardImportStatus::Queued->value],
        );
        if ($started !== 1) {
            return;
        }

        $import = BoardImport::query()->withoutWorkspaceScope()->findOrFail($importId);
        $board = Board::query()->withoutWorkspaceScope()->find($import->board_id);
        $source = $import->source_asset_id === null ? null : MediaAsset::query()->withoutWorkspaceScope()->find($import->source_asset_id);
        $directory = storage_path('app/whiteboard-imports/'.$import->uuid);
        /** @var list<MediaAsset> $pictures */
        $pictures = [];

        try {
            if ($board === null) {
                throw new ImportFailed(BoardImportFailure::BoardDeleted);
            }
            $provider = $source === null ? null : $this->providers->for($source);
            if ($source === null || ! $provider instanceof LocalMediaProvider || $source->provider_asset_id === null) {
                throw new ImportFailed(BoardImportFailure::Corrupt);
            }
            $pdfPath = $provider->disk()->path($source->provider_asset_id);

            $count = $this->read(fn () => $this->pdf->count($pdfPath));
            if ($count < 1) {
                throw new ImportFailed(BoardImportFailure::Corrupt);
            }
            if ($count > WhiteboardSettings::importMaxPages()) {
                throw new ImportFailed(BoardImportFailure::TooManyPages);
            }

            File::ensureDirectoryExists($directory);
            $drawn = $this->read(fn () => $this->pdf->render($pdfPath, $directory, $count));
            if (count($drawn) !== $count) {
                throw new ImportFailed(BoardImportFailure::Corrupt);
            }

            foreach ($drawn as $i => $page) {
                $pictures[] = $this->store->handle($page['path'], 'page-'.($i + 1).'.jpg', 'image/jpeg', $board, (int) $board->workspace_id, $import->user_id);
            }

            $this->insert($board, $import, $drawn, $pictures);
        } catch (Throwable $error) {
            foreach ($pictures as $picture) {
                $this->delete->handle($picture);
            }
            $reason = $error instanceof ImportFailed ? $error->reason : BoardImportFailure::Corrupt;
            DB::update(
                'UPDATE board_imports SET status = ?, failure_reason = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status = ?',
                [BoardImportStatus::Failed->value, $reason->value, now()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s'), $importId, BoardImportStatus::Converting->value],
            );
            if (! $error instanceof ImportFailed) {
                report($error);
            }
        } finally {
            File::deleteDirectory($directory);
            if ($source !== null) {
                $this->delete->handle($source);
            }
        }
    }

    /**
     * @param  list<array{path: string, width: int, height: int}>  $drawn
     * @param  list<MediaAsset>  $pictures
     */
    private function insert(Board $board, BoardImport $import, array $drawn, array $pictures): void
    {
        $ceiling = WhiteboardSettings::maxPagesPerBoard();
        $count = count($drawn);

        DB::transaction(function () use ($board, $import, $drawn, $pictures, $ceiling, $count): void {
            try {
                $this->gate->open($board, $count, $ceiling);
            } catch (WhiteboardRefusal $refusal) {
                // Being deleted (`operation_pending`), or gone altogether, is one answer.
                $gone = $refusal->reason === 'operation_pending' || ! DB::table('boards')->where('id', $board->id)->exists();
                throw new ImportFailed($gone ? BoardImportFailure::BoardDeleted : BoardImportFailure::TooManyPages);
            }

            $ids = array_values(BoardPage::query()->withoutWorkspaceScope()->where('board_id', $board->id)->orderBy('position')->pluck('id')->map(fn ($id): int => (int) $id)->all());
            $max = (int) BoardPage::query()->withoutWorkspaceScope()->where('board_id', $board->id)->max('position');
            $after = $import->insert_after_page_id === null ? false : array_search((int) $import->insert_after_page_id, $ids, true);
            $at = $after === false ? count($ids) : $after + 1;

            $new = [];
            foreach ($drawn as $i => $page) {
                $uuid = (string) Str::orderedUuid();
                $scene = BoardScene::withPicture($uuid, $board->background->value, (string) $pictures[$i]->uuid, $page['height'], (string) $import->uuid, $i + 1);
                $row = new BoardPage([
                    'workspace_id' => $board->workspace_id,
                    'board_id' => $board->id,
                    'position' => $max + $i + 1,
                    'scene' => $scene,
                    'scene_bytes' => strlen($scene),
                ]);
                $row->uuid = $uuid;
                $row->background_asset_id = (int) $pictures[$i]->getKey();
                $row->save();
                $new[] = (int) $row->getKey();
            }

            $total = (int) BoardPage::query()->withoutWorkspaceScope()->where('board_id', $board->id)->sum('scene_bytes');
            if ($total > WhiteboardSettings::maxBoardBytes()) {
                throw new ImportFailed(BoardImportFailure::TooManyPages);
            }

            array_splice($ids, $at, 0, $new);
            $this->gate->place($board, $ids);

            $done = DB::update(
                'UPDATE board_imports SET status = ?, pages_count = ?, finished_at = ?, updated_at = ? WHERE id = ? AND status = ?',
                [BoardImportStatus::Done->value, $count, now()->format('Y-m-d H:i:s'), now()->format('Y-m-d H:i:s'), $import->id, BoardImportStatus::Converting->value],
            );
            if ($done !== 1) {
                // The sweep timed it out meanwhile: nothing of this run may stay.
                throw new ImportFailed(BoardImportFailure::Timeout);
            }
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function read(callable $read): mixed
    {
        try {
            return $read();
        } catch (PdfUnreadable) {
            throw new ImportFailed(BoardImportFailure::Corrupt);
        }
    }
}
