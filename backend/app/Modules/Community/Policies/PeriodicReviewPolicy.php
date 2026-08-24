<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;

/**
 * Who may write and publish an assessment.
 *
 * ⚠️ THERE IS NO `view()` HERE, AND ITS ABSENCE IS DELIBERATE. Nothing in the HTTP
 * surface fetches a single assessment by uuid — the teacher reads a list of their
 * own and the student reads a list of theirs — so a row-level `view()` would be a
 * method NOTHING exercises, which in this repository is a method nobody has ever
 * tested and whose default is whatever the first person wrote. It would also be a
 * second spelling of a rule `StudentReviewController` already enforces on the
 * query, and a list filtered by a query and a record fetched by id are two
 * different questions that must not be allowed to answer each other.
 *
 * The reading rule lives where it runs: an explicit `student_user_id` filter plus
 * `whereNotNull('published_at')`, and `childrenOf(..., Results)` for a guardian —
 * all in that controller, all measured by `PeriodicReviewTest`.
 *
 * ⚠️ AND `manage` IS NOT `PROGRESS_VIEW_STUDENT` REUSED. That one answers «may this
 * role ever LOOK»; writing an assessment that reaches the student's guardian is a
 * different power over the same people — the SESSIONS_VIEW/ATTENDANCE_VIEW split,
 * and CHAT_REPLY/CHAT_MODERATE in this very spec.
 */
class PeriodicReviewPolicy
{
    public function manage(User $user): bool
    {
        return $user->can(Permissions::REVIEWS_PERIODIC_MANAGE);
    }
}
