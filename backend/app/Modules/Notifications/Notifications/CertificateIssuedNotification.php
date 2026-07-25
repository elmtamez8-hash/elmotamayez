<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Certificates\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateIssuedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Certificate $certificate,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your certificate has been issued')
            ->line("Certificate #{$this->certificate->certificate_number} has been issued.")
            ->action('View certificate', url('/certificates/verify/'.$this->certificate->verification_code));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->certificate->workspace_id,
            'type' => 'certificate_issued',
            'certificate_uuid' => $this->certificate->uuid,
            'verification_code' => $this->certificate->verification_code,
            'course_title' => $this->certificate->course?->title,
        ];
    }
}
