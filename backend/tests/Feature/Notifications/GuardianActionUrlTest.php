<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;

/*
| ⚠️ ONE `action_url` FOR EVERY RECIPIENT WAS THE STUDENT'S LINK ON THE PARENT'S
| PHONE. An exam result sent the guardian to `/exams/{attempt}/result`, whose
| `GET /attempts/{uuid}` has no guardian branch in `AttemptPolicy::view()` — a
| 403 behind the one control on the notification. The guardian's copy now opens
| their own dashboard with that child selected; the student's copy is untouched.
*/

function guardianLinkNotify(User $student, NotificationType $type, ?string $url): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: $type,
        variables: [
            'student_name' => $student->name,
            'exam_title' => 'اختبار الفصل',
            'score' => '88٪',
            'name' => $student->name,
            'course_title' => 'الرياضيات',
        ],
        // The exact shape `NotifyStudentExamResult` writes.
        actionUrl: $url,
        subject: $student,
    ));
}

function guardianLinkFor(User $user, NotificationType $type): ?string
{
    return Notification::query()
        ->where('recipient_user_id', $user->getKey())
        ->where('type', $type->value)
        ->sole()
        ->action_url;
}

it('sends the guardian\'s copy of an exam result to the dashboard for that child', function (): void {
    $student = User::factory()->create();
    $guardian = guardianOf($student, [GuardianPermission::Results]);

    guardianLinkNotify($student, NotificationType::ExamResult, '/exams/a1b2c3d4/result');

    expect(guardianLinkFor($student, NotificationType::ExamResult))->toBe('/exams/a1b2c3d4/result')
        ->and(guardianLinkFor($guardian, NotificationType::ExamResult))->toBe('/dashboard?student='.$student->uuid);
});

it('leaves a page that already serves a guardian as it is', function (): void {
    $student = User::factory()->create();
    $guardian = guardianOf($student, [GuardianPermission::Results]);

    guardianLinkNotify($student, NotificationType::ExamResult, '/reviews');

    expect(guardianLinkFor($guardian, NotificationType::ExamResult))->toBe('/reviews');
});

it('gives the guardian no link where the student had none', function (): void {
    $student = User::factory()->create();
    $guardian = guardianOf($student, [GuardianPermission::Results]);

    guardianLinkNotify($student, NotificationType::ExamResult, null);

    expect(guardianLinkFor($guardian, NotificationType::ExamResult))->toBeNull();
});
