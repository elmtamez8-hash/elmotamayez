<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Courses\Events\ExamItemOpened;
use App\Modules\Learning\Listeners\CompleteExamLessonOnSubmission;
use App\Modules\Learning\Listeners\CompleteExamLessonsAlreadyAnswered;
use App\Modules\Learning\Support\EloquentEnrollmentDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class LearningServiceProvider extends Module
{
    protected string $name = 'Learning';

    public function register(): void
    {
        parent::register();

        // Learning owns the enrolment; Media asks through the interface rather
        // than reaching into these models, which Constitution III forbids. Same
        // binding shape as Identity's GuardianDirectory.
        $this->app->bind(EnrollmentDirectory::class, EloquentEnrollmentDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Assessments does not know a course tree exists. It announces that an
        // attempt was submitted; whether that finishes an item somewhere is
        // Learning's business — which is the only reason an exam placed in a
        // tree can ever be completed at all.
        Event::listen(ExamSubmitted::class, CompleteExamLessonOnSubmission::class);

        // And the other half of the same problem: students who answered the exam
        // BEFORE the teacher placed it. No submission event will ever fire for
        // them again, so publishing the item is the moment to credit them.
        Event::listen(ExamItemOpened::class, CompleteExamLessonsAlreadyAnswered::class);
    }
}
