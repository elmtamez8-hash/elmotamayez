<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Certificates\Events\CertificateRegenerated;
use App\Modules\Notifications\Notifications\CertificateRegeneratedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStudentCertificateRegenerated implements ShouldQueue
{
    public function handle(CertificateRegenerated $event): void
    {
        $event->certificate->student?->notify(new CertificateRegeneratedNotification($event->certificate));
    }
}
