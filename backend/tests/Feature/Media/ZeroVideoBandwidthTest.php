<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BunnyFixtures;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-001أ — THE RECORDING REACHES THE PROVIDER AND NOT ONE BYTE OF IT PASSES
| THROUGH THIS APPLICATION.
|
| ⚠️ WRITTEN AS A NEGATION OVER THE WHOLE REQUEST LIST, AND THE SHAPE IS THE TEST.
| The obvious version — "assert `videos/fetch` was called" — passes on an
| implementation that downloads the file, uploads it, and then calls fetch. It
| would certify the exact thing this phase exists to remove. `Http::fake()` records
| EVERY outgoing request, so what is asserted here is that none of them is a
| transfer (research §R8).
|
| The value of this guard is not this change. It is the person who opens the
| adapter in a year to fix something in the encoding ladder, finds a direct upload
| simpler, and is stopped by a test that explains why rather than by a comment that
| hopes.
*/

beforeEach(function (): void {
    // Only the timeline jobs. A bare Queue::fake() would swallow work this test
    // measures and turn "no bandwidth" into an assertion about an idle process.
    Queue::fake([CloseClassSessionJob::class]);
    Storage::fake('local');
    config(BunnyFixtures::config());

    // A catch-all so an unexpected request is RECORDED rather than erroring — an
    // exception would make the failure look like a broken test instead of a
    // gigabyte on the wire.
    Http::fake([
        BunnyFixtures::api('/videos/fetch') => Http::response(BunnyFixtures::fetchAccepted()),
        '*' => Http::response(BunnyFixtures::searchResult(), 200),
    ]);

    $this->provider = new FakeBroadcastProvider;
    $this->provider->pendingRecording = new RecordingArtifact(
        // Points at our own bucket, private, and the provider is expected to
        // fetch it for itself against a signed URL.
        downloadUrl: 'https://'.BunnyFixtures::SOURCE_HOST.'/recordings/session-abc.mp4',
        sizeBytes: 1_073_741_824,
        durationSeconds: 3600,
        mimeType: 'video/mp4',
    );
    $this->app->instance(BroadcastProviderInterface::class, $this->provider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->subHours(2),
        'ends_at' => CarbonImmutable::now()->subHour(),
    ]);

    $this->session->forceFill([
        'room_closed_at' => CarbonImmutable::now()->subHour(),
        'recording_status' => 'pending',
        'recording_attempts' => 0,
    ])->save();
});

function ingestForBandwidth(int $sessionId): void
{
    app()->call([new IngestSessionRecordingJob($sessionId), 'handle']);
}

/** @return list<Request> */
function outgoing(): array
{
    return array_map(fn (array $pair): Request => $pair[0], Http::recorded()->all());
}

/*
| ⚠️ THE POSITIVE CONTROL, AND THE NEGATIONS BELOW ARE WORTH NOTHING WITHOUT IT.
|
| Every assertion in this file is "no request did X". Over an EMPTY request list
| all of them pass — so a delivery that silently did nothing at all would certify
| itself as bandwidth-free. This is the test that says the list is not empty and
| that the one call in it is the right one.
*/
it('delivers by asking the provider to fetch, once', function (): void {
    ingestForBandwidth((int) $this->session->getKey());

    $fetches = array_values(array_filter(
        outgoing(),
        fn (Request $request): bool => str_contains($request->url(), '/videos/fetch'),
    ));

    expect($fetches)->toHaveCount(1);

    $body = $fetches[0]->data();

    // The signed source URL, and the title that is the join key — the two fields
    // that make the whole hand-off one call (research §R3).
    expect($body['url'])->toContain(BunnyFixtures::SOURCE_HOST)
        ->and($body['url'])->toContain('X-Amz-Signature')
        ->and($body['title'])->toStartWith(BunnyFixtures::TITLE_PREFIX.':');
});

it('sends no request at all to the source bucket', function (): void {
    ingestForBandwidth((int) $this->session->getKey());

    $toSource = array_values(array_filter(
        outgoing(),
        fn (Request $request): bool => str_contains($request->url(), BunnyFixtures::SOURCE_HOST),
    ));

    // A GET here is the download. There is no acceptable number of these above
    // zero — the signed URL is built so that SOMEBODY ELSE fetches it.
    expect($toSource)->toBe([]);
});

it('sends no request that carries a payload the size of a video', function (): void {
    ingestForBandwidth((int) $this->session->getKey());

    $bytes = array_sum(array_map(
        fn (Request $request): int => strlen($request->body()),
        outgoing(),
    ));

    // Every legitimate call in this path is a small JSON exchange. The ceiling is
    // generous on purpose: what it excludes is a transfer, not a verbose body.
    expect($bytes)->toBeLessThan(4096);
});

it('uploads to no upload path', function (): void {
    ingestForBandwidth((int) $this->session->getKey());

    $uploads = array_values(array_filter(
        outgoing(),
        fn (Request $request): bool => $request->method() === 'PUT'
            || str_contains($request->url(), 'tusupload'),
    ));

    expect($uploads)->toBe([]);
});

// The other half of the same measurement: a download that never went out over
// Http would still have to land somewhere. Nothing may land.
it('writes no video onto our own disk', function (): void {
    ingestForBandwidth((int) $this->session->getKey());

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

/*
| T013 — the source scan, same family as 017's name-containment guard.
|
| A behavioural test only sees the path it drives. This one reads the file: the
| transfer idioms must not appear in the adapter at all, so a second code path
| added later cannot introduce one where no test happens to look.
|
| ⚠️ SCOPED TO THE ADAPTER AND THE JOB, NOT THE WHOLE MODULE. `LocalMediaProvider`
| streams to disk with `sink()` on purpose — that code MOVED there and is what a
| provider with no remote fetch has to do. A scan covering it would fail on the
| design it is meant to protect.
*/
it('contains no transfer idiom in the adapter or the ingest job', function (): void {
    $files = [
        base_path('app/Modules/Media/Providers/BunnyMediaProvider.php'),
        base_path('app/Modules/LiveSessions/Jobs/IngestSessionRecordingJob.php'),
    ];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        foreach (['->sink(', '->attach(', '->body()'] as $idiom) {
            expect($source)->not->toContain($idiom, "{$file} uses {$idiom}");
        }
    }
});

// The exception has to BE an exception: if the download had not really moved, this
// rule would be passing over an empty premise.
it('finds the transfer where it now belongs', function (): void {
    expect((string) file_get_contents(base_path('app/Modules/Media/Providers/LocalMediaProvider.php')))
        ->toContain('->sink(');
});
