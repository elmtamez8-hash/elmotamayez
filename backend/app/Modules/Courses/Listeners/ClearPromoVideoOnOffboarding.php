<?php

declare(strict_types=1);

namespace App\Modules\Courses\Listeners;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Courses\Models\Course;

/**
 * A departing teacher's promo videos stop being shown (018 · FR-012).
 *
 * ⚠️ WE CLEAR A LINK, NOT A VIDEO. The video lives on the teacher's own
 * channel and is theirs; what ends is our showing it on a page under our name
 * after the relationship has ended.
 *
 * ⚠️ AND THIS IS A LISTENER, NOT A WRITE FROM `Compliance`. Constitution III:
 * cross-module integration goes through a domain event, never a reach into
 * another module's tables.
 *
 * ⚠️ WHAT IT CHANGES IS NOT WHAT A VISITOR SEES TODAY.
 * `Marketplace\Listeners\UnlistDepartedTeacher` hangs off the same event and
 * already hides every course of a departing teacher, so clearing the id moves
 * nothing on the public path right now. It matters if the teacher is ever
 * relisted: without it an approval granted before they left comes back with
 * them, unreviewed, over a video nobody has looked at since.
 *
 * ⚠️ AND THE UPDATE IS EXPLICITLY WORKSPACE-FILTERED rather than leaning on the
 * global scope. This runs on a queue worker where `WorkspaceContext` resolves to
 * whatever the previous job left it at; naming the workspace makes the statement
 * true regardless. `WorkspaceContext::set()` is forbidden outside an HTTP cycle
 * for exactly that reason, and `forWorkspace()` is unnecessary here because the
 * predicate carries the workspace itself.
 */
class ClearPromoVideoOnOffboarding
{
    public function handle(TeacherOffboardingCompleted $event): void
    {
        Course::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $event->offboarding->workspace_id)
            ->where('created_by', $event->offboarding->teacher_user_id)
            ->whereNotNull('promo_video_id')
            ->update([
                'promo_video_id' => null,
                'promo_video_status' => Course::PROMO_NONE,
                'promo_video_reviewed_at' => null,
                'promo_video_reviewed_by' => null,
            ]);
    }
}
