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

            return $workspace;
        });
    }
}
