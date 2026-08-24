<?php

declare(strict_types=1);

namespace App\Modules\Community\Listeners;

use App\Modules\Community\Events\AnnouncementPublished;
use App\Modules\Community\Jobs\FanOutAnnouncementJob;

/**
 * Start the walk (FR-043).
 *
 * ⚠️ NOT `ShouldQueue`. The listener does one thing — dispatch a job — and queuing
 * that would put a job on a queue whose only work is to enqueue another one. The
 * work itself is queued, which is what the requirement is about; this line runs
 * inside the publish request and costs one insert.
 */
class FanOutAnnouncement
{
    public function handle(AnnouncementPublished $event): void
    {
        FanOutAnnouncementJob::dispatch((int) $event->announcement->getKey());
    }
}
