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

it('defaults to whatsapp for exactly the eighteen guardian types plus the security alert', function (): void {
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
    // 19 → 22 with spec 013: the two new guardian-targeting types
    // (`data_ownership_transferred`, `teacher_offboarding_notice`) follow the
    // derivation, and `guardian_consent_required` is the SECOND named exception —
    // addressed to a guardian rather than copied to one, about a child's account
    // that cannot be used until they act.
    expect($onWhatsApp)->toHaveCount(22);
});

it('derives the set from targetsGuardians, with two named exceptions and no others', function (): void {
    // Two hand-written lists answering one question diverge at the first type
    // anybody adds, and the divergence is silent: the new type simply never
    // leaves the platform. So the rule stays derived, and every exception is
    // asserted BY NAME — a third unlisted divergence fails here.
    //
    // The second exception arrived with 013 and is the same shape as the first:
    // a message whose whole value is arriving before the recipient's next visit,
    // to someone who may not have one.
    $exceptions = [NotificationType::SecurityAlert, NotificationType::GuardianConsentRequired];

    foreach (NotificationType::cases() as $type) {
        expect(in_array(NotificationChannel::WhatsApp, $type->defaultChannels(), true))
            ->toBe($type->targetsGuardians() || in_array($type, $exceptions, true), $type->value);
    }
});

it('sends the security alert to a phone without sending it to a guardian', function (): void {
    // The alert says somebody else signed in as you, and the bell reaches its
    // owner only on their next visit — which, if the eviction worked, is the
    // person who no longer can. So it leaves the platform.
    expect(NotificationType::SecurityAlert->defaultChannels())
        ->toBe([NotificationChannel::InApp, NotificationChannel::WhatsApp]);

    // ⚠️ AND THE OTHER HALF, which is why this is not one word in
    // targetsGuardians(): the sign-in it reports may BE the guardian's, and a
    // student's own security alert copied to their parent is a different feature
    // nobody asked for. No fan-out, and no guardian permission to gate it by.
    expect(NotificationType::SecurityAlert->targetsGuardians())->toBeFalse();
    expect(NotificationType::SecurityAlert->requiredGuardianPermission())->toBeNull();
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
