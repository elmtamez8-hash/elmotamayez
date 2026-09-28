<?php

declare(strict_types=1);

use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| The faces in a chat (production, 2026-09-28: «no sender or recipient photos»).
|
| The photos existed — `SaveAccountPhoto` writes them and the account menu shows
| them — and no chat payload carried one. Both keys are read from relations the
| Actions eager-load, and `ChatQueryBudgetTest` is what keeps that true; this
| file says the RIGHT face reaches each side.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    TeacherProfile::factory()->create([
        'user_id' => $this->teacher->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'photo_path' => 'account-photos/teacher.jpg',
    ]);

    $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->student->studentProfile()->create(['avatar_path' => 'account-photos/student.jpg']);
    $this->createEnrollment($this->workspace, $course, $this->student);

    // Through the door, so the student's participant row exists and the thread
    // is on THEIR list as well as the teacher's.
    Sanctum::actingAs($this->student);
    $uuid = (string) $this->postJson('/api/v1/conversations', ['workspace' => $this->workspace->uuid])
        ->assertCreated()
        ->json('uuid');

    $this->conversation = Conversation::query()->withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
});

it('puts each sender\'s own photo on their messages', function (): void {
    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", ['body' => 'سؤال'])
        ->assertCreated()
        ->assertJsonPath('sender_avatar_url', asset('storage/account-photos/student.jpg'));

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);
    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", ['body' => 'جواب'])
        ->assertCreated();

    // Read by the student, whose context is NOT the teacher's workspace — the
    // case a scoped `teacher_profiles` load answers with no photo at all.
    Sanctum::actingAs($this->student);
    $page = $this->getJson("/api/v1/conversations/{$this->conversation->uuid}/messages")->assertOk();

    expect($page->json('*.sender_avatar_url'))->toBe([
        asset('storage/account-photos/student.jpg'),
        asset('storage/account-photos/teacher.jpg'),
    ]);
});

it('shows the student the teacher, and the teacher the student', function (): void {
    Sanctum::actingAs($this->student);
    $mine = collect($this->getJson('/api/v1/conversations')->assertOk()->json())
        ->firstWhere('uuid', (string) $this->conversation->uuid);

    expect($mine['counterparty_avatar_url'])->toBe(asset('storage/account-photos/teacher.jpg'));

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);
    $theirs = collect($this->getJson('/api/v1/conversations')->assertOk()->json())
        ->firstWhere('uuid', (string) $this->conversation->uuid);

    expect($theirs['counterparty_avatar_url'])->toBe(asset('storage/account-photos/student.jpg'));
});

it('answers null rather than a broken link for somebody with no photo', function (): void {
    $this->student->studentProfile()->update(['avatar_path' => null]);

    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);

    $row = collect($this->getJson('/api/v1/conversations')->assertOk()->json())
        ->firstWhere('uuid', (string) $this->conversation->uuid);

    expect($row)->toHaveKey('counterparty_avatar_url')
        ->and($row['counterparty_avatar_url'])->toBeNull();
});
