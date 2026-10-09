<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Actions\Public;

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\TrialLessonRule;
use App\Shared\Actions\Action;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A course's «حصة تجريبية» for the guest door (spec 040 · FR-010).
 *
 * ⚠️ THE DOOR TAKES A COURSE AND NOTHING ELSE. There is no lesson uuid in the
 * route, so nothing can be guessed: the course's key leads to its CURRENT trial,
 * re-checked by `TrialLessonRule::scopeEligible()` on every request (the page
 * may be stale; this answer never is), or to the one 404.
 *
 * Two or three queries by key: the course (lightweight `publiclyListed()`, not
 * `ReadPublicCourse`'s tree), then the lesson with its asset. Hit again by the
 * stream route at every link reload, so it stays cheap and uncached — a cache
 * would keep serving a trial the teacher just withdrew to a NEW viewer.
 *
 * ⚠️ `withoutWorkspaceScope()` on both reads, declared: a signed-in teacher from
 * another workspace resolves their own context, and the scope would answer
 * «no such course» about a public one (the `ReadPublicPreviewLesson` trap).
 */
final class ReadCourseTrial extends Action
{
    /** @return array{0: Course, 1: Lesson} */
    public function handle(string $courseKey): array
    {
        $course = Course::query()
            ->withoutWorkspaceScope()
            ->publiclyListed()
            ->whereNotNull('trial_lesson_id')
            // Grouped: a bare `orWhere` would escape `publiclyListed()`.
            ->where(fn ($query) => $query->where('slug', $courseKey)->orWhere('uuid', $courseKey))
            ->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', [$courseKey])
            ->first();

        $lesson = $course === null ? null : Lesson::query()
            ->withoutWorkspaceScope()
            ->whereKey($course->trial_lesson_id)
            ->where('course_id', $course->getKey())
            ->tap(fn ($query) => TrialLessonRule::scopeEligible($query))
            ->with('mediaAsset')
            ->first();

        if ($course === null || $lesson === null) {
            throw new NotFoundHttpException('غير متاح');
        }

        return [$course, $lesson];
    }
}
