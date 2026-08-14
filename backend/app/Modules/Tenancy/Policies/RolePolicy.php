<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Policies;

use App\Models\User;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;

/**
 * Who may edit a role, and which roles.
 *
 * ⚠️ THE ROW-LEVEL HALF OF THE SCOPE. `TeamRoleScope` keeps another workspace's
 * roles out of the LIST; this keeps them out of the URL. A list filtered by a
 * query and a record fetched by id are two different questions, and the second
 * is the one an address bar asks.
 *
 * ⚠️ AND A PLATFORM ROLE IS NOT EDITABLE FROM HERE AT ALL — not by the owner,
 * not by the super admin. `super-admin` and `finance-admin` hold the permissions
 * that decide how much the platform may be owed and who may approve money; their
 * sets come from `RolePermissionMatrix`, in code, reviewed like code. A screen
 * that could widen `finance-admin` by one tick is a screen that can mint a second
 * super admin quietly. Delegation is done by naming a PERSON — `platform_staff` —
 * which is a different act with a different record.
 *
 * ⚠️ AND IT DELIBERATELY DOES NOT EXTEND `BasePolicy`, whose `before()` waves the
 * super admin past every refusal below it. Here the refusals are about WHICH ROW,
 * not about who — the platform roles are code-owned, and a super admin editing
 * them from a screen is the case this exists to prevent, not an exception to it.
 */
class RolePolicy
{
    /**
     * ⚠️ THE SUPER ADMIN IS NAMED HERE, and forgetting them cost a working
     * screen. Their authority is the `users.is_super_admin` COLUMN, not a spatie
     * role — nothing is assigned to them, so `can('roles.manage')` is false — and
     * this policy deliberately does not extend `BasePolicy`, whose `before()`
     * would have waved them through. The result was a platform admin being
     * bounced back to the login page by a screen that opened perfectly for a
     * teacher's owner.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin() || $user->can(Permissions::ROLES_MANAGE);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user) && $this->belongsToCurrentWorkspace($role);
    }

    /**
     * Creating a role is allowed; naming it after a platform role is not — and
     * THAT refusal is on the model, not here.
     *
     * `Role::creating` throws when a team-scoped role borrows a platform name,
     * because a policy answers "may this person", not "is this row coherent",
     * and only the model sees every writer. Written down because the reverse —
     * a name check described in a policy and implemented nowhere — is the shape
     * of guard this codebase has been burned by before.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->viewAny($user)
            && $this->belongsToCurrentWorkspace($role)
            && ! in_array($role->name, Roles::platformRoles(), true);
    }

    /**
     * Deleting a DEFAULT role is refused, and that is not tidiness.
     *
     * `SeedDefaultRoles` creates the five at workspace creation and never runs
     * again, so a deleted `teacher` is gone for good — and every member holding
     * it loses everything at once, silently, with no screen that shows why. A
     * role somebody invented is theirs to remove.
     */
    public function delete(User $user, Role $role): bool
    {
        return $this->update($user, $role)
            && ! in_array($role->name, Roles::workspaceRoles(), true);
    }

    /**
     * ⚠️ A ROW IS EDITED INSIDE A WORKSPACE, INCLUDING BY THE PLATFORM. A super
     * admin operating globally has no current workspace, so they may READ the
     * list and may not edit a row until they select one — which is the honest
     * answer rather than a convenient one: a role only means anything beside the
     * workspace it belongs to, and an edit made with no workspace in mind is an
     * edit to a row picked from a list of identical names.
     */
    private function belongsToCurrentWorkspace(Role $role): bool
    {
        $current = app(WorkspaceContext::class)->id();

        return $current !== null && (int) $role->getAttribute('team_id') === $current;
    }
}
