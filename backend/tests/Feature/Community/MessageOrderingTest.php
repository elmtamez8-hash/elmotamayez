<?php

declare(strict_types=1);

use App\Modules\Community\Actions\PostMessage;
use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| SC-007 — the order of messages is identical at every party, in 100% of cases.
|
| ⚠️ THE FIXTURE WRITES AN IDENTICAL `created_at`, AND WITHOUT THAT THIS FILE
| MEASURES NOTHING. Two messages sent in the same second are the ordinary case in
| a live chat, and a `ORDER BY created_at` passes every test whose fixture wrote
| its rows a second apart — then puts the reply above the question on the one
| conversation anybody complains about. Timestamps here are equal to the second on
| purpose, so only an order by the monotonic key can satisfy the assertion.
|
| ⚠️ AND `last_message_id` IS ASSERTED ALONGSIDE. It is written by a conditional
| update — `WHERE last_message_id IS NULL OR last_message_id < ?` — because two
| concurrent writers can otherwise land the SMALLER id last, and the conversation
| is then sorted for ever by a message that is not its most recent one. The
| out-of-order write is simulated directly below, since it is a race no
| single-threaded test reaches by accident.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    Sanctum::actingAs($this->student);

    $this->conversationUuid = (string) $this->postJson('/api/v1/conversations', [
        'workspace' => $this->workspace->uuid,
    ])->assertCreated()->json('uuid');
});

it('returns the same order to both parties when every message shares one timestamp', function (): void {
    $stamp = now()->startOfSecond();

    $bodies = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة'];

    foreach ($bodies as $index => $body) {
        // Alternating senders, so a reader ordering by anything to do with WHO
        // sent a message would come apart here as well.
        Sanctum::actingAs($index % 2 === 0 ? $this->student : $this->owner);

        if ($index % 2 !== 0) {
            $this->setCurrentWorkspace($this->workspace, $this->owner);
        }

        $uuid = (string) $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", [
            'body' => $body,
        ])->assertCreated()->json('uuid');

        // ⚠️ Every row carries the SAME second. `update()` on the query builder
        // rather than the model, because `$timestamps` would rewrite it back.
        Message::query()->withoutWorkspaceScope()
            ->where('uuid', $uuid)
            ->update(['created_at' => $stamp, 'updated_at' => $stamp]);
    }

    Sanctum::actingAs($this->student);
    $studentOrder = $this->getJson("/api/v1/conversations/{$this->conversationUuid}/messages")
        ->assertOk()->json('*.body');

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    Sanctum::actingAs($this->owner);
    $teacherOrder = $this->getJson("/api/v1/conversations/{$this->conversationUuid}/messages")
        ->assertOk()->json('*.body');

    expect($studentOrder)->toBe($bodies)
        ->and($teacherOrder)->toBe($bodies);
});

it('keeps the newest message as the conversation pointer even when an older id lands last', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", ['body' => 'س'])->assertCreated();
    $this->postJson("/api/v1/conversations/{$this->conversationUuid}/messages", ['body' => 'ص'])->assertCreated();

    $conversation = Conversation::query()->withoutWorkspaceScope()
        ->where('uuid', $this->conversationUuid)->firstOrFail();

    $newest = (int) Message::query()->withoutWorkspaceScope()
        ->where('conversation_id', $conversation->getKey())->max('id');

    expect((int) $conversation->last_message_id)->toBe($newest);

    /*
    | The race, played out: the FIRST message's writer finishes its pointer update
    | after the second one's. Written through the Action's own claim so the
    | assertion is about the shipped statement and not about a fixture.
    */
    $oldest = (int) Message::query()->withoutWorkspaceScope()
        ->where('conversation_id', $conversation->getKey())->min('id');

    app(PostMessage::class)->claimLastMessage($conversation, $oldest);

    expect((int) $conversation->fresh()?->last_message_id)->toBe($newest);
});
