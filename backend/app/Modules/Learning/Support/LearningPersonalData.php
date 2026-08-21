<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Shared\Contracts\PersonalDataOwner;
use App\Shared\Data\DataSubject;
use App\Shared\Support\ErasureMode;
use App\Shared\Support\ExpiryBehaviour;
use App\Shared\Support\ExportWalk;
use Carbon\CarbonImmutable;

/**
 * Learning's half of the data-rights contract (spec 013).
 *
 * ⚠️ REGISTERED WITH ONE TAGGED LINE in this module's provider, and `Compliance`
 * never names a table here. That is the whole reason a requirement crossing
 * thirteen schemas does not violate Constitution III.
 *
 * @see PersonalDataOwner
 */
class LearningPersonalData implements PersonalDataOwner
{
    public function moduleKey(): string
    {
        return 'learning';
    }

    /** @return list<string> */
    public function describe(): array
    {
        return ['enrollment_record', 'lesson_progress'];
    }

    /**
     * ⚠️ A GENERATOR, NOT AN ARRAY. `SC-014` measures fifty thousand rows, and
     * thirteen full arrays held in memory while each is JSON-encoded peaks at
     * twice the serialised size — above the worker's ceiling, with `tries: 1`.
     *
     * @return iterable<string, array<int, array<string, mixed>>>
     */
    public function export(DataSubject $subject): iterable
    {
        /*
        | The course TITLE rather than its id, and it arrives by JOIN.
        |
        | ⚠️ NEVER `->with('course')`: an eager load runs the related model's own
        | global scope inside the relation query, so `WorkspaceScope` would return
        | null for every course outside whatever workspace the reader happens to be
        | resolved into — silently exporting a list of blanks. A join is raw SQL and
        | no scope touches it. This is the defect that shipped in the audit chain.
        */
        yield from ExportWalk::keyed(
            'enrollment_record',
            Enrollment::query()
                ->withoutWorkspaceScope()
                ->leftJoin('courses', 'courses.id', '=', 'enrollments.course_id')
                ->where('enrollments.student_user_id', $subject->user->getKey())
                ->select(['enrollments.*', 'courses.title as course_title']),
            fn (Enrollment $enrollment): array => [
                'uuid' => $enrollment->uuid,
                'course_title' => $enrollment->getAttribute('course_title'),
                'status' => $enrollment->status,
                'source' => $enrollment->source,
                'progress_pct' => $enrollment->progress_pct,
                'enrolled_at' => ExportWalk::at($enrollment->enrolled_at),
                'completed_at' => ExportWalk::at($enrollment->completed_at),
                'expires_at' => ExportWalk::at($enrollment->expires_at),
            ],
            column: 'enrollments.id',
        );

        /*
        | ⚠️ `lesson_progress` CARRIES NO USER COLUMN. It reaches its student only
        | through `enrollment_id`, which is why {@see DataSubject} carries the id
        | list — resolved once at the top of the walk rather than by each module.
        |
        | The list is sliced at 500 because IT is the large object here, not the
        | walk: a `whereIn` with thousands of bound parameters is what breaks first,
        | and the rows themselves are already paged underneath it.
        */
        /*
        | ⚠️ THE KEY IS YIELDED EVEN WHEN THE ID LIST IS EMPTY. `array_chunk([])`
        | iterates zero times, so a person with no enrolments produced no
        | `lesson_progress.json` at all — silence where "we hold no progress for you"
        | is the answer. The same shape as the six early returns
        | `ExportWalk::none()` covers.
        */
        if ($subject->enrollmentIds === []) {
            yield from ExportWalk::none('lesson_progress');
        }

        foreach (array_chunk($subject->enrollmentIds, 500) as $enrollmentIds) {
            yield from ExportWalk::keyed(
                'lesson_progress',
                LessonProgress::query()
                    ->withoutWorkspaceScope()
                    ->leftJoin('lessons', 'lessons.id', '=', 'lesson_progress.lesson_id')
                    ->whereIn('lesson_progress.enrollment_id', $enrollmentIds)
                    ->select(['lesson_progress.*', 'lessons.title as lesson_title']),
                fn (LessonProgress $row): array => [
                    'lesson_title' => $row->getAttribute('lesson_title'),
                    'status' => $row->status,
                    'started_at' => ExportWalk::at($row->started_at),
                    'completed_at' => ExportWalk::at($row->completed_at),
                    'time_spent_seconds' => $row->time_spent_seconds,
                ],
                column: 'lesson_progress.id',
            );
        }
    }

    /**
     * ⚠️ THE MODE IS RECEIVED, NEVER INVENTED, and the walk is `chunkById` (for
     * anonymising, where the row survives and needs a cursor) or a
     * `->limit(n)->delete()` loop (for deleting). Never `chunk`: it paginates by
     * OFFSET while the predicate shrinks underneath it, so every page after the
     * first skips as many rows as the last one fixed — and reports success.
     */
    public function erase(DataSubject $subject, ErasureMode $mode, int $limit): int
    {
        if ($mode !== ErasureMode::Delete) {
            return 0;
        }

        /*
        | ⚠️ CHILDREN FIRST, AND `lesson_progress` REACHES ITS STUDENT ONLY THROUGH
        | `enrollment_id`. Deleting the enrolments first would orphan every progress
        | row behind an id nothing can resolve — rows about a person that no walk
        | can ever find again, which is FR-020 broken by an ordering rather than by
        | an omission.
        |
        | The id list comes from the subject rather than from a query here: it is
        | resolved once at the top of the walk, and it is still true after this
        | batch because the enrolments are deleted only when the progress is gone.
        */
        $deleted = 0;

        foreach (array_chunk($subject->enrollmentIds, 500) as $enrollmentIds) {
            $deleted += LessonProgress::query()
                ->withoutWorkspaceScope()
                ->whereIn('enrollment_id', $enrollmentIds)
                ->limit($limit)
                ->delete();

            if ($deleted >= $limit) {
                return $deleted;
            }
        }

        // Only once no progress row is left: the predicate above depends on these.
        return $deleted + Enrollment::query()
            ->withoutWorkspaceScope()
            ->where('student_user_id', $subject->user->getKey())
            ->limit($limit - $deleted)
            ->delete();
    }

    /**
     * ⚠️ THE FUNCTION WITHOUT WHICH THERE IS NO SWEEP. `erase()` takes a PERSON;
     * retention takes an AGE and no person. The module owns the predicate, so the
     * module owns its `(created_at)` index.
     */
    public function expire(string $category, CarbonImmutable $before, ExpiryBehaviour $mode, int $limit): int
    {
        // TODO(013-US5): process rows of $category older than $before.
        return 0;
    }
}
