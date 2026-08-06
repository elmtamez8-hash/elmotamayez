<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use App\Modules\Identity\Support\TwoFactorMandate;
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

        // The invitation names its invitee: a forwarded token must not let a
        // different account into the workspace.
        if (! hash_equals(mb_strtolower($invitation->email), mb_strtolower($user->email))) {
            throw new \DomainException("This invitation was sent to {$invitation->email}. Sign in with that address to accept it.");
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

            // Joining as a teacher or an assistant grants access to other
            // people's payments and records; joining as a student does not.
            if (TwoFactorMandate::isPrivileged($invitation->role)) {
                TwoFactorMandate::applyTo($user);
            }

            event(new WorkspaceMemberAdded($workspace, $user, $invitation->role));

            return $workspace;
        });
    }
}
