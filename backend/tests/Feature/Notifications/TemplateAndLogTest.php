<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Data\NotificationRequest;
use App\Modules\Notifications\Exceptions\PermanentDeliveryException;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Notifications\Support\TemplateRenderer;

function dispatchEnrolled(User $user): void
{
    app(DispatchNotification::class)->handle(new NotificationRequest(
        recipient: $user,
        type: NotificationType::EnrollmentCreated,
        variables: ['name' => $user->name, 'course_title' => 'الرياضيات'],
    ));
}

// SC-015 — the whole reason templates are rows and not code.
it('applies an edited template to the next message without a deploy', function (): void {
    $user = User::factory()->create();

    dispatchEnrolled($user);

    // Through the MODEL, as /admin does: `body` is a translatable JSON column
    // and a bulk `update()` applies no cast.
    MessageTemplate::query()
        ->where('type', NotificationType::EnrollmentCreated->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail()
        ->update(['body' => 'أهلاً {{ name }}! انضممت إلى {{ course_title }}.']);

    dispatchEnrolled($user);

    $notifications = Notification::query()->forRecipient($user)->orderBy('id')->get();

    expect($notifications)->toHaveCount(2)
        ->and($notifications[1]->body)->toStartWith('أهلاً')
        // And the archive keeps the wording it was sent with — editing a template
        // must not rewrite history.
        ->and($notifications[0]->body)->toStartWith('مرحباً');
});

// FR-037, first half.
it('refuses to render a template with a missing variable', function (): void {
    $renderer = app(TemplateRenderer::class);

    $renderer->render(
        NotificationType::EnrollmentCreated,
        NotificationChannel::InApp,
        ['name' => 'سلمى'], // course_title missing
    );
})->throws(PermanentDeliveryException::class);

it('treats an empty variable as missing', function (): void {
    $renderer = app(TemplateRenderer::class);

    // A body reading "انضممت إلى " with nothing after it is worse than no message.
    $renderer->render(
        NotificationType::EnrollmentCreated,
        NotificationChannel::InApp,
        ['name' => 'سلمى', 'course_title' => ''],
    );
})->throws(PermanentDeliveryException::class);

// FR-037, second half — WhatsApp and SMS providers vet templates before they may
// be sent; refusing here makes the reason readable in our own log instead of
// arriving as a provider error code.
it('refuses a template awaiting provider approval', function (): void {
    MessageTemplate::query()
        ->where('type', NotificationType::EnrollmentCreated->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->update(['provider_approval_status' => MessageTemplate::APPROVAL_PENDING]);

    app(TemplateRenderer::class)->render(
        NotificationType::EnrollmentCreated,
        NotificationChannel::InApp,
        ['name' => 'سلمى', 'course_title' => 'الرياضيات'],
    );
})->throws(PermanentDeliveryException::class);

it('refuses a deactivated template', function (): void {
    MessageTemplate::query()
        ->where('type', NotificationType::EnrollmentCreated->value)
        ->update(['is_active' => false]);

    app(TemplateRenderer::class)->render(
        NotificationType::EnrollmentCreated,
        NotificationChannel::InApp,
        ['name' => 'سلمى', 'course_title' => 'الرياضيات'],
    );
})->throws(PermanentDeliveryException::class);

it('refuses a type with no template at all', function (): void {
    MessageTemplate::query()->where('type', NotificationType::ExamResult->value)->delete();

    app(TemplateRenderer::class)->render(
        NotificationType::ExamResult,
        NotificationChannel::InApp,
        [],
    );
})->throws(PermanentDeliveryException::class);

// A template fault is a deployment problem. It must not fail the enrollment,
// payment or exam that triggered the notification.
it('drops the notification instead of breaking the operation that triggered it', function (): void {
    MessageTemplate::query()->where('type', NotificationType::EnrollmentCreated->value)->delete();

    $user = User::factory()->create();

    dispatchEnrolled($user);

    expect(Notification::query()->count())->toBe(0);
});

// FR-040 — the log holds no contact details, because the schema has nowhere to
// put them. The channel reads the destination off the user at send time.
it('stores no contact details in the delivery log', function (): void {
    $columns = Schema::getColumnListing('notification_deliveries');

    foreach (['phone', 'email', 'contact', 'contact_value', 'recipient_address'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('ships one in-app template per notification type', function (): void {
    $seeded = MessageTemplate::query()
        ->where('channel', NotificationChannel::InApp->value)
        ->pluck('type')
        ->sort()
        ->values()
        ->all();

    $expected = collect(NotificationType::cases())
        ->map(fn (NotificationType $type): string => $type->value)
        ->sort()
        ->values()
        ->all();

    expect($seeded)->toBe($expected);
});
