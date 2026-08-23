<?php

declare(strict_types=1);

use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-005 · FR-013 — zero reads and zero writes for someone who is not a party,
| and zero successful subscriptions to a channel they were not admitted to.
|
| ⚠️ TWO WORKSPACES AND A STUDENT IN EACH, and the second one is the whole test.
| `WorkspaceScope` is inert for a student — they are a member of no workspace, so
| `WorkspaceContext::id()` is null and the global scope adds NO condition at all.
| A single-workspace fixture therefore proves nothing: every row in the database
| belongs to the one workspace, so a query with no tenant filter and a query with
| a correct one return the same thing. The leak this file exists to catch is
| student B reading student A's conversation, and it only exists when there are
| two of them.
|
| ⚠️ AND THE CHANNEL HALF CANNOT BE MEASURED UNDER THE TEST BROADCASTER.
| `phpunit.xml` sets `BROADCAST_CONNECTION=null`, and `NullBroadcaster::auth()`
| is an empty method body — it authorises NOTHING, so `/api/broadcasting/auth`
| answers 200 to every channel name for every user. An assertion written against
| the default connection is vacuously green, which is exactly the shape `SC-005`
| forbids. `subscribeToChannel()` switches to a driver that actually runs the
| callback in `routes/channels.php`; see its own note in `tests/Pest.php`.
*/

beforeEach(function (): void {
    [$this->workspaceA, $this->ownerA] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أ']);
    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    $this->courseA = Course::factory()->create(['workspace_id' => $this->workspaceA->getKey()]);
    $this->studentA = $this->addWorkspaceMember($this->workspaceA, Roles::STUDENT);
    $this->createEnrollment($this->workspaceA, $this->courseA, $this->studentA);

    [$this->workspaceB, $this->ownerB] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية ب']);
    $this->setCurrentWorkspace($this->workspaceB, $this->ownerB);
    $this->courseB = Course::factory()->create(['workspace_id' => $this->workspaceB->getKey()]);
    $this->studentB = $this->addWorkspaceMember($this->workspaceB, Roles::STUDENT);
    $this->createEnrollment($this->workspaceB, $this->courseB, $this->studentB);

    // A's conversation, opened by A's student through the shipped endpoint —
    // never by a factory, because `POST /conversations` is where the race and the
    // authorisation both live.
    Sanctum::actingAs($this->studentA);

    $this->conversationUuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspaceA->uuid,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", [
        'body' => 'السلام عليكم يا أستاذ.',
    ])->assertCreated();
});

it('lets both parties read the conversation they belong to', function (): void {
    Sanctum::actingAs($this->studentA);
    $this->getJson("/api/v1/conversations/{$this->conversationUuid}/messages")
        ->assertOk()
        ->assertJsonCount(1);

    // The teacher's side of the same conversation. Without this half every
    // refusal below could just as well be a broken endpoint.
    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    Sanctum::actingAs($this->ownerA);
    $this->getJson("/api/v1/conversations/{$this->conversationUuid}/messages")
        ->assertOk()
        ->assertJsonCount(1);
});

it('refuses the other workspace student every door into it', function (): void {
    Sanctum::actingAs($this->studentB);

    $this->getJson("/api/v1/conversations/{$this->conversationUuid}/messages")->assertForbidden();
    $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", [
        'body' => 'مرحباً',
    ])->assertForbidden();
});

it('refuses the other teacher, who is a member of a workspace but not of this one', function (): void {
    $this->setCurrentWorkspace($this->workspaceB, $this->ownerB);
    Sanctum::actingAs($this->ownerB);

    $this->getJson("/api/v1/conversations/{$this->conversationUuid}/messages")->assertForbidden();
});

it('shows a student none of the conversations that are not theirs', function (): void {
    /*
    | ⚠️ THE PLATFORM-WIDE READ, AND IT IS A LIST RATHER THAN A REFUSAL. A student
    | issues no `workspace_id` condition of their own, so `ListConversations`
    | without an explicit `conversation_participants` filter returns EVERY private
    | conversation on the platform with a 200 beside it — no 403 anywhere to
    | notice. Student B has none of their own, so the correct answer is zero.
    */
    Sanctum::actingAs($this->studentB);

    $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0);

    // The control: the list works, and A's student sees exactly their one.
    Sanctum::actingAs($this->studentA);

    $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1);
});

it('admits a party to the conversation channel and refuses everyone else', function (): void {
    $channel = "private-conversation.{$this->conversationUuid}";

    Sanctum::actingAs($this->studentA);
    subscribeToChannel($channel)->assertOk();

    Sanctum::actingAs($this->studentB);
    subscribeToChannel($channel)->assertForbidden();
});

it('admits a person to their own user channel and refuses another person theirs', function (): void {
    Sanctum::actingAs($this->studentA);

    subscribeToChannel('private-user.'.$this->studentA->uuid)->assertOk();
    subscribeToChannel('private-user.'.$this->studentB->uuid)->assertForbidden();
});

it('tells a confined assistant nothing about a student outside their courses', function (): void {
    /*
    | ⚠️ THE CONFINEMENT MUST HOLD AT THE NOTIFICATION TOO, NOT ONLY AT THE DOOR.
    | The obvious recipient list is «everyone in the workspace», and it defeats
    | `mayActOnStudent()` at a lower resolution: an assistant restricted to
    | courses this student is not in still cannot OPEN the thread — the policy
    | refuses — but is told by name, with a link, every single time that student
    | writes. Most of what the confinement exists to withhold, delivered by the
    | bell.
    |
    | Both directions, because a zero on its own is also what a broken dispatch
    | produces: the in-scope assistant IS notified in the same run.
    */
    $this->setCurrentWorkspace($this->workspaceA, $this->ownerA);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspaceA->getKey());

    $far = Course::factory()->create(['workspace_id' => $this->workspaceA->getKey()]);

    $confined = $this->addWorkspaceMember($this->workspaceA, Roles::ASSISTANT_TEACHER);
    $unconfined = $this->addWorkspaceMember($this->workspaceA, Roles::ASSISTANT_TEACHER);

    $assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $confined->getKey(),
        'invited_by_user_id' => $this->ownerA->getKey(),
    ]);

    // Confined to a course studentA is NOT enrolled in.
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $assignment->getKey(),
        'course_id' => $far->getKey(),
    ]);

    AssistantAssignment::factory()->create([
        'assistant_user_id' => $unconfined->getKey(),
        'invited_by_user_id' => $this->ownerA->getKey(),
    ]);

    app()->forgetScopedInstances();

    Sanctum::actingAs($this->studentA);

    $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", [
        'body' => 'سؤال عن الواجب',
    ])->assertCreated();

    $rowsFor = fn (int $userId): int => Notification::query()
        // ⚠️ `recipient_user_id`, NOT `user_id` — a wrong column name here returns
        // zero for everybody, and the refusal assertion above passes vacuously.
        ->where('recipient_user_id', $userId)
        ->where('type', NotificationType::ChatMessage->value)
        ->count();

    expect($rowsFor((int) $confined->getKey()))->toBe(0)
        // The control: the unconfined assistant IS told, so the zero above is the
        // confinement rather than a dispatch that never ran.
        ->and($rowsFor((int) $unconfined->getKey()))->toBeGreaterThan(0)
        ->and($rowsFor((int) $this->ownerA->getKey()))->toBeGreaterThan(0)
        // And the sender never hears about their own message.
        ->and($rowsFor((int) $this->studentA->getKey()))->toBe(0);
});
