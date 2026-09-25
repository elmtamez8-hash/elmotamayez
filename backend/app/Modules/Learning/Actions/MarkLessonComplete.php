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
            // Unscoped for the stamped student (see `Enrollment::progress()`): a
            // scoped lookup misses the existing row and tries to create a second.
            $progress = LessonProgress::query()->withoutWorkspaceScope()->firstOrCreate(
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
            //
            // ⛔ `CourseCompleted` fires on the TRANSITION only. `sync()` answers
            // «is it complete now», not «did it just become so» — and since a
            // `completed` enrolment may keep completing lessons the teacher adds
            // later, reaching 100% a second time must not announce the course
            // finished again. `ResyncCourseProgress` guards the same way.
            $wasComplete = $enrollment->isCompleted();
            $shouldComplete = CourseProgress::sync($enrollment) && ! $wasComplete;

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
