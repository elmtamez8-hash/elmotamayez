<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Notifications;

use App\Modules\Learning\Models\Enrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnrollmentCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Enrollment $enrollment,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $course = $this->enrollment->course;

        return (new MailMessage)
            ->subject('You have been enrolled in a course')
            ->line("You have been enrolled in: {$course->title}")
            ->action('Start learning', url('/courses/'.$course->uuid));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'workspace_id' => $this->enrollment->workspace_id,
            'type' => 'enrollment_created',
            'enrollment_uuid' => $this->enrollment->uuid,
            'course_uuid' => $this->enrollment->course->uuid,
            'course_title' => $this->enrollment->course->title,
        ];
    }
}
