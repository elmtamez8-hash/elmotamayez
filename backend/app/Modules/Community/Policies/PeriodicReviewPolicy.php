<?php

declare(strict_types=1);

namespace App\Modules\Community\Policies;

use App\Models\User;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

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

    /**
     * Publish ONE assessment — send it to the student and their guardian.
     *
     * ⛔ TWO RULES BESIDE THE PERMISSION (spec 010 · FR-005, 2026-09-30):
     *
     * 1. A confined assistant publishes only for a student of their own courses
     *    — `mayActOnStudent()`, the question the list and the write ask too.
     * 2. AN ASSISTANT PUBLISHES ONLY WHAT THEY WROTE. A draft is its author's
     *    judgement of a student's term, and publishing it sends that judgement
     *    to a guardian; an assistant who holds the permission sends their own,
     *    never the teacher's or another assistant's. The teacher and the owner
     *    publish any draft, as before.
     *    ⚠️ THIS APPLIES TO AN UNCONFINED ASSISTANT TOO — the one place this
     *    change narrows somebody the scope does not confine, because authorship
     *    is not a question of scope. `SubmitPeriodicReview` keeps the same rule
     *    for a REVISION, or it would be the way around this one: revising a
     *    draft makes the reviser its author.
     */
    public function publish(User $user, PeriodicReview $review): Response
    {
        if (! $this->manage($user)) {
            return Response::deny();
        }

        $assistants = app(AssistantScopeDirectory::class);
        $workspaceId = (int) $review->workspace_id;

        if (! $assistants->mayActOnStudent($user, $workspaceId, (int) $review->student_user_id)) {
            return Response::deny('هذا الطالب خارج نطاق عملك.');
        }

        if ($assistants->isAssistantIn($user, $workspaceId)
            && (int) $review->teacher_user_id !== (int) $user->getKey()) {
            return Response::deny('ينشر المساعد التقييمات التي كتبها بنفسه فقط.');
        }

        return Response::allow();
    }
}
