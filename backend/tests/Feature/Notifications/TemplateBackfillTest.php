<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use Database\Seeders\NotificationTemplateSeeder;

/*
| A live database gets the templates for every type that has none — and keeps
| every wording an admin has edited.
|
| ⚠️ THE DEFECT THIS GUARDS IS SILENT BY CONSTRUCTION. `TemplateRenderer` refuses
| a missing row and `DispatchNotification` LOGS rather than failing the operation
| that triggered it, deliberately — so a type shipped without its row reaches
| nobody, for ever, with nothing on any screen saying so. Spec 010's first live
| announcement reached ZERO of three students on a database whose migrations were
| fully up to date, and the whole suite was green: `tests/Pest.php` seeds the
| templates before every Feature test, so no ordinary test can see it.
|
| ⚠️ AND THE SECOND ASSERTION IS THE ONE THAT COSTS SOMETHING. `run()` writes with
| `updateOrCreate`; called from a deploy it would replace every wording an admin
| edited from the panel with the shipped default — a worse defect than the one
| being fixed, and equally silent. `seedMissing()` is what must not do that.
*/

it('creates a template that a live database is missing', function (): void {
    // `tests/Pest.php` seeds them all, so the gap is made rather than assumed.
    MessageTemplate::query()
        ->where('type', NotificationType::AnnouncementUrgent->value)
        ->delete();

    (new NotificationTemplateSeeder)->seedMissing();

    expect(MessageTemplate::query()
        ->where('type', NotificationType::AnnouncementUrgent->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->exists())->toBeTrue();
});

it('leaves an admin\'s edited wording exactly as they wrote it', function (): void {
    $template = MessageTemplate::query()
        ->where('type', NotificationType::Announcement->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail();

    // An ASCII sentinel, for the reason every exposure test in this product uses
    // one — and here because the shipped default is Arabic and a comparison
    // against it would be true for the wrong reason on any encoding slip.
    $template->update(['body_ar' => 'ADMIN_EDITED_SENTINEL']);

    (new NotificationTemplateSeeder)->seedMissing();

    expect($template->fresh()?->body_ar)->toBe('ADMIN_EDITED_SENTINEL');
});

it('overwrites deliberately when the seeder is run for a fresh database', function (): void {
    $template = MessageTemplate::query()
        ->where('type', NotificationType::Announcement->value)
        ->where('channel', NotificationChannel::InApp->value)
        ->firstOrFail();

    $template->update(['body_ar' => 'ADMIN_EDITED_SENTINEL']);

    // The other direction, asserted so the two modes cannot quietly become one:
    // `migrate:fresh --seed` must restore the shipped wording.
    (new NotificationTemplateSeeder)->run();

    expect($template->fresh()?->body_ar)->not->toBe('ADMIN_EDITED_SENTINEL');
});

it('holds a template for every type the product can send', function (): void {
    /*
    | ⚠️ THE COUNT IS DERIVED FROM THE ENUM, NEVER WRITTEN DOWN. A literal here
    | would have to be edited by the same person who forgot the template row —
    | so it would be edited, and the guard would pass. This fails the moment a
    | `NotificationType` case is added without wording beside it.
    */
    $missing = [];

    foreach (NotificationType::cases() as $type) {
        $exists = MessageTemplate::query()
            ->where('type', $type->value)
            ->where('channel', NotificationChannel::InApp->value)
            ->exists();

        if (! $exists) {
            $missing[] = $type->value;
        }
    }

    expect($missing)->toBe([]);
});
