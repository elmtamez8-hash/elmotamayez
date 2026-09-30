<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Policies;

use App\Models\User;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
use App\Shared\Contracts\AssistantScopeDirectory;
use Illuminate\Auth\Access\Response;

class CertificatePolicy extends BasePolicy
{
    public function view(User $user, Certificate $certificate): Response
    {
        // Ownership first: a student stamped with another teacher's workspace
        // fails the workspace check on the certificate they earned.
        if ($certificate->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        if (($workspaceCheck = $this->belongsToCurrentWorkspace($certificate))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $certificate))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::CERTIFICATES_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    public function regenerate(User $user, Certificate $certificate): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($certificate))->denied()) {
            return $workspaceCheck;
        }

        if (($scopeCheck = $this->withinAssistantScope($user, $certificate))->denied()) {
            return $scopeCheck;
        }

        return $user->can(Permissions::CERTIFICATES_REGENERATE)
            ? Response::allow()
            : Response::deny();
    }

    /**
     * Spec 010 · FR-005 (audit 2026-09-30) — a confined assistant reads and
     * re-issues the certificates of their own courses only. Asked BESIDE the
     * permission and below the student's own-row branch, so a certificate the
     * assistant earned themselves is still theirs to open.
     */
    private function withinAssistantScope(User $user, Certificate $certificate): Response
    {
        return app(AssistantScopeDirectory::class)->mayActOnCourse(
            $user,
            (int) $certificate->workspace_id,
            (int) $certificate->course_id,
        )
            ? Response::allow()
            : Response::deny('هذا الكورس خارج نطاق عملك.');
    }
}
