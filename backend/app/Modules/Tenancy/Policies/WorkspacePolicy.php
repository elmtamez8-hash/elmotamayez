<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

class WorkspacePolicy extends BasePolicy
{
    /*
    | ⚠️ قائمةُ مساحاتِ العملِ سؤالٌ عن المنصّةِ كلِّها لا عن مساحةٍ بعينها، فلا
    | فرعَ عضويّةٍ يُجيبُ عنه: `view()` أدناه تسألُ «هل أنتَ عضوٌ في هذه؟» وهو
    | سؤالٌ لا معنى له قبلَ أن يُختارَ صفّ.
    |
    | وغيابُها ليس حياداً: `Resource::canViewAny()` تُفوِّضُ إلى السياسة، وسياسةٌ
    | بلا دالّةٍ بهذا الاسمِ تسقطُ إلى `Response::allow()` — أي تُسلِّمُ كلَّ من يفتحُ
    | اللوحةِ قائمةَ كلِّ مدرّسٍ على المنصّةِ ومالكَه وعددَ أعضائِه.
    */
    public function viewAny(User $user): Response
    {
        return $user->isSuperAdmin()
            ? Response::allow()
            : Response::deny('Only a platform administrator may list workspaces.');
    }

    /**
     * Spec 025 · FR-007 — nobody makes a workspace by hand any more.
     *
     * ⚠️ THIS IS THE ONE SPELLING, read by `CreateWorkspaceRequest::authorize()`
     * and by the Filament resource. Two spellings of «may you create one» put one
     * answer on the screen and another at the door, which is a defect this
     * repository has paid for from both sides.
     *
     * The permission sits in no tenant role, so a teacher who already owns their
     * implicit workspace is refused HERE — a 403, not the 422 of FR-008, because
     * they never reach the second-workspace check at all. Super-admin is waved
     * past by {@see BasePolicy::before()}, which is what keeps FR-009 possible.
     */
    public function create(User $user): Response
    {
        return $user->can(Permissions::WORKSPACES_CREATE)
            ? Response::allow()
            // ⚠️ «مكان العمل» لا «مساحة العمل». رسالةُ الرفضِ نصٌّ يقرؤه إنسان،
            // وSC-002 تشمل رسائلَ الاستثناءات ونصوصَ `lang/ar` في الخلفيّة.
            : Response::deny('مكان العمل يُنشأ مع الحساب، ولا يُنشأ يدويًا.');
    }

    public function view(User $user, Workspace $workspace): Response
    {
        return $workspace->members()->where('user_id', $user->getKey())->exists()
            ? Response::allow()
            : Response::deny('You do not belong to this workspace.');
    }

    public function update(User $user, Workspace $workspace): Response
    {
        return $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny('Only the workspace owner can update settings.');
    }

    public function delete(User $user, Workspace $workspace): Response
    {
        return $workspace->isOwnedBy($user)
            ? Response::allow()
            : Response::deny('Only the workspace owner can delete the workspace.');
    }

    public function manageMembers(User $user, Workspace $workspace): Response
    {
        if (! $workspace->members()->where('user_id', $user->getKey())->exists()) {
            return Response::deny('You do not belong to this workspace.');
        }

        return $user->can(Permissions::MEMBERS_INVITE)
            ? Response::allow()
            : Response::deny('You are not authorized to manage workspace members.');
    }

    /**
     * Switch how this workspace collects (spec 006, FR-011).
     *
     * ⚠️ THE ONLY CALLER THAT REACHES THE `allow` BRANCH TODAY NEVER RUNS THIS
     * METHOD. `BILLING_SETTINGS_MANAGE` moved to platform-only after 006 shipped
     * — since 014 pays the teacher from delivery, deferred collection is the
     * PLATFORM lending money — so it now sits in `$all` alone, and super-admin is
     * waved past every policy by {@see BasePolicy::before()}. What is left below
     * is therefore a refusal for everyone else, which is correct.
     *
     * The membership check stays because the permission may yet be delegated: a
     * grant made in one workspace must not carry into another, since `can()`
     * answers for the CURRENT team rather than for `$workspace`. That is the same
     * reason it precedes the permission in manageMembers.
     *
     * ⚠️ AND IT IS A TRAP FOR THE PLATFORM-STAFF MECHANISM WHEN IT IS CHOSEN. A
     * finance officer editing a teacher's collection mode is by definition not a
     * member of that teacher's workspace, so granting them this permission
     * without touching this line gives them an unexplainable 403. Whoever picks
     * the mechanism reads this method first.
     */
    /**
     * Move an existing member between roles.
     *
     * ⚠️ SEPARATE FROM {@see manageMembers()}, AND THAT IS THE WHOLE POINT OF
     * `members.update` EXISTING. Inviting adds somebody at a role the inviter
     * chose; this changes what somebody ALREADY INSIDE may do — an assistant
     * becoming a teacher, or a teacher becoming a student. An owner may delegate
     * the first without delegating the second, and until now the second was not a
     * capability the product had at all.
     *
     * ⚠️ AND ITS ABSENCE WAS A SILENT DENY, WHICH IS THE RIGHT DIRECTION AND AN
     * EASY ONE TO MISREAD: a policy with no method for an ability answers `false`
     * with no error anywhere, so the route was answering 403 to an owner holding
     * the permission while `members.invite` beside it worked. Laravel's guesser
     * fails OPEN into «no policy applies» only when there is no policy at all;
     * with one bound, a missing method is a refusal.
     */
    public function updateMembers(User $user, Workspace $workspace): Response
    {
        if (! $workspace->members()->where('user_id', $user->getKey())->exists()) {
            return Response::deny('You do not belong to this workspace.');
        }

        return $user->can(Permissions::MEMBERS_UPDATE)
            ? Response::allow()
            : Response::deny('You are not authorized to change a member\'s role.');
    }

    public function manageBillingSettings(User $user, Workspace $workspace): Response
    {
        if (! $workspace->members()->where('user_id', $user->getKey())->exists()) {
            return Response::deny('You do not belong to this workspace.');
        }

        return $user->can(Permissions::BILLING_SETTINGS_MANAGE)
            ? Response::allow()
            : Response::deny('You are not authorized to change billing settings.');
    }
}
