<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Models\User;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Scopes\TeamRoleScope;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use DomainException;

/**
 * Nobody hands out more than they hold (audit 2026-09-30).
 *
 * ⚠️ `members.update` AND `members.invite` ARE DELEGABLE, AND THE ONLY REFUSAL
 * WAS THE OWNER'S OWN ROW. So a custom role an owner ticked «تعديل — الأعضاء» on
 * could set ITSELF to `tenant-owner` — every tenant permission, including the
 * workspace's money — or promote a friend there; one holding `members.invite`
 * could invite a new `tenant-owner`. Two doors (`UpdateWorkspaceMemberRole`,
 * `InviteMember`), one rule, asked from both:
 *
 *  - nobody changes their OWN role — the owner's row is already refused, and a
 *    self-promotion is the escalation itself;
 *  - `tenant-owner` is granted by the workspace's owner (or a super admin, who
 *    reaches every workspace at platform level) and by nobody else;
 *  - any other role is granted only by somebody who holds EVERY permission it
 *    carries here. Asked through `can()`, never `hasPermissionTo()`, so the
 *    assistant wall's `Gate::before` counts: a walled assistant with
 *    `members.update` cannot mint a teacher, whose role carries the finances the
 *    wall keeps from them.
 *
 * ⚠️ NOT A CHECK ON DEMOTION. Moving a SECOND `tenant-owner` down is still open
 * to a delegated `members.update` — a nuisance, not an escalation, and left for
 * the owner to decide.
 */
final class RoleGrants
{
    public const SELF = 'لا يمكنك تغيير دورك بنفسك.';

    public const OWNER_ONLY = 'منح دور «مالك» لصاحب مساحة العمل وحده.';

    public const EXCEEDS = 'لا يمكنك منح دور يحمل صلاحيات لا تملكها.';

    public function __construct(private readonly WorkspaceContext $context) {}

    /** @throws DomainException when the actor may not hand `$role` to `$member` */
    public function guard(Workspace $workspace, User $actor, ?User $member, string $role): void
    {
        if ($member !== null && (int) $member->getKey() === (int) $actor->getKey()) {
            throw new DomainException(self::SELF);
        }

        if ($actor->isSuperAdmin() || $workspace->isOwnedBy($actor)) {
            return;
        }

        if ($role === Roles::TENANT_OWNER) {
            throw new DomainException(self::OWNER_ONLY);
        }

        $permissions = Role::query()
            ->withoutGlobalScope(TeamRoleScope::class)
            ->where('name', $role)
            ->where('team_id', $workspace->getKey())
            ->first()
            ?->permissions()
            ->pluck('name')
            ->all() ?? [];

        $lacking = $this->context->forWorkspace($workspace, function () use ($actor, $permissions): bool {
            // Read under THIS workspace's team id, not whatever was cached.
            $actor->unsetRelation('roles')->unsetRelation('permissions');

            foreach ($permissions as $permission) {
                if (! $actor->can((string) $permission)) {
                    return true;
                }
            }

            return false;
        });

        if ($lacking) {
            throw new DomainException(self::EXCEEDS);
        }
    }
}
