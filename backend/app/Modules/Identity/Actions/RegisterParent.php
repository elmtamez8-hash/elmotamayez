<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Data\RegisterParentData;
use App\Modules\Identity\Models\NotificationPreference;
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

        // Both on by default. A parent signs up precisely to be told how their
        // child is doing; defaulting to silence would make the account useless
        // until they find a settings page (FR-077).
        NotificationPreference::query()->create([
            'user_id' => $user->getKey(),
            'weekly_reports' => true,
            'session_alerts' => true,
        ]);

        // No workspace and no tenant role, same as a student (FR-010).
        event(new Registered($user));

        return $user;
    }
}
