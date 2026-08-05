<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Contracts\EnrollmentDirectory;

/**
 * Learning's answer to "is this person entitled?".
 *
 * Queries deliberately run without the workspace scope: a student is enrolled
 * with many teachers and asks about a lesson before any workspace is current, so
 * scoping here would return nothing and silently deny every playback. The guard
 * is the student's own id, which is stricter than a workspace filter would be.
 */
class EloquentEnrollmentDirectory implements EnrollmentDirectory
{
    public function hasActiveEnrollment(User $user, int $courseId): bool
    {
        return Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->exists();
    }

    /** @return list<int> */
    public function activeCourseIdsFor(User $user): array
    {
        $ids = Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $user->getKey())
            ->where('status', 'active')
            ->pluck('course_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        return array_values($ids);
    }
}
