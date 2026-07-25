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
        $workspaces = $request->user()->workspaces()->withPivot('role')->get();

        return response()->json(WorkspaceResource::collection($workspaces));
    }

    public function store(CreateWorkspaceRequest $request, CreateWorkspace $action): JsonResponse
    {
        $workspace = $action->handle(CreateWorkspaceDTO::fromArray($request->validated()), $request->user());

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
            'data' => $workspace->members()->withPivot('role')->get()->map(fn ($m) => [
                'uuid' => $m->uuid,
                'name' => $m->name,
                'email' => $m->email,
                'role' => $m->pivot->role,
            ]),
        ]);
    }

    public function invite(InviteMemberRequest $request, Workspace $workspace, InviteMember $action): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $invitation = $action->handle($workspace, $request->validated('email'), $request->validated('role'), $request->user());

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

    public function acceptInvitation(Request $request, string $token, AcceptInvitation $action): JsonResponse
    {
        $invitation = Invitation::where('token', $token)->firstOrFail();

        $workspace = $action->handle($invitation, $request->user());

        return response()->json(WorkspaceResource::make($workspace));
    }
}
