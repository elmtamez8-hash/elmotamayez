<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\BoardPageGate;
use App\Modules\Whiteboard\Support\BoardScene;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Modules\Whiteboard\Support\WhiteboardSettings;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A new page — blank, or a copy of one of THIS board's pages (US3).
 *
 * ⚠️ THE GATE IS THE FIRST STATEMENT of the transaction: the page ceiling is its
 * WHERE clause, so two adds racing at 299 pages cannot both pass.
 *
 * A copy keeps the scene's element ids (ids are unique per page, which is all a
 * later collaboration document needs) and its background picture (same board,
 * so the same owner); only the frame is renamed to the new page's uuid.
 */
final class AddBoardPage extends Action
{
    public function __construct(
        private readonly BoardPageGate $gate,
        private readonly BoardLock $lock,
    ) {}

    public function handle(Board $board, User $actor, string $tab, ?BoardPage $after, ?BoardPage $copyOf): BoardPage
    {
        $ceiling = WhiteboardSettings::maxPagesPerBoard();

        return DB::transaction(function () use ($board, $actor, $tab, $after, $copyOf, $ceiling): BoardPage {
            $this->gate->open($board, 1, $ceiling);
            $this->lock->assertHeldBy($board, (int) $actor->getKey(), $tab);

            $uuid = (string) Str::orderedUuid();
            $scene = $copyOf === null
                ? BoardScene::blank($uuid, $board->background->value)
                : str_replace('"frame:'.$copyOf->uuid.'"', '"frame:'.$uuid.'"', $copyOf->scene);

            // The byte ceiling is the board's, whatever adds the bytes — a copy too.
            $total = (int) BoardPage::query()->where('board_id', $board->getKey())->sum('scene_bytes');
            if ($total + strlen($scene) > WhiteboardSettings::maxBoardBytes()) {
                throw new WhiteboardRefusal('board_too_large');
            }

            $ids = BoardPage::query()->where('board_id', $board->getKey())->orderBy('position')->pluck('id')->all();
            $max = (int) BoardPage::query()->where('board_id', $board->getKey())->max('position');

            $page = new BoardPage([
                'workspace_id' => $board->workspace_id,
                'board_id' => $board->getKey(),
                'position' => $max + 1,
                'scene' => $scene,
                'scene_bytes' => strlen($scene),
            ]);
            $page->uuid = $uuid;
            $page->background_asset_id = $copyOf?->background_asset_id;
            $page->save();

            $at = $after === null ? count($ids) : (int) array_search($after->getKey(), $ids, true) + 1;
            array_splice($ids, $at, 0, [$page->getKey()]);
            $this->gate->place($board, array_values(array_map('intval', $ids)));

            return $page->refresh();
        });
    }
}
