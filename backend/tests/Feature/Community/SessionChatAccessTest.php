<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| FR-017 · FR-018 — the room under a session is open to whoever is entitled to be
| in it, and to nobody else.
|
| ⚠️ THE REFUSED STUDENT IS ENROLLED, and that is what makes the test mean
| something. A stranger is refused by every guard there is; the case worth
| measuring is the student who studies with this teacher, could book this very
| session, and has not — the room is for the people in the lesson, not for
| everyone who might one day join it.
|
| ⚠️ AND THE CHANNEL IS MEASURED TOO. `ConversationPolicy::view()` is the single
| door: `ReadMessages`, `PostMessage` and `routes/channels.php` all ask it. Opening
| the public kinds there without measuring the socket would leave the one surface
| whose refusal nobody sees — see `subscribeToChannel()` for why the default test
| broadcaster cannot answer this question at all.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->session = billableSession($this->workspace, $this->owner, $this->course, seatsTotal: 5);

    $this->seated = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->enrolledOnly = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->createEnrollment($this->workspace, $this->course, $this->seated);
    $this->createEnrollment($this->workspace, $this->course, $this->enrolledOnly);

    SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->seated->getKey(),
        'status' => BookingStatus::Booked,
    ]);

    [$this->otherWorkspace, $this->otherOwner] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);
});

it('opens the session room to a seat holder and to the teacher', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    $this->postJson("/api/v1/conversations/{$uuid}/messages", [
        'body' => 'أهلاً بكم، نبدأ بعد قليل.',
    ])->assertCreated();

    Sanctum::actingAs($this->seated);

    // The same room, resolved again — one conversation per session, never a
    // second one per reader.
    expect((string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid'))->toBe($uuid);

    $this->getJson("/api/v1/conversations/{$uuid}/messages")->assertOk()->assertJsonCount(1);
    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'جاهزون'])->assertCreated();
});

it('refuses the room to an enrolled student who holds no seat', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    Sanctum::actingAs($this->enrolledOnly);

    $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")->assertForbidden();
    $this->getJson("/api/v1/conversations/{$uuid}/messages")->assertForbidden();
    $this->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'مرحباً'])->assertForbidden();
});

it('refuses the room to another workspace teacher and to their student', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    $this->setCurrentWorkspace($this->otherWorkspace, $this->otherOwner);
    Sanctum::actingAs($this->otherOwner);

    $this->getJson("/api/v1/conversations/{$uuid}/messages")->assertForbidden();

    $stranger = $this->addWorkspaceMember($this->otherWorkspace, Roles::STUDENT);
    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/conversations/{$uuid}/messages")->assertForbidden();
});

it('admits a seat holder to the room channel and refuses a student without one', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $uuid = (string) $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/chat")
        ->assertOk()->json('uuid');

    Sanctum::actingAs($this->seated);
    subscribeToChannel("private-conversation.{$uuid}")->assertOk();

    Sanctum::actingAs($this->enrolledOnly);
    subscribeToChannel("private-conversation.{$uuid}")->assertForbidden();
});
