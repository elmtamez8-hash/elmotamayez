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
 * In batches of `$batchSize`, never one unbounded DELETE. The total work is the
 * same, but a single statement over a night's worth of the fastest-growing table
 * holds its locks and its undo log for the whole walk — every grant a student
 * opens meanwhile waits behind it — and one that outlives the worker's timeout
 * is killed and rolled back whole, so the next night starts from the same pile
 * plus a day. Each batch commits on its own: a killed run keeps what it did.
 * `->limit()->delete()` is `DELETE … LIMIT` on MySQL and a `rowid IN (… LIMIT)`
 * rewrite on SQLite, both from Laravel's grammar.
 *
 * ponytail: at millions of rows a day the upgrade is monthly range partitioning
 * on `expires_at` and dropping the partition, a metadata operation.
 */
class PruneExpiredGrantsJob implements ShouldQueue
{
    use Queueable;

    private const KEEP_DAYS = 7;

    public function __construct(
        private readonly int $batchSize = 5000,
    ) {}

    public function handle(): void
    {
        // Fixed once, so every batch deletes against the same boundary.
        $cutoff = now()->subDays(self::KEEP_DAYS);
        $deleted = 0;

        do {
            $batch = PlaybackGrant::query()
                ->where('expires_at', '<', $cutoff)
                ->limit($this->batchSize)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        if ($deleted > 0) {
            Log::info('Pruned expired playback grants.', ['deleted' => $deleted]);
        }
    }
}
