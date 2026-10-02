<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Models\User;
use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Shared\Actions\Action;

/** Let go of this tab's lock — straight to the waiting tab when a handover is pending. */
final class ReleaseBoardLock extends Action
{
    public function __construct(private readonly BoardLock $lock) {}

    public function handle(Board $board, User $actor, string $tab): void
    {
        $this->lock->release($board, (int) $actor->getKey(), $tab);
    }
}
