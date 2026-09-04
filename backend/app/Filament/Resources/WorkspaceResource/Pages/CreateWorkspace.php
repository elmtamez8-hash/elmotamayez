<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkspaceResource\Pages;

use App\Filament\Resources\WorkspaceResource;
use App\Models\User;
use App\Modules\Tenancy\Actions\CreateWorkspace as CreateWorkspaceAction;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec 025 · FR-009 — the last remaining door, and it goes through the Action.
 *
 * ⚠️ `handleRecordCreation` EXISTS SO FILAMENT NEVER CALLS `Workspace::create()`.
 * That is not a style preference. `SeedDefaultRoles` fires from
 * `WorkspaceCreated`, which only the Action dispatches, so a screen that let
 * Filament write the row directly would produce a workspace with no roles at all
 * — an owner who can do nothing inside their own place, and nothing on the screen
 * to say why. The production row `slug = platform` is that defect, measured:
 * written with `forceFill`, still carrying zero roles.
 *
 * ⚠️ AND THE OWNER IS A FIELD, unlike `CreatePlatformStaff` where the actor is
 * the author by definition. `WorkspaceController::store()` makes the CALLER the
 * owner, which is exactly why the API can never satisfy FR-009: an administrator
 * creating «for a teacher» would own it themselves. Here the owner is chosen and
 * the ACTOR is recorded separately — `CreateWorkspace` logs the activity with
 * `Auth::user()` as the causer, which is FR-009's «recorded in their name».
 *
 * The two refusals (a non-teacher owner, a second workspace) live in the Action
 * and surface here as its `DomainException`. Repeating them in this page would be
 * two spellings of one rule, and the second one to drift is always the one on a
 * screen.
 */
class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $owner */
        $owner = User::query()->findOrFail($data['owner_user_id']);

        return app(CreateWorkspaceAction::class)->handle(
            new CreateWorkspaceDTO(
                name: (string) $data['name'],
                // Spec 025 · FR-005 — every workspace is a teacher's. The `academy`
                // and `school` values stay in the data and leave the interface.
                type: 'teacher',
            ),
            $owner,
        );
    }
}
