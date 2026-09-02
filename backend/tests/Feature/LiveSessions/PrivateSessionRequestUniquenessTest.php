<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Spec 023 · T040 — ⚠️ TRAP 1: THE THIRD CASE IS THE ONE THAT BITES.
|
| FR-022 says a request is unique per (student × starting instant) WHILE IT IS
| LIVE. The obvious spelling is a partial unique index — and MySQL has none at
| all, while SQLite has had them since 3.8, so the wrong form is green in every
| local run of this suite and kills the migration on the deploy.
|
| The guard is `pending_slot`: `0` while the request is live, the row's own id
| once it is settled. NULL never equals NULL and a settled id is unique by
| construction, so every decided request coexists with every other and only the
| live ones compete.
|
| ⚠️ CASES ONE AND TWO PASS AGAINST A BUILD THAT NEVER RELEASES THE SLOT AT ALL.
| A design that writes `pending_slot = 0` and leaves it there is perfectly unique
| among live requests and also among dead ones — so a Tuesday six o'clock a
| teacher once refused is reserved against that student FOR EVER, and the second
| ask comes back «you already have a request at this time» about a request that
| no longer exists. The third case is what sees it.
*/

function submitPrivateRequest(array $fx, ?string $startsAt = null): TestResponse
{
    Sanctum::actingAs($fx['student']);

    return test()->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $startsAt ?? $fx['startsAt']->toIso8601String(),
    ]);
}

it('accepts the first ask, refuses the same moment twice, and frees it once refused', function (): void {
    $fx = privateSessionFixture();

    // 1 — the ask.
    submitPrivateRequest($fx)->assertCreated();

    // 2 — the same instant while the first is still waiting.
    $duplicate = submitPrivateRequest($fx);
    $duplicate->assertStatus(422);
    expect($duplicate->json('message'))->toContain('طلب قائم');

    // 3 — THE LOAD-BEARING ONE. The teacher refuses, and the hour becomes
    // askable again. Without the slot moving off its zero this is a 422 for the
    // rest of the student's life at this teacher.
    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->firstOrFail();

    $this->setCurrentWorkspace($fx['workspace'], $fx['owner']);
    Sanctum::actingAs($fx['owner']);

    $this->postJson("/api/v1/manage/private-session-requests/{$request->uuid}/decide", [
        'accept' => false,
        'decision_reason' => 'مشغول في ذلك الوقت.',
    ])->assertOk();

    $this->asGuest();

    submitPrivateRequest($fx)->assertCreated();

    expect(PrivateSessionRequest::query()->withoutWorkspaceScope()->count())->toBe(2);
});

it('lets two students ask for the very same hour', function (): void {
    $fx = privateSessionFixture();

    submitPrivateRequest($fx)->assertCreated();

    // The index is on the STUDENT and the instant. A teacher cannot teach both —
    // that is what the overlap check at acceptance is for, and it belongs there:
    // refusing the second ask outright would let whoever clicked first take the
    // hour before the teacher had an opinion about either.
    $second = privateSessionFixture();

    Sanctum::actingAs($second['student']);

    test()->postJson("/api/v1/courses/{$second['course']->uuid}/private-session-requests", [
        'starts_at' => $second['startsAt']->toIso8601String(),
    ])->assertCreated();
});

it('holds one student to three live requests at one teacher', function (): void {
    $fx = privateSessionFixture();

    // Three distinct hours inside the same declared window.
    foreach ([0, 1, 2] as $offset) {
        submitPrivateRequest($fx, $fx['startsAt']->subHour()->addMinutes($offset * 60)->toIso8601String())
            ->assertCreated();
    }

    $fourth = submitPrivateRequest($fx, $fx['startsAt']->addWeek()->toIso8601String());
    $fourth->assertStatus(422);
    expect($fourth->json('message'))->toContain('تنتظر الردّ');

    // ⚠️ AND THE CEILING IS RELEASED BY A DECISION, NOT BY TIME. A limit that
    // only clears on expiry would make one teacher's slow week a week the
    // student cannot ask for anything at all.
    $request = PrivateSessionRequest::query()->withoutWorkspaceScope()->pending()->firstOrFail();

    Sanctum::actingAs($fx['student']);
    $this->deleteJson("/api/v1/private-session-requests/{$request->uuid}")->assertOk();

    submitPrivateRequest($fx, $fx['startsAt']->addWeek()->toIso8601String())->assertCreated();
});
