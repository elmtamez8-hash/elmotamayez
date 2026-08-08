<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Modules\Learning\Events\CourseCompleted;
use App\Modules\Learning\Events\LessonCompleted;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Models\ProgressHistory;
use App\Modules\Learning\Support\CourseProgress;
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

            // The arithmetic itself lives in `CourseProgress`, shared with the
            // resync that runs after a publish and with the preview that promises
            // the teacher what that publish will do. Three copies of one formula
            // would be three answers to "what percentage is this student at", and
            // `SC-018` is the promise that the preview's answer is the real one.
            $shouldComplete = CourseProgress::sync($enrollment);

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
}
