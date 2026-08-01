<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Data\TeacherStepOneData;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Support\PlatformWorkspace;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * Step 1 of the wizard: the account plus an empty application to fill in.
 *
 * Like the student path this creates no workspace *membership* and grants no
 * role (FR-010). The application row does need a workspace_id — every tenant
 * row does — so it lands in the platform workspace until an academy claims them.
 */
class RegisterTeacher extends Action
{
    public function handle(TeacherStepOneData $data): TeacherApplication
    {
        $workspace = PlatformWorkspace::resolve();

        return DB::transaction(function () use ($data, $workspace): TeacherApplication {
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

            // forWorkspace(), never set(): this runs inside a request whose context
            // is a guest, and set() would leak the platform workspace into it.
            return app(WorkspaceContext::class)->forWorkspace(
                $workspace,
                fn () => TeacherApplication::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $user->getKey(),
                    'status' => TeacherApplication::STATUS_DRAFT,
                    'current_step' => 2,
                ]),
            );
        });
    }
}
