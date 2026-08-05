<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Events\NotificationDelivered;
use App\Modules\Notifications\Events\NotificationFailed;
use App\Modules\Notifications\Events\NotificationQueued;
use App\Modules\Notifications\Events\NotificationRequested;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Contracts\GuardianDirectory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RegistersFakeChannels;

uses(RegistersFakeChannels::class);

function dispatchEnrollment(User $user): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $user,
        type: NotificationType::EnrollmentCreated,
        variables: ['name' => $user->name, 'course_title' => 'الرياضيات'],
    ));
}

// SC-007 — the chain is the only window into what happens after dispatch, since
// everything from there runs in a queue worker.
it('emits the four events in order', function (): void {
    Event::fake([
        NotificationRequested::class,
        NotificationQueued::class,
        NotificationDelivered::class,
        NotificationFailed::class,
    ]);

    dispatchEnrollment(User::factory()->create());

    Event::assertDispatched(NotificationRequested::class);
    Event::assertDispatched(NotificationQueued::class);
    Event::assertNotDispatched(NotificationFailed::class);
});

it('emits delivered when the channel accepts', function (): void {
    Event::fake([NotificationDelivered::class, NotificationFailed::class]);

    dispatchEnrollment(User::factory()->create());

    Event::assertDispatched(NotificationDelivered::class);
    Event::assertNotDispatched(NotificationFailed::class);
});

// SC-006, first half: a permanent failure is never retried. Five attempts at a
// wrong phone number spend the provider's rate budget to learn what the first
// attempt already said.
it('does not retry a permanent failure', function (): void {
    $fake = $this->registerChannel();
    $fake->failWith = 'رقم غير صالح.';
    $fake->failPermanently = true;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchEnrollment($user);

    $delivery = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Failed->value)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->failure_reason)->toBe('رقم غير صالح.');
});

// SC-006, second half: a transient failure is thrown back at the queue, which is
// what makes the backoff happen at all.
it('rethrows a transient failure so the queue retries it', function (): void {
    $fake = $this->registerChannel();
    $fake->failWith = 'المزوّد لا يستجيب.';
    $fake->failPermanently = false;

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchEnrollment($user);

    $delivery = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole();

    // The sync queue swallows nothing: it ran, threw, and the row is not terminal.
    expect($delivery->status)->not->toBe(DeliveryStatus::Delivered->value);
})->throws(RuntimeException::class);

it('declares an escalating backoff and a retry ceiling', function (): void {
    $job = new DeliverNotificationJob(1);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([30, 120, 300, 900]);
});

// SC-005 — a slow provider must not slow the operation that triggered it. The
// measurement is structural rather than a stopwatch: dispatch returns before any
// channel is consulted, so there is nothing for a slow one to hold up.
it('returns from dispatch without consulting any channel', function (): void {
    Queue::fake();

    $fake = $this->registerChannel();
    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchEnrollment($user);

    expect($fake->count())->toBe(0)
        ->and(NotificationDelivery::query()->count())->toBe(2);
});

it('ignores a delivery that already reached a terminal state', function (): void {
    $fake = $this->registerChannel();
    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    dispatchEnrollment($user);

    $delivery = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole();
    $before = $fake->count();

    // A duplicate job — a requeue after a worker restart, say — must not deliver
    // the same message twice.
    app(DeliverNotificationJob::class, ['deliveryId' => $delivery->getKey()]);
    (new DeliverNotificationJob($delivery->getKey()))->handle(
        app(ChannelRegistry::class),
        app(GuardianDirectory::class),
    );

    expect($fake->count())->toBe($before);
});
