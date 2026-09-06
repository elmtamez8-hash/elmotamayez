<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Policies;

use App\Models\User;
use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Who may choose, adjust, upload and delete a workspace's certificate design.
 *
 * ⚠️ NO NEW PERMISSION WAS MINTED, AND THAT IS A MEASUREMENT RATHER THAN LAZINESS
 * (`research.md` ق-١٢). `SeedDefaultRoles` runs ONCE, at workspace creation — so a
 * permission added today reaches ZERO existing workspaces unless a role-backfill
 * migration is written for it, and one that is assigned to no role at all is
 * derived as PLATFORM-level by `RolePermissionMatrix::platformPermissions()`
 * (`all()` minus everything any tenant role holds), which means no teacher on the
 * platform could hold it. That is the `taxonomy.manage` defect: declared, seeded,
 * asserted platform-level by its own test, and read by nothing that anybody could
 * reach.
 *
 * `certificates.regenerate` is the permission that guarded the deleted
 * `CertificateTemplateController` — the same act, the same actor — and it is in
 * the `teacher` role today.
 *
 * ⚠️ AND THE ALLOW DIRECTION IS WHAT `DesignSelectionTest` MEASURES. A deny-only
 * test proves nothing about whether this file is ever consulted: Laravel's policy
 * resolution fails OPEN into "no policy applies", so a student refused for some
 * unrelated reason reads exactly like a policy that works. The teacher who CAN
 * select is the case that fails if this class is never reached.
 */
class CertificateDesignPolicy extends BasePolicy
{
    public function viewAny(User $user): Response
    {
        return $this->mayDesign($user);
    }

    public function create(User $user): Response
    {
        return $this->mayDesign($user);
    }

    public function update(User $user, CertificateDesign $design): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($design))->denied()) {
            return $workspaceCheck;
        }

        return $this->mayDesign($user);
    }

    public function delete(User $user, CertificateDesign $design): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($design))->denied()) {
            return $workspaceCheck;
        }

        return $this->mayDesign($user);
    }

    private function mayDesign(User $user): Response
    {
        return $user->can(Permissions::CERTIFICATES_REGENERATE)
            ? Response::allow()
            : Response::deny();
    }
}
