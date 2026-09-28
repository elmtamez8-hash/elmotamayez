<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\Conversation;
use App\Modules\Courses\Models\Course;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Support\AudioContainer;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
| Finishing a chat upload (production, 2026-09-28).
|
| ⚠️ TWO DEFECTS, AND EACH ONE HID BEHIND THE OTHER.
|
|  1. The client finished every chat upload through `/media/assets/{asset}/complete`,
|     whose policy is the LESSON author's (`LESSONS_MANAGE` + membership). A
|     student holds neither, so every picture a student sent was refused with
|     «لا تملك صلاحية لهذا الإجراء» after its bytes had landed.
|  2. The teacher DID get through that door — and every voice note they recorded
|     then failed anyway: libmagic calls a WebM file `video/webm` whatever is in
|     it, the audio list refused it, and the send said «لم يكتمل رفع المرفق بعد».
|
| The fixture sent `audio/webm` straight into a Ready row (`ChatAttachmentTest`),
| so nothing in the suite ever walked a real recording through the real door.
| These tests do: ticket → signed PUT → complete → send.
*/

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    $this->conversation = Conversation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => 'private',
        'student_user_id' => $this->student->getKey(),
    ]);
});

/** A 1×1 PNG — finfo reads the header, so it has to be a real one. */
function chatPngBytes(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    );
}

/**
 * The first bytes of what Chrome's `MediaRecorder` writes: an EBML header naming
 * `webm`, a Segment of unknown size, and a `Tracks` element with ONE track whose
 * `TrackType` is 2 (audio) or 1 (video).
 */
function chatWebmBytes(int $trackType): string
{
    $codec = $trackType === 2 ? 'A_OPUS' : 'V_VP8';

    $entry = "\xD7\x81\x01"                             // TrackNumber = 1
        ."\x83\x81".chr($trackType)                      // TrackType
        ."\x86".chr(0x80 | strlen($codec)).$codec;       // CodecID

    $trackEntry = "\xAE".chr(0x80 | strlen($entry)).$entry;
    $tracks = "\x16\x54\xAE\x6B".chr(0x80 | strlen($trackEntry)).$trackEntry;

    return hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D42878104428581021853806701FFFFFFFFFFFFFF')
        .$tracks
        .str_repeat("\x00", 256);
}

/** Ticket → signed PUT → the chat's own completion. Returns the completion response. */
function chatUploadAndComplete(
    Conversation $conversation,
    User $sender,
    string $kind,
    string $bytes,
): TestResponse {
    Sanctum::actingAs($sender);

    $ticket = test()->postJson("/api/v1/conversations/{$conversation->uuid}/attachments", [
        'kind' => $kind,
        'filename' => $kind === 'voice' ? 'voice-note' : 'photo',
        'size_bytes' => strlen($bytes),
    ])->assertCreated();

    test()->call('PUT', (string) $ticket->json('upload.url'), [], [], [], [], $bytes)->assertOk();

    return test()->postJson(
        "/api/v1/conversations/{$conversation->uuid}/attachments/{$ticket->json('asset.uuid')}/complete",
    );
}

it('lets a student finish their own picture and send it', function (): void {
    $response = chatUploadAndComplete($this->conversation, $this->student, 'image', chatPngBytes())
        ->assertOk();

    expect($response->json('status'))->toBe('ready')
        ->and($response->json('mime_type'))->toBe('image/png');

    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $response->json('uuid'),
    ])->assertCreated()->assertJsonPath('attachment.kind', 'image');
});

it('lets the teacher finish their own picture', function (): void {
    $response = chatUploadAndComplete($this->conversation, $this->teacher, 'image', chatPngBytes())
        ->assertOk();

    expect($response->json('status'))->toBe('ready');
});

it('records who asked for the ticket', function (): void {
    $response = chatUploadAndComplete($this->conversation, $this->student, 'image', chatPngBytes());

    $asset = MediaAsset::query()->withoutGlobalScopes()->where('uuid', $response->json('uuid'))->firstOrFail();

    expect((int) $asset->uploaded_by_user_id)->toBe((int) $this->student->getKey());
});

it('refuses the other end of the thread, who may post there but did not send this file', function (): void {
    Sanctum::actingAs($this->student);

    $ticket = $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/attachments", [
        'kind' => 'image',
        'filename' => 'photo',
    ])->assertCreated();

    $this->call('PUT', (string) $ticket->json('upload.url'), [], [], [], [], chatPngBytes())->assertOk();

    Sanctum::actingAs($this->teacher);

    $this->postJson(
        "/api/v1/conversations/{$this->conversation->uuid}/attachments/{$ticket->json('asset.uuid')}/complete",
    )->assertForbidden();

    $asset = MediaAsset::query()->withoutGlobalScopes()->where('uuid', $ticket->json('asset.uuid'))->firstOrFail();

    expect($asset->status)->not->toBe(MediaAssetStatus::Ready);
});

it('refuses a stranger who may not post in the thread', function (): void {
    Sanctum::actingAs($this->student);

    $ticket = $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/attachments", [
        'kind' => 'image',
        'filename' => 'photo',
    ])->assertCreated();

    // Another student of the same teacher: enrolled, a member, and nothing to do
    // with this thread.
    $stranger = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $stranger);

    Sanctum::actingAs($stranger);

    $this->postJson(
        "/api/v1/conversations/{$this->conversation->uuid}/attachments/{$ticket->json('asset.uuid')}/complete",
    )->assertForbidden();
});

it('answers 404 for an asset that belongs to another thread', function (): void {
    $other = Conversation::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'kind' => 'private',
        'student_user_id' => $this->addWorkspaceMember($this->workspace, Roles::STUDENT)->getKey(),
    ]);

    // The teacher's own upload, in another thread — the uploader matches, the
    // owner does not.
    $asset = MediaAsset::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => Conversation::class,
        'owner_id' => $other->getKey(),
        'uploaded_by_user_id' => $this->teacher->getKey(),
        'provider' => 'local',
        'kind' => MediaKind::Document,
        'role' => 'attachment',
        'status' => MediaAssetStatus::Pending,
        'is_downloadable' => false,
        'original_filename' => 'photo',
    ]);

    Sanctum::actingAs($this->teacher);

    $this->postJson(
        "/api/v1/conversations/{$this->conversation->uuid}/attachments/{$asset->uuid}/complete",
    )->assertNotFound();

    // A lesson video by uuid answers the same: this door knows only its own files.
    $lessonAsset = MediaAsset::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->postJson(
        "/api/v1/conversations/{$this->conversation->uuid}/attachments/{$lessonAsset->uuid}/complete",
    )->assertNotFound();
});

it('accepts a voice note recorded by Chrome, whose container libmagic calls video', function (): void {
    // The premise, measured rather than assumed: this is what production saw.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    expect(finfo_buffer($finfo, chatWebmBytes(2)))->toBe('video/webm');

    $response = chatUploadAndComplete($this->conversation, $this->teacher, 'voice', chatWebmBytes(2))
        ->assertOk();

    expect($response->json('status'))->toBe('ready')
        ->and($response->json('mime_type'))->toBe('audio/webm')
        ->and($response->json('failure_reason'))->toBeNull();

    // And it renders as a voice note: the resource derives the kind from the
    // `audio/` prefix, which `video/webm` would have turned into a broken <img>.
    $this->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", [
        'body' => '',
        'attachment' => $response->json('uuid'),
    ])->assertCreated()->assertJsonPath('attachment.kind', 'voice');
});

it('accepts the same recording from the student', function (): void {
    chatUploadAndComplete($this->conversation, $this->student, 'voice', chatWebmBytes(2))
        ->assertOk()
        ->assertJsonPath('status', 'ready');
});

it('still refuses a WebM that carries a video track as a voice note', function (): void {
    $response = chatUploadAndComplete($this->conversation, $this->student, 'voice', chatWebmBytes(1))
        ->assertOk();

    expect($response->json('status'))->toBe('failed')
        ->and($response->json('failure_reason'))->toBe(MediaKind::Audio->rejectionMessage());
});

it('reads a Safari recording (MP4 with a sound handler and none for video) as audio', function (): void {
    $hdlr = fn (string $type): string => pack('N', 33).'hdlr'."\0\0\0\0"."\0\0\0\0".$type.str_repeat("\0", 12)."\0";

    $ftyp = pack('N', 20).'ftyp'.'mp42'."\0\0\0\0".'mp42';
    $sound = $ftyp.pack('N', 8 + 33).'moov'.$hdlr('soun');
    $film = $ftyp.pack('N', 8 + 66).'moov'.$hdlr('vide').$hdlr('soun');

    expect(AudioContainer::audioOnlyMime($sound, 'video/mp4'))->toBe('audio/mp4')
        ->and(AudioContainer::audioOnlyMime($film, 'video/mp4'))->toBeNull()
        // A container this does not know is left to the list that refuses it.
        ->and(AudioContainer::audioOnlyMime('PK', 'application/zip'))->toBeNull();
});

it('refuses a PDF sent as a chat picture, which the lesson list would have accepted', function (): void {
    chatUploadAndComplete($this->conversation, $this->student, 'image', "%PDF-1.4\n%%EOF\n")
        ->assertOk()
        ->assertJsonPath('status', 'failed');
});
