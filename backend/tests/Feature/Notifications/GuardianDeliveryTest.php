<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\RevokeRelation;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Contracts\GuardianDirectory;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Facades\Queue;

function notifyAbout(User $student, NotificationType $type): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: $type,
        variables: [
            'student_name' => $student->name,
            'amount' => '300 ر.ق',
            'session_title' => 'حصّة الرياضيات',
            'session_date' => 'الأحد',
            'starts_at' => 'غداً 5 م',
            'exam_title' => 'اختبار الفصل',
            'score' => '88',
            'course_title' => 'الرياضيات',
            'note' => 'يحتاج مراجعة.',
            'name' => $student->name,
        ],
        subject: $student,
    ));
}

// SC-010 — three people told, and each of them gets exactly one record.
it('reaches the student, the parent and every guardian', function (): void {
    $student = User::factory()->create();

    $parent = User::factory()->create();
    $guardianOne = User::factory()->create();
    $guardianTwo = User::factory()->create();

    ParentStudentRelation::factory()->parent()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $parent->getKey(),
    ]);
    ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardianOne->getKey(),
    ]);
    ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardianTwo->getKey(),
    ]);

    notifyAbout($student, NotificationType::AttendanceAlert);

    expect(Notification::query()->count())->toBe(4);

    foreach ([$student, $parent, $guardianOne, $guardianTwo] as $person) {
        expect(Notification::query()->forRecipient($person)->count())->toBe(1);
    }
});

// SC-011, first half — permissions are per-kind, not all-or-nothing.
it('never tells a guardian about something they are not authorised for', function (): void {
    $student = User::factory()->create();
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()
        ->withPermissions([GuardianPermission::Attendance])
        ->create([
            'student_user_id' => $student->getKey(),
            'guardian_user_id' => $guardian->getKey(),
        ]);

    notifyAbout($student, NotificationType::PaymentReminder);

    expect(Notification::query()->forRecipient($guardian)->count())->toBe(0)
        ->and(Notification::query()->forRecipient($student)->count())->toBe(1);

    notifyAbout($student, NotificationType::AttendanceAlert);

    expect(Notification::query()->forRecipient($guardian)->count())->toBe(1);
});

it('never tells a revoked guardian anything', function (): void {
    $student = User::factory()->create();
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()->revoked()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardian->getKey(),
    ]);

    notifyAbout($student, NotificationType::AttendanceAlert);

    expect(Notification::query()->forRecipient($guardian)->count())->toBe(0);
});

it('does not treat a pending relation as authorised', function (): void {
    $student = User::factory()->create();
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardian->getKey(),
        'status' => RelationStatus::Pending->value,
    ]);

    notifyAbout($student, NotificationType::AttendanceAlert);

    expect(Notification::query()->forRecipient($guardian)->count())->toBe(0);
});

// SC-011, second half — "stops immediately" has to include work already queued,
// or a revoked guardian keeps receiving for as long as the backlog lasts.
it('stops a delivery already in the queue when the relation is revoked', function (): void {
    Queue::fake();

    $student = User::factory()->create();
    $guardian = User::factory()->create();

    $relation = ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardian->getKey(),
    ]);

    notifyAbout($student, NotificationType::AttendanceAlert);

    $delivery = NotificationDelivery::query()
        ->whereHas('notification', fn ($q) => $q->where('recipient_user_id', $guardian->getKey()))
        ->sole();

    // Revoked after the job was queued, before the worker picked it up.
    app(RevokeRelation::class)->handle($relation);

    (new DeliverNotificationJob($delivery->getKey()))->handle(
        app(ChannelRegistry::class),
        app(GuardianDirectory::class),
    );

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Skipped->value);
});

it('leaves the student own delivery alone when a guardian is revoked', function (): void {
    Queue::fake();

    $student = User::factory()->create();

    notifyAbout($student, NotificationType::AttendanceAlert);

    $delivery = NotificationDelivery::query()->sole();

    (new DeliverNotificationJob($delivery->getKey()))->handle(
        app(ChannelRegistry::class),
        app(GuardianDirectory::class),
    );

    // The student is the subject; no relation gates their own message.
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Delivered->value);
});

it('does not involve guardians in types that are not about them', function (): void {
    $student = User::factory()->create();
    $guardian = User::factory()->create();

    ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $guardian->getKey(),
    ]);

    // A security alert is about the account holder, and nobody else's business.
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: NotificationType::SecurityAlert,
        variables: ['name' => $student->name, 'event' => 'تم تغيير كلمة المرور.'],
        subject: $student,
    ));

    expect(Notification::query()->forRecipient($guardian)->count())->toBe(0);
});

it('tells every guardian-facing type apart', function (): void {
    $guardianTypes = array_filter(
        NotificationType::cases(),
        static fn (NotificationType $type): bool => $type->targetsGuardians(),
    );

    // Eleven since spec 006 added the second credit threshold, the withholding
    // notice, the restoration and — in phase 12 — the dormancy reminder. The
    // number is asserted rather than derived on purpose: a type that quietly
    // starts reaching guardians is a consent decision, not a detail — this
    // assertion failing is the mechanism, and raising it is meant to be an act
    // with a reason attached.
    //
    // The reason for the first three: money owed on a child's account is the
    // guardian's business by definition, and all of them ride
    // GuardianPermission::Payments. The FIRST credit threshold deliberately does
    // not — the ladder starts with a quiet word to the student alone (FR-030).
    //
    // And for the eleventh: the dormancy notice is about money the family has
    // already paid and nobody has used. The person who paid it is exactly who
    // would want to hear.
    //
    // Sixteen since 007 added the five payment outcomes — confirmed, failed,
    // receipt approved, receipt rejected, reversed. Every one of them is about
    // money leaving or failing to leave the family's account, and the guardian
    // is usually the person whose account it is. They ride the same
    // GuardianPermission::Payments as the rest, so a guardian with no right to
    // the financial record still receives none of them.
    expect($guardianTypes)->toHaveCount(16);

    foreach ($guardianTypes as $type) {
        expect($type->requiredGuardianPermission())->not->toBeNull();
    }
});
