<?php

declare(strict_types=1);

namespace App\Modules\Community\Actions;

use App\Modules\Community\Models\AssistantAssignment;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Collection;

/**
 * The teacher's team, as it stands.
 *
 * ⚠️ THE WITHDRAWN ARE INCLUDED, and the screen says so rather than hiding them.
 * A row that vanishes is a row nobody can ask about, and «who marked this paper
 * in March» is a question a teacher asks about somebody who has left (FR-009).
 *
 * ⚠️ AND BOTH RELATIONS ARE EAGER-LOADED. A Resource runs once per row, so the
 * assistant's name and their course list are an N+1 by construction — the defect
 * `ClassSessionResource` shipped and `QueryBudgetTest` exists to stop.
 */
class ListAssistants extends Action
{
    /** @return Collection<int, AssistantAssignment> */
    public function handle(int $workspaceId): Collection
    {
        return AssistantAssignment::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->with(['assistant:id,uuid,name', 'scopes.course:id,uuid,title'])
            ->orderBy('id')
            ->get();
    }
}
