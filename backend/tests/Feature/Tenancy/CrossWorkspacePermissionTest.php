<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Enums\ModerationVerdict;
use App\Modules\Community\Models\Message;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Actions\AwardPoints;
use App\Modules\Gamification\Data\AwardRequest;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MediaFixtures;

uses(MediaFixtures::class);

/*
| Security scan 2026-10-10 — F1 · F3 · F8 · F13.
|
| ⛔ ONE PERSON, TWO WORKSPACES, TWO ROLES, and that pairing is the whole test.
| The intruder OWNS workspace B (so holds chat.reply, chat.moderate and
| sessions.manage there) and is a mere STUDENT member of workspace A, with B as
| their current workspace. Every door below paired «member of A» with a
| permission read from the AMBIENT team — B's — and so opened A to them. A
| single-workspace fixture cannot see it: the ambient team and the row's
| workspace are the same one.
*/

beforeEach(function (): void {
    [$this->workspaceA, $this->ownerA] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أ']);
    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    $this->courseA = Course::factory()->create(['workspace_id' => $this->workspaceA->getKey()]);
    $this->studentA = $this->addWorkspaceMember($this->workspaceA, Roles::STUDENT);
    $this->createEnrollment($this->workspaceA, $this->courseA, $this->studentA);

    [$this->workspaceB, $this->intruder] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية ب']);
    $this->addWorkspaceMember($this->workspaceA, Roles::STUDENT, $this->intruder);
    $this->setCurrentWorkspace($this->workspaceB, $this->intruder);
});

function openThreadInA(object $test): string
{
    Sanctum::actingAs($test->studentA);

    $uuid = (string) $test->postJson('/api/v1/conversations', [
        'body' => 'سؤال خاص للأستاذ',
        'workspace' => $test->workspaceA->uuid,
    ])->assertCreated()->json('uuid');

    return $uuid;
}

it('refuses a private thread of A to an owner of B who is only a student in A', function (): void {
    $thread = openThreadInA($this);

    // The control: A's own teacher reads it, so the refusal below is not a broken door.
    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    Sanctum::actingAs($this->ownerA);
    $this->getJson("/api/v1/conversations/{$thread}/messages")->assertOk();

    $this->setCurrentWorkspace($this->workspaceB, $this->intruder);
    Sanctum::actingAs($this->intruder);
    $this->getJson("/api/v1/conversations/{$thread}/messages")->assertForbidden();
    $this->postJson("/api/v1/conversations/{$thread}/messages", ['body' => 'مرحباً'])->assertForbidden();
});

it('refuses hiding a message of A to an owner of B who is only a student in A', function (): void {
    openThreadInA($this);
    $message = Message::query()->withoutWorkspaceScope()->latest('id')->firstOrFail();

    $this->setCurrentWorkspace($this->workspaceB, $this->intruder);
    Sanctum::actingAs($this->intruder);

    $response = $this->postJson('/api/v1/moderation/actions', [
        'verdict' => ModerationVerdict::Hidden->value,
        'subject_type' => 'message',
        'subject_uuid' => $message->uuid,
        'reason' => 'إخفاء',
    ]);

    expect($response->status())->toBeIn([403, 404])
        ->and($message->fresh()->hidden_at)->toBeNull();
});

it('refuses a recording of A to a teacher who manages sessions only in B', function (): void {
    $lesson = $this->lessonWithVideo($this->workspaceA);
    $session = app(WorkspaceContext::class)->forWorkspace(
        $this->workspaceA,
        fn () => ClassSession::factory()->create(),
    );
    $lesson->forceFill(['class_session_id' => $session->getKey()])->save();

    $this->setCurrentWorkspace($this->workspaceB, $this->intruder);
    Sanctum::actingAs($this->intruder);
    $this->asGuest();
    $this->sessionFor($this->intruder)
        ->forceFill(['token_id' => $this->intruder->currentAccessToken()->getKey()])
        ->save();

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/playback")->assertForbidden();
});

it('shows a teacher the student\'s purse in their own workspace only', function (): void {
    $student = User::factory()->create();
    $this->createEnrollment($this->workspaceA, $this->courseA, $student);

    foreach ([$this->workspaceA, $this->workspaceB] as $index => $workspace) {
        app(AwardPoints::class)->handle(new AwardRequest(
            studentUserId: (int) $student->getKey(),
            actionKey: 'session_attended',
            sourceType: 'class_session',
            sourceId: $index + 1,
            workspaceId: (int) $workspace->getKey(),
        ));
    }

    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    Sanctum::actingAs($this->ownerA);

    expect($this->getJson("/api/v1/gamification/students/{$student->uuid}")->assertOk()->json('coin_balances'))
        ->toHaveCount(1);
});
