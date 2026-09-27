<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use DomainException;

/**
 * A student's or a parent's account never becomes workspace staff (owner
 * decision 2026-09-26).
 *
 * ⛔ THREE DOORS WRITE A STAFF ROW, AND THIS IS ASKED AT ALL THREE:
 * `InviteMember` (the invitation is refused before it is mailed),
 * `AcceptInvitation` (an existing learner account holding one is refused), and
 * `UpdateWorkspaceMemberRole` (a learner's `student` row is not promoted). One
 * rule in one class, because a rule written three times is three rules that
 * agree until the first one moves.
 *
 * The person who both studies and teaches keeps two accounts — one per email —
 * and the refusal says so, rather than leaving them to guess why.
 *
 * ⚠️ THE ACCOUNT IS READ FROM `users.platform_role`, AND A NULL ROLE PASSES.
 * That column is null for the academy-signup path and for dozens of older
 * accounts (37 measured, 8 of them teaching): nothing says what those are, and
 * refusing them would lock real teachers out of their own teams. The rule is
 * about accounts that DECLARED themselves a student or a parent.
 *
 * ⚠️ «STAFF» IS ASKED IN THE NEGATIVE — every role but `student` — so a custom
 * role invented from the roles screen counts as staff and is refused too.
 */
final class StaffAccounts
{
    public const REFUSAL = 'هذا البريد مسجّل لحساب طالب أو وليّ أمر، ولا يمكن أن يصبح عضواً في فريق العمل. أنشئ حساباً منفصلاً للتدريس ببريد إلكتروني آخر.';

    public static function isStaffRole(string $role): bool
    {
        return $role !== Roles::STUDENT;
    }

    public static function isLearnerAccount(User $user): bool
    {
        return $user->platform_role === PlatformRole::Student
            || $user->platform_role === PlatformRole::Parent;
    }

    /** @throws DomainException when a learner account would receive a staff role */
    public static function guard(?User $user, string $role): void
    {
        if ($user !== null && self::isStaffRole($role) && self::isLearnerAccount($user)) {
            throw new DomainException(self::REFUSAL);
        }
    }

    /**
     * The existing account behind an email, compared case-insensitively on
     * every engine: MySQL's `_ci` collation already does, SQLite does not, and
     * a guard that only bites in production is a guard no test proves.
     */
    public static function accountFor(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();
    }
}
