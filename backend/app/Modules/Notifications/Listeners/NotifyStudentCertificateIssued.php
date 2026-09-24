<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Certificates\Events\CertificateIssued;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Scopes\WorkspaceScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

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

        /*
        | ⚠️ REFUSE, DON'T SEND A GAP. The body prints «عن «{{ course_title }}»»,
        | and `course_id` is nullable on the certificate — so a certificate whose
        | course was removed used to go out as «عن «»», a sentence naming nothing.
        | The title is a REQUIRED template variable now, so an empty one would be
        | refused by the renderer anyway; saying so here, with the certificate's
        | id in the log, is what makes the silence findable. The certificate
        | itself is issued and verifiable either way.
        */
        // ⚠️ Without the scope: the course belongs to the certificate's
        // workspace, and a context that resolves elsewhere (a stamped student's
        // `last_workspace_id`) would read null and skip a real certificate.
        $course = $certificate->course()->withoutGlobalScope(WorkspaceScope::class)->first();

        if ($course === null || (string) $course->title === '') {
            Log::warning('[notifications] certificate_issued skipped: certificate has no course', [
                'certificate_id' => $certificate->getKey(),
            ]);

            return;
        }

        $this->dispatch->handle(new NotificationRequest(
            recipient: $student,
            type: NotificationType::CertificateIssued,
            variables: [
                'name' => $student->name,
                'certificate_number' => $certificate->certificate_number,
                'course_title' => $course->title,
            ],
            actionUrl: '/certificates/verify/'.$certificate->verification_code,
            subject: $student,
            workspaceId: $certificate->workspace_id,
        ));
    }
}
