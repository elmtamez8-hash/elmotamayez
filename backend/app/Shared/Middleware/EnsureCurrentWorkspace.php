<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Shared\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the current workspace is resolved for the authenticated user before
 * the controller runs. Also syncs the spatie/permission team id so that
 * permission checks are scoped to the current workspace.
 */
final class EnsureCurrentWorkspace
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = null;

        if ($request->user() !== null) {
            $workspaceId = $this->context->id();
        }

        // Set the spatie team id to match the current workspace (null = global/Super Admin).
        $this->registrar->setPermissionsTeamId($workspaceId);

        return $next($request);
    }
}
