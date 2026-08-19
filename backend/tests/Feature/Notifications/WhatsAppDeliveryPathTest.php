<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| SC-005 · SC-012 — the paths that were written in 003 and never once ran.
|
| Quiet hours and digesting apply to EXTERNAL channels only, and until spec 020
| `isExternal()` was false for every channel that existed. So both features have
| been shipped, tested against a FAKE channel, and never exercised by a real one.
| These cases drive the real class through DispatchNotification end to end.
|
| Deliberately not Queue::fake() where the delivery is the subject: a bare fake
| swallows DeliverNotificationJob, and "the message arrived" becomes a confident
| assertion about a job that never ran.
*/

function reportTo(User $student): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: NotificationType::SessionReport,
        variables: [
            'title' => 'حصّة الجبر',
            'student_name' => $student->name,
            'status' => 'حاضرة',
            'minutes' => '45',
            'note' => 'أداء جيّد',
        ],
        subject: $student,
    ));
}

it('fans one session report out to the bell and to whatsapp, as one record', function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    Http::fake(['provider.test/*' => Http::response([], 200)]);

    $student = withVerifiedWhatsApp();

    reportTo($student);

    // One notification, two deliveries, two independent jobs — the shape 003
    // built and 020 is the first to actually use.
    expect(NotificationDelivery::query()->count())->toBe(2)
        ->and(NotificationDelivery::query()
            ->where('channel', NotificationChannel::WhatsApp->value)
            ->value('status'))->toBe(DeliveryStatus::Delivered->value);

    Http::assertSentCount(1);
});

it('skips whatsapp for the parent who never proved a number, and still rings their bell', function (): void {
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);
    Http::fake(['provider.test/*' => Http::response([], 200)]);

    $student = User::factory()->create();
    $parent = User::factory()->create();

    ParentStudentRelation::factory()->parent()->create([
        'student_user_id' => $student->getKey(),
        'guardian_user_id' => $parent->getKey(),
    ]);

    reportTo($student);

    $parentWhatsApp = NotificationDelivery::query()
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->whereHas('notification', fn ($q) => $q->where('recipient_user_id', $parent->getKey()))
        ->sole();

    // Skipped, never failed. Being unreachable is a fact about the account, not
    // an incident — and a red row here would train whoever reads this log to
    // stop reading it.
    expect($parentWhatsApp->status)->toBe(DeliveryStatus::Skipped->value)
        ->and(NotificationDelivery::query()
            ->where('channel', NotificationChannel::InApp->value)
            ->whereHas('notification', fn ($q) => $q->where('recipient_user_id', $parent->getKey()))
            ->value('status'))->not->toBe(DeliveryStatus::Skipped->value);
});

it('holds a non-mandatory whatsapp message until quiet hours end, and never the bell', function (): void {
    Queue::fake();
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);

    $student = withVerifiedWhatsApp();
    $student->forceFill([
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => 'Asia/Qatar',
    ])->save();

    // 02:00 in Doha — inside a window that crosses midnight.
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));

    reportTo($student);

    $whatsApp = NotificationDelivery::query()
        ->where('channel', NotificationChannel::WhatsApp->value)->sole();
    $inApp = NotificationDelivery::query()
        ->where('channel', NotificationChannel::InApp->value)->sole();

    expect($whatsApp->deferred_until)->not->toBeNull()
        // An in-app notification wakes nobody at 3am.
        ->and($inApp->deferred_until)->toBeNull();
});

// SC-012 — twenty alerts in an hour are one message, and twenty rows in the bell.
it('folds a burst of one type into a single whatsapp message', function (): void {
    Queue::fake();
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::SessionReport->value);

    $student = withVerifiedWhatsApp();

    NotificationPreference::query()->create([
        'user_id' => $student->getKey(),
        'type' => NotificationType::SessionReport->value,
        'channels' => [NotificationChannel::InApp->value, NotificationChannel::WhatsApp->value],
        'digest_window_minutes' => 60,
    ]);

    for ($i = 0; $i < 20; $i++) {
        reportTo($student);
    }

    $queued = NotificationDelivery::query()
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->where('status', DeliveryStatus::Queued->value)
        ->count();

    // Digesting is about how loudly a channel pushes, never about hiding what
    // happened: the feed still lists every one of the twenty.
    expect($queued)->toBe(1)
        ->and(NotificationDelivery::query()
            ->where('channel', NotificationChannel::InApp->value)
            ->count())->toBe(20);
});

it('never holds a mandatory whatsapp message, whatever the hour', function (): void {
    Queue::fake();
    configureWhatsApp();
    approveWhatsAppTemplate(NotificationType::AccessWithheld->value);

    $student = withVerifiedWhatsApp();
    $student->forceFill([
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => 'Asia/Qatar',
    ])->save();

    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));

    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $student,
        type: NotificationType::AccessWithheld,
        variables: [
            'student_name' => $student->name,
            'course_title' => 'الرياضيات',
            'balance' => '-3',
            'reason' => 'رصيد غير كافٍ',
        ],
        subject: $student,
    ));

    // Being locked out is a fact about what the account can DO right now. A
    // delay is a preference by another name, and this is the one thing a
    // preference may not hide.
    expect(NotificationDelivery::query()
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->value('deferred_until'))->toBeNull();
});
