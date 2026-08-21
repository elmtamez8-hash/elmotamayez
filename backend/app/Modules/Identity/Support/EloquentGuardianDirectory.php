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

    /** @return list<GuardianPermission> */
    public function permissionsFor(User $guardian, User $student): array
    {
        $relation = ParentStudentRelation::query()
            ->forStudent($student)
            ->active()
            ->where('guardian_user_id', $guardian->getKey())
            ->first();

        if ($relation === null) {
            return [];
        }

        // Asked through `allows()` rather than by reading the column, because that
        // method is where a wildcard or a future default would live — reading the
        // json directly is a second answer to the question it exists to answer.
        return array_values(array_filter(
            GuardianPermission::cases(),
            fn (GuardianPermission $permission): bool => $relation->allows($permission),
        ));
    }

    /** @return Collection<int, User> */
    public function childrenOf(User $guardian, GuardianPermission $permission): Collection
    {
        $studentIds = ParentStudentRelation::query()
            ->where('guardian_user_id', $guardian->getKey())
            ->active()
            // A child known only by name has no account to act on. Filtered in
            // SQL rather than after mapping, so a null never reaches the caller
            // as a Collection element it has to remember to check.
            ->whereNotNull('student_user_id')
            ->get()
            ->filter(fn (ParentStudentRelation $relation): bool => $relation->allows($permission))
            ->pluck('student_user_id')
            // One person, one row — a student holding both a parent and a
            // guardian relation to the same adult is a data fault, but listing
            // them twice would double every total computed from this list.
            ->unique()
            ->all();

        // Loaded by id rather than through the relation: `student` is nullable
        // on the model, and a mapped-then-filtered collection carries that null
        // through the type without ever being able to produce one here.
        return User::query()->whereIn('id', $studentIds)->get()->values();
    }
}
