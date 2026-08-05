<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\RegisterParentData;
use App\Modules\Identity\Support\PlatformRole;
use App\Shared\Actions\Action;
use Illuminate\Auth\Events\Registered;

class RegisterParent extends Action
{
    public function handle(RegisterParentData $data): User
    {
        $user = new User;

        // forceFill: platform_role is guarded so no payload can pick it.
        $user->forceFill([
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'email' => $data->email,
            'password' => $data->password,
            'phone' => $data->phone,
            'country' => $data->country,
            'platform_role' => PlatformRole::Parent,
        ])->save();

        // No preference rows are written. Since spec 003, absence means "use the
        // type's defaults" (FR-028), and every default is on — a parent signs up
        // precisely to be told how their child is doing. Seeding a row per type
        // per user would say the same thing in twelve rows instead of none, and
        // would freeze today's defaults for accounts created today.

        // No workspace and no tenant role, same as a student (FR-010).
        event(new Registered($user));

        return $user;
    }
}
