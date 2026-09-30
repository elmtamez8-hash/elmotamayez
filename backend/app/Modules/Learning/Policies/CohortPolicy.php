<?php

declare(strict_types=1);

namespace App\Modules\Learning\Policies;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

/**
 * Who may run a group.
 *
 * ⚠️ ON `COURSES_UPDATE`, WITH NO NEW PERMISSION INVENTED. A group is a run of a
 * course, and whoever may edit the course may schedule its runs. A new
 * permission would mean a seeder row and a backfill migration for every workspace
 * that already exists — `SeedDefaultRoles` runs once at creation and never comes
 * back — in exchange for no delegation anybody asked for.
 *
 * ⚠️ AND THIS POLICY IS REGISTERED EXPLICITLY IN THE PROVIDER. Laravel's guesser
 * fails OPEN into "no policy applies", and it fails exactly when one policy
 * serves two models — the shape this repository keeps choosing, and the shape
 * `taxonomy.manage` shipped guarding nothing under.
 *
 * There is deliberately no `delete()`: FR-035 has no delete to authorise, and a
 * method that only ever denies is a method somebody eventually "fixes".
 *
 * ⚠️ AND A CONFINED ASSISTANT RUNS THE GROUPS OF THEIR OWN COURSES ONLY (spec 010
 * · FR-005, audit 2026-09-30). `courses.update` says «this role may run groups»,
 * never «of this course»: until the fix an assistant confined to one course read
 * every other course's roll, history and transfer queue, and added, removed,
 * renamed and archived there. So every door asks the scope BESIDE the
 * permission — the course-keyed ones (`viewAny`, `create`) are handed the
 * route's course, the row-keyed ones read the group's own. `cohorts.assign`
 * stays above it: a platform officer is nobody's assistant.
 */
class CohortPolicy extends BasePolicy
{
    /**
     * ⚠️ THE COURSE IS REQUIRED. Every list of groups is one course's list, and a
     * `viewAny` that could be asked without one would be the door the scope
     * cannot see — call it as `authorize('viewAny', [Cohort::class, $course])`.
     */
    public function viewAny(User $user, Course $course): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($course))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, (int) $course->workspace_id, (int) $course->getKey()))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة مجموعات هذا الكورس.');
    }

    public function view(User $user, Cohort $cohort): Response
    {
        return $this->platformAssign($user) ?? $this->manage($user, $cohort);
    }

    public function create(User $user, Course $course): Response
    {
        return $this->viewAny($user, $course);
    }

    public function update(User $user, Cohort $cohort): Response
    {
        return $this->manage($user, $cohort);
    }

    public function archive(User $user, Cohort $cohort): Response
    {
        return $this->manage($user, $cohort);
    }

    /** Adding somebody by hand, taking somebody out, reading the roll. */
    public function manageMembers(User $user, Cohort $cohort): Response
    {
        return $this->platformAssign($user) ?? $this->manage($user, $cohort);
    }

    /**
     * الإدارةُ تُسنِدُ في كلِّ مساحةٍ (٠٣٤ · FR-001) — **فوقَ سؤالِ المساحة**.
     *
     * ⚠️ **سياقٌ يُحَلُّ ولا يُطابِقُ رفضٌ كذلك، وهذه ثانيةُ طبقاتِ ٠٢٤ الخمس.**
     * `WorkspaceContext::id()` يرجعُ إلى `users.last_workspace_id` لكلِّ مستخدمٍ
     * **بمن فيهم موظَّفُ المنصّة** — فموظَّفٌ يملكُ مساحةَ عملٍ (وهو حالٌ عاديّ:
     * مالكُ المنصّةِ يدرّسُ أيضاً) كانَ سيُرَدُّ بـ٤٠٣ عن كلِّ مجموعةٍ خارجَها،
     * على الشاشةِ التي كلُّ غرضِها العملُ عبرَ المساحاتِ كلِّها.
     *
     * ⚠️ **وعلى `view` و`manageMembers` وحدَهما.** وضعُ الفرعِ في `manage()`
     * يمنحُ حاملَ `cohorts.assign` **`update` و`archive`** معه — أي إعادةَ تسميةِ
     * مجموعةِ مدرّسٍ وأرشفتَها. الصلاحيّةُ تقولُ «أسنِدْ»، لا «أدِرْ».
     *
     * ⚠️ **و`null` لا `deny()`**: عدمُ حملِ الصلاحيّةِ ليسَ رفضاً هنا — المدرّسُ
     * صاحبُ المجموعةِ يمرُّ من الفرعِ الذي تحتَه. (سابقةُ الشكلِ:
     * `OrderPolicy::platformReads()`.)
     */
    private function platformAssign(User $user): ?Response
    {
        return $user->can(Permissions::COHORTS_ASSIGN) ? Response::allow() : null;
    }

    private function manage(User $user, Cohort $cohort): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($cohort))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, (int) $cohort->workspace_id, (int) $cohort->course_id))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة مجموعات هذا الكورس.');
    }

    /**
     * Spec 010 · FR-005 — asked beside the permission, never instead of it; a
     * no-op for everybody who is not a confined assistant in this workspace.
     */
    private function withinAssistantScope(User $user, int $workspaceId, int $courseId): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse($user, $workspaceId, $courseId)
            ? Response::allow()
            : Response::deny('هذا الكورس خارج نطاق عملك.');
    }
}
