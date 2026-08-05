<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Channels\ChannelRegistry;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Jobs\DeliverNotificationJob;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\DeliveryStatus;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Notifications\Support\QuietHours;
use App\Shared\Contracts\GuardianDirectory;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RegistersFakeChannels;

/**
 * Quiet hours apply to external channels only, and every external channel is
 * unimplemented at launch — so this behaviour is inert in production today and is
 * proven here with a fake external channel (research R8).
 *
 * It is built now rather than later because retrofitting it after the first real
 * channel means revisiting every send path instead of one.
 */
uses(RegistersFakeChannels::class);

function sleeper(string $timezone = 'Asia/Qatar'): User
{
    $user = User::factory()->create();

    $user->forceFill([
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => $timezone,
    ])->save();

    return $user;
}

function fire(User $user, NotificationType $type): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $user,
        type: $type,
        variables: [
            'name' => $user->name,
            'course_title' => 'الرياضيات',
            'student_name' => $user->name,
            'amount' => '100 ر.ق',
            'event' => 'حدث',
            'session_title' => 'حصّة الرياضيات',
            'session_date' => 'الأحد',
        ],
    ));
}

it('defers a non-mandatory external message sent inside the window', function (): void {
    Queue::fake();
    $this->registerChannel();

    $user = sleeper();
    $this->optIn($user, NotificationChannel::Email);

    // 02:00 in Doha — inside a window that crosses midnight.
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));

    fire($user, NotificationType::EnrollmentCreated);

    $external = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole();
    $inApp = NotificationDelivery::query()->where('channel', NotificationChannel::InApp->value)->sole();

    expect($external->deferred_until)->not->toBeNull()
        // In-app wakes nobody, so it is never deferred.
        ->and($inApp->deferred_until)->toBeNull();
});

it('sends an external message outside the window immediately', function (): void {
    $this->registerChannel();

    $user = sleeper();
    $this->optIn($user, NotificationChannel::Email);

    // 12:00 in Doha.
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 09:00:00', 'UTC'));

    fire($user, NotificationType::EnrollmentCreated);

    expect(NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole()->deferred_until)
        ->toBeNull();
});

// FR-035 — the point of a 3am security alert is that it arrives at 3am.
it('never defers a mandatory type', function (): void {
    Queue::fake();
    $this->registerChannel();

    $user = sleeper();
    $this->optIn($user, NotificationChannel::Email);

    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));

    fire($user, NotificationType::SecurityAlert);

    foreach (NotificationDelivery::query()->get() as $delivery) {
        expect($delivery->deferred_until)->toBeNull();
    }
});

// FR-033 — deferred, not dropped. The queue itself holds the delay, so there is
// no sweeper that can fail to run.
it('delivers a deferred message once the window ends', function (): void {
    $fake = $this->registerChannel();

    $user = sleeper();
    $this->optIn($user, NotificationChannel::Email);

    Queue::fake();
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));

    fire($user, NotificationType::EnrollmentCreated);

    $delivery = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->sole();

    expect($delivery->status)->toBe(DeliveryStatus::Queued->value);

    // Morning: the worker picks the job up.
    $this->travelTo(Carbon\Carbon::parse('2026-08-06 05:00:00', 'UTC'));

    (new DeliverNotificationJob($delivery->getKey()))->handle(
        app(ChannelRegistry::class),
        app(GuardianDirectory::class),
    );

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Delivered->value)
        ->and($fake->count())->toBe(1);
});

it('does nothing for a user with no window set', function (): void {
    $quiet = app(QuietHours::class);
    $user = User::factory()->create();

    expect($quiet->deferUntil($user, NotificationType::EnrollmentCreated, NotificationChannel::Email))
        ->toBeNull();
});

// A window like 22:00 → 07:00 crosses midnight, so "inside" cannot be a simple
// between(): at 02:00 the start belongs to yesterday.
it('handles a window that crosses midnight from both sides', function (): void {
    $quiet = app(QuietHours::class);
    $user = sleeper();

    // 23:30 Doha — after the start, before midnight.
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 20:30:00', 'UTC'));
    $lateNight = $quiet->deferUntil($user, NotificationType::EnrollmentCreated, NotificationChannel::Email);

    // 02:00 Doha — after midnight, before the end.
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 23:00:00', 'UTC'));
    $earlyMorning = $quiet->deferUntil($user, NotificationType::EnrollmentCreated, NotificationChannel::Email);

    // 09:00 Doha — outside.
    $this->travelTo(Carbon\Carbon::parse('2026-08-06 06:00:00', 'UTC'));
    $daytime = $quiet->deferUntil($user, NotificationType::EnrollmentCreated, NotificationChannel::Email);

    expect($lateNight)->not->toBeNull()
        ->and($earlyMorning)->not->toBeNull()
        ->and($daytime)->toBeNull()
        // Both resolve to the same 07:00 Doha boundary — 04:00 UTC on the 6th.
        ->and($lateNight->format('Y-m-d H:i'))->toBe('2026-08-06 04:00')
        ->and($earlyMorning->format('Y-m-d H:i'))->toBe('2026-08-06 04:00');
});

it('respects the user own timezone', function (): void {
    $quiet = app(QuietHours::class);

    $doha = sleeper('Asia/Qatar');
    $london = sleeper('Europe/London');

    // 23:00 Doha (inside its window) is 21:00 London (outside).
    $this->travelTo(Carbon\Carbon::parse('2026-08-05 20:00:00', 'UTC'));

    expect($quiet->deferUntil($doha, NotificationType::EnrollmentCreated, NotificationChannel::Email))->not->toBeNull()
        ->and($quiet->deferUntil($london, NotificationType::EnrollmentCreated, NotificationChannel::Email))->toBeNull();
});

// FR-034 — twenty alerts in an hour arrive as one message, not twenty.
it('folds repeats of the same type into one queued message', function (): void {
    Queue::fake();
    $fake = $this->registerChannel();

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    NotificationPreference::query()
        ->where('user_id', $user->getKey())
        ->where('type', NotificationType::AttendanceAlert->value)
        ->update(['digest_window_minutes' => 60]);

    for ($i = 0; $i < 5; $i++) {
        fire($user, NotificationType::AttendanceAlert);
    }

    $external = NotificationDelivery::query()->where('channel', NotificationChannel::Email->value)->get();

    // Five records in the feed — digesting is about how loudly the channel
    // pushes, not about hiding what happened.
    expect(Notification::query()->count())->toBe(5)
        ->and($external->where('status', DeliveryStatus::Queued->value))->toHaveCount(1)
        ->and($external->where('status', DeliveryStatus::Skipped->value))->toHaveCount(4);
});

it('does not digest a mandatory type however the preference is set', function (): void {
    Queue::fake();
    $this->registerChannel();

    $user = User::factory()->create();
    $this->optIn($user, NotificationChannel::Email);

    NotificationPreference::query()
        ->where('user_id', $user->getKey())
        ->where('type', NotificationType::SecurityAlert->value)
        ->update(['digest_window_minutes' => 60]);

    fire($user, NotificationType::SecurityAlert);
    fire($user, NotificationType::SecurityAlert);

    expect(NotificationDelivery::query()
        ->where('channel', NotificationChannel::Email->value)
        ->where('status', DeliveryStatus::Queued->value)
        ->count())->toBe(2);
});
