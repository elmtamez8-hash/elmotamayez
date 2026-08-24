<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Models\Announcement;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

/**
 * Edit a notice, and carry the edit to everyone already holding it (FR-047).
 *
 * ⚠️ THE NOTIFICATION ROWS ARE REWRITTEN, NOT LEFT BEHIND. `FR-047` says an edit
 * «must be reflected on the recipients», and for an announcement the recipient's
 * copy IS the notification: there is no announcements screen for a student, and
 * FR-044 makes the notification centre the delivery. So an edit that touched only
 * the `announcements` row would change what the teacher sees and nothing at all
 * of what the class reads — the correction visible to the one person who did not
 * need it.
 *
 * ⚠️ AND IT IS A BULK `update()`, WHICH IS SAFE HERE FOR THE REASON IT IS BANNED
 * ON THE LEDGER. Nothing model-booted is needed on an update — `HasUuid` fires on
 * insert — and there is no append-only guard on `notifications` to bypass. Row by
 * row it would be one query per recipient for a change every recipient shares.
 *
 * ⚠️ AND THE SCOPE AND THE URGENCY DO NOT MOVE AFTER PUBLISHING. The audience has
 * already been told; re-scoping would leave one group holding a message meant for
 * another and would make FR-046's two counters describe an audience that no longer
 * exists. Urgency is baked into the notification TYPE at send time, so changing it
 * afterwards would be a flag on the teacher's screen that means nothing anywhere
 * else — worse than refusing it.
 */
class UpdateAnnouncement extends Action
{
    use LogsActivity;

    public function handle(Announcement $announcement, string $body, ?bool $isUrgent = null): Announcement
    {
        $before = $announcement->body;

        $announcement->body = $body;

        if ($isUrgent !== null && $announcement->published_at === null) {
            $announcement->is_urgent = $isUrgent;
        }

        $announcement->save();

        if ($announcement->isLive()) {
            DB::table('notifications')
                ->where('source_type', Announcement::SOURCE_TYPE)
                ->where('source_id', $announcement->getKey())
                ->update(['body_ar' => $body, 'updated_at' => now()]);
        }

        // FR-047's second half: it is recorded. The subject is the announcement,
        // so the activity row reaches it through the ordinary polymorphic key
        // rather than through a description somebody has to parse.
        $this->logActivity('announcement.updated', $announcement, [
            'body_before' => $before,
        ]);

        return $announcement;
    }
}
