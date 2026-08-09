<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\TermsConsent;
use App\Policies\BasePolicy;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Auth\Access\Response;

/**
 * Platform-owned, and append-only in spirit.
 *
 * A consent is evidence of what someone agreed to at a moment: the version, the
 * time, the address. There is no update and no delete, because amending a
 * consent is indistinguishable from fabricating one — a later agreement is a new
 * row, and the pair is the history.
 *
 * The signer is the student themselves or a guardian authorised for money; the
 * teacher never signs on a student's behalf, which is the whole point of
 * recording who did.
 */
class TermsConsentPolicy extends BasePolicy
{
    public function view(User $user, TermsConsent $consent): Response
    {
        if ($consent->user_id === $user->getKey() || $consent->student_user_id === $user->getKey()) {
            return Response::allow();
        }

        return Response::deny();
    }

    /** Whether this user may sign for this student. */
    public function signFor(User $user, User $student): Response
    {
        if ($student->getKey() === $user->getKey()) {
            return Response::allow();
        }

        return app(GuardianDirectory::class)->isAuthorised($user, $student, GuardianPermission::Payments)
            ? Response::allow()
            : Response::deny();
    }
}
