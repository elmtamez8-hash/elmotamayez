<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Actions\SetAssistantPowers;
use App\Modules\Tenancy\Http\Requests\UpdateAssistantPowersRequest;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Http\JsonResponse;

/**
 * «ما يستطيعه مساعدوك» on `/manage/assistants`: the current workspace's
 * `assistant-teacher` role, read and written through {@see SetAssistantPowers}.
 * The role is found under spatie's team scope, so it is always THIS workspace's.
 */
class AssistantPowersController extends Controller
{
    public function show(): JsonResponse
    {
        $role = $this->role();

        return response()->json(['groups' => SetAssistantPowers::read($role)]);
    }

    public function update(UpdateAssistantPowersRequest $request, SetAssistantPowers $action): JsonResponse
    {
        $role = $this->role();

        /** @var list<string> $groups */
        $groups = array_values($request->validated('groups'));
        $action->handle($role, $groups);

        return response()->json(['groups' => SetAssistantPowers::read($role->refresh())]);
    }

    private function role(): Role
    {
        $role = Role::query()->where('name', Roles::ASSISTANT_TEACHER)->firstOrFail();
        $this->authorize('update', $role);

        return $role;
    }
}
