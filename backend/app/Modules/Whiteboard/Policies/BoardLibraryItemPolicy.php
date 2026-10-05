<?php

declare(strict_types=1);

namespace App\Modules\Whiteboard\Policies;

use App\Models\User;
use App\Modules\Tenancy\Support\Roles;
use App\Modules\Whiteboard\Models\BoardLibraryItem;
use App\Modules\Whiteboard\Support\MemberRoles;
use App\Shared\Contracts\AssistantScopeDirectory;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\Response;

/**
 * The academy's shared board library (owner decisions 2026-10-05):
 *  - every teacher who may prepare boards SEES it and SHARES into it — the
 *    board list's own door (`BoardPolicy::viewAny`), so a learner, a removed
 *    member and an unresolved context are refused the same way;
 *  - an item is REMOVED by the teacher who shared it, or by the academy's owner
 *    (the board policy's «manager»: `tenant-owner`, never as an assistant).
 */
class BoardLibraryItemPolicy
{
    public function viewAny(User $user): Response
    {
        return app(BoardPolicy::class)->viewAny($user);
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, BoardLibraryItem $item): Response
    {
        // Another workspace's item reads as missing, never as forbidden.
        if (app(WorkspaceContext::class)->id() !== (int) $item->workspace_id) {
            return Response::denyAsNotFound();
        }

        if (! $this->viewAny($user)->allowed()) {
            return Response::denyAsNotFound();
        }

        $isSharer = $item->created_by_user_id !== null && (int) $item->created_by_user_id === (int) $user->getKey();
        $isOwner = app(MemberRoles::class)->roleIn($user, $item->workspace_id) === Roles::TENANT_OWNER
            && ! app(AssistantScopeDirectory::class)->isAssistantIn($user, $item->workspace_id);

        return $isSharer || $isOwner ? Response::allow() : Response::deny('يحذف الشكلَ مَن شاركه أو صاحبُ الأكاديمية.');
    }
}
