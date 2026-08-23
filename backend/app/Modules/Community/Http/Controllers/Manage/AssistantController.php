<?php

declare(strict_types=1);

namespace App\Modules\Community\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Community\Actions\ListAssistants;
use App\Modules\Community\Actions\RevokeAssistant;
use App\Modules\Community\Actions\SetAssistantScope;
use App\Modules\Community\Data\AssistantScopeData;
use App\Modules\Community\Http\Requests\SetAssistantScopeRequest;
use App\Modules\Community\Http\Resources\AssistantAssignmentResource;
use App\Modules\Community\Models\AssistantAssignment;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The owner's screen: who is on the team, on what, and who has left.
 *
 * ⚠️ THERE IS NO `POST`. Membership is written by `AcceptInvitation` and
 * `CreateWorkspace` alone, and the invitation flow is shipped with its own
 * screens — `CreateAssistantAssignment` rides it. A create endpoint here would be
 * a second membership story, and the two disagree the first time somebody uses
 * the older one.
 *
 * ⚠️ AND THE BINDING IS IMPLICIT ON PURPOSE HERE, WHICH IT IS NOT ON A PUBLIC
 * ROUTE. `assistant_assignments` is workspace-scoped and the reader is a member,
 * so the scope resolves and another workspace's uuid 404s before the policy runs;
 * `AssistantAssignmentPolicy` is the row-level half on top of it. That safety
 * comes from the reader being a MEMBER — it does not transfer to any route a
 * student can reach.
 */
class AssistantController extends Controller
{
    public function index(Request $request, ListAssistants $action): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AssistantAssignment::class);

        $workspaceId = app(WorkspaceContext::class)->id();

        abort_if($workspaceId === null, 403);

        return AssistantAssignmentResource::collection($action->handle($workspaceId));
    }

    public function scope(
        SetAssistantScopeRequest $request,
        AssistantAssignment $assignment,
        SetAssistantScope $action,
    ): JsonResponse {
        $this->authorize('update', $assignment);

        $updated = $action->handle($assignment, AssistantScopeData::fromArray($request->validated()));

        return response()->json(
            AssistantAssignmentResource::make($updated->load('assistant:id,uuid,name', 'scopes.course:id,uuid,title'))
        );
    }

    public function destroy(AssistantAssignment $assignment, RevokeAssistant $action): Response
    {
        $this->authorize('delete', $assignment);

        $action->handle($assignment);

        // 204 on the second call too: the withdrawal is idempotent by
        // construction, and answering 404 for «already withdrawn» would make a
        // retried request look like a missing row.
        return response()->noContent();
    }
}
