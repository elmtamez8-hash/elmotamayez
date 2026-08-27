<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationCategory;
use App\Modules\Notifications\Support\NotificationType;
use Laravel\Sanctum\Sanctum;

/*
| `?category=` وشريطُ التبويبات المشتقُّ منه.
|
| ⚠️ الحائطُ هو المشكلة، لا الترتيب. طالبٌ واحدٌ في بياناتِ العرضِ عندَه أربعةٌ
| وستّون إشعاراً غيرَ مقروء، وصفحتُه الأولى وحدَها فيها سبعةُ أنواعٍ مختلفة —
| تقاريرُ حصصٍ وشاراتٌ ودرجةُ واجبٍ ورسالةُ شات، كلُّها بالشكلِ نفسِه صفّاً تحتَ
| صفّ. ثمانيةٌ وأربعونَ نوعاً ليست مرشِّحاً، بل قائمةٌ ثانيةٌ تُقرَأ.
|
| ⚠️ AND THE TABS ARE DERIVED, SO ONE THAT ANSWERS NOTHING IS NEVER OFFERED —
| a student receives no settlement notice and a teacher no guardian-consent
| request, and a fixed strip would show each of them a control that empties the
| page. The rule spec 009's leaderboard picker was fixed under, and the one the
| mistake notebook's bar follows.
*/

/** @return array{user: User, other: User} */
function categoryFeed(): array
{
    $user = User::factory()->create();
    $other = User::factory()->create();

    $put = function (User $recipient, NotificationType $type, bool $read = false) {
        $factory = Notification::factory();

        return ($read ? $factory->read() : $factory)->create([
            'recipient_user_id' => $recipient->getKey(),
            'type' => $type->value,
        ]);
    };

    // Study: two unread, one already read.
    $put($user, NotificationType::ExamResult);
    $put($user, NotificationType::AssignmentGraded);
    $put($user, NotificationType::AcademicWarning, read: true);

    // Sessions: three unread.
    $put($user, NotificationType::SessionReport);
    $put($user, NotificationType::SessionCancelled);
    $put($user, NotificationType::AppointmentReminder);

    // Messages: one unread.
    $put($user, NotificationType::AnnouncementUrgent);

    // Somebody else's settlement notice — the reader has no such subject.
    $put($other, NotificationType::TeacherPayoutIssued);

    return ['user' => $user, 'other' => $other];
}

/** @return list<string> */
function feedTypes(array $payload): array
{
    return array_map(static fn (array $row): string => (string) $row['type'], $payload['data'] ?? []);
}

beforeEach(function (): void {
    $this->fx = categoryFeed();

    Sanctum::actingAs($this->fx['user']);
});

it('narrows the feed to one subject', function (): void {
    $types = feedTypes($this->getJson('/api/v1/notifications?category=sessions')->assertOk()->json());

    sort($types);

    expect($types)->toBe([
        NotificationType::AppointmentReminder->value,
        NotificationType::SessionCancelled->value,
        NotificationType::SessionReport->value,
    ]);
});

/*
| ⚠️ AN UNKNOWN SUBJECT EMPTIES THE FEED, IT DOES NOT WIDEN IT. A tab headed
| «الحصص والمواعيد» that silently dropped its filter would show the reader their
| whole feed under one word — the direction every filter in this product fails in.
*/
it('answers a subject it does not know with nothing rather than everything', function (): void {
    expect(feedTypes($this->getJson('/api/v1/notifications?category=nonsense')->assertOk()->json()))
        ->toBeEmpty();

    // The control: unfiltered, the feed is still whole.
    expect(feedTypes($this->getJson('/api/v1/notifications')->assertOk()->json()))->toHaveCount(7);
});

it('offers only the subjects this reader actually has, with what is unread in each', function (): void {
    $categories = $this->getJson('/api/v1/notifications')->assertOk()->json('meta.categories');

    $byKey = collect($categories)->keyBy('key');

    expect($byKey->keys()->all())->toBe(['study', 'sessions', 'messages'])
        ->and($byKey['study']['unread'])->toBe(2)
        ->and($byKey['study']['total'])->toBe(3)
        ->and($byKey['sessions']['unread'])->toBe(3)
        ->and($byKey['messages']['unread'])->toBe(1)
        // The name a reader acts on, never the machine word.
        ->and($byKey['sessions']['label'])->toBe(NotificationCategory::Sessions->label());
});

it('offers no subject belonging to somebody else\'s feed', function (): void {
    $keys = array_column($this->getJson('/api/v1/notifications')->assertOk()->json('meta.categories'), 'key');

    expect($keys)->not->toContain(NotificationCategory::Settlement->value)
        ->and($keys)->not->toContain(NotificationCategory::Account->value);
});

/*
| ⚠️ THE STRIP DESCRIBES THE WHOLE FEED, NOT THE TAB BEING READ. Recomputed under
| the open category, every other tab would report zero and the reader would have
| no way back to them.
*/
it('keeps the strip whole while one subject is open', function (): void {
    $keys = array_column(
        $this->getJson('/api/v1/notifications?category=sessions')->assertOk()->json('meta.categories'),
        'key',
    );

    expect($keys)->toBe(['study', 'sessions', 'messages']);
});

/*
| ⚠️ EVERY OFFERED TAB ANSWERS A ROW — walked through the real endpoint, the
| shape `LeaderboardScopesTest` established. A strip built beside the reader
| rather than from it offers what the reader refuses.
*/
it('offers no tab the feed answers empty', function (): void {
    $categories = $this->getJson('/api/v1/notifications')->assertOk()->json('meta.categories');

    expect($categories)->not->toBeEmpty();

    foreach ($categories as $category) {
        $rows = feedTypes($this->getJson('/api/v1/notifications?category='.$category['key'])->assertOk()->json());

        expect($rows)->not->toBeEmpty("the «{$category['label']}» tab is offered and answers nothing")
            ->and($rows)->toHaveCount($category['total']);
    }
});
