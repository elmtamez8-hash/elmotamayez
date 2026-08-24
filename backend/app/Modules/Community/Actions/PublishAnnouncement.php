<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Events\AnnouncementPublished;
use App\Modules\Community\Models\Announcement;
use App\Shared\Actions\Action;

/**
 * Make one notice visible to the students in its scope (FR-042).
 *
 * ⚠️ THE CLAIM IS A CONDITIONAL UPDATE, NOT A READ FOLLOWED BY A WRITE, and the
 * cost of getting it wrong is measured in families rather than in rows. Two taps
 * on a slow connection both read `published_at` as null, both write, and two
 * fan-outs start over three hundred students. The per-recipient diff inside
 * `FanOutAnnouncementJob` would then be racing itself rather than protecting
 * anybody — two runners can both read «not yet notified» for the same person.
 *
 * The seat idiom, the same one `captured_order_id` and `StructureVersion::claim()`
 * use, and never `lockForUpdate()`, a no-op on SQLite that would pass locally and
 * prove nothing about the MySQL this ships to.
 */
class PublishAnnouncement extends Action
{
    public function handle(Announcement $announcement): Announcement
    {
        $claimed = Announcement::query()
            ->withoutGlobalScopes()
            ->whereKey($announcement->getKey())
            ->whereNull('published_at')
            ->whereNull('hidden_at')
            ->update(['published_at' => now(), 'updated_at' => now()]);

        $fresh = $announcement->fresh() ?? $announcement;

        // Only the claimant announces. A loser returns the same published row —
        // their request succeeded, it simply was not the one that published.
        if ($claimed === 1) {
            event(new AnnouncementPublished($fresh));
        }

        return $fresh;
    }
}
