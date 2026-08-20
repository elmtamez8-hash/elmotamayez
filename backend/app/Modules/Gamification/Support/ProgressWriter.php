<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Modules\Gamification\Models\CoinBalance;
use App\Modules\Gamification\Models\StudentProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every write to a running total in this module.
 *
 * ⚠️ CONDITIONAL STATEMENTS, NEVER `$model->x += $n; $model->save()`. Two awards
 * landing together both read the same starting value and the second overwrites
 * the first — one award silently lost, with the ledger still holding both entries
 * and the aggregate permanently out of step with FR-005.
 *
 * ⚠️ AND EVERY DEDUCTION IS GUARDED IN THE `WHERE`, NOT IN THE ASSIGNMENT.
 * `SET xp = xp - :n WHERE xp >= :n` rules the underflow out BEFORE the assignment
 * is evaluated. The two tempting alternatives both fail on exactly one engine:
 *
 * - `GREATEST(xp - :n, 0)` — SQLite has no GREATEST at all. This is written out
 *   in `CreditLedger::applyToBalance()`, which was implemented as two branches
 *   for this very reason; the design note that recommended GREATEST was wrong and
 *   the shipped code is what to copy.
 * - `MAX(xp - :n, 0)` — scalar in SQLite, AGGREGATE-ONLY in MySQL. So the
 *   "obvious local fix" is green on every developer machine and a syntax error in
 *   production.
 *
 * And an unsigned column asked to go negative raises MySQL's ERROR 1690, which no
 * SQLite test can ever reproduce.
 *
 * ⚠️ NO `lockForUpdate()` ANYWHERE IN THIS MODULE. It is a no-op on SQLite, so a
 * test written around it passes locally and guards nothing on MySQL.
 */
class ProgressWriter
{
    /**
     * The student's progress row, created on first use.
     *
     * insertOrIgnore + read-back rather than firstOrCreate: two concurrent awards
     * for a student with no row yet would both pass the "does it exist?" check.
     */
    public function progressFor(int $userId): StudentProgress
    {
        StudentProgress::query()->insertOrIgnore([
            // Passed explicitly: insertOrIgnore is a Query Builder call, so no
            // model is booted and HasUuid never fires. On MySQL the resulting NOT
            // NULL violation is downgraded to a warning and '' is stored, after
            // which every later row on the platform collides with it.
            'uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StudentProgress::query()->where('user_id', $userId)->firstOrFail();
    }

    public function coinBalanceFor(int $userId, int $workspaceId): CoinBalance
    {
        CoinBalance::query()->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CoinBalance::query()
            // The reader is a student, who belongs to no workspace — so the scope
            // adds no condition and the explicit pair below IS the guard.
            ->withoutWorkspaceScope()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->firstOrFail();
    }

    /**
     * Move experience by a signed amount.
     *
     * @return bool false when a deduction could not be applied in full — the
     *              caller rolls back, because the entry it already wrote says a
     *              different number from what the aggregate would hold
     */
    public function moveXp(int $userId, int $delta): bool
    {
        if ($delta === 0) {
            return true;
        }

        $query = DB::table('student_progress')->where('user_id', $userId);

        if ($delta < 0) {
            // The guard is in the WHERE, so the underflow is ruled out before the
            // assignment is evaluated — never in the assignment itself.
            $query->where('xp', '>=', -$delta);

            return $query->decrement('xp', -$delta, ['updated_at' => now()]) > 0;
        }

        return $query->increment('xp', $delta, ['updated_at' => now()]) > 0;
    }

    /** Move coins in one teacher's purse by a signed amount. */
    public function moveCoins(int $userId, int $workspaceId, int $delta): bool
    {
        if ($delta === 0) {
            return true;
        }

        $query = DB::table('coin_balances')
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId);

        if ($delta < 0) {
            $query->where('coins', '>=', -$delta);

            return $query->decrement('coins', -$delta, ['updated_at' => now()]) > 0;
        }

        return $query->increment('coins', $delta, ['updated_at' => now()]) > 0;
    }

    /**
     * How much of a requested deduction can actually be taken.
     *
     * ⚠️ THIS IS WHAT KEEPS FR-005 AND FR-009 FROM CONTRADICTING EACH OTHER. A
     * student with 20 xp and a −50 penalty: write −50 in the entry and floor the
     * aggregate at 0, and the sum of the entries is −30 while the aggregate is 0
     * — for ever. The reconciliation job would then report a drift every night
     * that the design itself requires. The entry records what was applied.
     */
    public function deductible(int $requested, int $available): int
    {
        return $requested >= 0 ? $requested : -min(-$requested, $available);
    }
}
