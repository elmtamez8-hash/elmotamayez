<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Models\Scopes\TeamRoleScope;
use App\Modules\Tenancy\Support\PlatformStaffDirectory;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use BackedEnum;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A role, with the one rule a permission screen must not be able to talk past.
 *
 * ⚠️ A WORKSPACE ROLE MAY NEVER HOLD A PLATFORM PERMISSION. Roles became
 * editable from `/admin` so an owner could grant their own staff what they need
 * — and the same tick box, unguarded, is how an owner grants THEMSELVES the
 * collection report, the credit ceiling, and the platform's half of the price. A
 * teacher deciding how much the platform may be owed is the exact thing
 * `RolePermissionMatrix` was built to prevent, and a screen that can undo it
 * makes the matrix a suggestion.
 *
 * ⚠️ AND THE GUARD IS ON THE MODEL, NOT ON THE FORM. A filtered picker is a
 * user interface: the request that follows it names permission ids, and nothing
 * stops a second one naming different ids. Both `givePermissionTo()` and
 * `syncPermissions()` are the doors every writer uses — the screen, the seeder,
 * a console command, a future importer — so the refusal sits where all of them
 * pass.
 *
 * Platform roles (`team_id === null`) are unaffected: they exist to hold exactly
 * these permissions, and their sets come from code, never from a screen.
 */
class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TeamRoleScope);

        /*
        | ⚠️ A WORKSPACE ROLE MAY NOT BORROW A PLATFORM ROLE'S NAME. Nothing
        | escalates if it does — `PlatformStaffDirectory` reads teamless rows
        | only — but `hasRole('finance-admin')` would then answer true for two
        | different authorities depending on a column nobody looks at, and the
        | next reader would have to know that before writing a condition.
        |
        | On the model rather than in the form, for the same reason the
        | permission refusal is: the screen shapes one request and not the next.
        */
        static::creating(function (self $role): void {
            if ($role->getAttribute('team_id') === null) {
                return;
            }

            if (in_array($role->name, Roles::platformRoles(), true)) {
                throw new DomainException(
                    'اسم «'.$role->name.'» محجوز لدور منصّة، ولا يُستعمل لدورٍ داخل مساحة عمل.'
                );
            }
        });
    }

    /**
     * Read roles belonging to no workspace, or to all of them.
     *
     * The one sanctioned bypass, and it has exactly one caller today:
     * {@see PlatformStaffDirectory} resolves the
     * TEAMLESS roles while a workspace team id is set, which is the ordinary
     * shape of a finance officer reading somebody's receipts.
     *
     * @param  Builder<static>  $builder
     * @return Builder<static>
     */
    public function scopeWithoutTeamScope(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope(TeamRoleScope::class);
    }

    /**
     * @param  mixed  ...$permissions
     */
    public function givePermissionTo(...$permissions): static
    {
        $this->refusePlatformPermissions($permissions);

        return parent::givePermissionTo(...$permissions);
    }

    /**
     * @param  mixed  ...$permissions
     */
    public function syncPermissions(...$permissions): static
    {
        $this->refusePlatformPermissions($permissions);

        return parent::syncPermissions(...$permissions);
    }

    /**
     * @param  array<array-key, mixed>  $permissions
     */
    private function refusePlatformPermissions(array $permissions): void
    {
        // A global role is the one that is SUPPOSED to hold them.
        if ($this->getAttribute('team_id') === null) {
            return;
        }

        $names = [];

        foreach (Arr::flatten($permissions) as $permission) {
            $names[] = match (true) {
                is_string($permission) => $permission,
                $permission instanceof BackedEnum => (string) $permission->value,
                $permission instanceof Permission => $permission->name,
                default => null,
            };
        }

        $forbidden = array_intersect(array_filter($names), RolePermissionMatrix::platformPermissions());

        if ($forbidden !== []) {
            throw new DomainException(
                'صلاحيات المنصّة لا تُمنح لدورٍ داخل مساحة عمل: '.implode('، ', $forbidden)
            );
        }
    }
}
