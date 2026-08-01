<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolve a single teacher for the public profile page.
 *
 * Route-model binding is deliberately NOT used: it resolves by uuid without the
 * publiclyListed() guard, which on a guest request means any workspace's draft
 * profile is reachable by url.
 */
class ShowPublicTeacher extends Action
{
    public function handle(string $uuid): TeacherProfile
    {
        $teacher = TeacherProfile::query()
            ->publiclyListed()
            ->with([
                'user:id,first_name,last_name',
                'subjects',
                'gradeLevels',
                'availabilitySlots',
            ])
            ->where('teacher_profiles.uuid', $uuid)
            ->first();

        if ($teacher === null) {
            // One response for "no such teacher", "not approved", "not published"
            // and "workspace withdrew". Distinguishing them would confirm that an
            // unpublished profile exists.
            throw new NotFoundHttpException('غير متاح');
        }

        return $teacher;
    }

    /**
     * The teacher's own publicly listed courses (FR-058).
     *
     * A separate query rather than a relation on TeacherProfile: courses point at
     * a user, not a profile, and adding a second path to the same rows is how the
     * two start disagreeing.
     *
     * @return Collection<int, Course>
     */
    public function coursesOf(TeacherProfile $teacher): Collection
    {
        return Course::query()
            ->publiclyListed()
            ->where('created_by', $teacher->user_id)
            ->with(['creator:id,first_name,last_name', 'creator.teacherProfile'])
            ->withCount(['lessons as lessons_count', 'enrollments as enrolled_count'])
            ->orderByDesc('created_at')
            ->get();
    }
}
