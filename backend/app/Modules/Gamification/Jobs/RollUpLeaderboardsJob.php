<?php

declare(strict_types=1);

namespace App\Modules\Gamification\Jobs;

use App\Modules\Gamification\Actions\RollUpLeaderboards;
use App\Modules\Gamification\Enums\LeaderboardPeriod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The hourly rebuild of the current period's boards.
 *
 * ⚠️ ON THE `maintenance` QUEUE, WHICH IS WATCHED FOR THE FIRST TIME AS OF SPEC
 * 009. It was named in Horizon's `defaults` and `environments` since 005 and
 * absent from `waits`, and a connection/queue pair absent from that list is not
 * watched at a default — it is not watched. A rollup that silently stops leaves
 * every student a stale board with nothing anywhere reporting a problem.
 */
class RollUpLeaderboardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly LeaderboardPeriod $period = LeaderboardPeriod::Week)
    {
        $this->onQueue('maintenance');
    }

    public function handle(RollUpLeaderboards $rollUp): void
    {
        $rollUp->handle($this->period);
    }
}
