<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Data\TeacherStepOneData;
use App\Modules\Marketplace\Events\TeacherRegistered;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Shared\Actions\Action;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of the wizard: the account, the teacher's own workspace, and an empty
 * application to fill in — one transaction, or none of them.
 *
 * ⚠️ Spec 025 · FR-001 REPEALS 001 · FR-010 («the three new signup paths must not
 * create a workspace and grant no role») for this path alone. The reason that
 * rule existed was that a workspace was a thing the user chose to make; it is not
 * one any more. The student and parent paths are untouched and 001 · FR-010 still
 * governs them — FR-004 says so, and this Action being the only dispatcher of
 * {@see TeacherRegistered} is how that is enforced rather than asserted.
 *
 * ⚠️ AND 001 · FR-013 («an approved teacher with no academy is attached to the
 * default platform workspace») is repealed with it: the application row lands in
 * the teacher's OWN workspace now, which is what makes `PlatformWorkspace`
 * deletable at all. That class recreated itself on every call, so FR-024 could
 * never have been executed while this line still asked for it.
 */
class RegisterTeacher extends Action
{
    public function handle(TeacherStepOneData $data): TeacherApplication
    {
        return DB::transaction(function () use ($data): TeacherApplication {
            $user = new User;

            $user->forceFill([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'email' => $data->email,
                'password' => $data->password,
                'phone' => $data->phone,
                'country' => $data->country,
                'platform_role' => PlatformRole::Teacher,
            ])->save();

            event(new Registered($user));

            // Tenancy answers this synchronously and creates the workspace inside
            // the transaction above (FR-003). See CreateImplicitWorkspace for why
            // it is a dedicated event rather than a branch inside `Registered`.
            event(new TeacherRegistered($user));

            /*
            | ⚠️ HOW THIS ACTION LEARNS THE WORKSPACE ANOTHER MODULE JUST MADE.
            |
            | `CreateWorkspace` stamps `users.last_workspace_id` in this same
            | transaction, and `users` is a shared table — so reading it back
            | crosses no module boundary and needs no return value threaded
            | through an event.
            |
            | NOT `WorkspaceContext`: registration is a GUEST request, and that
            | singleton caches its resolution — it froze at null before the
            | workspace existed and stays null for the rest of this request. So
            | `BelongsToWorkspace`'s auto-fill contributes nothing here and the
            | row would be written with no workspace at all. The explicit value
            | wins over the auto-fill by design (the trait only fills a null).
            */
            $workspaceId = $user->refresh()->last_workspace_id;

            return TeacherApplication::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $user->getKey(),
                'status' => TeacherApplication::STATUS_DRAFT,
                'current_step' => 2,
            ]);
        });
    }
}
