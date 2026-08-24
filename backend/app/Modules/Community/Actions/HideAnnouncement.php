<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Models\Announcement;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

/**
 * Retract a notice, and take it back off every screen holding it (FR-047).
 *
 * ⚠️ THE NOTIFICATIONS ARE DELETED, NOT LEFT AS ORPHANS. The recipient's copy IS
 * the announcement — there is no student announcements screen — so a hide that
 * touched only the `announcements` row would remove it from the one person who
 * retracted it and leave three hundred students reading a lesson time that has
 * been withdrawn.
 *
 * ⚠️ AND THAT IS ALSO WHY `FR-046` FORBIDS STORING THE COUNT. Deleting these rows
 * LOWERS «how many were told» to zero, which is the honest answer about a message
 * nobody now holds — a stored counter would keep reporting three hundred
 * recipients of a notice that no longer exists anywhere.
 *
 * ⚠️ AND THE FAN-OUT STOPS WITHIN ONE CHUNK. `hidden_at` is claimed BEFORE the
 * rows are deleted, and `FanOutAnnouncementJob` re-reads the announcement at the
 * top of every pass — reversed, the job's next chunk would write fresh
 * notifications behind the delete, for a message that had already been retracted.
 */
class HideAnnouncement extends Action
{
    use LogsActivity;

    public function handle(Announcement $announcement): Announcement
    {
        $claimed = Announcement::query()
            ->withoutGlobalScopes()
            ->whereKey($announcement->getKey())
            ->whereNull('hidden_at')
            ->update(['hidden_at' => now(), 'updated_at' => now()]);

        $fresh = $announcement->fresh() ?? $announcement;

        if ($claimed !== 1) {
            return $fresh;
        }

        DB::table('notifications')
            ->where('source_type', Announcement::SOURCE_TYPE)
            ->where('source_id', $announcement->getKey())
            ->delete();

        $this->logActivity('announcement.hidden', $fresh);

        return $fresh;
    }
}
