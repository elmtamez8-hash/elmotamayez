<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\RegisterStudentData;
use App\Modules\Identity\Support\PlatformRole;
use App\Shared\Actions\Action;
use Illuminate\Auth\Events\Registered;

class RegisterStudent extends Action
{
    public function handle(RegisterStudentData $data): User
    {
        $user = new User;

        // forceFill, not fill: platform_role is guarded precisely so a request
        // payload can never choose it.
        $user->forceFill([
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'email' => $data->email,
            'password' => $data->password,
            'phone' => $data->phone,
            'country' => $data->country,
            'platform_role' => PlatformRole::Student,
        ])->save();

        // Student-only facts live in their own table (spec 004): `users` carries
        // what every account has, and nothing more.
        $user->studentProfile()->create([
            'grade_level_slug' => $data->gradeLevelSlug,
            'registered_by_parent' => $data->registeredByParent,
        ]);

        // No workspace, no membership, no role (FR-010). A student browses the
        // marketplace across every workspace; belonging to one would narrow that
        // and would hand them a tenant role they never asked for.
        event(new Registered($user));

        return $user;
    }
}
