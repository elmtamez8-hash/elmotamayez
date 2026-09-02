<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Spec 023 · T044 — what is refused, and WHEN.
|
| FR-025 puts the money and discipline refusals at SUBMISSION. A student blocked
| for an unpaid balance who is allowed to submit spends the whole deadline
| waiting for a teacher to press a button that will then refuse them — and
| neither of them ever learns why. FR-016ب puts the whole DURATION inside a
| declared window, not merely its first minute.
*/

it('refuses a student who never bought the course', function (): void {
    $fx = privateSessionFixture();
    $stranger = User::factory()->create();

    Sanctum::actingAs($stranger);

    $refused = $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ]);

    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('لست مسجّلاً');

    // FR-017: nothing exists after a refusal, including the row itself.
    expect(PrivateSessionRequest::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses an hour whose END falls outside the declared window', function (): void {
    // The window is 14:00–18:00 and the lesson is 45 minutes, so 17:30 starts
    // inside it and finishes half an hour after the teacher goes home.
    $fx = privateSessionFixture(minutes: 45);

    Sanctum::actingAs($fx['student']);

    $refused = $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->setTime(17, 30)->toIso8601String(),
    ]);

    $refused->assertStatus(422);
    expect($refused->json('message'))->toContain('خارج مواعيد المدرّس');

    // The control: the same start with a duration that fits is accepted, so the
    // refusal above is about the END and not about the hour.
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->setTime(17, 0)->toIso8601String(),
    ])->assertCreated();
});

it('refuses an hour on a day the teacher declared nothing', function (): void {
    $fx = privateSessionFixture();

    Sanctum::actingAs($fx['student']);

    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->addDay()->toIso8601String(),
    ])->assertStatus(422);
});

it('refuses at submission when the balance has run out, not at acceptance', function (): void {
    // No funding at all: the launch default is prepaid credits, so an empty
    // balance is exactly the student FR-025 is about.
    $fx = privateSessionFixture(credits: 0);

    Sanctum::actingAs($fx['student']);

    $refused = $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ]);

    $refused->assertStatus(422);
    // SC-010 — the sentence names the number and the way to pay. «غير مسموح» is a
    // dead end wearing the same status code.
    expect($refused->json('message'))->toContain('رصيدك');

    expect(PrivateSessionRequest::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses the acceptance when the balance ran out AFTER the ask', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture(credits: 1);

    Sanctum::actingAs($fx['student']);
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    // The credit is spent between the ask and the answer — which is legitimate
    // and is why FR-021 says an acceptance CAN fail. It costs the student a
    // request and nothing else, because the ask held nothing.
    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): void {
        consumeCredits(billingBalance($fx['workspace'], $fx['student'], $fx['course']), 1);
    });

    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->firstOrFail();

    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    $this->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", [
        'accept' => true,
    ])->assertStatus(422);

    // ⚠️ AND NOTHING PARTIAL SURVIVES (FR-020). A lesson on the calendar with no
    // seat is an hour the student is not in, and the teacher would be paid for it.
    expect(ClassSession::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and(SessionBooking::query()->withoutWorkspaceScope()->count())->toBe(0)
        ->and($request->refresh()->status)->toBe(PrivateSessionRequest::PENDING);
});

it('refuses the acceptance when the teacher took that hour elsewhere', function (): void {
    fakeSessionTimeline();

    $fx = privateSessionFixture();

    Sanctum::actingAs($fx['student']);
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): void {
        ClassSession::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'teacher_profile_id' => $fx['profile']->getKey(),
            'course_id' => $fx['course']->getKey(),
            'starts_at' => $fx['startsAt'],
            'ends_at' => $fx['startsAt']->addHour(),
        ]);
    });

    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->firstOrFail();

    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    $clash = $this->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", [
        'accept' => true,
    ]);

    $clash->assertStatus(422);
    expect($clash->json('message'))->toContain('حصة أخرى');
});
