<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Modules\Learning\Events\CourseCompleted;
use App\Modules\Learning\Events\LessonCompleted;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Models\ProgressHistory;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

class MarkLessonComplete extends Action
{
    public function handle(Enrollment $enrollment, int $lessonId): LessonProgress
    {
        $enrollment->loadMissing('course');

        return DB::transaction(function () use ($enrollment, $lessonId): LessonProgress {
            $progress = LessonProgress::firstOrCreate(
                [
                    'workspace_id' => app(WorkspaceContext::class)->id(),
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
                    event(new CourseCompleted($enrollment->fresh()));
                }
            });

            return $progress;
        });
    }

    private function recomputeProgress(Enrollment $enrollment): void
    {
        $totalLessons = $enrollment->course->lessons()->count();
        $completedLessons = $enrollment->progress()->where('status', 'completed')->count();

        $pct = $totalLessons > 0 ? (int) round(($completedLessons / $totalLessons) * 100) : 0;

        $enrollment->update(['progress_pct' => $pct]);
    }

    private function shouldCompleteCourse(Enrollment $enrollment): bool
    {
        $totalLessons = $enrollment->course->lessons()->count();
        if ($totalLessons === 0) {
            return false;
        }

        $completedLessons = $enrollment->progress()->where('status', 'completed')->count();

        return $completedLessons >= $totalLessons;
    }
}
