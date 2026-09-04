<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Events\WorkspaceCreated;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class CreateWorkspace extends Action
{
    public function handle(CreateWorkspaceDTO $dto, User $owner): Workspace
    {
        return DB::transaction(function () use ($dto, $owner): Workspace {
            $workspace = Workspace::create([
                'name' => $dto->name,
                'slug' => $dto->slug ?? Str::slug($dto->name.'-'.Str::random(6)),
                'type' => $dto->type,
                'owner_user_id' => $owner->getKey(),
                'settings' => $dto->settings,
            ]);

            // Attach the owner as a member with the tenant-owner role.
            $workspace->members()->attach($owner->getKey(), [
                'role' => Roles::TENANT_OWNER,
                'joined_at' => now(),
            ]);

            // Set the owner's last workspace so future requests resolve the context.
            $owner->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

            // Owning a workspace means approving payments and managing members,
            // so the clock on enrolling a second factor starts here (FR-028).
            TwoFactorMandate::applyTo($owner);

            // WorkspaceCreated fires SeedDefaultRoles synchronously, creating the
            // workspace-scoped roles + permissions. After that, assign the
            // tenant-owner spatie role to the owner within this workspace's team.
            event(new WorkspaceCreated($workspace, $owner));

            $registrar = app(PermissionRegistrar::class);
            $previousTeam = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($workspace->getKey());
            try {
                $owner->assignRole(Roles::TENANT_OWNER);
            } finally {
                $registrar->setPermissionsTeamId($previousTeam);
            }

            /*
            | ⚠️ `activity()` DIRECTLY, NOT THE `LogsActivity` TRAIT — and the
            | reason is a defect this cost, measured: the trait's one line is
            | `'workspace_id' => app(WorkspaceContext::class)->id()`, and that
            | singleton CACHES its resolution. Calling it here freezes the answer
            | at whatever the creator's context was BEFORE the workspace existed —
            | null, for a brand-new owner — so every `can()` in the rest of that
            | process answers false. Five Tenancy tests turned 403 on invitation
            | endpoints that have nothing to do with logging.
            |
            | And the explicit id is the CORRECT value anyway: the workspace being
            | recorded is the one just created, never «whichever workspace the
            | creator happened to be looking at».
            |
            | Logged at all because activity logging is opt-in per Action with no
            | global subscriber — `UpdateWorkspace` and `RemoveMember` carry it,
            | while creation, the one act that hands somebody 68 permissions, wrote
            | nothing. The causer is the authenticated user, so a platform admin
            | creating a workspace from the panel is named; a backfill migration has
            | none and records the act with no causer rather than inventing one.
            */
            $entry = activity()->withProperties([
                'workspace_id' => $workspace->getKey(),
                'owner_user_id' => $owner->getKey(),
                'type' => $dto->type,
            ])->performedOn($workspace);

            if (Auth::user() instanceof User) {
                $entry->causedBy(Auth::user());
            }

            $entry->log('created');

            return $workspace;
        });
    }
}
