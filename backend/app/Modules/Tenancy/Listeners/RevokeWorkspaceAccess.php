<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Compliance\Events\TeacherOffboardingCompleted;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;

/**
 * Everybody's access to a wound-down workspace ends (spec 013 · FR-037 · SC-013).
 *
 * ⚠️ IN ONE WORKSPACE, AND THE SCOPE IS THE WHOLE DIFFICULTY. An assistant may
 * work for two teachers; ending "their permissions" without naming a workspace
 * takes away a job they still hold with somebody who is not leaving. spatie runs
 * in TEAM MODE here with `team_id = workspace_id`, so a role is already held per
 * workspace — but `model_has_roles` rows are deleted by a query, and a query with
 * no team column deletes every one of them.
 *
 * ⚠️ AND THE TEAM ID HAS TO BE PUSHED IN BEFORE THE DELETE. The registrar's team
 * id is set by `EnsureCurrentWorkspace` during an HTTP request; this listener runs
 * from a queued job as often as not, where it is whatever the last request left
 * behind — or null. `WorkspaceContext::forWorkspace()` is what sets it and puts it
 * back afterwards, and it is used rather than `set()` for the reason written in
 * every job in this repository: `set()` leaks a workspace into the next thing the
 * same worker handles.
 */
class RevokeWorkspaceAccess
{
    public function handle(TeacherOffboardingCompleted $event): void
    {
        $workspaceId = (int) $event->offboarding->workspace_id;

        /*
        | ⚠️ EVERY MEMBER EXCEPT A STUDENT, AND THE EXCLUSION IS FR-035 SURVIVING
        | FR-037. The requirement is the teacher's own access "and their
        | assistants' permissions" — a student is neither, and stripping their role
        | takes away the course they PAID for, which is the thing the previous
        | requirement promises stays for the rest of their term. Two requirements
        | one line apart, and the obvious implementation of the second breaks the
        | first: measured, not reasoned about — the content-access test refused a
        | paying student their own lesson with a 403.
        |
        | In production a student is a member of no workspace at all (only
        | `AcceptInvitation` and `CreateWorkspace` write that pivot), so this
        | filter is usually a no-op. That is exactly why it must be here: a guard
        | that only bites in the unusual case is one nobody notices missing.
        */
        $members = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('role', '!=', Roles::STUDENT)
            ->get();

        if ($members->isEmpty()) {
            return;
        }

        /*
        | ⚠️ `forWorkspace()`, NEVER `setPermissionsTeamId()` FOLLOWED BY A FORGET —
        | AND THE FIRST DRAFT OF THIS FILE DID THE SECOND, WHICH SIGNED A PAYING
        | STUDENT OUT OF THEIR OWN COURSE. `WorkspaceContext` is an
        | application-wide singleton that caches its resolution; pushing a team id
        | into the registrar and dropping the resolution afterwards leaves the
        | request that follows resolving from scratch, and for a student with no
        | `last_workspace_id` that is a null context, no team id, and therefore no
        | roles at all. Their next read answered 403 about a lesson they had paid
        | for — FR-035 broken from inside FR-037's implementation, by a line meant
        | to be housekeeping.
        |
        | `forWorkspace()` sets both and puts both back. It is the same helper every
        | job in this repository uses, for the mirror of this reason.
        |
        | The roles go before the memberships. A membership row is what the context
        | resolves from; delete it first and the team id this listener needs to
        | scope the role removal names a workspace nobody belongs to — the roles
        | would survive, held by people with no way in and no way to be found
        | again. The "children first" ordering every walk in this phase uses.
        */
        app(WorkspaceContext::class)->forWorkspace($workspaceId, function () use ($members, $workspaceId): void {
            foreach ($members as $member) {
                $user = $member->user;

                if ($user === null) {
                    continue;
                }

                // Scoped by the team id `forWorkspace` pushed in: this person's
                // roles in the OTHER teacher's workspace are a different set of
                // rows and stay exactly as they are.
                $user->syncRoles([]);
            }

            WorkspaceMember::query()
                ->where('workspace_id', $workspaceId)
                ->where('role', '!=', Roles::STUDENT)
                ->delete();
        });
    }
}
