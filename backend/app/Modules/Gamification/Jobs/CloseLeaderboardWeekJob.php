<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Jobs;

use App\Modules\Gamification\Actions\RollUpLeaderboards;
use App\Modules\Gamification\Enums\LeaderboardPeriod;
use App\Modules\Gamification\Support\GamificationCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Seal the week that just ended, at Sunday dawn Doha time (FR-021 · SC-010).
 *
 * ⚠️ CLOSING IS A FINAL ROLLUP OF THE OLD KEY, NOT A DELETION. The new week
 * starts empty because its period key has no rows yet — nothing has to be
 * cleared, and the week that ended stays readable until the retention sweep takes
 * it. "Reset" that erased would destroy the record the hall of fame is built from.
 *
 * ⚠️ AND THE SCHEDULER RUNS IN UTC. Without `->timezone('Asia/Qatar')` on the
 * schedule entry, "Sunday dawn" lands three hours after the student's week has
 * already rolled over.
 */
class CloseLeaderboardWeekJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(RollUpLeaderboards $rollUp, GamificationCalendar $calendar): void
    {
        // The week that has just ended — this runs after midnight on the Sunday
        // that opens the new one, so "last week" is the one being sealed.
        $closing = $calendar->weekKey($calendar->weekStart()->subDay());

        $rollUp->handle(LeaderboardPeriod::Week, $closing);

        // And the hall of fame, which accumulates across the term (FR-022).
        $rollUp->handle(LeaderboardPeriod::Term);
    }
}
