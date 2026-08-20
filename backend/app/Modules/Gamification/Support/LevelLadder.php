<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Support;

use App\Modules\Gamification\Models\Level;
use Illuminate\Support\Facades\DB;

/**
 * Where a given amount of experience puts a student (FR-012).
 */
class LevelLadder
{
    public function levelFor(int $xp): int
    {
        $level = Level::query()
            ->where('xp_threshold', '<=', $xp)
            ->orderByDesc('xp_threshold')
            ->value('level');

        // No ladder seeded means level one, not level zero: the catalogue being
        // empty is a configuration state, not a demotion.
        return $level === null ? 1 : (int) $level;
    }

    /**
     * Raise the stored level, never lower it.
     *
     * ⚠️ `WHERE level < :n` IS NOT DECORATION. Experience can go DOWN — a penalty
     * is an ordinary row in the catalogue — so a level recomputed from the current
     * total and written unconditionally would drop a student a level and then
     * "promote" them again on their next award, firing LevelReachedUp and a
     * congratulation every time. Levels are a ratchet.
     */
    public function raise(int $userId, int $level): bool
    {
        return DB::table('student_progress')
            ->where('user_id', $userId)
            ->where('level', '<', $level)
            ->update(['level' => $level, 'updated_at' => now()]) > 0;
    }

    /**
     * Claim the right to congratulate, exactly once per level.
     *
     * Separate from {@see self::raise()} and conditional for the same reason: the
     * award path can run twice for one event, and the second congratulation is the
     * one the student notices.
     */
    public function claimCongratulation(int $userId, int $level): bool
    {
        return DB::table('student_progress')
            ->where('user_id', $userId)
            ->where('notified_level', '<', $level)
            ->update(['notified_level' => $level, 'updated_at' => now()]) > 0;
    }
}
