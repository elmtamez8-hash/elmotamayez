<?php

declare(strict_types=1);

use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| «راسِل» — the teacher's side opening the private thread (spec 010 · US2).
|
| `POST /conversations` has taken `student` since it shipped, and nothing on a
| teacher's screen ever sent it. The screen does now, so the door it reaches is
| measured from that side: the teacher may open a thread with a student who
| studies with them, and with nobody else (NFR-001أ).
|
| ⚠️ TWO WORKSPACES, and the second student is the whole point. The stranger is
| a real, enrolled student — at another teacher. A bare uuid parameter is an
| identity probe unless the answer carries nothing about the person behind it:
| no row written, and no name in the refusal.
|
| ⚠️ THE STRANGER'S NAME IS AN ASCII SENTINEL. `getContent()` escapes non-ASCII,
| so an Arabic needle is vacuously absent from any body.
*/

beforeEach(function (): void {
    [$this->workspaceA, $this->ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    $this->courseA = Course::factory()->create(['workspace_id' => $this->workspaceA->getKey()]);
    $this->studentA = $this->addWorkspaceMember($this->workspaceA, Roles::STUDENT);
    $this->createEnrollment($this->workspaceA, $this->courseA, $this->studentA);

    [$this->workspaceB, $this->ownerB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);
    $this->setCurrentWorkspace($this->workspaceB, $this->ownerB);
    $this->courseB = Course::factory()->create(['workspace_id' => $this->workspaceB->getKey()]);
    $this->stranger = $this->addWorkspaceMember($this->workspaceB, Roles::STUDENT);
    $this->stranger->forceFill(['first_name' => 'STRANGERSENTINEL', 'last_name' => 'Zed'])->save();
    $this->createEnrollment($this->workspaceB, $this->courseB, $this->stranger);

    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    Sanctum::actingAs($this->ownerA);
});

it('lets a teacher open the thread with their own student, and returns the same one twice', function (): void {
    $first = $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspaceA->uuid,
        'student' => $this->studentA->uuid,
    ])->assertCreated()->json();

    expect($first['student_uuid'])->toBe((string) $this->studentA->uuid);

    // The second press finds the open thread rather than writing another.
    $second = $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspaceA->uuid,
        'student' => $this->studentA->uuid,
    ])->assertCreated()->json('uuid');

    expect($second)->toBe($first['uuid'])
        ->and(Conversation::query()->withoutWorkspaceScope()->count())->toBe(1);

    // And it is on the student's own list, not only on the teacher's. (The list
    // is a bare array: `JsonResource::withoutWrapping()` is on globally.)
    Sanctum::actingAs($this->studentA);
    expect(collect($this->getJson('/api/v1/conversations')->assertOk()->json())->pluck('uuid')->all())
        ->toContain($first['uuid']);
});

it('refuses a teacher a thread with a student who studies elsewhere, writing nothing and naming nobody', function (): void {
    $response = $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspaceA->uuid,
        'student' => $this->stranger->uuid,
    ]);

    $response->assertForbidden();

    expect($response->getContent())->not->toContain('STRANGERSENTINEL')
        ->and(Conversation::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('refuses a teacher who names another workspace to reach the student studying there', function (): void {
    $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspaceB->uuid,
        'student' => $this->stranger->uuid,
    ])->assertForbidden();

    expect(Conversation::query()->withoutWorkspaceScope()->count())->toBe(0);
});
