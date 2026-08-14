<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Roles belong to a workspace, and a query for them must say so.
 *
 * ⚠️ WHY THIS APPEARED THE DAY ROLES BECAME EDITABLE. `roles` carries a
 * `team_id` and nothing enforced it: `Role::query()->get()` returned every
 * workspace's rows, which was harmless while the only readers were the seeder
 * and spatie's own pivot lookups. The role SCREEN reads the table directly, and
 * `/admin` is reachable by every teacher — so an unscoped list is one teacher
 * holding another teacher's roles, with an edit button beside each.
 *
 * ⚠️ IT KEYS ON spatie's TEAM ID, NOT ON `WorkspaceContext`, and the two are the
 * same number by construction — `EnsureCurrentWorkspace` pushes one into the
 * other. Reading the registrar is what makes this agree with every permission
 * check happening in the same request; reading the context instead would be a
 * second source that disagrees during the one operation that switches workspaces.
 *
 * ⚠️ AND IT IS INERT WHEN THE TEAM ID IS NULL — the platform context, which is
 * the super admin operating globally and the seeder building reference data. The
 * same early return `WorkspaceScope` makes, for the same reason and with the
 * same hazard: null means "no tenant", never "no rows".
 *
 * @implements Scope<Model>
 */
class TeamRoleScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /*
        | ⚠️ PLATFORM ROLES ARE OUTSIDE THIS QUERY ALTOGETHER, whatever the team
        | id is — and that is what makes the role screen coherent rather than
        | merely filtered. `super-admin` and `finance-admin` have no `team_id`,
        | so the earlier "filter only when a team is set" version showed them to
        | any super admin operating globally, with an edit button beside each.
        | `RolePolicy` refuses that edit and Shield's own edit page does not
        | consult it, so the row simply must not be in the screen's world.
        |
        | Their permission sets come from `RolePermissionMatrix`, reviewed like
        | code, and the readers that legitimately need them — the seeder and
        | `PlatformStaffDirectory` — say `withoutTeamScope()` out loud.
        */
        $builder->whereNotNull($model->getTable().'.team_id');

        $teamId = getPermissionsTeamId();

        if ($teamId === null) {
            return;
        }

        $builder->where($model->getTable().'.team_id', $teamId);
    }
}
