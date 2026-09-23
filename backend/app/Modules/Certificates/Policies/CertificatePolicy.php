<?php

declare(strict_types=1);

namespace App\Modules\Certificates\Policies;

use App\Models\User;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Tenancy\Support\Permissions;
use App\Policies\BasePolicy;
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

        return $user->can(Permissions::CERTIFICATES_VIEW_ALL)
            ? Response::allow()
            : Response::deny();
    }

    public function regenerate(User $user, Certificate $certificate): Response
    {
        if (($workspaceCheck = $this->belongsToCurrentWorkspace($certificate))->denied()) {
            return $workspaceCheck;
        }

        return $user->can(Permissions::CERTIFICATES_REGENERATE)
            ? Response::allow()
            : Response::deny();
    }
}
