<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * The teachers and courses a public article may link to (011 · US5 · FR-038).
 *
 * ⚠️ IT LIVES IN MARKETPLACE AND NOT IN CMS, AND THAT IS THE REQUIREMENT RATHER
 * THAN TIDINESS. FR-038 forbids a related link to a suspended teacher or to one
 * outside the public marketplace — which is `publiclyListed()`, and that
 * predicate belongs to the module that owns approval, suspension and
 * participation. A CMS-side query joining `teacher_profiles` would be a second
 * spelling of «who may be shown», and the failure direction is a card for a
 * teacher the platform has suspended, sitting on an indexed page.
 *
 * ⚠️ AND RELATEDNESS IS THE WORKSPACE, not a subject match. The article is
 * written by a teacher about their own teaching, so the link a reader wants is
 * that teacher and what they sell; a subject-similarity ranking is a feature
 * nobody asked for and a second source of truth for «related». If the workspace
 * has withdrawn from the marketplace the two lists come back EMPTY rather than
 * widened to somebody else's teachers — an article that is public because its
 * workspace opted in cannot advertise a workspace that did not.
 */
class RelatedTeachers extends Action
{
    /**
     * @return array{teachers: Collection<int, TeacherProfile>, courses: Collection<int, Course>}
     */
    public function handle(int $workspaceId, int $teacherLimit = 3, int $courseLimit = 3): array
    {
        /*
        | ⚠️ THE READER IS A GUEST, SO THIS RUNS OUTSIDE THE TENANT SCOPE ALREADY
        | — but not necessarily: `/public/articles/{slug}` is reachable by a
        | signed-in member too, and then `WorkspaceScope` filters both queries by
        | THEIR workspace, so a teacher reading a colleague's article on another
        | platform tenant would see an empty related block and nothing would say
        | why. `withoutScope()` makes the answer the same for everybody, which is
        | what a public payload has to be.
        */
        return app(WorkspaceContext::class)->withoutScope(function () use ($workspaceId, $teacherLimit, $courseLimit): array {
            $teachers = TeacherProfile::query()
                ->publiclyListed()
                ->where('teacher_profiles.workspace_id', $workspaceId)
                ->with(['user:id,first_name,last_name', 'subjects', 'gradeLevels', 'availabilitySlots'])
                ->orderByDesc('teacher_profiles.trust_score')
                ->limit($teacherLimit)
                ->get();

            $courses = Course::query()
                ->publiclyListed()
                ->where('courses.workspace_id', $workspaceId)
                ->with(['creator:id,first_name,last_name', 'creator.teacherProfile'])
                ->withCount([
                    // ⚠️ SCOPED, exactly as `ListPublicCourses` had to be. A bare
                    // `withCount('lessons')` counts a teacher's drafts and their
                    // retired archive, so the card advertises more items than the
                    // course opens — FR-062's leak arriving as one number.
                    'lessons as lessons_count' => fn ($query) => $query->visibleToStudents(),
                    'enrollments as enrolled_count',
                ])
                ->orderByDesc('courses.created_at')
                ->limit($courseLimit)
                ->get();

            return ['teachers' => $teachers, 'courses' => $courses];
        });
    }
}
