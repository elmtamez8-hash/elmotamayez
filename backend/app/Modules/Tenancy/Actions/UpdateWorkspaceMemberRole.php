<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\LogsActivity;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Move an existing member from one role to another.
 *
 * ⛔ **`members.update` WAS DECLARED, SEEDED, OFFERED ON THE ROLES SCREEN AS
 * «تعديل — الأعضاء», AND READ BY NOTHING.** There was no PATCH on a member, no
 * relation manager in the panel, and no button anywhere: the only way to change
 * an assistant's role was to REMOVE them and invite them again, which costs them
 * their membership record and puts a fresh invitation in their inbox for a job
 * they already had. A permission that names a capability the product does not
 * have is worse than a missing one — it ends the question for whoever reads the
 * screen.
 *
 * ⚠️ THE ROLE LIVES IN TWO PLACES AND BOTH MOVE HERE. `workspace_members.role`
 * is what the members list reads; spatie's `model_has_roles` (scoped by
 * `team_id`) is what every `can()` in the product reads. `AcceptInvitation`
 * writes both when somebody joins, so changing one of them here would leave a
 * member the screen calls a teacher and the server treats as a student —
 * silently, and only on the permissions half.
 *
 * ⚠️ AND PROMOTION APPLIES THE TWO-FACTOR MANDATE, for the reason
 * `AcceptInvitation` applies it: teacher and assistant reach other people's
 * payments and records, and a student does not. Promoting without it is the same
 * grant through a different door — the shape this repository keeps paying for.
 * Demotion deliberately does NOT lift it: the account has already been inside,
 * and removing a security requirement from somebody who has seen the data is a
 * downgrade nobody asked for.
 *
 * ⚠️ `forWorkspace()`, NEVER `set()`. The caller may be a platform officer whose
 * own context is another workspace entirely, and `WorkspaceContext` caches its
 * resolution for the rest of the request — `AcceptInvitation` can use `set()`
 * because the person accepting is joining that workspace and stays in it, which
 * is not true of an owner editing a member.
 */
class UpdateWorkspaceMemberRole extends Action
{
    use LogsActivity;

    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Workspace $workspace, User $member, string $role): void
    {
        /*
        | ⚠️ THE OWNER IS NOT A ROW TO EDIT. `workspaces.owner_user_id` is what
        | `isOwnedBy()` reads and no role change touches it, so demoting the owner
        | would leave the person who owns the workspace holding a student's
        | permissions inside it — locked out of their own product with nothing on
        | any screen able to put it back. `removeMember` refuses them for the same
        | reason one method away.
        */
        if ($workspace->isOwnedBy($member)) {
            throw new DomainException('لا يمكن تغيير دور المالك.');
        }

        $current = $workspace->members()
            ->where('user_id', $member->getKey())
            ->first()?->getRelationValue('pivot')?->getAttribute('role');

        if ($current === null) {
            throw new DomainException('هذا الحساب ليس ضمن فريقك.');
        }

        if ($current === $role) {
            return;
        }

        DB::transaction(function () use ($workspace, $member, $role, $current): void {
            $workspace->members()->updateExistingPivot($member->getKey(), ['role' => $role]);

            /*
            | Delete then assign, the spelling `RemoveMember` already uses: spatie
            | puts `team_id` inside the primary key of `model_has_roles`, and
            | `syncRoles()` reaches only the roles visible under the CURRENT team
            | id — so a member carrying a role in another workspace would have it
            | swept away by a sync and left behind by nothing else.
            */
            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $member->getKey())
                ->where('team_id', $workspace->getKey())
                ->delete();

            $this->context->forWorkspace($workspace, function () use ($member, $role): void {
                // The relation was loaded under the old rows; assigning without
                // clearing it re-saves what was just deleted.
                $member->unsetRelation('roles');
                $member->assignRole($role);
            });

            if (TwoFactorMandate::isPrivileged($role)) {
                TwoFactorMandate::applyTo($member);
            }

            $this->logActivity('member role changed', $workspace, [
                'member_email' => $member->email,
                'from' => $current,
                'to' => $role,
            ]);
        });
    }
}
