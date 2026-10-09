<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\DTOs\SetTrialLessonData;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\TrialLessonRule;
use App\Shared\Actions\Action;
use Illuminate\Validation\ValidationException;

/**
 * Marks — or clears — a course's «حصة تجريبية» (spec 040). The ONE writer of
 * `courses.trial_lesson_id`; the column is deliberately not mass-assignable.
 *
 * MARKING is a plain write of the column: a second pick replaces the first by
 * construction, and the last writer winning breaks no rule because every reader
 * asks `TrialLessonRule::scopeEligible()` again.
 *
 * CLEARING is conditional on the lesson the reader saw (FR-007): «clear THIS
 * trial» from a stale tab must not wipe a newer pick made elsewhere. Zero rows
 * affected is not an error — the answer is simply the current state.
 *
 * Who may call this is `CoursePolicy::chooseTrialLesson`, asked by the caller.
 */
final class SetCourseTrialLesson extends Action
{
    public function handle(Course $course, SetTrialLessonData $data): Course
    {
        if ($data->lesson === null) {
            $replacing = Lesson::query()
                ->withoutWorkspaceScope()
                ->where('uuid', (string) $data->replacing)
                ->value('id');

            if ($replacing !== null) {
                Course::query()
                    ->withoutWorkspaceScope()
                    ->whereKey($course->getKey())
                    ->where('trial_lesson_id', $replacing)
                    ->update(['trial_lesson_id' => null]);
            }

            return $course->refresh();
        }

        $lesson = Lesson::query()
            ->withoutWorkspaceScope()
            ->with('mediaAsset')
            ->where('uuid', $data->lesson)
            ->first();

        $refusal = $lesson === null
            ? 'اختر درساً من هذا الكورس.'
            : TrialLessonRule::refusalFor($course, $lesson);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['lesson' => $refusal]);
        }

        $course->forceFill(['trial_lesson_id' => $lesson?->getKey()])->save();

        return $course->refresh();
    }
}
