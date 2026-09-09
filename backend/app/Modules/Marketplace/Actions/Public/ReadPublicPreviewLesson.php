<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Actions\Action;

/**
 * One open embedded lesson, read by a visitor with no account (032 · US2).
 *
 * ⚠️ ONE QUERY, ONE REFUSAL. Any check performed AFTER the row is fetched
 * produces a second refusal branch, and two branches do not produce one body
 * however alike their status codes look. FR-009 asks for a refusal that is
 * byte-identical to «no such thing», so there is exactly one road to it.
 *
 * ⚠️ AND THE COURSE KEY IS A SLUG **OR** A UUID. `ReadPublicCourse` resolves
 * both and the public course page lives at `/courses/[slug]`, so a route
 * accepting the uuid alone answers 404 to every link that page builds — with the
 * uniform body making the cause invisible. The resolver is reused verbatim
 * rather than restated.
 */
class ReadPublicPreviewLesson extends Action
{
    public function __construct(
        private readonly ReadPublicCourse $courses,
    ) {}

    /**
     * @return array{0: Course, 1: Lesson}|null null for every refusal alike
     */
    public function handle(string $courseKey, string $lessonUuid): ?array
    {
        /*
        | ⚠️ NO SECOND STATUS CHECK HERE, AND ITS ABSENCE IS DELIBERATE.
        | `publicListingConstraints()` inside that resolver already requires
        | `status = 'published'`, `visibility = 'public'`, an approved and
        | publicly listed teacher, and a participating workspace. Repeating one
        | of them would be a second spelling of «is this course public» — and
        | the open mark opens the LESSON, it never publishes the course (FR-011).
        */
        $course = $this->courses->handle($courseKey);

        if ($course === null) {
            return null;
        }

        $lesson = Lesson::query()
            /*
            | ⚠️ DECLARED, NOT INHERITED — and «a guest activates no scope» is
            | true and is exactly the blind spot. Whoever opens this page may be
            | a TEACHER SIGNED IN FROM ANOTHER WORKSPACE: their context resolves
            | from `users.last_workspace_id`, the scope bites, and they get the
            | 404 that means «no such thing» about a lesson that exists. The
            | guest reads it correctly whether the bypass is here or not, so a
            | test with only the guest case proves nothing.
            */
            ->withoutWorkspaceScope()
            ->where('course_id', $course->getKey())
            /*
            | ⚠️ THREE STATUS CONDITIONS, NOT ONE. The lesson, its chapter and
            | its section. A teacher who unpublishes a SECTION after the link
            | has spread would otherwise leave a public door working for ever —
            | the defect `IssuePlaybackGrant` was fixed for once already.
            */
            ->visibleToStudents()
            ->where('type', LessonType::Embed->value)
            /*
            | ⚠️ GROUPED. A bare `orWhere` at the top level swallows every
            | condition above it and hands the whole tree to any visitor.
            */
            ->where(fn ($query) => $query->where('is_preview', true)->orWhere('is_free', true))
            ->where('uuid', $lessonUuid)
            ->first();

        if ($lesson === null) {
            return null;
        }

        // ⚠️ THE BYPASS IS PER MODEL. `->with('course')` would run Course's own
        // global scope inside the relation query and answer null for the very
        // reader described above.
        $lesson->setRelation('course', $course);

        return [$course, $lesson];
    }
}
