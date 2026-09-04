<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\TwoFactorMandate;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Events\WorkspaceCreated;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Actions\Action;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class CreateWorkspace extends Action
{
    /**
     * Spec 025 · FR-008 — a second workspace for one owner is refused here, in
     * the Action, and not only in the Form Request.
     *
     * ⚠️ THE FORM REQUEST GUARDS ONE DOOR OF THREE. Filament and every seeder
     * reach this class with no request behind them, and spec 010 already wrote
     * down what that costs: a Filament LIST never calls the row policy at all.
     * The Action is the entrance the API, the panel and the seeds share.
     *
     * The unique index on `workspaces.owner_user_id` is the other half and is not
     * redundant: a check followed by a write is the race, and the surfaces that
     * can run it concurrently are real (the panel screen double-clicked; an
     * administrator creating for a teacher while the backfill creates for the
     * same teacher). This refusal exists so the ordinary case reads a sentence
     * rather than a `QueryException`.
     */
    public function handle(CreateWorkspaceDTO $dto, User $owner): Workspace
    {
        /*
        | Spec 025 · FR-004 · SC-005 — the owner must be a teacher.
        |
        | ⚠️ THIS ACTION ACCEPTED ANY `User` UNTIL NOW, and the FR-009 owner picker
        | is what makes that dangerous: it can name anybody on the platform, so
        | without this line an administrator could install a STUDENT as owner with
        | `tenant-owner`'s 68 permissions. SC-005 reads «zero, before the change
        | and after it».
        |
        | It also guards the rule half the product stands on — a student belongs to
        | no workspace, which is why `WorkspaceScope` is inert for them and why
        | `PlatformOwnershipTest` polices the duplication defect in both
        | directions.
        |
        | ⚠️ AND IT COULD NOT SHIP BEFORE THIS PHASE. It guards a rule — «the owner
        | is a teacher» — that only becomes true once academy self-signup ends with
        | FR-026. Every workspace owner in the fixtures and both `ScenarioSeeder`
        | owners carried `platform_role = NULL`, and `createWorkspaceWithOwner()`
        | is used in 317 test files. Those two seeders and that helper change in
        | the same commit as this line, never before it.
        */
        if ($owner->platform_role !== PlatformRole::Teacher) {
            throw new DomainException('لا يُنشأ مكان عمل إلا لحساب مدرّس.');
        }

        if (Workspace::query()->where('owner_user_id', $owner->getKey())->exists()) {
            throw new DomainException('هذا الحساب يملك مكان عمل بالفعل.');
        }

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
