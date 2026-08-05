<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Clears out read notifications past the retention window (FR-017).
 *
 * Read only. An unread row is something the recipient has not seen yet, and
 * deleting it turns a delivered message into one that silently never arrived —
 * which is worse than an old feed.
 *
 * Deliveries follow via the cascade on notification_id, so the log shrinks with
 * the feed rather than outliving it as orphans.
 */
class PruneOldNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $cutoff = now()->subDays((int) config('notifications.retention_days'));

        // Chunked: a year of notifications is a large DELETE, and one statement
        // holding that many row locks stalls every insert behind it.
        do {
            $deleted = Notification::query()
                ->whereNotNull('read_at')
                ->where('created_at', '<', $cutoff)
                ->limit(1000)
                ->delete();
        } while ($deleted > 0);
    }
}
