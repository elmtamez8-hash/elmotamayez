<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Media\Actions\RequestUploadTicket;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Enums\MediaKind;
use App\Modules\Media\Providers\BunnyMediaProvider;
use App\Modules\Media\Support\MediaLimits;
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

it('points the upload at the provider and carries no credential', function (): void {
    ['ticket' => $ticket] = requestTicket();

    expect($ticket->url)->toStartWith('https://video.bunnycdn.com/')
        // Not our own route: the whole point is that the bytes never come here.
        ->and($ticket->url)->not->toContain('/api/v1/media/upload');

    $serialised = strtolower((string) json_encode(
        [$ticket->url, $ticket->headers, $ticket->fields],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));

    // The two secrets, by their configured values. Asserting on key NAMES would
    // miss a provider that invented its own; asserting on the values cannot.
    expect($serialised)->not->toContain(strtolower((string) config('media.bunny.access_key')))
        ->and($serialised)->not->toContain(strtolower((string) config('media.bunny.security_key')));

    foreach (['api_key', 'apikey', 'secret', 'access_key', 'password', 'private_key'] as $needle) {
        expect($serialised)->not->toContain($needle);
    }
});

it('proves the upload can be authorised without the key travelling', function (): void {
    ['ticket' => $ticket] = requestTicket();

    // A hash OF the key, not the key: the browser can prove it was authorised for
    // this one video for a bounded time, and nothing else.
    expect($ticket->fields)->toHaveKeys([
        'LibraryId', 'VideoId', 'AuthorizationExpire', 'AuthorizationSignature',
    ]);

    expect($ticket->fields['AuthorizationSignature'])->toHaveLength(64)
        ->and($ticket->expiresAt->isFuture())->toBeTrue();
});

// The id is known before any bytes move here, which is the difference from the
// recording path — so there is nothing to recover afterwards.
it('records the provider id at ticket time for a direct upload', function (): void {
    ['asset' => $asset] = requestTicket();

    expect($asset->provider_asset_id)->toBe('created-guid')
        ->and($asset->status)->toBe(MediaAssetStatus::Pending);
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
