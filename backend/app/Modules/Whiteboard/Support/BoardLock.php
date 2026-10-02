<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Support;

use App\Modules\Whiteboard\Models\Board;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The edit lock: one editor per board, a heartbeat, and the owning teacher's
 * «خُذ التحرير» with a 10-second grace (research R-09, Q3 as refined and approved
 * on 2026-10-01).
 *
 * ⚠️ ONE PHP CLOCK, BOUND AS STRINGS WITH MILLISECONDS. `$now` is taken once per
 * call and every bound time is `->format('Y-m-d H:i:s.v')`:
 *  - a bound date OBJECT is formatted by the grammar as `Y-m-d H:i:s`, dropping the
 *    fraction — so `timestamp(3)` alone would store whole seconds;
 *  - on MySQL an UPDATE that writes the value already stored reports ZERO affected
 *    rows, so a heartbeat in the same second as the last would read as a lost lock;
 *  - SQL date arithmetic (`NOW() - INTERVAL 10 SECOND`) is MySQL-only and a
 *    syntax error on the SQLite the suite runs on, and `travel()` cannot move it.
 * The assumption this buys: ONE application server, so one clock.
 *
 * ⚠️ POSITIONAL `?` ONLY. With native prepares (`ATTR_EMULATE_PREPARES => false`)
 * pdo_mysql refuses a named parameter used twice (HY093) while SQLite accepts it —
 * a green suite and a 500 in production.
 *
 * Every statement is ONE conditional UPDATE whose WHERE clause is the whole rule;
 * success is the row it changed. Never read-then-write (gotchas/live-sessions.md).
 */
final class BoardLock
{
    public const EXPIRES_AFTER_SECONDS = 120;

    public const HANDOVER_GRACE_SECONDS = 10;

    /** Every time written to an `editor_*` column passes through here. */
    public static function stamp(CarbonImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.v');
    }

    /**
     * Acquire a free or expired lock, renew one this tab holds, or receive a handover
     * whose grace has run out. Returns whether this tab holds the lock now, and
     * whether the owning teacher has asked for it.
     *
     * @return array{held: bool, handover_requested: bool}
     */
    public function acquire(Board $board, int $userId, string $tab): array
    {
        $now = CarbonImmutable::now();
        $stamp = self::stamp($now);
        $graceEnds = self::stamp($now->subSeconds(self::HANDOVER_GRACE_SECONDS));
        $expired = self::stamp($now->subSeconds(self::EXPIRES_AFTER_SECONDS));

        // 1. Renew: this tab holds it and no handover's grace has run out. Touches
        //    editor_seen_at ONLY, through DB::table, so `updated_at` does not churn.
        $renewed = DB::table('boards')
            ->where('id', $board->id)
            ->where('editor_user_id', $userId)
            ->where('editor_tab_id', $tab)
            ->where(fn ($q) => $q->whereNull('editor_handover_at')->orWhere('editor_handover_at', '>', $graceEnds))
            ->update(['editor_seen_at' => $stamp]);

        if ($renewed === 1 || ($renewed === 0 && $this->holdsWithinGrace($board->id, $userId, $tab, $graceEnds))) {
            return ['held' => true, 'handover_requested' => $this->handoverPending($board->id)];
        }

        // 2. Receive a handover whose grace has run out, or take a free/expired lock.
        $taken = DB::table('boards')
            ->where('id', $board->id)
            ->where(fn ($q) => $q
                ->where(fn ($handover) => $handover
                    ->where('editor_handover_tab', $tab)
                    ->where('editor_handover_at', '<=', $graceEnds))
                ->orWhere(fn ($free) => $free
                    ->whereNull('editor_handover_tab')
                    ->where(fn ($idle) => $idle->whereNull('editor_tab_id')->orWhere('editor_seen_at', '<', $expired))))
            ->update([
                'editor_user_id' => $userId,
                'editor_tab_id' => $tab,
                'editor_seen_at' => $stamp,
                'editor_handover_tab' => null,
                'editor_handover_at' => null,
            ]);

        return ['held' => $taken === 1, 'handover_requested' => false];
    }

    /**
     * «خُذ التحرير» — authorised by `BoardPolicy::takeLock` (the owning teacher).
     * Records the request; the lock moves when the holder releases or the grace
     * runs out. Asked twice, it returns the SAME moment (the first request's).
     *
     * @return string|null when the lock moves, or null when nobody else holds it
     *                     (the caller simply acquires)
     */
    public function take(Board $board, string $tab): ?string
    {
        $now = CarbonImmutable::now();
        $stamp = self::stamp($now);
        $expired = self::stamp($now->subSeconds(self::EXPIRES_AFTER_SECONDS));

        DB::table('boards')
            ->where('id', $board->id)
            ->whereNotNull('editor_tab_id')
            ->where('editor_tab_id', '<>', $tab)
            ->where('editor_seen_at', '>=', $expired)
            ->whereNull('editor_handover_at')
            ->update(['editor_handover_tab' => $tab, 'editor_handover_at' => $stamp]);

        $row = DB::table('boards')->where('id', $board->id)->first(['editor_handover_tab', 'editor_handover_at']);

        if ($row === null || $row->editor_handover_tab !== $tab || $row->editor_handover_at === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $row->editor_handover_at)
            ->addSeconds(self::HANDOVER_GRACE_SECONDS)
            ->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * Release this tab's lock. With a handover pending the lock passes straight to
     * the tab that asked — no gap in which a third tab could take it.
     */
    public function release(Board $board, int $userId, string $tab): void
    {
        $stamp = self::stamp(CarbonImmutable::now());

        // Only the owning teacher ever asks for a handover (BoardPolicy::takeLock),
        // so that is who receives it — no column records the requester.
        $receiver = BoardOwnership::owningTeacherId($board);

        /*
        | ⚠️ THE ORDER OF THE ASSIGNMENTS IS LOAD-BEARING. MySQL evaluates a
        | single-table UPDATE's SET list left to right, each later expression seeing
        | the earlier NEW values; SQLite evaluates all of them against the OLD row.
        | Every expression that READS `editor_handover_tab` therefore comes before
        | the one that clears it, and both engines agree.
        */
        DB::update(
            'UPDATE boards SET '
            .'editor_user_id = CASE WHEN editor_handover_tab IS NULL THEN NULL ELSE ? END, '
            .'editor_seen_at = CASE WHEN editor_handover_tab IS NULL THEN NULL ELSE ? END, '
            .'editor_tab_id = editor_handover_tab, '
            .'editor_handover_tab = NULL, '
            .'editor_handover_at = NULL '
            .'WHERE id = ? AND editor_user_id = ? AND editor_tab_id = ?',
            [$receiver, $stamp, $board->id, $userId, $tab],
        );
    }

    /**
     * Every writing Action calls this first. Holding the lock is not permission —
     * the Action still asks the policy on every request.
     *
     * @throws WhiteboardRefusal `lock_lost`
     */
    public function assertHeldBy(Board $board, int $userId, string $tab): void
    {
        $graceEnds = self::stamp(CarbonImmutable::now()->subSeconds(self::HANDOVER_GRACE_SECONDS));

        if (! $this->holdsWithinGrace($board->id, $userId, $tab, $graceEnds)) {
            throw new WhiteboardRefusal('lock_lost');
        }
    }

    private function holdsWithinGrace(int $boardId, int $userId, string $tab, string $graceEnds): bool
    {
        return DB::table('boards')
            ->where('id', $boardId)
            ->where('editor_user_id', $userId)
            ->where('editor_tab_id', $tab)
            ->where(fn ($q) => $q->whereNull('editor_handover_at')->orWhere('editor_handover_at', '>', $graceEnds))
            ->exists();
    }

    private function handoverPending(int $boardId): bool
    {
        return DB::table('boards')->where('id', $boardId)->whereNotNull('editor_handover_tab')->exists();
    }
}
