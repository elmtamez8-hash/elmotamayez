<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Marketplace\Models\TeacherApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherChangesRequestedNotification extends Notification
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
            ->subject('نحتاج تعديلاً بسيطاً على طلبك')
            ->line('راجع فريقنا الأكاديمي طلبك ويحتاج منك توضيحاً قبل إتمام القرار.')
            ->line('المطلوب: '.$this->reason)
            ->action('تعديل الطلب', url('/signup/teacher'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->application->workspace_id,
            'type' => 'teacher_changes_requested',
            'application_uuid' => $this->application->uuid,
            'reason' => $this->reason,
        ];
    }
}
