<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Shared\Actions\Action;

/**
 * Take a free lock, renew this tab's (the 5-second heartbeat), or receive a
 * handover whose grace has run out. The rule is BoardLock's; this is the entrance.
 */
final class AcquireBoardLock extends Action
{
    public function __construct(private readonly BoardLock $lock) {}

    /** @return array{held: bool, handover_requested: bool} */
    public function handle(Board $board, User $actor, string $tab): array
    {
        return $this->lock->acquire($board, (int) $actor->getKey(), $tab);
    }
}
