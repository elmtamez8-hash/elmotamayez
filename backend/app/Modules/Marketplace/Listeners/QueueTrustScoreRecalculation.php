<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Listeners;

use App\Modules\Marketplace\Events\ComplaintConfirmed;
use App\Modules\Marketplace\Events\ReviewModerated;
use App\Modules\Marketplace\Events\ReviewSubmitted;
use App\Modules\Marketplace\Jobs\RecalculateTrustScoreJob;

/**
 * Every event that can change a trust score ends here, and the recalculation runs
 * once from one place. The alternative — each Action updating counters inline —
 * is three copies of the same arithmetic that drift the first time one changes.
 */
class QueueTrustScoreRecalculation
{
    public function handle(ReviewSubmitted|ReviewModerated|ComplaintConfirmed $event): void
    {
        RecalculateTrustScoreJob::dispatch($event->teacherProfileId());
    }
}
