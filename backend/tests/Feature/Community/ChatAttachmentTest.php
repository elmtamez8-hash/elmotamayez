<?php

declare(strict_types=1);

use App\Modules\Community\Models\Conversation;
use App\Modules\Community\Models\Message;
use App\Modules\Courses\Models\Course;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Enums\MediaRole;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| Chat attachments (spec 010 · `FR-060` … `FR-063`).
|
| ⚠️ THE THREE REFUSALS BELOW ARE ATTACKS, NOT FORMALITIES. The asset uuid arrives
| in a request body, so without them a sender could name ANY asset on the platform
| and have it rendered inside their own thread — every lesson video and every other
| conversation's pictures, reachable by guessing nothing harder than a uuid they
| were already given.
*/

beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    /*
    | ⚠️ AN ACTIVE ENROLMENT, NOT MERELY A USER. `ConversationPolicy::post()` asks
    | `FR-014`'s question — is the teaching relationship live — so a fixture
    | without one answers 403 to every send, and every assertion below would be
    | measuring the enrolment check instead of the attachment rules it names.
    */
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    $this->conversation = Conversation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => 'private',
        'student_user_id' => $this->student->getKey(),
    ]);
});

/** An asset that finished uploading, owned by the thread under test unless told otherwise. */
function readyAsset(Conversation $conversation, string $mime = 'image/png'): MediaAsset
{
    return MediaAsset::query()->create([
        'workspace_id' => $conversation->workspace_id,
        'owner_type' => Conversation::class,
        'owner_id' => $conversation->getKey(),
        'provider' => 'local',
        'provider_asset_id' => 'chat/'.uniqid().'.png',
        'kind' => str_starts_with($mime, 'audio/') ? MediaKind::Audio : MediaKind::Document,
        'role' => MediaRole::Attachment,
        'status' => MediaAssetStatus::Ready,
        'is_downloadable' => false,
        'original_filename' => 'photo.png',
        'mime_type' => $mime,
        'size_bytes' => 70,
    ]);
}

it('sends a message that is only an attachment', function (): void {
    $asset = readyAsset($this->conversation);

    Sanctum::actingAs($this->student);

    $response = $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $asset->uuid,
    ]);

    $response->assertCreated();

    // ⚠️ NULL AND NOT '': the column is nullable precisely so «empty message» and
    // «message that is only a recording» stay distinguishable rows.
    $message = Message::query()->withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($message->body)->toBeNull()
        ->and($message->media_asset_id)->toBe($asset->getKey())
        ->and($response->json('attachment.kind'))->toBe('image')
        // The link is a signature, never a storage path — `provider_asset_id` is
        // the one field `FR-011` forbids in a payload.
        ->and($response->json('attachment.url'))->toContain('signature=')
        ->and($response->json('attachment.url'))->not->toContain($asset->provider_asset_id);
});

it('refuses a message with neither text nor attachment', function (): void {
    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", ['body' => ''])
        ->assertStatus(422);
});

it('refuses an asset belonging to another conversation', function (): void {
    $other = Conversation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => 'private',
        'student_user_id' => $this->addWorkspaceMember($this->workspace, Roles::STUDENT)->getKey(),
    ]);

    $asset = readyAsset($other);

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $asset->uuid,
    ])->assertStatus(422);

    expect(Message::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an asset that has not finished uploading', function (): void {
    $asset = readyAsset($this->conversation);
    $asset->forceFill(['status' => MediaAssetStatus::Pending])->save();

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $asset->uuid,
    ])->assertStatus(422);
});

it('refuses to send the same attachment twice', function (): void {
    $asset = readyAsset($this->conversation);

    Sanctum::actingAs($this->student);

    $send = fn () => $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $asset->uuid,
    ]);

    $send()->assertCreated();

    /*
    | ⚠️ ONE TICKET, ONE MESSAGE. Without this the moderation archive describes two
    | messages by one file, so hiding one leaves the other showing the picture that
    | was hidden.
    */
    $send()->assertStatus(422);

    expect(Message::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('stops serving the bytes once the message is hidden', function (): void {
    $asset = readyAsset($this->conversation);

    Sanctum::actingAs($this->student);

    $created = $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $asset->uuid,
    ])->assertCreated();

    $url = (string) $created->json('attachment.url');

    // Hidden by its own sender, which is the path `HideMessage` guards.
    $this->deleteJson('/api/v1/messages/'.$created->json('uuid'))->assertOk();

    /*
    | ⚠️ THE BYTES ARE THE MESSAGE. A picture that kept streaming after its
    | message was hidden would make the whole moderation control cosmetic — and
    | the link is already in the hands of everyone who loaded the thread.
    */
    $this->get($url)->assertNotFound();
});

it('refuses an unsigned or tampered link', function (): void {
    $asset = readyAsset($this->conversation);

    Sanctum::actingAs($this->student);

    $created = $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $asset->uuid,
    ])->assertCreated();

    $uuid = (string) $created->json('uuid');
    $url = (string) $created->json('attachment.url');

    $this->get("/api/v1/chat-media/{$uuid}")->assertForbidden();
    $this->get($url.'x')->assertForbidden();
});

it('previews an attachment-only thread with words rather than a blank line', function (): void {
    $asset = readyAsset($this->conversation, 'audio/webm');

    Sanctum::actingAs($this->student);

    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $asset->uuid,
    ])->assertCreated();

    // The teacher's list, where the preview is read.
    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    Sanctum::actingAs($this->teacher);

    /*
    | ⚠️ `0.last_message.body`, NOT `data.0…`. `JsonResource::withoutWrapping()` is
    | enabled, so a collection response is a BARE array — `lib/api.ts` puts the
    | envelope back on the client. Written the other way this assertion reads null
    | and reports «expected iterable», which is a puzzle about Pest rather than
    | about the product; five files learnt it the same way in the first chat phase.
    */
    $body = $this->getJson('/api/v1/conversations')->assertOk()->json('0.last_message.body');

    expect($body)->toContain('رسالة صوتية');
});
