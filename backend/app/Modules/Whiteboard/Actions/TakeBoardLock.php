<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Actions;

use App\Modules\Whiteboard\Models\Board;
use App\Modules\Whiteboard\Support\BoardLock;
use App\Shared\Actions\Action;

/**
 * «خُذ التحرير» — the owning teacher asks; the holder gets 10 seconds to save and
 * let go (Q3, approved 2026-10-01). Authorised by `BoardPolicy::takeLock`.
 */
final class TakeBoardLock extends Action
{
    public function __construct(private readonly BoardLock $lock) {}

    /** @return string|null when the lock moves, or null when nobody else holds it */
    public function handle(Board $board, string $tab): ?string
    {
        return $this->lock->take($board, $tab);
    }
}
