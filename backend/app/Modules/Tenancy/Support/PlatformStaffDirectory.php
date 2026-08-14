<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Models\User;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Models\Role;

/**
 * What a person may do for the platform, in every workspace at once.
 *
 * ⚠️ THIS EXISTS BECAUSE spatie CANNOT ATTACH A TEAMLESS ROLE. `model_has_roles`
 * has `team_id` inside its primary key and NOT NULL, so `finance-admin` — seeded,
 * correct, and belonging to no workspace — could never be given to anybody. The
 * standing lives in `platform_staff`; the PERMISSIONS behind it still come from
 * the spatie role, so there is one place a permission set is written and one
 * place it is read.
 *
 * ⚠️ MEMOISED PER REQUEST, because a `Gate::before` runs on EVERY ability check
 * — a Filament page renders dozens — and a query inside one is a query per
 * checkbox. The memo is keyed by user id and lives as long as the container
 * does, which for a queued job is one job.
 */
class PlatformStaffDirectory
{
    /** @var array<int, list<string>> */
    private array $permissions = [];

    /** @var array<int, list<string>> */
    private array $roles = [];

    /**
     * Every permission this person holds platform-wide. Empty for almost everyone.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user): array
    {
        $id = (int) $user->getKey();

        if (array_key_exists($id, $this->permissions)) {
            return $this->permissions[$id];
        }

        $roles = $this->rolesFor($user);

        if ($roles === []) {
            return $this->permissions[$id] = [];
        }

        // `whereNull('team_id')` explicitly: the platform roles are the teamless
        // ones, and a workspace's own `finance-admin` — if anybody ever seeds one
        // — must not lend its permissions to every other workspace.
        $names = Role::query()
            // ⚠️ The declared bypass. A workspace team id is set on almost every
            // request an officer makes, and `TeamRoleScope` would then filter
            // these teamless rows out entirely — the officer would hold nothing,
            // everywhere, silently.
            ->withoutTeamScope()
            ->whereNull('team_id')
            ->whereIn('name', $roles)
            ->with('permissions')
            ->get()
            ->flatMap(fn (Role $role): array => $role->permissions->pluck('name')->all())
            ->unique()
            ->values()
            ->all();

        /** @var list<string> $names */
        return $this->permissions[$id] = $names;
    }

    /**
     * The platform roles this person holds by name.
     *
     * @return list<string>
     */
    public function rolesFor(User $user): array
    {
        $id = (int) $user->getKey();

        if (array_key_exists($id, $this->roles)) {
            return $this->roles[$id];
        }

        /** @var list<string> $names */
        $names = PlatformStaff::query()
            ->where('user_id', $id)
            ->pluck('role')
            ->all();

        return $this->roles[$id] = $names;
    }

    public function holds(User $user, string $role): bool
    {
        return in_array($role, $this->rolesFor($user), true);
    }

    /**
     * Drop what was remembered about one person — or about everybody.
     *
     * Called after a standing is granted or revoked. Without it, the request that
     * performs the grant goes on answering from the memo it filled before the
     * grant existed, and a Filament page rendered right afterwards shows the old
     * answer to the person who just changed it.
     */
    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->permissions = [];
            $this->roles = [];

            return;
        }

        $id = (int) $user->getKey();

        unset($this->permissions[$id], $this->roles[$id]);
    }
}
