<?php

declare(strict_types=1);

namespace App\Modules\Assessments;

use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Policies\ConceptPolicy;
use App\Modules\Assessments\Policies\GradingPolicy;
use App\Modules\Assessments\Policies\QuestionPolicy;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Gate;

class AssessmentsServiceProvider extends Module
{
    protected string $name = 'Assessments';

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
    }
}
