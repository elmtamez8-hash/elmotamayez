<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;
use Illuminate\Support\Facades\DB;

class RemoveMember extends Action
{
    use LogsActivity;

    public function handle(Workspace $workspace, User $member): void
    {
        $workspace->members()->detach($member->getKey());

        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $member->getKey())
            ->where('team_id', $workspace->getKey())
            ->delete();

        if ($member->last_workspace_id === $workspace->getKey()) {
            $member->forceFill(['last_workspace_id' => null])->save();
        }

        $this->logActivity('removed member', $workspace, [
            'member_email' => $member->email,
        ]);
    }
}
