<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Marketplace\Models\TeacherApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly TeacherApplication $application,
        public readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('بخصوص طلبك للتدريس')
            ->line('راجع فريقنا الأكاديمي طلبك ولم نتمكّن من قبوله في الوقت الحالي.')
            // The reason is always included: a rejection with no stated cause gives
            // the applicant nothing to act on (FR-016).
            ->line('السبب: '.$this->reason);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->application->workspace_id,
            'type' => 'teacher_rejected',
            'application_uuid' => $this->application->uuid,
            'reason' => $this->reason,
        ];
    }
}
