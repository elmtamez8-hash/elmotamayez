<?php

declare(strict_types=1);

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Models\PlaybackGrant;
use App\Modules\Media\Support\PlaybackGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deletes grants that expired long enough ago to be of no further use.
 *
 * Not a security measure — an expired grant is already refused by
 * {@see PlaybackGuard}, and deleting the row changes
 * nothing about that. It is housekeeping: one row per lesson opened per viewer
 * per session, which is the fastest-growing table in the module.
 *
 * The week of grace is deliberate. A support question — "why did the video stop
 * for this student on Tuesday?" — is answered from these rows, and deleting them
 * the same night would make the module's most common complaint unanswerable.
 *
 * ponytail: a single unbounded DELETE. At millions of rows the upgrade is
 * monthly range partitioning on `expires_at` and dropping the partition, which
 * turns this into a metadata operation. Chunking would only move the same amount
 * of work into more statements.
 */
class PruneExpiredGrantsJob implements ShouldQueue
{
    use Queueable;

    private const KEEP_DAYS = 7;

    public function handle(): void
    {
        $deleted = PlaybackGrant::query()
            ->where('expires_at', '<', now()->subDays(self::KEEP_DAYS))
            ->delete();

        if ($deleted > 0) {
            Log::info('Pruned expired playback grants.', ['deleted' => $deleted]);
        }
    }
}
