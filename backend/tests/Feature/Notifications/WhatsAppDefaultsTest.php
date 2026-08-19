<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
| SC-006 — the half of this phase that ships green and delivers nothing.
|
| A channel class with defaultChannels() untouched creates no delivery row at
| all, so every assertion about WhatsApp passes by finding nothing to contradict
| it. The count below is what notices.
*/

it('defaults to whatsapp for exactly the seventeen types a guardian receives', function (): void {
    $onWhatsApp = array_values(array_filter(
        NotificationType::cases(),
        fn (NotificationType $type): bool => in_array(
            NotificationChannel::WhatsApp,
            $type->defaultChannels(),
            true,
        ),
    ));

    // ⚠️ MEASURED AS A NUMBER, not as "at least one". Sliding a type into the
    // guardian set by accident is exactly how a message nobody chose starts
    // costing money on a parent's phone — and the opposite slip is how the one
    // message this phase was built for stops arriving.
    expect($onWhatsApp)->toHaveCount(17);
});

it('derives the set from targetsGuardians rather than from a second list', function (): void {
    // Two hand-written lists answering one question diverge at the first type
    // anybody adds, and the divergence is silent: the new type simply never
    // leaves the platform.
    foreach (NotificationType::cases() as $type) {
        expect(in_array(NotificationChannel::WhatsApp, $type->defaultChannels(), true))
            ->toBe($type->targetsGuardians(), $type->value);
    }
});

it('leaves the security alert on the bell alone, which is a decision and not an oversight', function (): void {
    // Mandatory, and the student's own — so targetsGuardians() is false. Named
    // here so that changing it is a deliberate act with a failing test attached,
    // rather than something discovered later in a diff.
    expect(NotificationType::SecurityAlert->defaultChannels())
        ->toBe([NotificationChannel::InApp]);
});

it('always keeps the in-app channel, so nothing is reachable only off-platform', function (): void {
    foreach (NotificationType::cases() as $type) {
        expect($type->defaultChannels())->toContain(NotificationChannel::InApp);
    }
});

it('ships a whatsapp template for every type that defaults to it', function (): void {
    // TemplateRenderer refuses a missing template and DispatchNotification LOGS
    // rather than fails, so a type without a row here is dropped in silence —
    // and every assertion about it passes vacuously against zero.
    $missing = [];

    foreach (NotificationType::cases() as $type) {
        if (! in_array(NotificationChannel::WhatsApp, $type->defaultChannels(), true)) {
            continue;
        }

        $exists = MessageTemplate::query()
            ->where('type', $type->value)
            ->where('channel', NotificationChannel::WhatsApp->value)
            ->exists();

        if (! $exists) {
            $missing[] = $type->value;
        }
    }

    expect($missing)->toBe([]);
});

it('seeds every whatsapp template pending, never approved', function (): void {
    // Approval is a human process at the provider that takes days. A row seeded
    // `approved` claims an outcome that has not happened, and turns the first
    // send into a provider error code instead of a refusal that names the key.
    $notPending = MessageTemplate::query()
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->where('provider_approval_status', '!=', MessageTemplate::APPROVAL_PENDING)
        ->pluck('key')
        ->all();

    expect($notPending)->toBe([]);
});

it('ships the verification template, without which no number can ever be proven', function (): void {
    // The circular one: canReach() wants a verified number, and this is the
    // message that proves one. Missing, the whole channel is decorative.
    expect(MessageTemplate::query()
        ->where('type', 'contact_verification')
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->exists())->toBeTrue();
});
