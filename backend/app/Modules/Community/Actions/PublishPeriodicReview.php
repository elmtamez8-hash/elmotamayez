<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Events\PeriodicReviewPublished;
use App\Modules\Community\Models\PeriodicReview;
use App\Shared\Actions\Action;

/**
 * Make one assessment visible to its student — and to their guardian (FR-029).
 *
 * ⚠️ THE CLAIM IS A CONDITIONAL UPDATE, NOT A READ FOLLOWED BY A WRITE. Two taps
 * on a slow connection both read `published_at` as null, both write, and the
 * guardian gets two WhatsApp messages — billed twice — about one assessment. The
 * seat idiom, the same one `captured_order_id` and `StructureVersion::claim()` use,
 * and never `lockForUpdate()`, which is a no-op on SQLite and would make the test
 * pass locally while proving nothing about MySQL.
 *
 * The row is refetched after the claim rather than stamped in memory: the listener
 * reads `published_at` and an in-memory value that never touched the database is
 * the difference between «published» and «we think we published».
 */
class PublishPeriodicReview extends Action
{
    public function handle(PeriodicReview $review): PeriodicReview
    {
        $claimed = PeriodicReview::query()
            ->withoutGlobalScopes()
            ->whereKey($review->getKey())
            ->whereNull('published_at')
            ->update(['published_at' => now(), 'updated_at' => now()]);

        $fresh = $review->fresh() ?? $review;

        // Only the claimant announces. A loser returns the same published row —
        // the caller's request succeeded, it simply was not the one that published.
        if ($claimed === 1) {
            event(new PeriodicReviewPublished($fresh));
        }

        return $fresh;
    }
}
