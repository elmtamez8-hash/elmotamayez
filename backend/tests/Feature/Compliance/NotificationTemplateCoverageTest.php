<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/**
 * Every type has a template on every channel it defaults to (SC-018).
 *
 * ⚠️ THE DEFECT THIS EXISTS FOR CANNOT BE SEEN ANY OTHER WAY. `TemplateRenderer`
 * refuses to render a missing or unapproved template and `DispatchNotification`
 * LOGS rather than failing the enrolment or the payment behind it — which is the
 * right call for the business and a disaster for testing, because the message
 * simply never appears and every assertion about it passes by finding nothing.
 *
 * ⚠️ AND `tests/Pest.php` SEEDS THE TEMPLATES BEFORE EVERY FEATURE TEST, so the
 * absence is invisible from inside any other test in the suite: whatever the
 * seeder happens to contain is what exists. This file compares the seeder against
 * the ENUM, which is the only place the gap shows.
 *
 * It has already caught six mechanisms of the same shape in this repository — the
 * homework types, the gamification types, and now the six of spec 013.
 */
it('seeds an in-app template for every notification type', function (): void {
    $seeded = MessageTemplate::query()
        ->where('channel', NotificationChannel::InApp->value)
        ->pluck('type')
        ->all();

    $missing = array_values(array_diff(
        array_column(NotificationType::cases(), 'value'),
        $seeded,
    ));

    expect($missing)->toBe([]);
});

/*
 * The second channel, and the half that costs money when it is wrong.
 *
 * A type that defaults to WhatsApp with no WhatsApp row is dropped in silence; a
 * WhatsApp row for a type that does NOT default to it is a template submitted to
 * the provider for approval that nothing will ever send. The seeder derives the
 * set from `defaultChannels()` precisely so neither can happen, and this asserts
 * the derivation still runs.
 */
it('seeds a WhatsApp template for exactly the types that default to it', function (): void {
    $expected = array_values(array_map(
        fn (NotificationType $type): string => $type->value,
        array_filter(
            NotificationType::cases(),
            fn (NotificationType $type): bool => in_array(
                NotificationChannel::WhatsApp,
                $type->defaultChannels(),
                true,
            ),
        ),
    ));

    $seeded = MessageTemplate::query()
        ->where('channel', NotificationChannel::WhatsApp->value)
        ->pluck('type')
        ->all();

    /*
    | ⚠️ ONE SEEDED ROW IS NOT A `NotificationType` AT ALL, and excluding it here
    | is the correct reading rather than a convenience. `contact_verification`
    | carries the one-time code, and it CANNOT go through `DispatchNotification`
    | by construction: `canReach()` requires a VERIFIED contact, and this is by
    | definition the message sent to an unverified one. The channel is called
    | directly through the `SendsVerificationCodes` capability.
    |
    | It is also the template that must be approved FIRST — until it is, no number
    | on the platform can be verified, so not one of the others ever leaves the
    | building.
    */
    $seeded = array_values(array_diff($seeded, ['contact_verification']));

    sort($expected);
    sort($seeded);

    expect($seeded)->toBe($expected);
});

/*
 * And the six this phase added, named individually.
 *
 * The two assertions above are derived and would stay green if all six were
 * dropped from the enum along with their templates — which is exactly what a
 * refactor that "cleaned up unused types" would do. Naming them is what makes
 * their removal a failure rather than a quiet simplification.
 */
it('carries the six data-protection templates by name', function (): void {
    $required = [
        NotificationType::GuardianConsentRequired,
        NotificationType::DataOwnershipTransferred,
        NotificationType::DataRequestCreated,
        NotificationType::DataRequestCompleted,
        NotificationType::GuardianConsentConflict,
        NotificationType::TeacherOffboardingNotice,
    ];

    foreach ($required as $type) {
        $template = MessageTemplate::query()
            ->where('type', $type->value)
            ->where('channel', NotificationChannel::InApp->value)
            ->first();

        expect($template)->not->toBeNull($type->value)
            // A row that exists but is not approved renders nothing, which is the
            // same silence with a different cause.
            ->and($template?->is_active)->toBeTrue($type->value)
            // ⚠️ AND A TEMPLATE WITH NO VARIABLES IS A TEMPLATE THAT SAYS NOTHING
            // ABOUT WHOM. Every one of these names a person or a request.
            ->and($template?->variables)->not->toBeEmpty($type->value);
    }
});

/*
 * ⚠️ AND NOT ONE OF THEM MAY NAME WHO REFUSED.
 *
 * The conflict message reaches BOTH guardians, who may be in a custody dispute.
 * «رفضت والدتك» in an automated message is personal data about a third party, sent
 * by us, in writing — and the variable list is where such a leak would first
 * appear, before any listener is written to fill it.
 */
it('gives the conflict template no variable that could name the refusing guardian', function (): void {
    $template = MessageTemplate::query()
        ->where('type', NotificationType::GuardianConsentConflict->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail();

    /** @var list<string> $variables */
    $variables = (array) $template->variables;

    foreach (['guardian_name', 'refused_by', 'refuser_name', 'decided_by'] as $forbidden) {
        expect($variables)->not->toContain($forbidden);
    }
});
