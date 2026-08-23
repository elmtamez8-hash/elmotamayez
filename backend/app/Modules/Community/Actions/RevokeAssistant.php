<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;

/**
 * FR-007 · FR-009 — the assistant stops working here, on the next request.
 *
 * ⚠️ A CONDITIONAL UPDATE IS BOTH THE CHECK AND THE CLAIM. `WHERE revoked_at IS
 * NULL` is the same idiom `captured_order_id` and `StructureVersion::claim()` use,
 * and it is here for the same reason: a read followed by a write lets two calls
 * both see «still live» and both run the removal, which re-stamps a date that is
 * supposed to answer «when did this person stop working here» and would instead
 * answer «whenever somebody last pressed the button». Never `lockForUpdate()` —
 * a no-op on SQLite, so a test built on it passes locally and proves nothing.
 *
 * ⚠️ THE ROW IS REVOKED, NEVER DELETED. Every mark the assistant gave points at a
 * user id that still resolves, so deleting the assignment breaks nothing visibly
 * — it just leaves the teacher, months later, reading a name with no record of
 * how that person came to be marking their papers (FR-009).
 *
 * ⚠️ AND THE ACCESS IS TAKEN AWAY BY REMOVING THE ROLES, NOT BY DELETING A TOKEN.
 * An assistant works for more than one teacher; destroying their Sanctum token
 * signs them out of a job they still hold, and answers `401` — which sends them
 * to a login screen that lets them straight back in. Refusal from a session that
 * is still perfectly authenticated is what SC-003 asks for.
 *
 * ⚠️ AND `forWorkspace()`, NEVER `set()` FOLLOWED BY A FORGET. spatie runs in team
 * mode with `team_id = workspace_id`, so `syncRoles([])` with no team id pushed in
 * deletes every role this person holds in EVERY workspace — the exact hazard
 * `RevokeWorkspaceAccess` records for a whole workspace, reached here one person
 * at a time. `forWorkspace()` sets the team id and puts the previous one back.
 */
class RevokeAssistant extends Action
{
    public function handle(AssistantAssignment $assignment): AssistantAssignment
    {
        $claimed = AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->whereKey($assignment->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($claimed === 0) {
            return $assignment->refresh();
        }

        $workspaceId = (int) $assignment->workspace_id;
        $userId = (int) $assignment->assistant_user_id;

        app(WorkspaceContext::class)->forWorkspace($workspaceId, function () use ($assignment, $workspaceId, $userId): void {
            $assignment->assistant?->syncRoles([]);

            // The roles go before the membership, the "children first" ordering:
            // the team id this removal is scoped by is resolved from the
            // membership, and deleting that first leaves the roles held by
            // somebody with no way in and no way to be found again.
            WorkspaceMember::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $userId)
                ->delete();
        });

        return $assignment->refresh();
    }
}
