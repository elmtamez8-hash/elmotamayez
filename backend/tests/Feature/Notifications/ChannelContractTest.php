<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RegistersFakeChannels;

/**
 * The architectural claim of this whole spec, tested rather than asserted in a
 * design document: a channel is one class and one registration line.
 */
uses(RegistersFakeChannels::class);

function dispatchOf(User $user, NotificationType $type): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $user,
        type: $type,
        variables: [
            'name' => $user->name,
            'course_title' => 'الرياضيات',
            'certificate_number' => 'C-1',
            'listing_state' => 'ملفك ظاهر.',
            'reason' => 'سبب',
            'event' => 'حدث',
            'student_name' => 'سلمى',
            'session_title' => 'حصّة',
            'session_date' => 'الأحد',
            'amount' => '100 ر.ق',
            'starts_at' => 'غداً',
            'exam_title' => 'اختبار',
            'score' => '90',
            'note' => 'ملاحظة',
            'title' => 'حصّة الجبر',
            'status' => 'حاضر',
            'minutes' => '45',
            // Settlement (014). The bag has to satisfy EVERY template, because
            // TemplateRenderer refuses a missing variable and DispatchNotification
            // logs the refusal rather than failing — so a type whose variables
            // are absent here simply never arrives, and the count below is what
            // notices.
            'session_type' => 'فردية',
            'effective_from' => '2026-09-01',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-30',
            'units_count' => '12',
            'net' => '900 ر.ق',
            'reference' => 'TRF-1',
            // Credits (006). Exactly the mechanism the comment above describes:
            // without these three the four credit types render nothing, are
            // logged and dropped, and the count below came back 19 against 23.
            'course' => 'الرياضيات',
            'credits' => '3',
            'credits_needed' => '2',
            // And `months`, added with the dormancy notice. Without it that
            // template refuses to render, the notification is logged and
            // dropped, and the count below comes back one short — which is this
            // test doing its job, not a channel that failed.
            'months' => '12',
            // And these four, added with spec 008's import report. Same mechanism
            // a third time: the template refuses to render without them, the
            // notification is logged and dropped, and the count comes back 29
            // against 30 — which is this test noticing a type that would have
            // silently reached nobody in production.
            // (`reason` is already above, and the failed-import template reuses it.)
            'filename' => 'questions.csv',
            'imported' => '990',
            'skipped' => '0',
            'failed' => '10',
        ],
    ));
}

// SC-001. Note what this test does NOT do: it changes no listener, no action and
// no notification type. If adding a channel required touching any of those, the
// test could not be written this way — which is exactly why it is the proof.
it('delivers every notification type to a newly registered channel', function (): void {
    $fake = $this->registerChannel();
    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    foreach (NotificationType::cases() as $type) {
        dispatchOf($user, $type);
    }

    expect($fake->count())->toBe(count(NotificationType::cases()));
});

// SC-003 — one channel throwing must not take the others down with it. Laravel's
// own notify() queues a single job for every channel, which is precisely the
// coupling FR-006 forbids.
it('keeps delivering on other channels when one fails', function (): void {
    $fake = $this->registerChannel();
    $fake->failWith = 'المزوّد لا يستجيب.';
    $fake->failPermanently = true;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    $deliveries = NotificationDelivery::query()->get()->keyBy('channel');

    expect($deliveries[NotificationChannel::InApp->value]->status)->toBe(DeliveryStatus::Delivered->value)
        ->and($deliveries[NotificationChannel::Email->value]->status)->toBe(DeliveryStatus::Failed->value);
});

// SC-004 / FR-007.
it('writes one notification record however many channels carry it', function (): void {
    $this->registerChannel();
    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(2);
});

it('skips a channel that is switched off without recording a failure', function (): void {
    $fake = $this->registerChannel();
    $fake->enabled = false;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    $delivery = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole();

    // Skipped, not failed: an unconfigured channel is a state of the deployment,
    // and counting it as a failure would make the delivery log's failure rate
    // meaningless.
    expect($delivery->status)->toBe(DeliveryStatus::Skipped->value);
});

it('skips a channel that cannot reach this recipient', function (): void {
    $fake = $this->registerChannel();
    $fake->reachable = false;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    expect(NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole()->status)
        ->toBe(DeliveryStatus::Skipped->value);
});

// FR-008 / SC-005: nothing is delivered inline. A channel that hangs must not
// hold up the enrollment, payment or exam that triggered it.
it('queues delivery instead of sending inline', function (): void {
    Queue::fake();

    $this->registerChannel();
    $user = User::factory()->create();

    dispatchOf($user, NotificationType::EnrollmentCreated);

    Queue::assertPushed(DeliverNotificationJob::class);

    // The record exists immediately; the delivery has not run.
    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->sole()->status)->toBe(DeliveryStatus::Queued->value);
});

it('records the notification even for a recipient who muted every channel', function (): void {
    $user = User::factory()->create();

    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::EnrollmentCreated->value,
        'channels' => [],
    ]);

    dispatchOf($user, NotificationType::EnrollmentCreated);

    // "Do not push this at me" is not "pretend it never happened" — the feed is
    // where a notification lives.
    expect(Notification::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(0);
});
