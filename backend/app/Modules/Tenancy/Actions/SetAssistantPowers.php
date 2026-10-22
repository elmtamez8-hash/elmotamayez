<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\AssistantPowers;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

/**
 * Open or close each of {@see AssistantPowers::GROUPS} on one workspace's
 * assistant role.
 *
 * ⚠️ `givePermissionTo` / `revokePermissionTo` per group, NEVER
 * `syncPermissions`: the role also carries the default assistant permissions and
 * whatever a super admin ticked on it from `/admin/roles`, and a sync would strip
 * both in silence. Both calls clear spatie's cache.
 */
final class SetAssistantPowers extends Action
{
    /** @param list<string> $enabled group keys to open; every other group closes */
    public function handle(Role $role, array $enabled): void
    {
        DB::transaction(function () use ($role, $enabled): void {
            foreach (AssistantPowers::GROUPS as $group => $permissions) {
                if (in_array($group, $enabled, true)) {
                    $role->givePermissionTo($permissions);

                    continue;
                }

                foreach ($permissions as $permission) {
                    if ($role->hasPermissionTo($permission)) {
                        $role->revokePermissionTo($permission);
                    }
                }
            }
        });
    }

    /** @return array<string, bool> */
    public static function read(Role $role): array
    {
        $held = $role->permissions()->pluck('name')->all();

        return array_map(
            fn (array $permissions): bool => array_diff($permissions, $held) === [],
            AssistantPowers::GROUPS,
        );
    }
}
