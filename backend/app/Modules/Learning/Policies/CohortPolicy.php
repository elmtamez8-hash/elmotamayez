<?php

declare(strict_types=1);

namespace App\Modules\Learning\Policies;

use App\Models\User;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
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
 */
class CohortPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة مجموعات هذا الكورس.');
    }

    public function view(User $user, Cohort $cohort): Response
    {
        return $this->platformAssign($user) ?? $this->manage($user, $cohort);
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
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

        return $user->can(Permissions::COURSES_UPDATE)
            ? Response::allow()
            : Response::deny('لا تملك إدارة مجموعات هذا الكورس.');
    }
}
