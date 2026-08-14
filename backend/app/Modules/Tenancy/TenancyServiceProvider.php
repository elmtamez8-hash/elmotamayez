<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Models\User;
use App\Modules\Tenancy\Events\WorkspaceCreated;
use App\Modules\Tenancy\Listeners\SeedDefaultRoles;
use App\Modules\Tenancy\Models\PlatformStaff;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Policies\PlatformStaffPolicy;
use App\Modules\Tenancy\Policies\RolePolicy;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\PlatformStaffDirectory;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class TenancyServiceProvider extends Module
{
    protected string $name = 'Tenancy';

    public function boot(): void
    {
        parent::boot();

        Event::listen(
            WorkspaceCreated::class,
            SeedDefaultRoles::class,
        );

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(PlatformStaff::class, PlatformStaffPolicy::class);

        $this->registerPlatformStanding();
    }

    /**
     * A platform officer's permissions, in whichever workspace they are looking at.
     *
     * ⚠️ `Gate::before` AND NOT A ROLE ASSIGNMENT, because the assignment is the
     * thing spatie cannot express here: `model_has_roles` puts `team_id` inside
     * its primary key and forbids NULL, so a role belonging to no workspace has
     * nobody to belong to. The standing lives in `platform_staff` and this is
     * where it becomes an answer to `can()`.
     *
     * ⚠️ IT RETURNS `null`, NEVER `false`. A `Gate::before` that answers false
     * SHORT-CIRCUITS every policy behind it — the abilities this hook knows
     * nothing about would start being refused, silently, for everyone. Null means
     * "no opinion" and lets the normal path run.
     *
     * ⚠️ AND IT ANSWERS ONLY FOR NAMES IN `Permissions::all()`. Filament checks
     * abilities like `view` and `update` against models constantly; without the
     * membership test this hook would be consulted on all of them and the
     * directory would be asked to prove a negative for every row on a table.
     */
    private function registerPlatformStanding(): void
    {
        /*
        | ⚠️ `scoped`, NOT `singleton`. A queue worker keeps its container across
        | jobs, so a singleton's memo would outlive the request that filled it —
        | a revoked officer would keep their permissions in that worker until it
        | restarted. Same hazard as the `WorkspaceContext::set()` rule: an
        | application-wide cache leaking into whatever the worker handles next.
        */
        $this->app->scoped(PlatformStaffDirectory::class);

        /** @var array<string, true> $known */
        $known = array_fill_keys(Permissions::all(), true);

        Gate::before(function (mixed $user, string $ability) use ($known): ?bool {
            if (! $user instanceof User || ! isset($known[$ability])) {
                return null;
            }

            $held = app(PlatformStaffDirectory::class)->permissionsFor($user);

            return in_array($ability, $held, true) ? true : null;
        });
    }
}
