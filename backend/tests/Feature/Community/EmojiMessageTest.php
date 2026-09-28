<?php

declare(strict_types=1);

use App\Modules\Community\Events\MessagePosted;
use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
| «A message with an emoji appears only after a refresh» (production, 2026-09-28).
|
| ⚠️ THE SERVER IS EMOJI-BLIND ON THE LIVE PATH, AND THIS FILE IS WHAT SAYS SO.
| The frame is two identifiers (`MessagePosted::broadcastWith()`), so nothing a
| sender types can reach the socket, and the words are fetched afterwards over
| the same authenticated route a refresh uses. The cause was on the screen: the
| thread decided «was the reader at the bottom?» AFTER the new bubble had been
| laid out, so the bubble's own height counted against the threshold — and a line
| carrying an emoji is a few pixels taller than one without. See
| `frontend/src/lib/chat-scroll.ts`.
|
| Asserted through `->json()`, never `getContent()`: that escapes non-ASCII and
| any needle outside ASCII would pass vacuously (`docs/gotchas/testing.md`).
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $course, $this->student);

    $this->conversation = Conversation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => 'private',
        'student_user_id' => $this->student->getKey(),
    ]);
});

it('announces an emoji message exactly as it announces plain text, and serves it back whole', function (): void {
    Event::fake([MessagePosted::class]);

    // Four-byte code points (outside the BMP), a ZWJ sequence and a flag — the
    // shapes a utf8 (three-byte) column or a byte-wise cut would break.
    $body = 'تمام 👍🏽 شكراً 👨‍👩‍👧 🇪🇬';

    Sanctum::actingAs($this->student);

    $sent = $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", ['body' => $body])
        ->assertCreated();

    expect($sent->json('body'))->toBe($body);

    Event::assertDispatched(MessagePosted::class, function (MessagePosted $event) use ($sent): bool {
        $payload = $event->broadcastWith();

        // Two identifiers and nothing that came from the sender's keyboard.
        return $payload === [
            'message_uuid' => $sent->json('uuid'),
            'conversation_uuid' => (string) $this->conversation->uuid,
        ] && json_encode($payload, JSON_THROW_ON_ERROR) !== '';
    });

    // What the other end's catch-up fetch reads.
    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);

    $read = $this->getJson("/api/v1/conversations/{$this->conversation->uuid}/messages")->assertOk();

    expect(collect($read->json())->pluck('body')->all())->toContain($body);
});
