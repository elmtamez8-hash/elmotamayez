<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BunnyFixtures;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-014 — THE MIDDLE STATE, WHICH IS THE ONE THAT DID NOT EXIST BEFORE.
|
| ⚠️ THE DEFECT HERE IS A PARTIAL SUCCESS, NOT A VISIBLE FAILURE. `videos/fetch`
| answers `{success, message, statusCode}` and does NOT return the new video's id
| (research §R3, from the OpenAPI schema — the narrative docs page disagrees and is
| the outlier). So a delivery can be accepted while the asset still has no
| `provider_asset_id`, and an implementation that reads a `guid` from that response
| gets null, writes it, and marks the asset ready: a published lesson pointing at
| nothing, with no error anywhere.
|
| Every test below is about the gap between "accepted" and "found".
*/

/*
| ⚠️ ONE CLOSURE FAKE, INSTALLED ONCE, DRIVEN BY STATE — NOT A SECOND `Http::fake()`
| PER TEST.
|
| Laravel APPENDS stub sets and uses the first pattern that matches, so a test that
| fakes an empty search and later re-fakes a populated one still gets the empty
| answer: the earlier stub is still registered and still wins. That cost this file
| one wrong red before it was noticed, and it is exactly the kind of thing that
| would otherwise be "fixed" by weakening the assertion.
*/
beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class]);
    Storage::fake('local');
    config(BunnyFixtures::config());

    // What the provider's library currently holds, and how it answers a delivery.
    // A test mutates these instead of re-faking.
    $this->videos = [];
    $this->fetchStatus = 200;
    // How `GET /videos/{guid}` answers. 200 means "read the video back".
    $this->videoStatus = 200;

    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/videos/fetch')) {
            return Http::response(
                $this->fetchStatus === 200 ? BunnyFixtures::fetchAccepted() : ['message' => 'refused'],
                $this->fetchStatus,
            );
        }

        // The search: `GET /videos` with a query string.
        if (str_ends_with($path, '/videos')) {
            return Http::response(BunnyFixtures::searchResult(...$this->videos));
        }

        // `GET`/`DELETE /videos/{guid}` — 404 for one that was never created,
        // which is also what a second delete sees.
        foreach ($this->videos as $video) {
            if (str_ends_with($path, '/'.$video['guid'])) {
                return $this->videoStatus === 200
                    ? Http::response($video)
                    : Http::response(['message' => 'upstream'], $this->videoStatus);
            }
        }

        return Http::response(['message' => 'Not found'], 404);
    });

    $this->provider = new FakeBroadcastProvider;
    $this->provider->pendingRecording = new RecordingArtifact(
        downloadUrl: 'https://'.BunnyFixtures::SOURCE_HOST.'/recordings/session-abc.mp4',
        sizeBytes: 1_073_741_824,
        durationSeconds: 3600,
        mimeType: 'video/mp4',
    );
    $this->app->instance(BroadcastProviderInterface::class, $this->provider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->subHours(2),
        'ends_at' => CarbonImmutable::now()->subHour(),
    ]);

    $this->session->forceFill([
        'room_closed_at' => CarbonImmutable::now()->subHour(),
        'recording_status' => 'pending',
        'recording_attempts' => 0,
    ])->save();
});

function runIngest(ClassSession $session): void
{
    app()->call([new IngestSessionRecordingJob((int) $session->getKey()), 'handle']);
}

function ingestAsset(ClassSession $session): ?MediaAsset
{
    $id = $session->refresh()->media_asset_id;

    return $id === null ? null : MediaAsset::query()->withoutWorkspaceScope()->find($id);
}

/*
| The state this whole file is named for: the provider took the file and the video
| has not appeared under its title yet.
*/
it('leaves an accepted delivery incomplete until its id is recovered', function (): void {
    // Nothing found yet — the encode has not produced a row we can see.
    runIngest($this->session);

    $asset = ingestAsset($this->session);

    expect($asset)->not->toBeNull()
        // NOT Ready. Ready here is the whole defect: a lesson published over a
        // file nothing can address.
        ->and($asset->status)->toBe(MediaAssetStatus::Processing)
        ->and($asset->provider_asset_id)->toBeNull();

    // And nothing was published, which is the consequence that reaches a student.
    expect(Lesson::query()->withoutWorkspaceScope()->where('class_session_id', $this->session->getKey())->exists())
        ->toBeFalse();

    // Still pending, so the sweep comes back. `ingesting` would be a grave: the
    // sweep only re-sends for 'pending', and Settlement holds the teacher's fee
    // for anything that is neither published nor failed.
    expect($this->session->refresh()->recording_status)->toBe('pending');
});

it('recovers the id by title and only then goes ready', function (): void {
    // First pass: delivered, not found.
    runIngest($this->session);

    $asset = ingestAsset($this->session);
    expect($asset->provider_asset_id)->toBeNull();

    // Second pass: the video now exists under the title we chose.
    $this->videos = [
        BunnyFixtures::video('recovered-guid', BunnyFixtures::titleFor((string) $asset->uuid)),
    ];

    runIngest($this->session);

    $asset = ingestAsset($this->session);

    // ⚠️ THE ORDER IS THE REQUIREMENT (FR-006ب). An asset that reached Ready
    // without this column would be playable in name only — nothing could build
    // its URL and nothing could delete it.
    expect($asset->provider_asset_id)->toBe('recovered-guid')
        ->and($asset->status)->toBe(MediaAssetStatus::Ready);

    expect($this->session->refresh()->recording_status)->toBe('published');
});

// The column is written the moment it is learned, even while the encode is still
// running — because that is the only window in which it can be lost.
it('writes the recovered id even while the video is still transcoding', function (): void {
    runIngest($this->session);

    $asset = ingestAsset($this->session);

    // status 3 = Transcoding. Found, but not playable.
    $this->videos = [
        BunnyFixtures::video('mid-guid', BunnyFixtures::titleFor((string) $asset->uuid), status: 3),
    ];

    runIngest($this->session);

    $asset = ingestAsset($this->session);

    expect($asset->provider_asset_id)->toBe('mid-guid')
        ->and($asset->status)->toBe(MediaAssetStatus::Processing);
});

/*
| A rate limit is "not yet", not "no".
|
| The limit is documented WITHOUT a number, so the retry ceiling stays the one
| inherited from 017 rather than an invented one.
*/
it('keeps a rate-limited delivery pending so the sweep retries it', function (): void {
    $this->fetchStatus = 429;

    runIngest($this->session);

    expect($this->session->refresh()->recording_status)->toBe('pending')
        ->and((int) $this->session->recording_attempts)->toBe(1);
});

/*
| ⚠️ AND THE SECOND CONSEQUENCE OF THE PROTOCOL, WHICH IS THE EXPENSIVE ONE.
|
| `videos/fetch` creates a NEW video on every call. So a retry after a rate limit
| can leave two videos for one lesson — one of which is paid for every month and
| referenced by nothing at all. The unique title is what makes that visible instead
| of a bill that grows for no apparent reason.
*/
it('does not deliver a second time for a session already handed over', function (): void {
    runIngest($this->session);
    runIngest($this->session);
    runIngest($this->session);

    $fetches = array_values(array_filter(
        array_map(fn (array $pair): Request => $pair[0], Http::recorded()->all()),
        fn (Request $request): bool => str_contains($request->url(), '/videos/fetch'),
    ));

    // Three passes of the sweep, one delivery. Re-asking is free; re-delivering
    // is a paid duplicate.
    expect($fetches)->toHaveCount(1);
});

it('reports a duplicate rather than quietly picking one', function (): void {
    Exceptions::fake();

    runIngest($this->session);

    $asset = ingestAsset($this->session);
    $title = BunnyFixtures::titleFor((string) $asset->uuid);

    $this->videos = [
        BunnyFixtures::video('first-guid', $title),
        // The duplicate a retry produced. Nothing points at it, and it is billed
        // every month.
        BunnyFixtures::video('second-guid', $title),
    ];

    runIngest($this->session);

    // The lesson still publishes from the first — a student is not punished for
    // our billing problem. What must not happen is nobody ever finding out.
    expect(ingestAsset($this->session)->provider_asset_id)->toBe('first-guid');

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'أكثرُ من ملف'));
});

/*
| ⚠️ A BAD FIVE MINUTES MUST NOT END A RECORDING THAT IS FINE.
|
| `Failed` is terminal for an asset: `ReconcileAssetStatus` selects `Processing` only,
| so nothing ever re-asks a failed one — and that job is the ONLY rescue for a
| recording whose ingest budget ran out while the provider was still transcoding. So a
| 500 or a 429 answered to a status poll used to kill a healthy video permanently and
| release the teacher's held fee against it.
|
| The tell that it was a bug rather than the contract: the same outage produced two
| different verdicts depending only on whether the id happened to be known yet — a
| failed title SEARCH already returned `Processing`.
*/
it('keeps a transiently unreachable provider in processing, never failed', function (): void {
    runIngest($this->session);

    $asset = ingestAsset($this->session);

    // The id is recoverable, so the poll gets past the search and asks directly.
    $this->videos = [
        BunnyFixtures::video('known-guid', BunnyFixtures::titleFor((string) $asset->uuid)),
    ];
    $this->videoStatus = 500;

    runIngest($this->session);

    $asset = ingestAsset($this->session);

    expect($asset->provider_asset_id)->toBe('known-guid')
        ->and($asset->status)->toBe(MediaAssetStatus::Processing)
        // Still pending, so both sweeps come back for it.
        ->and($this->session->refresh()->recording_status)->toBe('pending');
});

// The mirror image, so the test above is not asserting "nothing is ever failed".
// A 404 is the provider saying the file is not there, which is an answer, not an
// outage — and it is the one non-terminal-looking case that must be terminal.
it('still fails an asset the provider says it does not have', function (): void {
    runIngest($this->session);

    $asset = ingestAsset($this->session);

    $this->videos = [
        BunnyFixtures::video('known-guid', BunnyFixtures::titleFor((string) $asset->uuid)),
    ];
    $this->videoStatus = 404;

    runIngest($this->session);

    expect(ingestAsset($this->session)->status)->toBe(MediaAssetStatus::Failed);
});

/*
| A wrong key is not a slow network. Five attempts over an hour tell the teacher
| nothing they could not be told at once.
*/
it('fails a rejected delivery at once instead of spending the attempt budget', function (): void {
    $this->fetchStatus = 401;

    runIngest($this->session);

    expect($this->session->refresh()->recording_status)->toBe('failed')
        ->and((int) $this->session->recording_attempts)
        ->toBe(app(SessionSettings::class)->recordingMaxAttempts());
});
