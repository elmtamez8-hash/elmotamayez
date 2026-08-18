<?php

declare(strict_types=1);

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\BunnyMediaProvider;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\BunnyFixtures;

/*
| WHAT THE PROVIDER'S OWN STATUS NUMBER MEANS.
|
| ⚠️ ITS OWN FILE, AND NOT INSIDE BunnyIngestTest, FOR A DOCUMENTED REASON:
| `Http::fake()` APPENDS stub sets and the FIRST matching pattern wins. That file
| registers one stateful catch-all closure in `beforeEach`, so a later `Http::fake`
| there is registered and never reached — the assertions then measure the earlier
| stub and read as a missing feature. These need their own fake, so they need their
| own file.
*/

beforeEach(function (): void {
    config(BunnyFixtures::config());

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

/*
| ⚠️ A LIBRARY WITH JIT ENCODING ON NEVER REACHES 4, AND EVERYTHING NOT 4/5/6 WAS
| REPORTED «STILL PROCESSING».
|
| Just-In-Time is a per-library switch: such a video goes `7 JitSegmenting` then
| `8 JitPlaylistsCreated` and STOPS there — 8 is playable. Read as Processing, the
| recording is never published, the lesson never appears, and the teacher's fee
| stays held. In perfect silence, for ever.
|
| Verified switched off on this account on 2026-08-18 — which is why nothing had
| gone wrong yet, and exactly why this is a test: a checkbox is not a guarantee.
*/
it('treats a JIT library as finished at eight', function (): void {
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'provider' => 'bunny',
        'provider_asset_id' => 'jit-guid',
        'status' => MediaAssetStatus::Processing,
    ]);

    Http::fake([
        '*/videos/jit-guid' => Http::response([
            'guid' => 'jit-guid',
            'status' => 8,
            'length' => 120,
            'storageSize' => 4096,
            'availableResolutions' => '360p,720p',
        ], 200),
    ]);

    $report = app(BunnyMediaProvider::class)->status($asset);

    expect($report->status)->toBe(MediaAssetStatus::Ready)
        ->and($report->durationSeconds)->toBe(120);
});

// 7 is genuinely mid-flight and must stay Processing, or the pair reads as
// "anything above six is ready" — which would publish a lesson over a file that
// is still being cut into segments.
it('keeps a JIT library segmenting at seven', function (): void {
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'provider' => 'bunny',
        'provider_asset_id' => 'jit-guid',
        'status' => MediaAssetStatus::Processing,
    ]);

    Http::fake(['*/videos/jit-guid' => Http::response(['guid' => 'jit-guid', 'status' => 7], 200)]);

    expect(app(BunnyMediaProvider::class)->status($asset)->status)
        ->toBe(MediaAssetStatus::Processing);
});

/*
| `availableResolutions` is absent on some finished videos, and `(string) null` is
| `''` — zero renditions under a `capabilities()` that claims adaptive bitrate,
| which makes the claim decorative. Every fixture in this suite hands back three,
| so nothing could ever have seen it.
|
| The lesson still plays and the player reads its ladder from the master playlist,
| so this reports rather than refuses. What is not acceptable is silence.
*/
it('reports a finished video that declares no renditions at all', function (): void {
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'provider' => 'bunny',
        'provider_asset_id' => 'bare-guid',
        'status' => MediaAssetStatus::Processing,
    ]);

    Http::fake([
        '*/videos/bare-guid' => Http::response([
            'guid' => 'bare-guid',
            'status' => 4,
            'length' => 60,
            'storageSize' => 1024,
            // The field is simply not there.
        ], 200),
    ]);

    Exceptions::fake();

    $report = app(BunnyMediaProvider::class)->status($asset);

    expect($report->status)->toBe(MediaAssetStatus::Ready)
        ->and($report->renditions)->toBe([]);

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains(
        $e->getMessage(),
        (string) $asset->uuid,
    ));
});

/*
| ⚠️ THE JOIN KEY HAD NO TRAP LAID FOR IT, SO NOTHING PROVED IT WAS USED.
|
| The id is recovered by searching the provider for `{prefix}:{asset_uuid}` — a
| SUBSTRING search, which is why the code filters the results for an exact title.
| Every fixture in the suite returned exactly one video carrying exactly the right
| title, so an implementation that took `items[0]` and skipped the filter passed
| this file, the contract file and the bandwidth file alike.
|
| The cost of that missing filter is not abstract: it hangs one lesson's video on
| another lesson's row. So the search here answers with a DECOY FIRST — another
| asset's video, exactly as a substring search would return it — and the
| assertions are on which video was then asked about.
*/
it('recovers the id by exact title even when the search returns another video first', function (): void {
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'provider' => 'bunny',
        'provider_asset_id' => null,
        'status' => MediaAssetStatus::Processing,
    ]);

    $decoyTitle = BunnyFixtures::titleFor('some-other-asset-uuid');

    Http::fake(function ($request) use ($asset, $decoyTitle) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_ends_with($path, '/videos')) {
            return Http::response(BunnyFixtures::searchResult(
                ['guid' => 'decoy-guid', 'title' => $decoyTitle],
                ['guid' => 'the-right-guid', 'title' => BunnyFixtures::titleFor((string) $asset->uuid)],
            ));
        }

        if (str_ends_with($path, '/the-right-guid')) {
            return Http::response([
                'guid' => 'the-right-guid',
                'status' => 4,
                'length' => 321,
                'storageSize' => 2048,
                'availableResolutions' => '360p,720p',
            ]);
        }

        // A decoy that answers happily, so taking it would NOT fail by accident.
        return Http::response([
            'guid' => 'decoy-guid',
            'status' => 4,
            'length' => 999,
            'storageSize' => 4096,
            'availableResolutions' => '360p,720p',
        ]);
    });

    $report = app(BunnyMediaProvider::class)->status($asset);

    // The duration is the tell: both videos are Ready, and only one is ours.
    expect($report->status)->toBe(MediaAssetStatus::Ready)
        ->and($report->durationSeconds)->toBe(321);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'the-right-guid'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'videos/decoy-guid'));
});
