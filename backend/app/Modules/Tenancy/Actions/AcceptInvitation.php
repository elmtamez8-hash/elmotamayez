<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Tenancy\Events\WorkspaceMemberAdded;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

class AcceptInvitation extends Action
{
    public function __construct(
        private readonly WorkspaceContext $context,
    ) {}

    public function handle(Invitation $invitation, User $user): Workspace
    {
        if ($invitation->isAccepted()) {
            throw new \DomainException('This invitation has already been accepted.');
        }

        if ($invitation->isExpired()) {
            throw new \DomainException('This invitation has expired.');
        }

        $workspace = $invitation->workspace;

        return DB::transaction(function () use ($invitation, $user, $workspace): Workspace {
            // Attach the user as a member if not already.
            if (! $workspace->members()->where('user_id', $user->getKey())->exists()) {
                $workspace->members()->attach($user->getKey(), [
                    'role' => $invitation->role,
                    'joined_at' => now(),
                ]);
            }

            $invitation->update([
                'accepted_at' => now(),
                'accepted_by' => $user->getKey(),
            ]);

            // Assign the spatie role within this workspace's team context.
            $this->context->set($workspace);
            $user->assignRole($invitation->role);

            event(new WorkspaceMemberAdded($workspace, $user, $invitation->role));

            return $workspace;
        });
    }
}
