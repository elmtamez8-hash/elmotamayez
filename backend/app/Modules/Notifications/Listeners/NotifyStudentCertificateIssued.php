<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Notifications\Notifications\CertificateIssuedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStudentCertificateIssued implements ShouldQueue
{
    public function handle(CertificateIssued $event): void
    {
        $event->certificate->student?->notify(new CertificateIssuedNotification($event->certificate));
    }
}
