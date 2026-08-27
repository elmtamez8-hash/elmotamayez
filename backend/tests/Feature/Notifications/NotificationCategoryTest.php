<?php

declare(strict_types=1);

use App\Modules\Notifications\Support\NotificationCategory;
use App\Modules\Notifications\Support\NotificationType;

/*
| المجموعاتُ السبعُ التي تُصفَّى بها صفحةُ الإشعارات.
|
| ⚠️ THE MAP RUNS CATEGORY → TYPES, SO AN UNCLASSIFIED TYPE CANNOT CRASH ANYTHING
| — it is simply absent from the tabs and still present in «الكل». That is the
| safe direction and it is also the silent one, which is why this file exists:
| without it a type added tomorrow would quietly stop being filterable and
| nothing anywhere would say so.
*/

it('classifies every notification type exactly once', function (): void {
    $seen = [];

    foreach (NotificationCategory::cases() as $category) {
        foreach ($category->typeValues() as $value) {
            $seen[] = $value;
        }
    }

    $all = array_map(static fn (NotificationType $type): string => $type->value, NotificationType::cases());

    sort($seen);
    sort($all);

    // ⚠️ TWO ASSERTIONS, AND THEY CATCH OPPOSITE MISTAKES. The first fails when a
    // new type is added and nobody filed it — the case this file was written
    // for. The second fails when one type is filed under two subjects, which no
    // list comparison would notice on its own: the tabs would then both claim
    // it, and the counts beside them would add up to more than the feed holds.
    expect($seen)->toBe($all)
        ->and($seen)->toBe(array_values(array_unique($seen)));
});

it('maps a type back to its subject, and answers null for one nobody filed', function (): void {
    $byType = NotificationCategory::byType();

    expect($byType[NotificationType::ExamResult->value])->toBe(NotificationCategory::Study)
        ->and($byType[NotificationType::SessionCancelled->value])->toBe(NotificationCategory::Sessions)
        ->and($byType[NotificationType::CertificateIssued->value])->toBe(NotificationCategory::Achievements)
        ->and($byType[NotificationType::AnnouncementUrgent->value])->toBe(NotificationCategory::Messages)
        ->and($byType[NotificationType::SecurityAlert->value])->toBe(NotificationCategory::Account)
        ->and($byType['no_such_type'] ?? null)->toBeNull();
});

/*
| ⚠️ THE STUDENT'S MONEY AND THE TEACHER'S PAY ARE TWO SUBJECTS, NOT ONE.
|
| Spec 006 draws that line through the whole product — settlement and billing
| share no key, no query and no screen — and a single «financial» tab here would
| be one control meaning two different things depending on who opened it.
*/
it('keeps what a student owes apart from what a teacher is owed', function (): void {
    $balance = NotificationCategory::Balance->typeValues();
    $settlement = NotificationCategory::Settlement->typeValues();

    expect($balance)->toContain(NotificationType::AccessWithheld->value)
        ->and($balance)->not->toContain(NotificationType::TeacherPayoutIssued->value)
        ->and($settlement)->toContain(NotificationType::TeacherPayoutIssued->value)
        ->and($settlement)->not->toContain(NotificationType::PaymentReminder->value)
        ->and(array_intersect($balance, $settlement))->toBeEmpty();
});

it('gives every subject a name a reader can act on', function (): void {
    foreach (NotificationCategory::cases() as $category) {
        expect($category->label())->not->toBe('')
            // Never the machine word: a tab reading «settlement» is a tab in the
            // wrong language on an Arabic-only product.
            ->and($category->label())->not->toBe($category->value);
    }
});
