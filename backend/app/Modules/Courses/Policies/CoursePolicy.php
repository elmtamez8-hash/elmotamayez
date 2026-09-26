<?php

declare(strict_types=1);

namespace App\Modules\Courses\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;

class CoursePolicy extends BasePolicy
{
    /** The refusal an assistant reads when they try to move a course's visibility. */
    public const VISIBILITY_REFUSAL = 'ظهور الكورس (عام أو خاص) يقرّره مدرّس الكورس وحده.';

    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::COURSES_VIEW)
            ? Response::allow()
            : Response::deny();
    }

    /*
    | ⛔ **فرعُ «منشور» فوقَ فحصِ المساحة، لأنّ المنشورَ عامٌّ بالتعريف.**
    |
    | `belongsToCurrentWorkspace()` لا يعترضُ على سياقٍ عدم، لكنّه يرفضُ حينَ
    | يُحَلُّ السياقُ إلى مساحةٍ أخرى — و`users.last_workspace_id` مختومٌ لكلِّ
    | طالبٍ أُضيفَ يوماً إلى مساحةِ عمل. فطالبٌ مختومٌ كانَ يُمنَعُ من كورسٍ
    | **منشورٍ** يراهُ في السوقِ ويقرأُ صفحتَه، لمجرَّدِ أنّ مدرِّسَه غيرُ مدرِّسِه.
    |
    | The workspace is a question about drafts, not about the published: the
    | draft branch below stays behind the check, and since 2026-09-26 it asks
    | COURSES_UPDATE or the author, never COURSES_VIEW (a student holds that).
    */
    public function view(User $user, Course $course): Response
    {
        if ($course->isPublished()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        /*
        | ⛔ **AN UNPUBLISHED COURSE IS FOR WHOEVER WRITES IT, NOT FOR WHOEVER MAY
        | LOOK.** This branch used to ask `COURSES_VIEW` — and the student role
        | holds it, so a student member of the workspace opened any DRAFT by uuid
        | at `GET /courses/{course}` and `/courses/{course}/sections`, the doors
        | `CourseController::index` stopped listing it on. Now: `COURSES_UPDATE`,
        | or the author — the pivot ROLE, never mere membership (docs/gotchas/
        | courses.md «THE AUTHOR IS THE PIVOT ROLE»), asked in the negative so an
        | unknown custom role falls toward refusal. `/learn/lessons/{uuid}` and
        | `IssuePlaybackGrant::mayWatch()` ask the same predicate.
        */
        if ($user->can(Permissions::COURSES_UPDATE) || $this->authors($user, $course)) {
            return Response::allow();
        }

        return Response::deny();
    }

    /** A non-student pivot role in the course's own workspace. */
    private function authors(User $user, Course $course): bool
    {
        return $user->workspaces()
            ->wherePivot('role', '!=', Roles::STUDENT)
            ->where('workspaces.id', $course->workspace_id)
            ->exists();
    }

    public function create(User $user): Response
    {
        return $user->can(Permissions::COURSES_CREATE)
            ? Response::allow()
            : Response::deny();
    }

    public function update(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $course))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Whether this course may be switched between public and private — the
     * teacher's decision alone (owner decision 2026-09-26).
     *
     * ⛔ ASKED BESIDE `update()`, NEVER INSTEAD OF IT: an assistant holding
     * `courses.update` still edits everything else on the course, and this is
     * the one field of it they may not move. {@see User::decidesCourseVisibilityIn()}
     */
    public function changeVisibility(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        return $user->decidesCourseVisibilityIn((int) $course->workspace_id)
            ? Response::allow()
            : Response::deny(self::VISIBILITY_REFUSAL);
    }

    /**
     * The same decision at CREATION, where there is no course yet — asked about
     * the workspace the creator is in. A course created by someone who may not
     * decide is created public, the default (owner decision 2026-09-26).
     */
    public function chooseVisibility(User $user): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        return $workspaceId !== null && $user->decidesCourseVisibilityIn($workspaceId)
            ? Response::allow()
            : Response::deny(self::VISIBILITY_REFUSAL);
    }

    public function delete(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::COURSES_DELETE)
            ? Response::allow()
            : Response::deny();
    }

    public function publish(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::COURSES_PUBLISH)
            ? Response::allow()
            : Response::deny();
    }

    public function manageLessons(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $course))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::LESSONS_MANAGE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Spec 010 · FR-005 — an assistant confined to a set of courses works on those.
     *
     * ⚠️ A SECOND QUESTION, ASKED BESIDE THE PERMISSION AND NEVER INSTEAD OF IT.
     * The permission answers «may this role ever edit content»; this answers «on
     * this course». It returns allow for everybody who is not an assistant here —
     * a teacher, an owner, a super admin — because none of them is confined by an
     * assignment, so the whole of it is a no-op on every workspace with no team.
     */
    private function withinAssistantScope(User $user, Course $course): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            (int) $course->workspace_id,
            (int) $course->getKey(),
        )
            ? Response::allow()
            : Response::deny('هذا الكورس خارج نطاق عملك.');
    }
}
