<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Modules\Learning\Events\CourseCompleted;
use App\Modules\Learning\Events\LessonCompleted;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Models\ProgressHistory;
use App\Shared\Actions\Action;
use Illuminate\Support\Facades\DB;

class MarkLessonComplete extends Action
{
    public function handle(Enrollment $enrollment, int $lessonId): LessonProgress
    {
        $enrollment->loadMissing('course');

        return DB::transaction(function () use ($enrollment, $lessonId): LessonProgress {
            $progress = LessonProgress::firstOrCreate(
                [
                    'workspace_id' => $enrollment->workspace_id,
                    'enrollment_id' => $enrollment->getKey(),
                    'lesson_id' => $lessonId,
                ],
                [
                    'status' => 'in_progress',
                    'started_at' => now(),
                ],
            );

            if ($progress->isCompleted()) {
                return $progress;
            }

            $progress->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            ProgressHistory::create([
                'workspace_id' => $progress->workspace_id,
                'lesson_progress_id' => $progress->getKey(),
                'event' => 'completed',
                'payload' => ['completed_at' => now()->toIso8601String()],
            ]);

            $this->recomputeProgress($enrollment);

            $shouldComplete = $this->shouldCompleteCourse($enrollment);
            if ($shouldComplete && ! $enrollment->isCompleted()) {
                $enrollment->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            // Dispatch events after the transaction commits so listeners
            // (certificate issuance, notifications) don't run inside the open transaction.
            $progress->refresh();
            $enrollment->refresh();

            DB::afterCommit(function () use ($progress, $enrollment, $shouldComplete): void {
                event(new LessonCompleted($progress));

                if ($shouldComplete) {
                    $enrollment->refresh();

                    event(new CourseCompleted($enrollment));
                }
            });

            return $progress;
        });
    }

    /**
     * The denominator: published, completable, non-recording lessons.
     *
     * It used to be `$enrollment->course->lessons()->count()` — every row, no
     * distinction. Two consequences, both live in production:
     *
     * A half-written lesson saved into a live course dropped every enrolled
     * student's percentage the moment it was created.
     *
     * And worse, a session recording sat in the denominator of students who
     * cannot open it. A recording is entitled by holding a SEAT in that session,
     * not by enrolling in the course (005 FR-030) — so an enrolled student
     * without a seat could never reach 100%, `shouldCompleteCourse()` could
     * never fire, and their certificate could never issue. Not late: never.
     */
    private function countableLessons(Enrollment $enrollment): int
    {
        return $enrollment->course->lessons()->countableForProgress()->count();
    }

    /**
     * Completed lessons that still count.
     *
     * Filtered the same way as the denominator: a student who finished a lesson
     * that was later archived should not end up at 110%, and one who watched a
     * recording should not be credited against a total it is not part of.
     */
    private function countableCompleted(Enrollment $enrollment): int
    {
        return $enrollment->progress()
            ->where('lesson_progress.status', 'completed')
            ->whereIn(
                'lesson_progress.lesson_id',
                $enrollment->course->lessons()->countableForProgress()->select('lessons.id'),
            )
            ->count();
    }

    private function recomputeProgress(Enrollment $enrollment): void
    {
        $totalLessons = $this->countableLessons($enrollment);
        $completedLessons = $this->countableCompleted($enrollment);

        $pct = $totalLessons > 0 ? (int) round(($completedLessons / $totalLessons) * 100) : 0;

        $enrollment->update(['progress_pct' => min(100, $pct)]);
    }

    private function shouldCompleteCourse(Enrollment $enrollment): bool
    {
        $totalLessons = $this->countableLessons($enrollment);

        if ($totalLessons === 0) {
            return false;
        }

        return $this->countableCompleted($enrollment) >= $totalLessons;
    }
}
