<?php

declare(strict_types=1);

namespace App\Modules\Certificates;

use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Certificates\Listeners\IssueCertificateIfEligible;
use App\Modules\Certificates\Models\CertificateDesign;
use App\Modules\Certificates\Policies\CertificateDesignPolicy;
use App\Modules\Certificates\Support\CertificatesPersonalData;
use App\Modules\Learning\Events\CourseCompleted;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class CertificatesServiceProvider extends Module
{
    protected string $name = 'Certificates';

    public function boot(): void
    {
        parent::boot();

        /*
        | Spec 013 — this module's half of the data-rights contract.
        |
        | ⚠️ ONE TAGGED LINE, and `Compliance` names no table of ours. It resolves
        | the tag and walks whatever registered itself — the same shape as 003's
        | `notification.channels`, and the reason a requirement crossing thirteen
        | schemas does not violate Constitution III.
        */
        $this->app->tag([CertificatesPersonalData::class], 'compliance.personal_data');

        /*
        | ⚠️ REGISTERED EXPLICITLY, though Laravel's guesser would also find it.
        | The guesser walks the model's namespace segments looking for a `Policies`
        | sibling, which is why `CertificatePolicy` beside it has never needed a
        | line — and that is exactly the reason to write one: a guess is silent when
        | it misses, and Laravel's failure mode is to fail OPEN into "no policy
        | applies" rather than to deny. `taxonomy.manage` is what that costs. Every
        | other module here (`Assessments`, `Community`, `Compliance`) registers by
        | hand for the same reason.
        */
        Gate::policy(CertificateDesign::class, CertificateDesignPolicy::class);

        Event::listen(CourseCompleted::class, [IssueCertificateIfEligible::class, 'handleCourseCompleted']);
        Event::listen(ExamPassed::class, [IssueCertificateIfEligible::class, 'handleExamPassed']);
    }
}
