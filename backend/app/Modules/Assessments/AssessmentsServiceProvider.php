<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

use App\Modules\Assessments\Models\Accommodation;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Assessments\Policies\AccommodationPolicy;
use App\Modules\Assessments\Policies\AssignmentPolicy;
use App\Modules\Assessments\Policies\ConceptPolicy;
use App\Modules\Assessments\Policies\GradingPolicy;
use App\Modules\Assessments\Policies\QuestionPolicy;
use App\Modules\Assessments\Policies\SubmissionPolicy;
use App\Modules\Assessments\Support\AssessmentsPersonalData;
use App\Modules\Assessments\Support\EloquentStudentGradeDirectory;
use App\Modules\Assessments\Support\EloquentUnlockDirectory;
use App\Shared\Contracts\StudentGradeDirectory;
use App\Shared\Contracts\UnlockDirectory;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Gate;

class AssessmentsServiceProvider extends Module
{
    protected string $name = 'Assessments';

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
        $this->app->tag([AssessmentsPersonalData::class], 'compliance.personal_data');

        /*
        | Spec 008 · US7. LiveSessions asks whether a student has earned the next
        | session; Assessments owns the rule, the homework and the exemption, so
        | it binds the answer. Exactly the arrow `AccountStanding` already draws
        | from 005 to Payments — a query contract, not an event, because the
        | caller needs the answer before its next line runs.
        */
        $this->app->bind(UnlockDirectory::class, EloquentUnlockDirectory::class);

        /*
        | Spec 010 · US5. Community builds the report card and Assessments owns
        | what an official grade IS — which attempts count, which submissions are
        | marked, and that a practice run is not a grade at all (FR-038). The
        | second arrow of the same shape, and the reason `Community` imports no
        | model of ours.
        */
        $this->app->bind(StudentGradeDirectory::class, EloquentStudentGradeDirectory::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Question::class, QuestionPolicy::class);
        Gate::policy(Concept::class, ConceptPolicy::class);
        // Named for the act rather than the model, so it needs registering:
        // Laravel's guesser would look for `AnswerPolicy`, and an answer is read
        // by its owner and marked by somebody else — two different questions that
        // a single policy named after the table blurs together.
        Gate::policy(Answer::class, GradingPolicy::class);
        Gate::policy(Assignment::class, AssignmentPolicy::class);
        Gate::policy(Submission::class, SubmissionPolicy::class);
        Gate::policy(Accommodation::class, AccommodationPolicy::class);
    }
}
