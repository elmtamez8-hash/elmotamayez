<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\Tenancy\Support\PlatformSettings;
use Laravel\Sanctum\Sanctum;

/*
| The minimum notice for a private hour — `sessions.min_lead_minutes`.
|
| ⛔ «NOT IN THE PAST» WAS THE WHOLE RULE, SO AN HOUR ONE MINUTE AWAY COULD BE
| ASKED FOR. The clock is moved to just before the fixture's slot rather than the
| slot moved to just after now: the slot has to sit inside the teacher's declared
| window, so it is the one thing in this file that may not move.
|
| ⚠️ THE COURSE PAGE READS THE SAME NUMBER — `PublicCourseSubscriptionDoorTest`
| asserts it travels on the public payload, and the picker counts from it, so it
| never offers an hour this door refuses.
*/

it('refuses a private hour that starts inside the minimum notice', function (): void {
    $fx = privateSessionFixture();

    // Thirty minutes before the slot — the past check passes, the lead does not.
    $this->travelTo($fx['startsAt']->subMinutes(30));

    Sanctum::actingAs($fx['student']);

    $refused = $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ]);

    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('اختر موعداً يبدأ بعد')
        ->and($refused->json('message'))->toContain('ساعتين');

    expect(PrivateSessionRequest::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('accepts the same hour once it is far enough ahead', function (): void {
    $fx = privateSessionFixture();

    // The control for the case above: same slot, same student, three hours out.
    $this->travelTo($fx['startsAt']->subHours(3));

    Sanctum::actingAs($fx['student']);

    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();
});

it('reads the notice from platform_settings, not from a constant', function (): void {
    $fx = privateSessionFixture();

    PlatformSettings::set('sessions.min_lead_minutes', 15);

    // Thirty minutes out is refused by the default and allowed by the row.
    $this->travelTo($fx['startsAt']->subMinutes(30));

    Sanctum::actingAs($fx['student']);

    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();
});
