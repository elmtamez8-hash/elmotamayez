<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Collection;

/**
 * «Where am I an assistant, and on what?» — asked by the assistant themselves.
 *
 * ⚠️ ACROSS EVERY WORKSPACE, WHICH IS WHY THE SCOPE IS OFF AND THE FILTER IS THE
 * USER. Working for more than one teacher is what an assistant does, and their own
 * client needs to know which workspace it is looking at before it can render
 * anything. The guard is `assistant_user_id = me`, which is ownership rather than
 * permission — the same shape as a student reading their own attendance row.
 *
 * ⚠️ AND ONLY THE LIVE ONES. This answers «what may I do now», not «what did I
 * once do»; a withdrawn assignment listed here is a workspace the client would
 * offer to open and the server would then refuse.
 */
class ListOwnAssignments extends Action
{
    /** @return Collection<int, AssistantAssignment> */
    public function handle(User $user): Collection
    {
        return AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->where('assistant_user_id', $user->getKey())
            ->active()
            ->with(['workspace:id,uuid,name', 'scopes.course:id,uuid,title'])
            ->orderBy('id')
            ->get();
    }
}
