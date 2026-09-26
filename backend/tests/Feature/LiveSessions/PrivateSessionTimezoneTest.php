<?php

declare(strict_types=1);

use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| The student's own list of private-session asks carries the declared zone.
|
| ⚠️ Since 2026-09-25 screens draw times on the VIEWER's zone and this field is
| the PLATFORM zone, kept for compatibility. What follows is its history:
| every other session time in the product was formatted in `sessions.timezone`,
| sent beside it by `ClassSessionResource`. A request that did not carry it left
| the screen to the browser's own zone — a different hour, on the same row, from
| the lesson it turns into.
|
| ⚠️ THE ZONE IS CHANGED FROM ITS DEFAULT BEFORE THE READ, so the assertion
| cannot pass on a literal that happens to match `Asia/Qatar`. It is changed
| AFTER the ask, because the ask is checked against the teacher's declared hours.
*/
it('sends the declared session timezone on the student own list', function (): void {
    $fx = privateSessionFixture();

    Sanctum::actingAs($fx['student']);
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    config()->set('sessions.timezone', 'Asia/Riyadh');

    // The state a real self-registered student is in: no workspace context.
    app()->forgetInstance(WorkspaceContext::class);
    $this->asGuest();
    Sanctum::actingAs($fx['student']);

    $mine = $this->getJson('/api/v1/private-session-requests')->assertOk();

    expect($mine->json('data'))->toHaveCount(1)
        ->and($mine->json('data.0.timezone'))->toBe('Asia/Riyadh')
        ->and($mine->json('data.0.status'))->toBe('pending')
        // The envelope the page reads — a bare array here reads as an empty list.
        ->and($mine->json('meta.total'))->toBe(1);
});

it("names the course on the student's list even when they are stamped with another teacher's workspace", function (): void {
    $fx = privateSessionFixture();

    Sanctum::actingAs($fx['student']);
    $this->postJson("/api/v1/courses/{$fx['course']->uuid}/private-session-requests", [
        'starts_at' => $fx['startsAt']->toIso8601String(),
    ])->assertCreated();

    // Stamped elsewhere: `forceFill`, because `last_workspace_id` is guarded and
    // `create([...])` would drop it in silence, rebuilding the null-context case.
    [$elsewhere] = $this->createWorkspaceWithOwner();
    $fx['student']->forceFill(['last_workspace_id' => $elsewhere->getKey()])->save();

    app()->forgetInstance(WorkspaceContext::class);
    $this->asGuest();
    Sanctum::actingAs($fx['student']->refresh());

    $mine = $this->getJson('/api/v1/private-session-requests')->assertOk();

    expect($mine->json('data.0.course.title'))->toBe($fx['course']->title);
});
