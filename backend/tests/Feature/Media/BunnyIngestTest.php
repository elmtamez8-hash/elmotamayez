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
| SC-014 — THE MIDDLE STATE, WHICH IS NARROWER THAN THIS FILE ONCE CLAIMED.
|
| ⚠️ CORRECTED 2026-08-17 AGAINST A REAL ACCOUNT. This file used to assert that
| `videos/fetch` answers `{success, message, statusCode}` and returns NO id — from the
| OpenAPI schema via research §R3, with the narrative docs page dismissed as the
| outlier. The narrative page was right:
|
|     {"id":"42d1c5e3-…","success":true,"message":"OK","statusCode":200}
|
| and `GET /videos/{id}` returns that value as its `guid`. So the id is authoritative
| on the happy path, and the id-less middle state is the RECOVERY path — a response
| that never came back — not the ordinary one.
|
| The tests below are about three gaps, and only the first is routine:
|   · accepted and encoding      → id known, not yet playable
|   · accepted and response lost → no id, found again by title
|   · refused, and BY WHOM       → the origin (retry) vs the provider (give up)
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
    // The body a 200 acceptance carries. A test swaps this for the id-less variant to
    // exercise the recovery path.
    $this->fetchBody = null;
    // What a refusal answers with. Set together with a non-200 $this->fetchStatus,
    // because the STATUS alone does not say who refused (see the origin tests).
    $this->refusalBody = ['message' => 'refused'];
    $this->deliveredGuid = 'delivered-guid';

    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/videos/fetch')) {
            if ($this->fetchStatus !== 200) {
                return Http::response($this->refusalBody, $this->fetchStatus);
            }

            $body = $this->fetchBody ?? BunnyFixtures::fetchAccepted($this->deliveredGuid);

            /*
             * ⚠️ THE FAKE CREATES THE VIDEO, BECAUSE BUNNY DOES.
             *
             * Verified live on 2026-08-17: the fetch answers with an id and
             * `GET /videos/{id}` returns that video at status 2 immediately after. A
             * fake that accepted the delivery and then answered 404 for the id it had
             * just issued would put every test one step away from reality — and it is
             * exactly the step where an implementation reading the id would look
             * broken while the one ignoring it looked fine.
             */
            if (isset($body['id'])) {
                $this->videos[] = BunnyFixtures::video(
                    (string) $body['id'],
                    (string) ($request->data()['title'] ?? ''),
                    status: 2,
                );
            }

            return Http::response($body, 200);
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
| ⚠️ THE ID ARRIVES WITH THE ACCEPTANCE, AND THIS FILE ASSERTED THE OPPOSITE UNTIL A
| REAL ACCOUNT ANSWERED (2026-08-17).
|
| The OpenAPI schema types the 200 as `StatusModel` and research §R3 built the whole
| title-as-join-key design on it; the narrative docs page showed an id and was
| dismissed as the outlier. The live answer is
| `{"id":"…","success":true,"message":"OK","statusCode":200}`, and that value IS the
| video's guid. So the happy path never passes through the id-less middle state at all.
|
| The title stays load-bearing all the same — see the recovery test below.
*/
it('records the video id the moment the provider accepts the delivery', function (): void {
    runIngest($this->session);

    $asset = ingestAsset($this->session);

    expect($asset)->not->toBeNull()
        ->and($asset->provider_asset_id)->toBe('delivered-guid')
        // NOT Ready — the video exists and is still encoding. Ready here would be a
        // lesson published over a file that cannot play yet.
        ->and($asset->status)->toBe(MediaAssetStatus::Processing);

    // And nothing was published, which is the consequence that reaches a student.
    expect(Lesson::query()->withoutWorkspaceScope()->where('class_session_id', $this->session->getKey())->exists())
        ->toBeFalse();

    // Still pending, so the sweep comes back. `ingesting` would be a grave: the
    // sweep only re-sends for 'pending', and Settlement holds the teacher's fee
    // for anything that is neither published nor failed.
    expect($this->session->refresh()->recording_status)->toBe('pending');
});

it('publishes once the encode finishes', function (): void {
    runIngest($this->session);

    $asset = ingestAsset($this->session);
    expect($asset->status)->toBe(MediaAssetStatus::Processing);

    // The same video, finished. status 4 = Finished.
    $this->videos = [BunnyFixtures::video('delivered-guid', BunnyFixtures::titleFor((string) $asset->uuid))];

    runIngest($this->session);

    // ⚠️ THE ORDER IS THE REQUIREMENT (FR-006ب). An asset that reached Ready
    // without this column would be playable in name only — nothing could build
    // its URL and nothing could delete it.
    expect(ingestAsset($this->session)->provider_asset_id)->toBe('delivered-guid')
        ->and(ingestAsset($this->session)->status)->toBe(MediaAssetStatus::Ready)
        ->and($this->session->refresh()->recording_status)->toBe('published');
});

/*
| ⚠️ AND THIS IS WHY THE TITLE IS STILL A JOIN KEY, NOT A LABEL.
|
| It is no longer the happy path; it is the RECOVERY path. A delivery whose response
| never came back — a timeout after Bunny accepted, a worker killed between the POST
| and the save — leaves an asset with no id and a video that exists. Without the title
| there is nothing on either side that names the other, and the file is paid for
| monthly and referenced by nothing. So `BUNNY_TITLE_PREFIX` stays a join key, and
| changing it still orphans every asset not yet recovered.
*/
it('recovers the id by title when the acceptance carried none', function (): void {
    $this->fetchBody = BunnyFixtures::fetchAcceptedWithoutId();

    runIngest($this->session);

    $asset = ingestAsset($this->session);
    expect($asset->provider_asset_id)->toBeNull()
        ->and($asset->status)->toBe(MediaAssetStatus::Processing);

    // The video is there under the title we chose, and only the title finds it.
    $this->videos = [
        BunnyFixtures::video('recovered-guid', BunnyFixtures::titleFor((string) $asset->uuid)),
    ];

    runIngest($this->session);

    expect(ingestAsset($this->session)->provider_asset_id)->toBe('recovered-guid')
        ->and($this->session->refresh()->recording_status)->toBe('published');
});

// The column is written the moment it is learned, even while the encode is still
// running — because that is the only window in which it can be lost.
it('writes a recovered id even while the video is still transcoding', function (): void {
    $this->fetchBody = BunnyFixtures::fetchAcceptedWithoutId();

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
| ⚠️ THE SOURCE REFUSING BUNNY IS NOT BUNNY REFUSING US, AND THE STATUS CANNOT TELL
| THEM APART — WHICH IS WHY THIS TEST EXISTS.
|
| Bunny MIRRORS the origin's status into its own reply, observed live as
| `403 {"success":false,"message":"Origin returned HTTP 403 (Forbidden)."}`. That 403
| used to land in the permanent arm, and the very first failure a presigned source url
| will ever produce is an expiry: the whole attempt budget spent at once, the recording
| marked failed, and the teacher's held fee released for a file still intact in the
| bucket. The retry is the fix, because the next pass signs a NEW url.
*/
it('retries when the source refused the provider, rather than giving up', function (): void {
    $this->fetchStatus = 403;
    $this->refusalBody = BunnyFixtures::originRefused();

    runIngest($this->session);

    expect($this->session->refresh()->recording_status)->toBe('pending')
        ->and((int) $this->session->recording_attempts)->toBe(1);
});

// The mirror image, so the test above is not asserting "nothing is ever permanent".
// A key that is wrong is wrong on the fifth attempt too — and Bunny answers its own
// refusals in PascalCase, which a reader of `json('success')` alone would miss.
it('gives up at once when the provider itself denies us', function (): void {
    $this->fetchStatus = 401;
    $this->refusalBody = BunnyFixtures::authDenied();

    runIngest($this->session);

    expect($this->session->refresh()->recording_status)->toBe('failed')
        ->and((int) $this->session->recording_attempts)
        ->toBe(app(SessionSettings::class)->recordingMaxAttempts());
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

/*
| ⚠️ REACHED ONLY THROUGH THE RECOVERY PATH, AND THAT IS THE POINT.
|
| A title search happens when the acceptance carried no id — so a duplicate title is a
| hazard of the RECOVERY path, not of the happy one. The id arriving with the
| acceptance is what removed the everyday exposure to this; it did not remove the case,
| because a lost response still lands here.
*/
it('reports a duplicate rather than quietly picking one', function (): void {
    Exceptions::fake();

    $this->fetchBody = BunnyFixtures::fetchAcceptedWithoutId();

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

    // The id came back with the acceptance, so the poll asks about it directly —
    // which is the stronger version of this case: the id is not in doubt, only the
    // provider's availability is.
    expect($asset->provider_asset_id)->toBe('delivered-guid');

    $this->videoStatus = 500;

    runIngest($this->session);

    $asset = ingestAsset($this->session);

    expect($asset->provider_asset_id)->toBe('delivered-guid')
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
