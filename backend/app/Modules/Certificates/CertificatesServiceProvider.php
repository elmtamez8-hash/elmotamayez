<?php

declare(strict_types=1);

namespace App\Modules\Certificates;

use App\Modules\Assessments\Events\ExamPassed;
use App\Modules\Certificates\Listeners\IssueCertificateIfEligible;
use App\Modules\Learning\Events\CourseCompleted;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class CertificatesServiceProvider extends Module
{
    protected string $name = 'Certificates';

    public function boot(): void
    {
        parent::boot();

        Event::listen(CourseCompleted::class, [IssueCertificateIfEligible::class, 'handleCourseCompleted']);
        Event::listen(ExamPassed::class, [IssueCertificateIfEligible::class, 'handleExamPassed']);
    }
}
