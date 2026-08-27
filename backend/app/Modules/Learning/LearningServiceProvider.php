<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Modules\Assessments\Events\ExamSubmitted;
use App\Modules\Courses\Events\CourseStructureChanged;
use App\Modules\Courses\Events\ExamItemOpened;
use App\Modules\Learning\Listeners\CompleteExamLessonOnSubmission;
use App\Modules\Learning\Listeners\CompleteExamLessonsAlreadyAnswered;
use App\Modules\Learning\Listeners\ResyncCourseProgress;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortTransferRequest;
use App\Modules\Learning\Policies\CohortPolicy;
use App\Modules\Learning\Policies\CohortTransferRequestPolicy;
use App\Modules\Learning\Support\EloquentCohortDirectory;
use App\Modules\Learning\Support\EloquentEnrollmentDirectory;
use App\Modules\Learning\Support\EloquentProgressImpact;
use App\Modules\Learning\Support\LearningPersonalData;
use App\Shared\Contracts\CohortDirectory;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\ProgressImpact;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class LearningServiceProvider extends Module
{
    protected string $name = 'Learning';

    public function register(): void
    {
        parent::register();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([LearningPersonalData::class], 'compliance.personal_data');

        // Learning owns the enrolment; Media asks through the interface rather
        // than reaching into these models, which Constitution III forbids. Same
        // binding shape as Identity's GuardianDirectory.
        $this->app->bind(EnrollmentDirectory::class, EloquentEnrollmentDirectory::class);

        // The group, asked the same way: LiveSessions decides which sessions a
        // student may see and Community decides who reaches a thread, and
        // neither imports a Cohort.
        $this->app->bind(CohortDirectory::class, EloquentCohortDirectory::class);

        // Same reason, other direction of the same wall: the authoring surface
        // shows a teacher what a publish does to the people enrolled, and asks
        // through an interface rather than importing an Enrollment.
        $this->app->bind(ProgressImpact::class, EloquentProgressImpact::class);
    }

    public function boot(): void
    {
        parent::boot();

        /*
        | ⚠️ REGISTERED EXPLICITLY, NEVER LEFT TO THE GUESSER. Laravel's guesser
        | fails OPEN into "no policy applies", and it fails exactly when one
        | namespace serves several models — which is how `taxonomy.manage`
        | shipped declared, seeded, asserted platform-level, and read by nothing
        | at all.
        */
        Gate::policy(Cohort::class, CohortPolicy::class);
        Gate::policy(CohortTransferRequest::class, CohortTransferRequestPolicy::class);

        // Assessments does not know a course tree exists. It announces that an
        // attempt was submitted; whether that finishes an item somewhere is
        // Learning's business — which is the only reason an exam placed in a
        // tree can ever be completed at all.
        Event::listen(ExamSubmitted::class, CompleteExamLessonOnSubmission::class);

        // And the other half of the same problem: students who answered the exam
        // BEFORE the teacher placed it. No submission event will ever fire for
        // them again, so publishing the item is the moment to credit them.
        Event::listen(ExamItemOpened::class, CompleteExamLessonsAlreadyAnswered::class);

        // A publish batch moves the denominator for everyone at once, and
        // `progress_pct` is otherwise written only when a lesson is completed —
        // so without this every stored percentage in the course describes a tree
        // that no longer exists.
        Event::listen(CourseStructureChanged::class, ResyncCourseProgress::class);
    }
}
