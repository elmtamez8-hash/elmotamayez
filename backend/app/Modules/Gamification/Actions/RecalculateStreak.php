<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Actions;

use App\Modules\Gamification\Models\StudentProgress;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Shared\Actions\Action;
use App\Shared\Contracts\FreezeDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The streak, recomputed on activity (FR-013 · FR-014 · FR-015 · FR-016).
 *
 * ⚠️ EVALUATED LAZILY, ON THE NEXT ACTIVITY — there is no nightly sweep, and that
 * is a decision rather than an omission. A streak nobody is looking at does not
 * need to be broken on schedule: the only moment its value matters is when the
 * student comes back, and that is exactly when this runs. A sweep would touch
 * every row on the platform every night to change numbers no one would read.
 *
 * ⚠️ AND EVERY WRITE IS CONDITIONAL AND MONOTONIC IN ONE DIRECTION. Two awards
 * landing in the same second must not advance the streak twice, and a shield must
 * not be burned twice for one missed day — which is what `streak_evaluated_day`
 * is for, stamped INSIDE the statement that consumes the shield. Precedent:
 * `notified_dormant_at` in spec 006, and the 72 accumulated passes CLAUDE.md
 * records after a single worker restart.
 */
class RecalculateStreak extends Action
{
    /**
     * How far back the gap is examined.
     *
     * A student returning after months is starting over by any reading of the
     * rule, so the walk is bounded rather than unbounded — an absence of two years
     * would otherwise be 700 freeze lookups on the first award back.
     */
    private const MAX_GAP_DAYS = 60;

    public function __construct(
        private readonly GamificationCalendar $calendar,
        private readonly FreezeDirectory $freezes,
    ) {}

    public function handle(StudentProgress $progress): void
    {
        $today = $this->calendar->dayKey();
        $last = $progress->last_active_day;

        // Already counted today. Zero rows from the statement below would say the
        // same thing, but returning early keeps the freeze walk off the hot path
        // for the second and every later award of the day.
        if ($last === $today) {
            return;
        }

        $streak = $this->continues($progress, $last, $today)
            ? $progress->current_streak + 1
            : 1;

        /*
        | ⚠️ THE `WHERE` IS WHAT MAKES THIS IDEMPOTENT. Zero rows means another
        | award for the same student already advanced today's streak, and the
        | value computed above is stale — so it must not be written.
        */
        $advanced = DB::table('student_progress')
            ->where('user_id', $progress->user_id)
            ->where(function ($query) use ($today): void {
                $query->whereNull('last_active_day')->orWhere('last_active_day', '<>', $today);
            })
            ->update([
                'current_streak' => $streak,
                'last_active_day' => $today,
                'updated_at' => now(),
            ]);

        if ($advanced === 0) {
            return;
        }

        // Monotonic: the best is only ever raised.
        DB::table('student_progress')
            ->where('user_id', $progress->user_id)
            ->where('best_streak', '<', $streak)
            ->update(['best_streak' => $streak, 'updated_at' => now()]);

        $progress->refresh();
    }

    /**
     * Whether the run survives the gap between the last active day and today.
     *
     * Three ways it can: the days between were consecutive (no gap at all), every
     * day in the gap was frozen, or a shield covers a single missed day.
     */
    private function continues(StudentProgress $progress, ?string $last, string $today): bool
    {
        if ($last === null) {
            return false;
        }

        $from = CarbonImmutable::parse($last)->addDay();
        $to = CarbonImmutable::parse($today);

        if ($from->greaterThan($to)) {
            // The clock moved backwards, or the row holds a future day. Treat it
            // as continuous rather than punishing the student for it.
            return true;
        }

        $missed = [];

        for ($day = $from; $day->lessThan($to); $day = $day->addDay()) {
            if (count($missed) > self::MAX_GAP_DAYS) {
                return false;
            }

            /*
            | ⚠️ FROZEN DAYS ARE NEITHER ACTIVITY NOR ABSENCE (FR-015). A holiday
            | the teacher declared must not cost a student their run — and the
            | question is asked through a contract, because a freeze period belongs
            | to LiveSessions and is workspace-scoped while the streak is not.
            */
            if (! $this->freezes->isFrozenForStudent((int) $progress->user_id, $day->format('Y-m-d'))) {
                $missed[] = $day->format('Y-m-d');
            }
        }

        if ($missed === []) {
            return true;
        }

        // More than one unfrozen day missed: no shield covers that (FR-016).
        if (count($missed) > 1) {
            return false;
        }

        return $this->consumeShield($progress, $today);
    }

    /**
     * Spend a shield, once.
     *
     * ⚠️ THE DAY STAMP MOVES IN THE SAME STATEMENT AS THE COUNTER. Two awards
     * arriving together would otherwise both see `shield_count > 0`, both spend
     * one, and a student would lose two shields for one missed day — the exact
     * shape of the bug `notified_dormant_at` was added to prevent in spec 006.
     */
    private function consumeShield(StudentProgress $progress, string $today): bool
    {
        return DB::table('student_progress')
            ->where('user_id', $progress->user_id)
            ->where('shield_count', '>', 0)
            ->where(function ($query) use ($today): void {
                $query->whereNull('streak_evaluated_day')->orWhere('streak_evaluated_day', '<>', $today);
            })
            ->update([
                'shield_count' => DB::raw('shield_count - 1'),
                'streak_evaluated_day' => $today,
                'updated_at' => now(),
            ]) > 0;
    }
}
