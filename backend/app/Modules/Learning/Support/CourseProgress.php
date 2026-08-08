<?php

declare(strict_types=1);

namespace App\Modules\Learning\Support;

use App\Modules\Learning\Models\Enrollment;

/**
 * How far through a course one student is — computed in exactly one place.
 *
 * There are now three callers: `MarkLessonComplete` (a lesson was finished), the
 * listener that resyncs after a publish (the denominator moved under everyone),
 * and the impact preview that promises the teacher what that publish will do
 * (`FR-049`). `SC-018` is the promise that the third one's answer matches the
 * first two's to the percentage point — which is only true while there is one
 * formula and one denominator, so both live here.
 *
 * **`sync()` never withdraws a completion.** `FR-050` is explicit: content added
 * after a student finished may drop their percentage but may not take back their
 * completion or their certificate. That is not enforced by a check somewhere; it
 * is the shape of this method — it writes `status` in one direction only.
 */
final class CourseProgress
{
    public static function percentage(int $completed, int $total): int
    {
        return $total > 0 ? min(100, (int) round(($completed / $total) * 100)) : 0;
    }

    /**
     * The denominator: published, completable, non-recording items of a course.
     *
     * It used to be `$enrollment->course->lessons()->count()` — every row, no
     * distinction. Two consequences, both of which were live: a half-written
     * lesson saved into a running course dropped every enrolled student's
     * percentage the moment it was created, and a session recording sat in the
     * denominator of students who cannot open it at all.
     */
    public static function total(Enrollment $enrollment): int
    {
        return $enrollment->course->lessons()->countableForProgress()->count();
    }

    /**
     * Completed items that still count.
     *
     * Filtered the same way as the denominator: a student who finished a lesson
     * that was later archived must not end up at 110%, and one who watched a
     * recording must not be credited against a total it is not part of.
     */
    public static function completed(Enrollment $enrollment): int
    {
        return $enrollment->progress()
            ->where('lesson_progress.status', 'completed')
            ->whereIn(
                'lesson_progress.lesson_id',
                $enrollment->course->lessons()->countableForProgress()->select('lessons.id'),
            )
            ->count();
    }

    /**
     * Recomputes the stored percentage, and completes the enrolment if everything
     * countable is done. Returns whether it is now complete.
     *
     * The completion half is not optional for the resync caller, and that is the
     * fifth road into this spec's forever-bug: the publish endpoint also moves
     * nodes to draft and archived, so archiving the one item a student had not
     * finished takes their remaining work to zero. If the resync only wrote
     * `progress_pct`, nothing would ever fire `CourseCompleted` for them again —
     * there is no lesson left to complete, and completion is only ever decided
     * when one is. They would sit at 100% with no certificate, permanently.
     *
     * `$total` may be passed in when the caller already has it: it is a fact
     * about the COURSE, identical for every enrolment in it, so a resync walking
     * five thousand students counts it once instead of five thousand times. It is
     * the same number from the same scope, not a second opinion.
     */
    public static function sync(Enrollment $enrollment, ?int $total = null): bool
    {
        $enrollment->loadMissing('course');

        $total ??= self::total($enrollment);
        $completed = self::completed($enrollment);

        $enrollment->update(['progress_pct' => self::percentage($completed, $total)]);

        $isComplete = $total > 0 && $completed >= $total;

        if ($isComplete && ! $enrollment->isCompleted()) {
            $enrollment->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }

        return $isComplete;
    }
}
