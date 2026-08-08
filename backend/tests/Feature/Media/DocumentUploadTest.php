<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * `pdf` and `file` have been declared lesson types since the first migration
 * with no way to upload either: the pipeline matched one flat video mime list
 * and its rejection message said so out loud.
 *
 * The three cases below are the ones that decide whether the new path is real:
 *
 * 1. A PDF named `.mp4` is **accepted** — the type is read from the bytes.
 * 2. A zip named `.pdf` is **refused** — for exactly the same reason.
 * 3. A file over the document ceiling is refused **with the actual number in the
 *    message**, because "too big" without a figure makes the next attempt
 *    another guess.
 */
function documentLesson(string $type = 'pdf'): Lesson
{
    /** @var Workspace $workspace */
    [$workspace, $owner] = test()->createWorkspaceWithOwner();

    Sanctum::actingAs($owner);
    test()->setCurrentWorkspace($workspace, $owner);

    return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $type): Lesson {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

        return Lesson::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'type' => $type,
        ]);
    });
}

/** Minimal but genuine bytes — finfo reads the header, not the extension. */
function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

function zipBytes(): string
{
    return "PK\x03\x04".str_repeat("\x00", 26);
}

function uploadAndComplete(Lesson $lesson, string $filename, string $bytes, string $kind): MediaAsset
{
    $created = test()->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => $filename,
        'kind' => $kind,
        'role' => 'primary',
    ])->assertCreated();

    /** @var MediaAsset $asset */
    $asset = MediaAsset::query()->where('uuid', $created->json('asset.uuid'))->firstOrFail();

    test()->call('PUT', "/api/v1/media/upload/{$asset->uuid}", [], [], [], [], $bytes);

    return app(CompleteMediaUpload::class)->handle($asset->refresh());
}

it('accepts a PDF whatever the filename claims', function (): void {
    Storage::fake('local');
    $lesson = documentLesson();

    // Named .mp4 on purpose. The extension is what the person typed; the header
    // is what the file is.
    $asset = uploadAndComplete($lesson, 'ملزمة.mp4', pdfBytes(), 'document');

    expect($asset->status)->toBe(MediaAssetStatus::Ready)
        ->and($asset->mime_type)->toBe('application/pdf')
        ->and($asset->failure_reason)->toBeNull();
});

it('refuses a zip whatever the filename claims', function (): void {
    Storage::fake('local');
    $lesson = documentLesson();

    $asset = uploadAndComplete($lesson, 'ملزمة.pdf', zipBytes(), 'document');

    expect($asset->status)->toBe(MediaAssetStatus::Failed)
        // Names the kind that was expected, not "not a supported video".
        ->and($asset->failure_reason)->toContain('مستند');
});

it('states the actual ceiling when a document is too large', function (): void {
    Storage::fake('local');
    $lesson = documentLesson();

    PlatformSettings::set('media.max_document_size_bytes', 52_428_800);

    $response = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'ضخم.pdf',
        'size_bytes' => 60_000_000,
        'kind' => 'document',
    ])->assertStatus(422);

    // 50 MiB, said in the message. A refusal with no figure makes the next
    // attempt another guess.
    expect($response->json('message'))->toContain('50')
        ->and($response->json('message'))->toContain('ميغابايت');
});

it('does not judge a document by the video ceiling', function (): void {
    Storage::fake('local');
    $lesson = documentLesson();

    // The video allowance is 2 GiB and the document allowance is 50 MiB. Before
    // 016 completion checked the first whatever had been uploaded, so a 60 MB
    // "PDF" passed the only check that reads the real file.
    expect(PlatformSettings::get('media.max_size_bytes'))
        ->toBeGreaterThan((int) PlatformSettings::get('media.max_document_size_bytes'));

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'كبير.pdf',
        'size_bytes' => 60_000_000,
        'kind' => 'document',
    ])->assertStatus(422);
});

it('refuses a primary whose kind contradicts the item type', function (): void {
    Storage::fake('local');
    $lesson = documentLesson('video');

    // A video item, a document file. `PublishReadiness` only asks whether a
    // primary asset exists, so without this the item published and the student
    // got a play button over a file their browser downloads.
    $response = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'ملزمة.pdf',
        'kind' => 'document',
        'role' => 'primary',
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('مرفق');
});

it('lets an attachment be any kind, on any type of item', function (): void {
    Storage::fake('local');
    $lesson = documentLesson('article');

    // FR-019: a worksheet under an article, slides under a video. An article has
    // no primary asset kind at all, and that must not stop an attachment.
    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'ورقة-عمل.pdf',
        'kind' => 'document',
        'role' => 'attachment',
    ])->assertCreated();
});

it('refuses a second primary instead of destroying the first', function (): void {
    Storage::fake('local');
    $lesson = documentLesson();

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'مرفق.pdf', 'kind' => 'document', 'role' => 'attachment',
    ])->assertCreated();

    $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'أصلي.pdf', 'kind' => 'document', 'role' => 'primary',
    ])->assertCreated();

    // This used to succeed, and succeeding was the defect: it deleted the
    // existing primary at the provider and in the database from a route carrying
    // only `auth:sanctum`. So the two-factor gate on `DELETE /media/assets/{asset}`
    // was optional in practice, `LESSONS_DELETE` was not required at all, and the
    // deletion skipped `DeleteMediaAsset` — leaving live grants un-revoked and
    // caption rows orphaned with their files on disk.
    //
    // 423 with `alternative`, not 422: the request was fine, the file is not this
    // route's to destroy, and the teacher is told which route is.
    $response = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'بديل.pdf', 'kind' => 'document', 'role' => 'primary',
    ])->assertStatus(423);

    expect($response->json('alternative'))->toBe('delete_asset')
        ->and($response->json('message'))->toContain('تحقّقاً بخطوتين')
        // The first file still stands, and the attachment is untouched — a
        // refusal that half-destroyed something would be worse than either.
        ->and($lesson->mediaAsset()->count())->toBe(1)
        ->and($lesson->mediaAsset?->original_filename)->toBe('أصلي.pdf')
        ->and($lesson->attachments()->count())->toBe(1);
});

it('states is_downloadable on the ticket rather than leaving it to the column', function (): void {
    Storage::fake('local');
    $lesson = documentLesson();

    // A model built with `new` carries no column default, and the key IS in
    // `casts()`, so the accessor returned null rather than falling back to false.
    // `POST /lessons/{l}/assets` answered `"is_downloadable": null` while
    // `GET /media/assets/{a}` answered `false` for the same row — one boolean with
    // two types, told apart only by which endpoint you asked.
    $created = $this->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
        'original_filename' => 'ملزمة.pdf', 'kind' => 'document', 'role' => 'primary',
    ])->assertCreated();

    expect($created->json('asset.is_downloadable'))->toBeFalse();

    $uuid = $created->json('asset.uuid');

    expect($this->getJson("/api/v1/media/assets/{$uuid}")->json('is_downloadable'))->toBeFalse();
});

it('refuses to publish an item whose upload failed', function (): void {
    Storage::fake('local');
    $lesson = documentLesson();

    // CompleteMediaUpload deletes the bytes of a rejected file and keeps the row
    // so the teacher can see why it broke. That row alone used to satisfy the
    // publish check — a published document item over a file that is not there.
    $asset = uploadAndComplete($lesson, 'ملزمة.pdf', zipBytes(), 'document');

    expect($asset->status)->toBe(MediaAssetStatus::Failed);

    $course = $lesson->course;

    $response = test()->postJson("/api/v1/courses/{$course?->uuid}/tree/publish", [
        'structure_version' => $course?->structure_version,
        'items' => [['uuid' => $lesson->uuid, 'status' => 'published']],
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('ارفع الملف');
});

it('rate-limits upload tickets under a named limiter', function (): void {
    Storage::fake('local');
    $lesson = documentLesson('article');

    // `throttle:upload`, not an inline `throttle:20,1`. ThrottleRequests keys
    // guests on domain|ip with no route in the hash, so every inline limit
    // shares one counter and the strictest wins.
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->uri() === 'api/v1/lessons/{lesson}/assets' && in_array('POST', $r->methods(), true));

    expect($route?->gatherMiddleware())->toContain('throttle:upload');

    // And each ticket reserves a row, so the limit has to be real, not declared.
    for ($i = 0; $i < 21; $i++) {
        $response = test()->postJson("/api/v1/lessons/{$lesson->uuid}/assets", [
            'original_filename' => "ورقة-{$i}.pdf",
            'kind' => 'document',
            'role' => 'attachment',
        ]);

        if ($response->status() === 429) {
            return;
        }
    }

    test()->fail('The upload limiter never engaged.');
});
