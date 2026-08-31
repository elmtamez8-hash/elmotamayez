<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Actions\SavePushSubscription;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\PushSubscription;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Facades\Queue;

/*
| Spec 012 · US2 · T082 — quiet hours reach push, measured against the REAL class.
|
| ⚠️ THE PRECEDENT IS `WhatsAppDeliveryPathTest`, AND IT IS NOT A STYLE NOTE.
| Quiet hours and digesting were written in spec 003 and did not once run for
| seven specs: both apply to EXTERNAL channels only, and `isExternal()` was false
| for every channel that existed until 020. Every assertion about them was made
| against a fake channel registered by the test itself. A push held until morning
| is the second real chance to measure it, and against a fake it would be the same
| vacuous green.
|
| ⚠️ AND A LOCK SCREEN IS THE WHOLE REASON THE REQUIREMENT EXISTS. A bell badge at
| 3am wakes nobody; a phone does.
*/

function pushingStudent(): User
{
    $student = User::factory()->create();

    // Through the Action, so the preference rows that make push reachable are
    // written the way the product writes them.
    app(SavePushSubscription::class)->handle(
        $student,
        'https://fcm.googleapis.com/fcm/send/quiet-hours-device',
        str_repeat('B', 87),
        str_repeat('a', 22),
        null,
    );

    $student->forceFill([
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => 'Asia/Qatar',
    ])->save();

    return $student;
}

it('holds a non-mandatory push until quiet hours end, and never the bell', function (): void {
    Queue::fake();
    configureWebPush();

    $student = pushingStudent();

    // 02:00 in Doha — inside a window that crosses midnight.
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: NotificationType::SessionReport,
        variables: [
            'title' => 'حصّة الجبر',
            'student_name' => 'طالب',
            'status' => 'حاضر',
            'minutes' => '45',
            'note' => '—',
        ],
        subject: $student,
    ));

    $push = NotificationDelivery::query()
        ->where('channel', NotificationChannel::Push->value)->sole();
    $inApp = NotificationDelivery::query()
        ->where('channel', NotificationChannel::InApp->value)->sole();

    expect($push->deferred_until)->not->toBeNull()
        ->and($push->status)->toBe(DeliveryStatus::Queued->value)
        // An in-app notification wakes nobody at 3am, so it is never held.
        ->and($inApp->deferred_until)->toBeNull();
});

it('never holds a mandatory push, whatever the hour', function (): void {
    Queue::fake();
    configureWebPush();

    $student = pushingStudent();

    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: NotificationType::AccessWithheld,
        variables: ['course' => 'الجبر', 'credits_needed' => '3'],
        subject: $student,
    ));

    $push = NotificationDelivery::query()
        ->where('channel', NotificationChannel::Push->value)->sole();

    // FR-035. The point of a 3am alert is that it arrives at 3am.
    expect($push->deferred_until)->toBeNull();
});

it('sends immediately outside the window', function (): void {
    Queue::fake();
    configureWebPush();

    $student = pushingStudent();

    // 13:00 in Doha, nowhere near the window — the positive control without which
    // a build that deferred EVERYTHING would satisfy the first case perfectly.
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 10:00:00', 'UTC'));

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: NotificationType::SessionReport,
        variables: [
            'title' => 'حصّة الجبر',
            'student_name' => 'طالب',
            'status' => 'حاضر',
            'minutes' => '45',
            'note' => '—',
        ],
        subject: $student,
    ));

    expect(NotificationDelivery::query()
        ->where('channel', NotificationChannel::Push->value)
        ->value('deferred_until'))->toBeNull();
});

it('records the push as skipped, never failed, when the deployment has no keys', function (): void {
    configureWebPush(false);

    $student = pushingStudent();

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: NotificationType::SessionReport,
        variables: [
            'title' => 'حصّة الجبر',
            'student_name' => 'طالب',
            'status' => 'حاضر',
            'minutes' => '45',
            'note' => '—',
        ],
        subject: $student,
    ));

    /*
    | Being unconfigured is a state of the deployment, not an incident — and a wall
    | of red rows would train whoever reads the delivery log to stop reading it.
    | The row is written all the same: «no row at all» is the one answer that
    | explains nothing to an operator asking why a message never arrived.
    */
    expect(NotificationDelivery::query()
        ->where('channel', NotificationChannel::Push->value)
        ->value('status'))->toBe(DeliveryStatus::Skipped->value)
        ->and(PushSubscription::query()->count())->toBe(1);
});
