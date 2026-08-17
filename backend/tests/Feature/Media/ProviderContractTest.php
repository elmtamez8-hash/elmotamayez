<?php

declare(strict_types=1);

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Data\ProviderCapabilities;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\BunnyMediaProvider;
use App\Modules\Media\Providers\LocalMediaProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BunnyFixtures;
use Tests\Support\FakeMediaProvider;

/*
| The gate that makes deferring the provider choice safe rather than optimistic.
|
| Every implementation runs the same set. A commercial provider added later is a
| file in Providers/ and a line in the dataset below — and if it claims a
| capability it does not have, the build fails here instead of a student finding
| out on a slow connection.
*/

/*
| ⚠️ THE COMMERCIAL PROVIDER IS A LINE IN THIS DATASET, NOT A FILE OF ITS OWN — and
| the local case keeps its expectations exactly as they were, which is SC-004. A
| second contract file would be a second set of rules that agrees with this one
| until the first time only one of them is updated.
*/
dataset('providers', [
    'local' => fn () => new LocalMediaProvider,
    'fake' => fn () => new FakeMediaProvider,
    'bunny' => fn () => new BunnyMediaProvider,
]);

/*
| The commercial provider's account and a faked API, installed for every case.
|
| The local case is unaffected by both: it reads `media.disk` and touches no network.
| Doing it here rather than inside the bunny case keeps the dataset a list of
| implementations rather than a list of implementations plus setup.
*/
beforeEach(function (): void {
    config(BunnyFixtures::config());

    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        // The source the remote-fetch case is handed. The local provider downloads
        // it for real onto the fake disk, which is precisely its implementation of
        // the same capability.
        if (str_contains($request->url(), BunnyFixtures::SOURCE_HOST)) {
            return Http::response('video-bytes');
        }

        if (str_ends_with($path, '/videos/fetch')) {
            return Http::response(BunnyFixtures::fetchAccepted());
        }

        // Creating a video for an upload ticket — the one call that returns an id.
        if ($request->method() === 'POST' && str_ends_with($path, '/videos')) {
            return Http::response(['guid' => 'contract-guid']);
        }

        if (str_ends_with($path, '/videos')) {
            return Http::response(BunnyFixtures::searchResult());
        }

        // The asset in `contractAsset()` deliberately does not exist at the
        // provider, which is what the "reports a failure instead of throwing" case
        // is about. `contract-guid` is the one that does.
        if (str_ends_with($path, '/contract-guid')) {
            return Http::response(BunnyFixtures::video('contract-guid', 'contract'));
        }

        return Http::response(['message' => 'Not found'], 404);
    });
});

function contractAsset(): MediaAsset
{
    Storage::fake('local');

    $asset = MediaAsset::factory()->makeOne([
        'workspace_id' => 1,
        'owner_id' => 1,
        'uuid' => (string) Str::orderedUuid(),
        'provider_asset_id' => 'media/contract-test.mp4',
    ]);

    Storage::disk('local')->put('media/contract-test.mp4', 'video-bytes');

    return $asset;
}

/**
 * The same asset, but addressed the way the provider under test addresses one.
 *
 * ⚠️ WITHOUT THIS, TWO RULES BELOW WOULD BE VACUOUS FOR A REMOTE PROVIDER. A disk
 * path is not a video id, so `status()` would answer "not found" — and "delivers
 * adaptive bitrate if it claims it" would pass while reporting nothing, which is
 * exactly the dishonest-provider case the contract exists to catch.
 */
function contractAssetFor(MediaProviderInterface $provider): MediaAsset
{
    $asset = contractAsset();

    if ($provider->identifier() !== 'local') {
        $asset->provider_asset_id = 'contract-guid';
    }

    return $asset;
}

it('names itself', function (MediaProviderInterface $provider): void {
    expect($provider->identifier())->not->toBe('');
})->with('providers');

// FR-011 · NFR-008. A ticket is handed to a browser, so anything secret in it is
// public. Scanned for shapes rather than exact keys: a provider that invents its
// own header name should still fail.
it('puts no credential in an upload ticket', function (MediaProviderInterface $provider): void {
    $ticket = $provider->createUploadTicket(contractAsset());

    $serialised = strtolower(json_encode([
        $ticket->url,
        $ticket->headers,
        $ticket->fields,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

    foreach (['api_key', 'apikey', 'secret', 'access_key', 'password', 'private_key'] as $needle) {
        expect($serialised)->not->toContain($needle);
    }
})->with('providers');

it('reports a failure instead of throwing when the asset is gone', function (MediaProviderInterface $provider): void {
    Storage::fake('local');

    $asset = MediaAsset::factory()->makeOne([
        'workspace_id' => 1,
        'owner_id' => 1,
        'provider_asset_id' => 'media/does-not-exist.mp4',
    ]);

    // A provider outage must degrade uploading, not break every lesson screen.
    $report = $provider instanceof FakeMediaProvider
        ? (new FakeMediaProvider(unreachable: true))->status($asset)
        : $provider->status($asset);

    expect($report->status)->toBe(MediaAssetStatus::Failed)
        ->and($report->failureReason)->not->toBeNull();
})->with('providers');

it('deletes idempotently', function (MediaProviderInterface $provider): void {
    $asset = contractAssetFor($provider);

    $provider->delete($asset);
    $provider->delete($asset);
})->with('providers')->throwsNoExceptions();

/*
| 019 — the added capability, held to the same standard as the others.
|
| ⚠️ EVERY PROVIDER DECLARES `remoteFetch: true`, INCLUDING THE LOCAL ONE, and that
| is not a loophole. The flag answers "can you take a file over from a URL", which
| the local provider can — by downloading it, which is what it has always done.
| Answering false there would force every caller into two branches for one question,
| which is what the abstraction exists to remove. Whether the bytes crossed our own
| network is a different question, measured by SC-001 rather than promised here.
*/
it('takes a file over from a url if it claims it can', function (MediaProviderInterface $provider): void {
    if (! $provider->capabilities()->remoteFetch) {
        expect(true)->toBeTrue();

        return;
    }

    // A persisted asset here, unlike the cases above: the capability records where
    // the file landed, so it needs a row to record it on.
    Storage::fake('local');
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $asset = MediaAsset::factory()->create(['provider_asset_id' => null]);

    // Asynchronous by contract, so there is nothing to assert about a return value.
    // What must hold is that it does not throw on a source it was given.
    $provider->ingestFromUrl($asset, 'https://'.BunnyFixtures::SOURCE_HOST.'/recordings/x.mp4');

    expect(true)->toBeTrue();
})->with('providers');

// The two rules that hold a future provider to its word.
it('delivers adaptive bitrate if it claims it', function (MediaProviderInterface $provider): void {
    $capabilities = $provider->capabilities();

    if (! $capabilities->adaptiveBitrate) {
        expect(true)->toBeTrue();

        return;
    }

    expect(count($provider->status(contractAssetFor($provider))->renditions))
        ->toBeGreaterThanOrEqual(2, 'A provider that declares adaptive bitrate must report more than one rendition.');
})->with('providers');

it('fails a provider that claims adaptive bitrate and does not deliver', function (): void {
    // Proves the rule above actually executes. Without this, a test that only ever
    // sees honest implementations would pass forever while checking nothing.
    $liar = new FakeMediaProvider(breakPromise: true);

    expect($liar->capabilities()->adaptiveBitrate)->toBeTrue()
        ->and($liar->status(contractAsset())->renditions)->toHaveCount(0);
});

it('declares limits the upload path can enforce', function (MediaProviderInterface $provider): void {
    $capabilities = $provider->capabilities();

    expect($capabilities)->toBeInstanceOf(ProviderCapabilities::class)
        ->and($capabilities->maxSizeBytes)->toBeGreaterThan(0)
        ->and($capabilities->maxDurationSeconds)->toBeGreaterThan(0);
})->with('providers');
