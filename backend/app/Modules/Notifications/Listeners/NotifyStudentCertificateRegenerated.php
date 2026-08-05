<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Certificates\Events\CertificateRegenerated;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStudentCertificateRegenerated implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(CertificateRegenerated $event): void
    {
        $certificate = $event->certificate;
        $student = $certificate->student;

        if ($student === null) {
            return;
        }

        // Nullable on the certificate; the template does not require the title.
        $course = $certificate->course;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::CertificateRegenerated,
            variables: [
                'name' => $student->name,
                'certificate_number' => $certificate->certificate_number,
                'course_title' => $course === null ? '' : $course->title,
            ],
            actionUrl: '/certificates/verify/'.$certificate->verification_code,
            subject: $student,
            workspaceId: $certificate->workspace_id,
        ));
    }
}
