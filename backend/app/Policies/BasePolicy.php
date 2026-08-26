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
     *
     * ⚠️ A NULL CONTEXT RAISES NO OBJECTION, AND SAYING OTHERWISE LOCKED EVERY
     * REAL STUDENT OUT OF THE PRODUCT.
     *
     * `WorkspaceContext::id()` resolves from the session key, then falls back to
     * `users.last_workspace_id`. Nothing on a student's path ever writes that
     * column: its only writers are `CreateWorkspace` (the owner) and
     * `WorkspaceContext::set()`, reached from `AcceptInvitation` and
     * `SwitchWorkspace` — both about workspace MEMBERS — plus the two seeders.
     * Enrolling writes nothing, and signing in writes nothing. So for everybody
     * who registered and bought a course the context is null, and comparing a
     * real `workspace_id` against null denied them their own rows.
     *
     * The tell is what that did to `EnrollmentPolicy::view()`: the very next line
     * is `if ($enrollment->student_user_id === $user->getKey()) return allow()`
     * — the ownership branch, unreachable, because the tenant guard fired first
     * on the person who owns the row. `/learn/lessons/{uuid}` is the ONLY surface
     * in this product that plays a lesson, and it answered 403 to every student
     * who was not stamped by a seeder.
     *
     * ⚠️ THE QUERY LAYER HAS ALWAYS READ NULL THE OTHER WAY. `WorkspaceScope::apply()`
     * returns early adding NO condition when the id is null; "no workspace" means
     * "no tenant filter" there, and has since 001. A policy layer answering "deny
     * everything" for the identical state is the two-spellings divergence this
     * repository keeps paying for — one answer at the query, another at the door.
     *
     * ⚠️ AND IT CANNOT OPEN ANYTHING, because of what else null implies. Spatie
     * runs in team mode: no team id means no roles at all, so every `can()` in
     * every policy below this line is already false for such a caller. The only
     * branches that can allow are the explicit ownership tests — `student_user_id
     * === $user->getKey()` and its siblings — which are the correct guard and the
     * one this check was suppressing. The audit that established this walked every
     * policy for two shapes: a bare `return $this->belongsToCurrentWorkspace(...)`
     * (one existed, `ExamModeWindowPolicy::view()`, given its permission in the
     * same change) and a workspace check followed by an unconditional allow (none).
     *
     * A RESOLVED context that does not match is still a denial, unchanged: that is
     * the real cross-tenant guard, and it is about members, who always have one.
     *
     * Found on 2026-08-26 by the `T051` walk (spec 017), four endpoints at a time,
     * by nulling that column on a student who worked.
     */
    protected function belongsToCurrentWorkspace(Model $model): Response
    {
        $currentWorkspaceId = app(WorkspaceContext::class)->id();

        if ($currentWorkspaceId === null) {
            return Response::allow();
        }

        if ($model->getAttribute('workspace_id') !== $currentWorkspaceId) {
            return Response::deny('This resource does not belong to your workspace.');
        }

        return Response::allow();
    }
}
