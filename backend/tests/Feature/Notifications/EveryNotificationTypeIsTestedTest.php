<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;

/*
 * The owner's rule: EVERY NOTIFICATION HAS A TEST.
 *
 * Three questions per `NotificationType` case, each of which has already failed
 * silently in this tree at least once:
 *
 *   (a) is there a SENDABLE in-app template? — a notification with none is
 *       dropped in silence (`TemplateRenderer` refuses, `DispatchNotification`
 *       logs and carries on). The predicate is `MessageTemplate::isSendable()`,
 *       the one `TemplateRenderer` itself asks — never a second spelling of it.
 *   (b) does anything in PRODUCTION send it? — `data_request_created`,
 *       `data_request_completed` and `guardian_consent_conflict` had a label, a
 *       template, a spec requirement and a test asserting what the message must
 *       NOT say — and no line anywhere that sent one. The test was green over
 *       zero rows.
 *   (c) is it named by a test that exercises it? — a scan of `tests/`, with the
 *       enum-shaped and plumbing-shaped files excluded: a file that only uses a
 *       type as a FIXTURE for the dispatcher (quiet hours, preferences, the
 *       WhatsApp path) proves nothing about whether the business event behind it
 *       ever produces one.
 *
 * ⚠️ THE EXCEPTIONS ARE A LIST WITH A REASON EACH, AND IT CUTS BOTH WAYS: a type
 * listed as having no producer that GAINS one fails here, so the entry has to be
 * removed — and its behavioural test written — in the same change.
 */

/**
 * Types allowed to have no producer, each with its reason.
 *
 * EMPTY since 2026-09-24, and meant to stay that way. The three reserved in 003
 * were decided by the owner: `attendance_alert` gained its producer
 * (`SendAbsenceAlerts`), `academic_warning` gained its
 * (`WarnOnConsecutiveFailures`), and `payment_reminder` was deleted. A new entry
 * here is a type shipped ahead of the code that sends it — say why beside it.
 *
 * @return array<string, string>
 */
function everyTypeReservedExceptions(): array
{
    return [];
}

/**
 * Files that name a type without exercising the business event behind it: the
 * enum's own shape (categories, defaults, templates, backfills) and the
 * dispatcher's plumbing, which picks an arbitrary type as a fixture.
 *
 * @return list<string>
 */
function everyTypeExcludedTestFiles(): array
{
    return [
        'Feature/Notifications/EveryNotificationTypeIsTestedTest.php',
        'Feature/Notifications/NotificationCategoryTest.php',
        'Feature/Notifications/NotificationCategoryFilterTest.php',
        'Feature/Compliance/NotificationTemplateCoverageTest.php',
        'Feature/Notifications/WhatsAppDefaultsTest.php',
        'Feature/Notifications/PushDefaultsUnchangedTest.php',
        'Feature/Notifications/TemplateBackfillTest.php',
        'Feature/Notifications/MigrationBackfillTest.php',
        'Feature/Notifications/ChannelContractTest.php',
        'Feature/Notifications/DeliveryLifecycleTest.php',
        'Feature/Notifications/NotificationCenterTest.php',
        'Feature/Notifications/PlatformOwnershipTest.php',
        'Feature/Notifications/PreferencesTest.php',
        'Feature/Notifications/QuietHoursTest.php',
        'Feature/Notifications/TemplateAndLogTest.php',
        'Feature/Notifications/GuardianDeliveryTest.php',
        'Feature/Notifications/PushQuietHoursTest.php',
        'Feature/Notifications/PushSharedDeviceTest.php',
        'Feature/Notifications/WhatsAppChannelTest.php',
        'Feature/Notifications/WhatsAppDeliveryPathTest.php',
        'Pest.php',
    ];
}

/**
 * @return array<string, string> relative path => comment-stripped source
 */
function everyTypeSources(string $root, callable $keep): array
{
    $sources = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (! $keep($relative)) {
            continue;
        }

        // Comments stripped (the shared `codeWithoutComments()` in Pest.php): a
        // docblock that names a type is not a test of it, and not a producer.
        $sources[$relative] = codeWithoutComments((string) file_get_contents($file->getPathname()));
    }

    return $sources;
}

/**
 * Where in production each type is sent from. Excluded by PATH, not included:
 * `SecurityAlert` is sent from `Identity/Support/DeviceRegistry.php`, so an
 * include-list of "Actions, Listeners, Jobs" would miss a real producer.
 *
 * @return array<string, list<string>>
 */
function everyTypeProducers(): array
{
    $sources = everyTypeSources(app_path(), static fn (string $path): bool => ! str_starts_with($path, 'Filament/')
        && ! str_contains($path, '/Database/Migrations/')
        && ! str_starts_with($path, 'Modules/Notifications/Support/'));

    $found = [];

    foreach (NotificationType::cases() as $type) {
        $pattern = '/NotificationType::'.$type->name.'\b/';

        foreach ($sources as $path => $source) {
            if (preg_match($pattern, $source) === 1) {
                $found[$type->value][] = $path;
            }
        }
    }

    return $found;
}

/**
 * @return array<string, list<string>>
 */
function everyTypeTestReferences(): array
{
    $excluded = everyTypeExcludedTestFiles();
    $sources = everyTypeSources(base_path('tests'), static fn (string $path): bool => ! str_starts_with($path, 'Unit/')
        && ! in_array($path, $excluded, true));

    $found = [];

    foreach (NotificationType::cases() as $type) {
        // The enum case, or the raw value used AS A TYPE — never the bare string,
        // which collides with refusal codes (`'access_withheld'` is also an HTTP
        // error code, and matching it would count a 403 as a notification test).
        $pattern = '/NotificationType::'.$type->name.'\b|[\'"]type[\'"]\s*(?:=>|,)\s*[\'"]'.$type->value.'[\'"]/';

        foreach ($sources as $path => $source) {
            if (preg_match($pattern, $source) === 1) {
                $found[$type->value][] = $path;
            }
        }
    }

    return $found;
}

it('seeds a sendable in-app template for every type', function (): void {
    $unsendable = [];

    foreach (NotificationType::cases() as $type) {
        $template = MessageTemplate::query()
            ->where('type', $type->value)
            ->where('channel', NotificationChannel::InApp->value)
            ->first();

        if ($template === null || ! $template->isSendable()) {
            $unsendable[] = $type->value;
        }
    }

    expect($unsendable)->toBe([]);
});

it('finds a production producer for every type that is not a reserved exception', function (): void {
    $producers = everyTypeProducers();

    // The scan has to have MATCHED something, or a broken pattern passes as a
    // clean bill of health. Enrolment is sent from exactly one listener.
    expect($producers[NotificationType::EnrollmentCreated->value] ?? [])
        ->toContain('Modules/Notifications/Listeners/NotifyStudentEnrolled.php');

    $reserved = array_keys(everyTypeReservedExceptions());

    $dead = array_values(array_filter(
        array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases()),
        static fn (string $value): bool => ! isset($producers[$value]) && ! in_array($value, $reserved, true),
    ));

    expect($dead)->toBe([]);

    // And the list does not outlive its reason: a reserved type that gains a
    // producer must leave this list, and bring its behavioural test with it.
    $revived = array_values(array_filter($reserved, static fn (string $value): bool => isset($producers[$value])));

    expect($revived)->toBe([]);
});

it('finds a behavioural test for every type that is sent', function (): void {
    $references = everyTypeTestReferences();

    expect($references[NotificationType::EnrollmentCreated->value] ?? [])
        ->toContain('Feature/Payments/PaymentTest.php');

    $reserved = array_keys(everyTypeReservedExceptions());

    $untested = array_values(array_filter(
        array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases()),
        static fn (string $value): bool => ! isset($references[$value]) && ! in_array($value, $reserved, true),
    ));

    expect($untested)->toBe([]);
});

it('keeps every exception reasoned and every excluded file real', function (): void {
    foreach (everyTypeReservedExceptions() as $value => $reason) {
        expect(NotificationType::tryFrom($value))->not->toBeNull()
            ->and(mb_strlen($reason))->toBeGreaterThan(40);
    }

    // A renamed file left on the exclusion list silently re-includes nothing and
    // excludes nothing — but a typo here would hide a real reference for ever.
    foreach (everyTypeExcludedTestFiles() as $path) {
        expect(is_file(base_path('tests/'.$path)))->toBeTrue("missing excluded file: {$path}");
    }
});
