<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Scopes\WorkspaceScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\PermissionRegistrar;

/**
 * Resolves the "current workspace" for the authenticated user.
 *
 * The current workspace is stored in the session (SPA cookie auth) and is used by
 * the global {@see WorkspaceScope} to filter every tenant-scoped
 * query, and by spatie/permission (team mode) to scope roles/permissions.
 */
class WorkspaceContext
{
    private const SESSION_KEY = 'workspace_id';

    private ?int $resolvedId = null;

    private bool $resolved = false;

    /**
     * Resolve and return the current workspace id, or null when operating globally
     * (Super Admin with no workspace selected).
     */
    public function id(): ?int
    {
        if ($this->resolved) {
            return $this->resolvedId;
        }

        $this->resolved = true;

        $user = $this->user();

        if ($user === null) {
            return $this->resolvedId = null;
        }

        // Super Admins may operate globally (no current workspace) unless one is set.
        $fromSession = $this->sessionValue();
        if ($fromSession !== null) {
            return $this->resolvedId = $fromSession;
        }

        // Fall back to the user's last-used workspace, if any.
        $lastUsed = $user instanceof Model ? $user->getAttribute('last_workspace_id') : null;
        if ($lastUsed !== null) {
            return $this->resolvedId = (int) $lastUsed;
        }

        return $this->resolvedId = null;
    }

    /**
     * Return the current Workspace model instance, or null.
     */
    public function current(): ?Workspace
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        return Workspace::query()->find($id);
    }

    /**
     * Persist the current workspace for the authenticated user.
     */
    public function set(Workspace $workspace): void
    {
        $this->resolvedId = $workspace->getKey();
        $this->resolved = true;

        Session::put(self::SESSION_KEY, $workspace->getKey());

        $user = $this->user();
        if ($user instanceof Model && $user->getAttribute('last_workspace_id') !== $workspace->getKey()) {
            $user->forceFill(['last_workspace_id' => $workspace->getKey()])->save();
        }

        // Sync spatie's team id so permission checks use the new workspace.
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());
    }

    /**
     * Forget the current workspace (operate globally).
     */
    public function forget(): void
    {
        $this->resolvedId = null;
        $this->resolved = true;

        Session::forget(self::SESSION_KEY);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * Execute a callback while bypassing the workspace global scope.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->resolvedId;
        $previousResolved = $this->resolved;

        $this->resolvedId = null;
        $this->resolved = true;

        try {
            return $callback();
        } finally {
            $this->resolvedId = $previous;
            $this->resolved = $previousResolved;
        }
    }

    public function isSuperAdmin(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // Super Admin is a platform-level flag (not a tenant role). Super Admins
        // bypass the workspace global scope and operate across all workspaces.
        return (bool) ($user->getAttribute('is_super_admin') ?? false);
    }

    private function user(): ?Authenticatable
    {
        return Auth::user();
    }

    private function sessionValue(): ?int
    {
        if (! Session::isStarted()) {
            return null;
        }

        $value = Session::get(self::SESSION_KEY);

        return $value !== null ? (int) $value : null;
    }
}
