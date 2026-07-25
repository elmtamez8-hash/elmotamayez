<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Certificates\Events\CertificateRegenerated;
use App\Modules\Learning\Events\EnrollmentCreated;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateIssued;
use App\Modules\Notifications\Listeners\NotifyStudentCertificateRegenerated;
use App\Modules\Notifications\Listeners\NotifyStudentEnrolled;
use App\Shared\Modules\Module;
use Illuminate\Support\Facades\Event;

class NotificationsServiceProvider extends Module
{
    protected string $name = 'Notifications';

    public function boot(): void
    {
        parent::boot();

        Event::listen(EnrollmentCreated::class, NotifyStudentEnrolled::class);
        Event::listen(CertificateIssued::class, NotifyStudentCertificateIssued::class);
        Event::listen(CertificateRegenerated::class, NotifyStudentCertificateRegenerated::class);
    }
}
