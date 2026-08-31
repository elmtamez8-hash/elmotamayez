<?php

declare(strict_types=1);

use App\Modules\Notifications\Actions\SavePushSubscription;
use App\Modules\Notifications\Support\NotificationCategory;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
| Spec 012 · US2 · T081 — the channel switched itself on for NOBODY, on purpose.
|
| ⚠️ THIS IS THE INVERSE OF THE GUARD SPEC 020 NEEDED. There, `defaultChannels()`
| returned `[InApp]` for all thirty-five types, so shipping `WhatsAppChannel`
| without touching it would have created no delivery row at all — nothing fails,
| because nothing runs, and every assertion about the new channel passes by
| finding nothing to contradict it. `WhatsAppDefaultsTest` counts exactly to stop
| that.
|
| Push is the opposite mistake, and it is the expensive one: in the defaults it is
| a delivery row and a queued job for EVERY notification to EVERY recipient on the
| platform, the large majority of whom have no device registered — and for a
| mandatory type `PreferenceResolver` merges the defaults ON TOP of the stored
| preference, making it a channel nobody can switch off, on a phone they may be
| sharing.
*/

it('leaves push out of every type default', function (): void {
    foreach (NotificationType::cases() as $type) {
        expect($type->defaultChannels())
            ->not->toContain(NotificationChannel::Push, "{$type->value} must not default to push");
    }
});

it('leaves the security alert defaults exactly as WhatsAppDefaultsTest asserts them', function (): void {
    /*
    | The literal that would break. `WhatsAppDefaultsTest` asserts this with
    | `toBe()` — an exact list — and it is repeated here so a reader of the PUSH
    | change sees which file fails and why, rather than discovering it as a
    | mysterious red line in a spec 020 test.
    */
    expect(NotificationType::SecurityAlert->defaultChannels())
        ->toBe([NotificationChannel::InApp, NotificationChannel::WhatsApp]);
});

it('turns push on through the three subject categories and no fourth list', function (): void {
    $expected = [
        NotificationCategory::Sessions,
        NotificationCategory::Balance,
        NotificationCategory::Account,
    ];

    /*
    | ⚠️ DERIVED FROM `NotificationCategory`, NEVER WRITTEN OUT AS TYPES. A type
    | added to «الحصص والمواعيد» tomorrow is covered without anyone remembering
    | `SavePushSubscription`; a literal list of types there would be a second
    | answer that diverges at the first addition, silently.
    */
    expect(SavePushSubscription::pushedCategories())->toBe($expected);

    // …and the ones deliberately left off: several a week on a lock screen is how
    // a person mutes the app, taking the attendance alert with them.
    expect(SavePushSubscription::pushedCategories())
        ->not->toContain(NotificationCategory::Achievements)
        ->not->toContain(NotificationCategory::Messages)
        ->not->toContain(NotificationCategory::Study)
        ->not->toContain(NotificationCategory::Settlement);
});
