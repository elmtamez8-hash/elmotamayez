<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base policy that centralizes super-admin bypass and workspace boundary enforcement.
 *
 * Every tenant-scoped policy should extend this class instead of duplicating
 * the before() and belongsToCurrentWorkspace() logic.
 */
abstract class BasePolicy
{
    use HandlesAuthorization;

    /**
     * Super Admins bypass all policy checks.
     */
    public function before(User $user, string $ability): ?Response
    {
        if ($user->isSuperAdmin()) {
            return Response::allow();
        }

        return null;
    }

    /**
     * Asserts that the given model belongs to the current workspace.
     * Use in view/update/delete methods of tenant-scoped policies.
     */
    protected function belongsToCurrentWorkspace(Model $model): Response
    {
        $currentWorkspaceId = app(WorkspaceContext::class)->id();

        if ($model->getAttribute('workspace_id') !== $currentWorkspaceId) {
            return Response::deny('This resource does not belong to your workspace.');
        }

        return Response::allow();
    }
}
