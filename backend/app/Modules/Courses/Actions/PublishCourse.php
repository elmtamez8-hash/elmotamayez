<?php

declare(strict_types=1);

namespace App\Modules\Courses\Actions;

use App\Modules\Courses\Enums\CourseStatus;
use App\Modules\Courses\Models\Course;
use App\Shared\Actions\Action;
use App\Shared\Traits\LogsActivity;

class PublishCourse extends Action
{
    use LogsActivity;

    public function handle(Course $course): Course
    {
        $course->update(['status' => CourseStatus::Published->value]);

        $this->logActivity('published', $course);

        $course->refresh();

        return $course;
    }
}
