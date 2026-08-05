<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Collection;

/**
 * Identity's answer to "who else should hear about this student?".
 *
 * Both methods filter on three things together — the student, an active status,
 * and the permission — because dropping any one of them is a silent leak: an
 * inactive relation still has rows, and an active one may cover attendance but
 * not money.
 */
class EloquentGuardianDirectory implements GuardianDirectory
{
    /** @return Collection<int, User> */
    public function authorisedGuardians(User $student, GuardianPermission $permission): Collection
    {
        return ParentStudentRelation::query()
            ->forStudent($student)
            ->active()
            ->with('guardian')
            ->get()
            ->filter(fn (ParentStudentRelation $relation): bool => $relation->allows($permission))
            ->map(fn (ParentStudentRelation $relation): User => $relation->guardian)
            // A guardian could hold both a parent and a guardian row for the same
            // student only through a data fault, but one person must never be
            // told twice about one event.
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->values();
    }

    public function isAuthorised(User $guardian, User $student, GuardianPermission $permission): bool
    {
        $relation = ParentStudentRelation::query()
            ->forStudent($student)
            ->active()
            ->where('guardian_user_id', $guardian->getKey())
            ->first();

        return $relation !== null && $relation->allows($permission);
    }
}
