<?php

declare(strict_types=1);

namespace App\Modules\Certificates;

use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Certificates\Listeners\IssueCertificateIfEligible;
use App\Modules\Certificates\Support\CertificatesPersonalData;
use App\Modules\Learning\Events\CourseCompleted;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

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

        Event::listen(CourseCompleted::class, [IssueCertificateIfEligible::class, 'handleCourseCompleted']);
        Event::listen(ExamPassed::class, [IssueCertificateIfEligible::class, 'handleExamPassed']);
    }
}
