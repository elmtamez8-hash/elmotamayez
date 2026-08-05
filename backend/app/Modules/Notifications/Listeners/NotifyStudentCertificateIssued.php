<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyStudentCertificateIssued implements ShouldQueue
{
    public function __construct(
        private readonly DispatchNotification $dispatch,
    ) {}

    public function handle(CertificateIssued $event): void
    {
        $certificate = $event->certificate;
        $student = $certificate->student;

        if ($student === null) {
            return;
        }

        // course_id is nullable on the certificate, and the template does not
        // require the title — a certificate whose course was removed is still
        // worth telling the student about, by its number.
        $course = $certificate->course;

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::CertificateIssued,
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
