<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RegistersFakeChannels;

uses(RegistersFakeChannels::class);

function send(User $user, NotificationType $type): void
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
            'exam_title' => 'اختبار',
            'score' => '90',
        ],
    ));
}

it('lists only implemented channels', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v1/notifications/types')->assertOk();

    $channels = array_column($response->json('channels'), 'key');

    // Spec 020 shipped WhatsApp, so it appears — and Telegram, SMS and push are
    // still known values with no class behind them and must not. A greyed-out
    // toggle promises a date nobody has committed to.
    expect($channels)->toBe([
        NotificationChannel::InApp->value,
        NotificationChannel::WhatsApp->value,
    ])
        ->and($response->json('types'))->toHaveCount(count(NotificationType::cases()));
});

// SC-012 — muting one channel must not mute the others.
it('stops delivery on a muted channel and keeps the rest', function (): void {
    $fake = $this->registerChannel();
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::EnrollmentCreated->value,
        'channels' => [NotificationChannel::Email->value],
    ]);

    send($user, NotificationType::EnrollmentCreated);

    $channels = NotificationDelivery::query()->pluck('channel')->all();

    expect($channels)->toBe([NotificationChannel::Email->value])
        ->and($fake->count())->toBe(1);
});

it('applies the type defaults when the user never chose', function (): void {
    $user = User::factory()->create();

    send($user, NotificationType::EnrollmentCreated);

    expect(NotificationDelivery::query()->sole()->channel)->toBe(NotificationChannel::InApp->value);
});

// SC-013 — a mandatory type cannot be silenced. Not "warns", not "defaults back
// on": refused.
it('refuses to switch off a mandatory type', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [[
            'type' => NotificationType::PaymentReminder->value,
            'channels' => [],
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors('preferences.0.channels');
});

it('still delivers a mandatory type when a stored preference tries to empty it', function (): void {
    $user = User::factory()->create();

    // Written directly, bypassing the endpoint — the way a bad migration or a
    // seeder could. The Action is the enforcement point, not the FormRequest.
    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::SecurityAlert->value,
        'channels' => [],
    ]);

    send($user, NotificationType::SecurityAlert);

    // Asserted per CHANNEL rather than as a total. Spec 020's Q6 added WhatsApp
    // to this type's defaults, so a bare count of 1 was measuring the size of
    // the default set and calling it "was it delivered" — the two questions
    // agreed only while there was one channel.
    expect(NotificationDelivery::query()
        ->where('channel', NotificationChannel::InApp->value)
        ->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1);
});

// FR-029 read correctly: "cannot be switched off" is not "cannot be added to".
it('lets a user add a channel to a mandatory type', function (): void {
    $fake = $this->registerChannel();
    $user = User::factory()->create();

    NotificationPreference::query()->create([
        'user_id' => $user->getKey(),
        'type' => NotificationType::SecurityAlert->value,
        'channels' => [NotificationChannel::Email->value],
    ]);

    send($user, NotificationType::SecurityAlert);

    $channels = NotificationDelivery::query()->pluck('channel')->sort()->values()->all();

    expect($channels)->toBe([NotificationChannel::Email->value, NotificationChannel::InApp->value])
        ->and($fake->count())->toBe(1);
});

// FR-030 — an unimplemented channel is rejected, not merely hidden.
it('refuses a channel that does not exist yet', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Telegram, not WhatsApp: spec 020 gave WhatsApp a class, so it is now a
    // channel that DOES exist. The case is about the ones that still do not —
    // and it had to move rather than be deleted, because "a stored preference
    // may only name an implemented channel" is the rule, not the example.
    $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [[
            'type' => NotificationType::ExamResult->value,
            'channels' => [NotificationChannel::Telegram->value],
        ]],
    ])->assertStatus(422);
});

it('saves and reads back a preference', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [[
            'type' => NotificationType::ExamResult->value,
            'channels' => [NotificationChannel::InApp->value],
            'digest_window_minutes' => 60,
        ]],
    ])->assertOk();

    $stored = $this->getJson('/api/v1/notifications/preferences')->assertOk()->json('preferences');

    expect($stored)->toHaveCount(1)
        ->and($stored[0]['type'])->toBe(NotificationType::ExamResult->value)
        ->and($stored[0]['digest_window_minutes'])->toBe(60);
});

it('rejects half a quiet-hours window', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/notifications/quiet-hours', ['quiet_hours_start' => '22:00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('quiet_hours_end');
});

it('saves a quiet-hours window', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/notifications/quiet-hours', [
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => 'Asia/Qatar',
    ])->assertOk();

    expect($user->fresh()->timezone)->toBe('Asia/Qatar');
});
