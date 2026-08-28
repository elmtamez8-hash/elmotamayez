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
