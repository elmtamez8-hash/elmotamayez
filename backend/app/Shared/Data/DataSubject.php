<?php

declare(strict_types=1);

namespace App\Shared\Data;

use App\Models\User;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Support\GuardianPermission;

/**
 * Who an export or an erasure is about, resolved ONCE (spec 013).
 *
 * ⚠️ THE CONTRACT TAKES THIS AND NOT A `User`, and the reason is that thirteen
 * modules would otherwise each answer the same question for themselves. "Which
 * workspaces is this student in" is one query, and every implementor would have
 * to get `withoutWorkspaceScope()` right on its own — thirteen places for one
 * decision, twelve of which nobody reviews. It is resolved at the top of the walk
 * and passed down.
 *
 * @see PersonalDataOwner
 */
final class DataSubject extends DataTransferObject
{
    /**
     * @param  list<int>  $workspaceIds  every workspace holding rows about them
     * @param  list<int>  $enrollmentIds  the parent-id list for the tables that carry
     *                                    no user column of their own —
     *                                    `lesson_progress` reaches its student only
     *                                    through here
     * @param  list<GuardianPermission>|null  $grantedScope  null when the subject
     *                                                       asked for their own data
     */
    public function __construct(
        public readonly User $user,
        public readonly array $workspaceIds = [],
        public readonly array $enrollmentIds = [],
        public readonly ?array $grantedScope = null,
    ) {}

    /**
     * Whether this reader is entitled to a category gated behind one permission.
     *
     * ⚠️ NULL MEANS EVERYTHING, AND THAT IS NOT A MISSING CHECK. The subject
     * reading their own record has no guardian scope to be limited by; a guardian
     * granted "attendance" alone must not receive results, payments or recordings
     * — the permission limits the CONTENT, not merely the door.
     */
    public function mayReceive(GuardianPermission $permission): bool
    {
        if ($this->grantedScope === null) {
            return true;
        }

        return in_array($permission, $this->grantedScope, true);
    }
}
