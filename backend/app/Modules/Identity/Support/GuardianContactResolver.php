<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Notifications\Models\ContactVerification;

/**
 * Turning "my guardian's number" into an account, or admitting it cannot be done.
 *
 * ⚠️ THE INVITATION IS THE RELATION ROW ITSELF (`FR-009هـ`) — there is no second
 * token mechanism — AND `parent_student_relations.guardian_user_id` IS NOT NULL.
 * Those two facts together mean a guardian with no account cannot be invited at
 * all: there is no row to create, and `DispatchNotification` needs a User to
 * address. The design assumed the contact would always resolve; it does not.
 *
 * So this class answers honestly and the caller handles both branches:
 *
 *  - an account exists → a `Pending` relation is created and the guardian is
 *    notified. `Pending` grants NOTHING today, because
 *    `EloquentGuardianDirectory` asks `->active()` in all three of its methods —
 *    so the row is an invitation with no authority BY CONSTRUCTION rather than by
 *    a check somebody has to remember.
 *  - no account → null. The contact is stored on the profile, the student's
 *    account stays `pending_guardian_consent`, and the registration response says
 *    so. Nothing is invented, and nothing the student typed is discarded.
 *
 * ⚠️ AND THE LOOKUP IS `contact_verifications`, NEVER `users.phone`. That column is
 * a free string nobody confirmed — a typo in it is a message about a child sent to
 * a stranger, which is the exact harm this phase exists to prevent. Matching only
 * VERIFIED rows means the worst case is "no match", not "the wrong parent".
 */
final class GuardianContactResolver
{
    public function resolve(?string $contact): ?User
    {
        if ($contact === null || trim($contact) === '') {
            return null;
        }

        $userId = ContactVerification::query()
            ->where('contact_value', trim($contact))
            ->whereNotNull('verified_at')
            // Newest wins: a number that moved between accounts belongs to
            // whoever proved it last.
            ->orderByDesc('verified_at')
            ->value('user_id');

        if ($userId === null) {
            return null;
        }

        // `whereKey()->first()` rather than `find()`: the latter also accepts an
        // array and so is typed as possibly returning a COLLECTION — and widening
        // this return type to match would let a collection reach a caller that
        // treats it as one guardian.
        return User::query()->whereKey($userId)->first();
    }
}
