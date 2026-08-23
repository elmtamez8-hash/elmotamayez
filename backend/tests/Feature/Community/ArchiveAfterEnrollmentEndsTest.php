<?php

declare(strict_types=1);

use App\Modules\Community\Models\Message;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| FR-014 — when the student's relationship with the teacher ends, sending stops
| and the archive stays readable.
|
| ⚠️ READING AND WRITING ARE TWO ABILITIES, NOT ONE WITH A FLAG. The obvious
| implementation asks «is this person a party?» once and uses the answer for
| both doors — and then the day the enrolment lapses the whole conversation
| disappears from the student who paid for the lessons in it. Two policy methods,
| measured here in both directions and from both sides: the teacher may not send
| into a finished relationship either, or the requirement would only bind the
| person with less power in it.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->enrollment = $this->createEnrollment($this->workspace, $this->course, $this->student);

    Sanctum::actingAs($this->student);

    $this->conversationUuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
    ])->assertCreated()->json('uuid');

    $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", [
        'body' => 'شكراً على الحصّة.',
    ])->assertCreated();
});

it('keeps the archive readable and refuses new messages once the enrolment ends', function (): void {
    $url = "/api/v1/conversations/{$this->conversationUuid}/messages";

    // The control: while the enrolment is live, both doors are open.
    Sanctum::actingAs($this->student);
    $this->getJson($url)->assertOk()->assertJsonCount(1);
    $this->postJson($url, ['body' => 'سؤال أخير'])->assertCreated();

    Enrollment::query()->withoutWorkspaceScope()
        ->whereKey($this->enrollment->getKey())
        ->update(['status' => 'completed']);

    Sanctum::actingAs($this->student);

    // The archive survives — everything written before, still there.
    $this->getJson($url)->assertOk()->assertJsonCount(2);

    // And the send is refused.
    $this->postJson($url, ['body' => 'مرحباً مجدّداً'])->assertForbidden();
});

it('refuses the teacher a new message into a relationship that has ended', function (): void {
    Enrollment::query()->withoutWorkspaceScope()
        ->whereKey($this->enrollment->getKey())
        ->update(['status' => 'completed']);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);

    $url = "/api/v1/conversations/{$this->conversationUuid}/messages";

    $this->getJson($url)->assertOk()->assertJsonCount(1);
    $this->postJson($url, ['body' => 'عد إلينا'])->assertForbidden();
});

it('hides a message from its own sender and leaves the row for moderation', function (): void {
    /*
    | FR-015 — deletion is `hidden_at` and never a removed row. Asserted through
    | the archive count as well as the database, because a `SoftDeletes` column
    | named `deleted_at` would pass a check on the API and silently shorten every
    | page of fifty for the moderator who needs to read exactly this.
    */
    Sanctum::actingAs($this->student);

    $url = "/api/v1/conversations/{$this->conversationUuid}/messages";
    $uuid = (string) $this->getJson($url)->assertOk()->json('0.uuid');

    $this->deleteJson("/api/v1/messages/{$uuid}")->assertOk();

    $this->getJson($url)->assertOk()->assertJsonCount(0);

    $this->assertDatabaseHas('messages', ['uuid' => $uuid]);
    expect(Message::query()->withoutWorkspaceScope()
        ->where('uuid', $uuid)->value('hidden_at'))->not->toBeNull();
});
