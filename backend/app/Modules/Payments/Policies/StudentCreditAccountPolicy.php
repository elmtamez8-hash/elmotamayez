<?php

declare(strict_types=1);

namespace App\Modules\Payments\Policies;

use App\Models\User;
use App\Modules\Payments\Models\StudentCreditAccount;
use App\Policies\BasePolicy;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Auth\Access\Response;

/**
 * Platform-owned — constitution v1.2.0 §I, kind (أ): student identity.
 *
 * One account per student across every teacher they study with, so it carries no
 * `workspace_id` and no global scope touches it. That means this policy is the
 * only thing between one person's account and anyone who can guess a uuid, in
 * exactly the way `ParentStudentRelationPolicy` is.
 *
 * Two readers and no third: the student, and a guardian authorised for money.
 * A teacher is deliberately absent — what a teacher may see is the balance
 * inside their own workspace, which is a different model with a different guard.
 * The account itself spans workspaces and telling a teacher about it would tell
 * them who else this student studies with.
 */
class StudentCreditAccountPolicy extends BasePolicy
{
    public function view(User $user, StudentCreditAccount $account): Response
    {
        if ($account->user_id === $user->getKey()) {
            return Response::allow();
        }

        // GuardianPermission::Payments specifically: a guardian with no right to
        // the financial record has no business in a payment reminder about it.
        return app(GuardianDirectory::class)->isAuthorised($user, $account->user, GuardianPermission::Payments)
            ? Response::allow()
            : Response::deny();
    }
}
