<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Models\BoardPage;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Modules\Whiteboard\Support\BoardPageGate;
use App\Modules\Whiteboard\Support\WhiteboardRefusal;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Put the board's pages in a new order (US3). The list must name EVERY page of the
 * board, each once — read under the gate's lock, so a page added in another tab a
 * moment ago makes this a `pages_changed`, never a page silently left at the end.
 */
final class ReorderBoardPages extends Action
{
    public function __construct(
        private readonly BoardPageGate $gate,
        private readonly BoardLock $lock,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return list<array{uuid: string, position: int}>
     */
    public function handle(Board $board, User $actor, string $tab, array $uuids): array
    {
        return DB::transaction(function () use ($board, $actor, $tab, $uuids): array {
            $this->gate->lock($board);
            $this->lock->assertHeldBy($board, (int) $actor->getKey(), $tab);

            /** @var array<string, int> $byUuid */
            $byUuid = BoardPage::query()->where('board_id', $board->getKey())->pluck('id', 'uuid')->map(fn ($id): int => (int) $id)->all();

            if (count($uuids) !== count($byUuid) || count(array_unique($uuids)) !== count($uuids) || array_diff($uuids, array_keys($byUuid)) !== []) {
                throw new WhiteboardRefusal('pages_changed');
            }

            $this->gate->place($board, array_map(fn (string $uuid): int => $byUuid[$uuid], $uuids));

            return array_map(fn (string $uuid, int $i): array => ['uuid' => $uuid, 'position' => $i + 1], $uuids, array_keys($uuids));
        });
    }
}
