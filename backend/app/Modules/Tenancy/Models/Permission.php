<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Models\Scopes\TeamRoleScope;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * ⚠️ THIS CLASS EXISTS FOR ONE LINE, AND WITHOUT IT ONE WORKSPACE OWNS THE
 * PLATFORM WHILE EVERY OTHER ONE LOSES EVERY PERMISSION IT HAS.
 *
 * spatie caches the whole permission map ONCE, globally, with
 * `Permission::select()->with('roles')->get()` — and since spec 007 that relation
 * resolves through {@see Role}, which carries {@see TeamRoleScope} filtering by the
 * CURRENT team. So the cached answer to "which roles hold this permission" only
 * ever contained the roles of whichever workspace happened to make the FIRST
 * request after a cache flush. Every other workspace then failed every check.
 *
 * Measured on the development database before the fix: `sessions.manage` is linked
 * to roles `1,2,3,6,7,10,11`, and the cache held `6,7`. Warming it from workspace 1
 * instead inverted exactly which academy worked and which was locked out — an
 * academy owner opening their panel found every button refused, because a
 * different owner had opened theirs a second earlier.
 *
 * ⚠️ AND EVERY DEPLOY RE-RUNS THE RACE. `permission:cache-reset`, `cache:clear`, a
 * TTL lapse, a new Redis — each one picks a new winner, with no error logged
 * anywhere.
 *
 * ⚠️ THE SCOPE ITSELF IS CORRECT AND STAYS. It is what stops one teacher seeing
 * another teacher's roles on `/admin` with an edit button beside each. What was
 * wrong is that spatie's INTERNAL cache-building query — which is not a screen and
 * belongs to no workspace — went through it.
 *
 * ⚠️ AND THE FIX IS CONFIGURATION, NOT A CONTAINER OVERRIDE. Replacing
 * `PermissionRegistrar` was the first attempt and it does not hold: spatie rebinds
 * that singleton in `packageBooted()`, which runs BEFORE any application provider
 * boots, so the override is silently discarded — `get_class()` still answered
 * spatie's own class. `config('permission.models.permission')` is read when the
 * model is resolved, so it wins whatever the provider order is.
 */
class Permission extends SpatiePermission
{
    /**
     * The roles that hold this permission — ACROSS ALL WORKSPACES.
     *
     * The only override in this class. Everything else is spatie's.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return parent::roles()->withoutGlobalScope(TeamRoleScope::class);
    }
}
