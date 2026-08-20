<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Jobs;

use App\Modules\Gamification\Support\GamificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Retention on the derived table (FR-026 · NFR-010).
 *
 * Safe to be blunt about, because the table is DERIVED: anything deleted here can
 * be rebuilt from the ledger by one command. That is the same property that made
 * an indexed table an acceptable answer to a question that looked like it wanted
 * a sorted set.
 *
 * ⚠️ IT PRUNES BY `created_at`, NOT BY PARSING `period_key`. A period key is a
 * string this module composes; deriving a date from it in SQL would be a second
 * implementation of the calendar, in the one place nobody would think to look
 * when the week boundary moves. The `index(period_key)` still serves the delete
 * because rows of one period share a creation window.
 */
class PruneOldLeaderboardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(GamificationSettings $settings): int
    {
        $cutoff = now()->subDays($settings->leaderboardRetentionDays());

        return DB::table('leaderboard_entries')->where('created_at', '<', $cutoff)->delete();
    }
}
