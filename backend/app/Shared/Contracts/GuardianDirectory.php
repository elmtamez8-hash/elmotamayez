<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Collection;

/**
 * Who else should hear about something that happened to a student.
 *
 * Exists so that Notifications can resolve guardians without reaching into
 * Identity's models, which Constitution III forbids. Identity owns the relation
 * and binds the implementation; Notifications depends on this interface and does
 * not know the table exists.
 *
 * Read-only by design. Creating or revoking a relation is Identity's own Action,
 * reached through Identity's own endpoints.
 */
interface GuardianDirectory
{
    /**
     * Active guardians of this student who are authorised for this permission.
     *
     * Returns an empty collection — never null — when the student has none, so a
     * caller cannot forget to handle "nobody".
     *
     * @return Collection<int, User>
     */
    public function authorisedGuardians(User $student, GuardianPermission $permission): Collection;

    /**
     * Whether this specific guardian is still authorised right now.
     *
     * Separate from the list because a queued delivery has to re-check at send
     * time: the relation may have been revoked while the job waited (FR-023).
     */
    public function isAuthorised(User $guardian, User $student, GuardianPermission $permission): bool;

    /**
     * The students this guardian may act for under this permission.
     *
     * The mirror of {@see self::authorisedGuardians()}, and added for spec 006:
     * without it a guardian has no route to their children's balances at all,
     * and the only alternative — Payments querying `parent_student_relations`
     * itself — is a Constitution III breach on a platform-owned table that
     * carries no workspace scope to fall back on.
     *
     * Children registered by name alone are absent by design: the relation
     * carries `student_name` with no `student_user_id` until that child signs
     * up, and there is no account to show a balance for.
     *
     * @return Collection<int, User>
     */
    public function childrenOf(User $guardian, GuardianPermission $permission): Collection;
}
