<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Enums\LessonType;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Support\LessonAudience;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Is this homework hidden from this student?» — asked through the tree.
 *
 * ⛔ THE HOMEWORK DOORS ASK {@see LessonAudience}, NEVER A RULE OF THEIR OWN.
 * `assignments` carries neither audience axis (who it is for · when it appears);
 * the item that places it does. So an assignment's audience IS its item's
 * audience, exactly as an exam's is — a column invented on `assignments` would
 * be a second answer to a question the tree already answers, and the lesson
 * page would open a homework the `/assignments` list hides (or the reverse).
 *
 * Three doors read this: the student's list (`AssignmentController::index`),
 * the single read (`show`, 404) and the hand-in (`SubmitAssignment`). The lesson
 * page is the fourth and asks `LessonGate`, which asks the same class.
 *
 * ⚠️ ANY hidden placement hides the homework, the way `ExamController`'s
 * `hiddenExamIds()` reads it: a teacher who restricted the item to one group
 * meant the homework, not one of its positions.
 *
 * Every read bypasses `WorkspaceScope` for the reason `LessonAudience` does: a
 * student's context is null or another teacher's workspace, and a scoped read
 * would answer differently by reader.
 */
final class AssignmentAudience
{
    /**
     * Ids of the homework hidden from this reader, among these courses' trees.
     *
     * @param  list<int>  $courseIds  the reader's own courses — the list is
     *                                already narrowed to them by `StudentScope`
     * @return list<int>
     */
    public static function hiddenIdsFor(User $viewer, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        return array_values(array_unique(LessonAudience::hiddenLessons($viewer, self::placements()
            ->whereIn('course_id', $courseIds))
            ->map(static fn (Lesson $lesson): int => (int) $lesson->reference_id)
            ->all()));
    }

    /** Whether any item placing this homework hides it from this reader. */
    public static function hides(User $viewer, Assignment $assignment): bool
    {
        $items = self::placements()
            ->where('reference_id', $assignment->getKey())
            ->get();

        if ($items->isEmpty()) {
            return false;
        }

        foreach (LessonAudience::hiddenAmong($viewer, $items) as $code) {
            if ($code !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return Builder<Lesson> */
    private static function placements(): Builder
    {
        return Lesson::query()
            ->withoutWorkspaceScope()
            ->where('type', LessonType::Assignment->value)
            ->whereNotNull('reference_id');
    }
}
