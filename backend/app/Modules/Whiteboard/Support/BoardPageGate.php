<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Modules\Whiteboard\Models\Board;
use Illuminate\Support\Facades\DB;

/**
 * The FIRST statement of every transaction that changes a board's pages — adding,
 * deleting, reordering, importing, duplicating. It locks the board's row, so two
 * of those never interleave (a reorder against an import's insert would collide
 * on `unique(board_id, position)`), and it moves `pages_count` under that lock.
 *
 * ⚠️ NEVER «count the pages, then insert» — the race the row lock exists for.
 *
 * ⚠️ POSITIONAL `?` ONLY (pdo_mysql refuses a repeated named parameter), and the
 * three statements below are three on purpose:
 *  - `open()` counts the row it changed: the ceiling and the pending-operation
 *    guard ARE its WHERE clause;
 *  - `lock()` (a reorder, delta zero) does NOT count: an UPDATE that changes
 *    nothing reports zero rows on MySQL, so a counted `pages_count + 0` would
 *    refuse every reorder in production while SQLite stayed green. `SET id = id`
 *    is the repo's row lock (Community `PostMessage`);
 *  - `close()` subtracts only `WHERE pages_count >= ?`: the column is unsigned and
 *    MySQL strict raises ERROR 1690 below zero where SQLite stores -1.
 */
final class BoardPageGate
{
    /**
     * ⚠️ `$ceiling` is read by the CALLER before its transaction begins: read here,
     * the settings query would run first and the gate would no longer be the
     * transaction's first statement (BoardPagesTest measures it).
     *
     * @throws WhiteboardRefusal `too_many_pages`
     */
    public function open(Board $board, int $delta, int $ceiling): void
    {
        $changed = DB::update(
            'UPDATE boards SET pages_count = pages_count + ? WHERE id = ? AND pages_count + ? <= ? AND pending_operation IS NULL',
            [$delta, $board->id, $delta, $ceiling],
        );

        if ($changed !== 1) {
            $pending = DB::table('boards')->where('id', $board->id)->value('pending_operation');

            throw new WhiteboardRefusal($pending === null ? 'too_many_pages' : 'operation_pending');
        }
    }

    public function lock(Board $board): void
    {
        DB::update('UPDATE boards SET id = id WHERE id = ?', [$board->id]);
    }

    /** @throws WhiteboardRefusal `last_page` when it would leave the board with none */
    public function close(Board $board, int $count): void
    {
        $changed = DB::update(
            'UPDATE boards SET pages_count = pages_count - ? WHERE id = ? AND pages_count > ? AND pending_operation IS NULL',
            [$count, $board->id, $count],
        );

        if ($changed !== 1) {
            $pending = DB::table('boards')->where('id', $board->id)->value('pending_operation');

            throw new WhiteboardRefusal($pending === null ? 'last_page' : 'operation_pending');
        }
    }

    /**
     * Move every page of the board ABOVE the current maximum, so a renumbering in
     * the same transaction never collides with `unique(board_id, position)` — and
     * never below zero (unsigned; MySQL ERROR 1264, SQLite silent). One statement:
     * `position + (max + 1)` keeps the order and clears every old slot.
     */
    public function park(Board $board): void
    {
        $max = (int) DB::table('board_pages')->where('board_id', $board->id)->max('position');

        DB::update('UPDATE board_pages SET position = position + ? WHERE board_id = ?', [$max + 1, $board->id]);
    }

    /**
     * Number the board's pages 1..n in the order given: park first, then one UPDATE
     * per page.
     * ponytail: n statements (n ≤ max_pages_per_board, 300); one CASE update if a
     * reorder of a full board is ever measured slow.
     *
     * @param  list<int>  $orderedIds  every page id of the board, in the new order
     */
    public function place(Board $board, array $orderedIds): void
    {
        $this->park($board);

        foreach ($orderedIds as $index => $id) {
            DB::update('UPDATE board_pages SET position = ? WHERE id = ? AND board_id = ?', [$index + 1, $id, $board->id]);
        }
    }
}
