<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;

/**
 * The only thing guarding parent_student_relations.
 *
 * That table is platform-owned, so it carries no workspace_id and no global scope
 * touches it. A query against it returns every family on the platform — the same
 * total exposure an unauthenticated marketplace query has, and for the same
 * reason: WorkspaceScope adds no condition when there is nothing to scope by.
 *
 * So the guard is written here, explicitly, three ways:
 *
 *  - the guardian sees their own rows
 *  - the student sees who is linked to them
 *  - a teacher sees a student's guardians ONLY while that student holds an active
 *    enrollment in a course inside the teacher's own workspace
 *
 * The third is the constitutional one (Principle I, teacher-visibility guard).
 * Without it the maths teacher reads the physics teacher's families — their
 * names, their ages, and who is financially answerable for them.
 */
class ParentStudentRelationPolicy
{
    public function view(User $user, ParentStudentRelation $relation): bool
    {
        if ($this->isParty($user, $relation)) {
            return true;
        }

        return $this->teacherMaySee($user, $relation);
    }

    public function update(User $user, ParentStudentRelation $relation): bool
    {
        // Changing what a guardian may see is the family's decision, never the
        // teacher's — the enrollment guard grants reading, not editing.
        return $this->isParty($user, $relation);
    }

    public function delete(User $user, ParentStudentRelation $relation): bool
    {
        return $this->isParty($user, $relation);
    }

    private function isParty(User $user, ParentStudentRelation $relation): bool
    {
        $id = $user->getKey();

        return $id === $relation->guardian_user_id || $id === $relation->student_user_id;
    }

    /**
     * Permission AND enrollment. The permission says this role may ever look; the
     * enrollment says it may look at this student. Either alone is not enough —
     * every teacher holds the permission, so it would gate nothing on its own.
     */
    private function teacherMaySee(User $user, ParentStudentRelation $relation): bool
    {
        if ($relation->student_user_id === null) {
            return false;
        }

        if (! $user->can(Permissions::RELATIONS_VIEW_STUDENT)) {
            return false;
        }

        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            return false;
        }

        return Enrollment::query()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $relation->student_user_id)
            ->where('status', 'active')
            ->exists();
    }
}
