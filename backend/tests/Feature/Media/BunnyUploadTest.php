<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\RequestUploadTicket;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\BunnyMediaProvider;
use App\Modules\Media\Support\MediaLimits;
use App\Modules\Tenancy\Support\PlatformSettings;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BunnyFixtures;

/*
| SC-009 — A TICKET IS HANDED TO A BROWSER, SO ANYTHING SECRET IN IT IS PUBLIC.
|
| The library id travels and the access key does not, and that distinction has to be
| stated rather than assumed: a criterion of "no provider key in any payload" is
| false by protocol and would be deleted rather than maintained. The id NAMES which
| library a signature belongs to and opens nothing by itself. What SIGNS never
| leaves the server — a hash of it does.
*/

beforeEach(function (): void {
    config(BunnyFixtures::config());

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->lesson = Lesson::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'type' => 'video',
    ]);

    Http::fake(function (Request $request) {
        // Creating the video is the one call that DOES return an id — unlike
        // `videos/fetch`, which is why a direct upload needs no recovery.
        if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/videos')) {
            return Http::response(['guid' => 'created-guid', 'title' => 'x']);
        }

        return Http::response([], 404);
    });
});

function requestTicket(?int $sizeBytes = null, ?int $durationSeconds = null): array
{
    return app(RequestUploadTicket::class)->handle(
        test()->lesson,
        'lecture.mp4',
        $sizeBytes,
        $durationSeconds,
        MediaKind::Video,
    );
}

/*
| ⚠️ THE TICKET IS REFUSED NOW, AND THE THREE TESTS THAT STOOD HERE ASSERTED THE
| SHAPE OF ONE THAT COULD NEVER WORK.
|
| They checked the url, that no secret travelled, that four values were present and
| that the id was written at ticket time — all true of the object, and all beside
| the point: Bunny wants those four as HTTP HEADERS, `media.uploadTo` sends
| `headers` and the file body and ignores `fields` entirely, and TUS is a protocol
| of a create request followed by PATCHes rather than one POST of a whole file. So
| every teacher upload failed, and because `createVideo` ran FIRST to learn the id,
| each attempt left an empty video object behind — billed monthly. A teacher
| retrying five times bought five.
|
| ⚠️ THE SECOND ASSERTION IS THE ONE THAT MATTERS. Refusing while still creating
| the video would keep the charge and change only the wording, so the network is
| held shut rather than merely faked.
*/
it('refuses a direct upload in words, before anything is created', function (): void {
    Http::preventStrayRequests();

    expect(fn () => app(BunnyMediaProvider::class)->createUploadTicket(
        MediaAsset::factory()->create(['provider' => 'bunny']),
    ))->toThrow(DomainException::class);
});

/*
| FR-020ب — the refusal quotes the ANNOUNCED limit, never an invented number.
|
| ⚠️ AND THE ANNOUNCEMENT AND THE ENFORCEMENT READ THE SAME ROW. The adapter's
| `capabilities()` derives its ceilings from MediaLimits, which is what the upload
| path already checks — so a limit an operator raises in `platform_settings` moves
| both at once. Two literals would be two numbers that agree until the first edit.
*/
it('refuses an oversized upload against the limit it announces', function (): void {
    $announced = app(BunnyMediaProvider::class)->capabilities();

    expect($announced->maxSizeBytes)->toBe(MediaLimits::maxSizeBytes(MediaKind::Video))
        ->and($announced->maxDurationSeconds)->toBe(MediaLimits::maxDurationSeconds(MediaKind::Video));

    expect(fn () => requestTicket(sizeBytes: $announced->maxSizeBytes + 1))
        ->toThrow(DomainException::class);

    expect(fn () => requestTicket(durationSeconds: $announced->maxDurationSeconds + 1))
        ->toThrow(DomainException::class);
});

// The refusal names the actual ceiling, so the next attempt is not another guess.
it('names the ceiling in the refusal', function (): void {
    try {
        requestTicket(sizeBytes: MediaLimits::maxSizeBytes(MediaKind::Video) + 1);
        $message = '';
    } catch (DomainException $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain(MediaLimits::humanBytes(MediaLimits::maxSizeBytes(MediaKind::Video)));
});

/*
| ⚠️ FR-020ب SAYS THE CEILING COMES FROM `platform_settings`, AND NOTHING TESTED
| THAT.
|
| The case above compares `capabilities()->maxSizeBytes` with
| `MediaLimits::maxSizeBytes()` — an implementation against an implementation,
| where both sides read the same call. A provider that hard-coded 2 GiB would
| satisfy it on any deployment whose setting happens to be 2 GiB, which is every
| deployment until an operator changes one.
|
| So the setting is MOVED, and the announcement has to move with it. That is the
| whole requirement: a limit that only changes by shipping code is a limit nobody
| ever tunes.
*/
it('announces the ceiling an operator set, not a number in the code', function (): void {
    $raised = MediaLimits::maxSizeBytes(MediaKind::Video) + 12_345;

    PlatformSettings::set('media.max_size_bytes', $raised);

    expect(app(BunnyMediaProvider::class)->capabilities()->maxSizeBytes)->toBe($raised);

    // And the enforcement moved with it: the size that was refused a moment ago
    // is now accepted, which is what "one number, two readers" means.
    expect(MediaLimits::maxSizeBytes(MediaKind::Video))->toBe($raised);
});
