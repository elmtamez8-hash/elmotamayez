<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeacherApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly TeacherProfile $profile) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('تم قبول طلبك للتدريس')
            ->line('تهانينا، راجع فريقنا الأكاديمي طلبك ووافق عليه.');

        // An approved teacher inside a workspace that has not opted into the
        // marketplace is approved but unlisted. Saying "your profile is live" would
        // send them looking for a page that is not there.
        return $this->profile->is_publicly_listed
            ? $message
                ->line('ملفك الآن ظاهر في السوق ويمكن للطلاب حجز حصص معك.')
                ->action('عرض ملفك', url('/teachers/'.$this->profile->uuid))
            : $message->line('ملفك جاهز، وسيظهر للطلاب فور تفعيل أكاديميتك للعرض في السوق.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->profile->workspace_id,
            'type' => 'teacher_approved',
            'teacher_profile_uuid' => $this->profile->uuid,
            'is_publicly_listed' => $this->profile->is_publicly_listed,
        ];
    }
}
