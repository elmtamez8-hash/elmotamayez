<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Events\WorkspaceMemberAdded;
use App\Modules\Tenancy\Support\RoleLabels;

/**
 * Tells the workspace's owner that somebody accepted their invitation
 * (co-teacher journey 2026-10-22).
 *
 * Synchronous: `AcceptInvitation` fires the event inside its transaction, and a
 * queued listener would run before the commit — `ShouldHandleEventsAfterCommit`
 * does nothing for one (see the assessments gotchas). The feed row is written in
 * the same transaction as the membership it reports.
 */
class NotifyOwnerMemberJoined
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(WorkspaceMemberAdded $event): void
    {
        $owner = $event->workspace->owner;

        if ($owner === null || $owner->is($event->user)) {
            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $owner,
            type: NotificationType::WorkspaceMemberJoined,
            variables: [
                'name' => $event->user->name,
                'role' => RoleLabels::for($event->role) ?? $event->role,
            ],
            actionUrl: '/members',
            subject: $event->user,
            workspaceId: (int) $event->workspace->getKey(),
        ));
    }
}
