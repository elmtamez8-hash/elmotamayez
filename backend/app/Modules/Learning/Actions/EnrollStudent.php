<?php

declare(strict_types=1);

namespace App\Modules\Learning\Actions;

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Learning\Models\Enrollment;
use App\Shared\Actions\Action;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\LogsActivity;

class EnrollStudent extends Action
{
    use LogsActivity;

    public function handle(Course $course, User $student, string $source = 'manual', ?int $orderId = null): Enrollment
    {
        $enrollment = Enrollment::firstOrCreate(
            [
                'workspace_id' => app(WorkspaceContext::class)->id(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
            ],
            [
                'source' => $source,
                'order_id' => $orderId,
                'status' => 'active',
                'enrolled_at' => now(),
            ],
        );

        if ($enrollment->wasRecentlyCreated) {
            $this->logActivity('enrolled', $enrollment, [
                'course_title' => $course->title,
                'student_name' => $student->name,
                'source' => $source,
            ]);

            event(new EnrollmentCreated($enrollment));
        }

        return $enrollment;
    }
}
