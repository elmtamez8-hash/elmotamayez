<?php

declare(strict_types=1);

namespace App\Modules\Courses\Support;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Retries a create whose position was taken between reading it and writing it.
 *
 * `HasSiblingOrder` allocates `max('order') + 1`, which is a read then a write —
 * the pattern CLAUDE.md bans for seats, and 016 turned its consequence from silent
 * to loud by adding `unique(chapter_id, order)`. Two creates into the same chapter
 * (two tabs, or a double-clicked "add") both read the same max, and the second
 * INSERT raises a duplicate key: a 500 carrying a raw driver message, which "never
 * show a raw error to the user" forbids outright.
 *
 * A bounded retry rather than a lock. `lockForUpdate()` is a no-op on SQLite, so a
 * test written around it would pass locally and prove nothing about the MySQL it
 * runs on — the same trap `SeatConcurrencyTest` exists to avoid. And unlike a seat,
 * there is no scarce resource here to arbitrate: the loser simply wants the next
 * free position, which is exactly what re-reading gives it.
 *
 * Three attempts, because the contention is one teacher's own two requests. A
 * genuine stampede on one chapter is not a scenario this product has; if it ever
 * becomes one, the fix is an allocation table, not a longer loop.
 */
final class SiblingOrderRetry
{
    private const ATTEMPTS = 3;

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $create
     * @return TReturn
     */
    public static function around(callable $create): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $create();
            } catch (UniqueConstraintViolationException $e) {
                // The last attempt rethrows: swallowing it forever would turn a
                // real schema problem into a hang.
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
