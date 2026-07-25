<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tenancy\Actions\AcceptInvitation;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\Actions\InviteMember;
use App\Modules\Tenancy\Actions\RemoveMember;
use App\Modules\Tenancy\Actions\SwitchWorkspace;
use App\Modules\Tenancy\Actions\UpdateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Http\Requests\CreateWorkspaceRequest;
use App\Modules\Tenancy\Http\Requests\InviteMemberRequest;
use App\Modules\Tenancy\Http\Requests\UpdateWorkspaceRequest;
use App\Modules\Tenancy\Http\Resources\WorkspaceResource;
use App\Modules\Tenancy\Models\Invitation;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspaces = $this->currentUser($request)->workspaces()->withPivot('role')->get();

        return response()->json(WorkspaceResource::collection($workspaces));
    }

    public function store(CreateWorkspaceRequest $request, CreateWorkspace $action): JsonResponse
    {
        $workspace = $action->handle(CreateWorkspaceDTO::fromArray($request->validated()), $this->currentUser($request));

        return response()->json(WorkspaceResource::make($workspace), 201);
    }

    public function switch(Request $request, Workspace $workspace, SwitchWorkspace $action): JsonResponse
    {
        $this->authorize('view', $workspace);

        $action->handle($workspace);

        return response()->json(WorkspaceResource::make($workspace->fresh()));
    }

    public function members(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json([
            'data' => $workspace->members()->withPivot('role')->get()->map(fn (User $member) => [
                'uuid' => $member->uuid,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->getRelationValue('pivot')?->getAttribute('role'),
            ]),
        ]);
    }

    public function invite(InviteMemberRequest $request, Workspace $workspace, InviteMember $action): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $invitation = $action->handle($workspace, $request->validated('email'), $request->validated('role'), $this->currentUser($request));

        return response()->json(['token' => $invitation->token], 201);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, UpdateWorkspace $action): JsonResponse
    {
        return response()->json(WorkspaceResource::make($action->handle($workspace, $request->validated())));
    }

    public function removeMember(Request $request, Workspace $workspace, User $member, RemoveMember $action): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        if ($workspace->isOwnedBy($member)) {
            return response()->json(['message' => 'The workspace owner cannot be removed.'], 422);
        }

        $action->handle($workspace, $member);

        return response()->json(null, 204);
    }

    /**
     * Public lookup so the invitee can see what they were invited to before
     * signing in. The token is the secret; nothing else identifying is exposed.
     */
    public function showInvitation(string $token): JsonResponse
    {
        $invitation = $this->findInvitation($token);

        return response()->json([
            'workspace_name' => $invitation->workspace->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at,
            'is_expired' => $invitation->isExpired(),
            'is_accepted' => $invitation->isAccepted(),
        ]);
    }

    public function acceptInvitation(Request $request, string $token, AcceptInvitation $action): JsonResponse
    {
        $workspace = $action->handle($this->findInvitation($token), $this->currentUser($request));

        return response()->json(WorkspaceResource::make($workspace));
    }

    /**
     * The invitee is not a member of the workspace yet, so their current
     * workspace must not filter this lookup — the single-use token authorizes it.
     */
    private function findInvitation(string $token): Invitation
    {
        return Invitation::query()
            ->withoutWorkspaceScope()
            ->with('workspace')
            ->where('token', $token)
            ->firstOrFail();
    }
}
