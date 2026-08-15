<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Models\User;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'status' => $this->status,
            'is_super_admin' => $this->is_super_admin,
            // FR-012: the frontend routes on this after login. Hidden on the model
            // so it never leaks through a stray ->toArray(); named here on purpose.
            'platform_role' => $this->platform_role?->value,
            'last_workspace_id' => $this->last_workspace_id,
            // Nested rather than flattened onto the root: these are true of a
            // student and of nobody else, and a null grade_level_slug on a
            // teacher's payload reads as missing data rather than as inapplicable.
            //
            // Read straight off the relation, not whenLoaded: every caller of this
            // resource passes a single user (never a collection), so the lazy load
            // is one query, and whenLoaded would silently omit the key wherever a
            // caller forgot to eager-load it.
            'student_profile' => $this->studentProfile === null ? null : [
                'grade_level_slug' => $this->studentProfile->grade_level_slug,
                'registered_by_parent' => $this->studentProfile->registered_by_parent,
            ],
            /*
             | ⚠️ WHAT THIS PERSON MAY DO, because the client had no way to ask.
             |
             | The panel's sidebar offered every teacher screen to every account:
             | a student signed in and was shown the course editor, the exam
             | builder, the question bank and the settlement statement. The server
             | refused all four with a 403 — this was never an authorisation hole —
             | but a menu of links that answer "forbidden" reads as a product that
             | is broken, and it teaches the reader that the app does not know who
             | they are.
             */
            'permissions' => $this->grantedPermissions(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Every permission name this user holds in the workspace they are in.
     *
     * ⚠️ ASKED THROUGH THE GATE, never through spatie's `getAllPermissions()`.
     * A super admin's powers come from `users.is_super_admin` and a platform
     * officer's from `platform_staff`, and BOTH are granted by a `Gate::before`
     * hook that spatie's own accessors cannot see — so the spatie-derived list
     * hands the super admin an empty sidebar. Both layers memoise per request, so
     * walking the constants costs nothing worth caching.
     *
     * ⚠️ AND IT IS COMPUTED INSIDE `forWorkspace()`, which is what makes the
     * LOGIN response correct. spatie runs in team mode, and during `/auth/login`
     * the context resolved as a guest and froze there — the singleton caches its
     * resolution — so every check would answer false and the panel would come up
     * with an empty menu until the first reload.
     *
     * @return list<string>
     */
    private function grantedPermissions(): array
    {
        $user = $this->resource;

        $resolve = static function () use ($user): array {
            /*
            | ⚠️ THE LOADED RELATIONS ARE DROPPED FIRST, and without this line the
            | answer is whichever workspace was asked about EARLIER in the same
            | process. spatie reads `$user->roles`, and once Eloquent has loaded
            | that relation it hands back the same rows however the team id moves
            | underneath it. Two symptoms, both found by the tests beside this
            | file: a person who teaches at one academy and studies at another saw
            | the first one's menu at the second, and the LOGIN response — where
            | the guest-resolved context is replaced a moment later — came back
            | with an empty list.
            */
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $granted = [];

            foreach (Permissions::all() as $permission) {
                if ($user->can($permission)) {
                    $granted[] = $permission;
                }
            }

            return $granted;
        };

        $workspaceId = app(WorkspaceContext::class)->id() ?? $user->last_workspace_id;

        // No workspace at all is a marketplace account, and the honest answer for
        // one is the empty list — not a guess at what they would hold somewhere.
        return $workspaceId === null
            ? $resolve()
            : app(WorkspaceContext::class)->forWorkspace((int) $workspaceId, $resolve);
    }
}
