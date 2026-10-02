<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\BoardPageGate;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Delete one page (US3). The last page stays — `close()` refuses it in its own
 * WHERE clause. The page's background picture is NOT deleted: a copied page may
 * share it, and the board's deletion takes every file the board owns.
 */
final class DeleteBoardPage extends Action
{
    public function __construct(
        private readonly BoardPageGate $gate,
        private readonly BoardLock $lock,
    ) {}

    public function handle(Board $board, User $actor, string $tab, BoardPage $page): void
    {
        DB::transaction(function () use ($board, $actor, $tab, $page): void {
            $this->gate->close($board, 1);
            $this->lock->assertHeldBy($board, (int) $actor->getKey(), $tab);

            $page->delete();

            $this->gate->place($board, array_values(array_map('intval',
                BoardPage::query()->where('board_id', $board->getKey())->orderBy('position')->pluck('id')->all(),
            )));
        });
    }
}
