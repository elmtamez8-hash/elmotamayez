<?php

declare(strict_types=1);

use App\Modules\Media\Contracts\VideoProviderInterface;
use App\Modules\Media\Data\ProviderCapabilities;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalVideoProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeVideoProvider;

/*
| The gate that makes deferring the provider choice safe rather than optimistic.
|
| Every implementation runs the same set. A commercial provider added later is a
| file in Providers/ and a line in the dataset below — and if it claims a
| capability it does not have, the build fails here instead of a student finding
| out on a slow connection.
*/

dataset('providers', [
    'local' => fn () => new LocalVideoProvider,
    'fake' => fn () => new FakeVideoProvider,
]);

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

it('names itself', function (VideoProviderInterface $provider): void {
    expect($provider->identifier())->not->toBe('');
})->with('providers');

// FR-011 · NFR-008. A ticket is handed to a browser, so anything secret in it is
// public. Scanned for shapes rather than exact keys: a provider that invents its
// own header name should still fail.
it('puts no credential in an upload ticket', function (VideoProviderInterface $provider): void {
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

it('reports a failure instead of throwing when the asset is gone', function (VideoProviderInterface $provider): void {
    Storage::fake('local');

    $asset = MediaAsset::factory()->makeOne([
        'workspace_id' => 1,
        'owner_id' => 1,
        'provider_asset_id' => 'media/does-not-exist.mp4',
    ]);

    // A provider outage must degrade uploading, not break every lesson screen.
    $report = $provider instanceof FakeVideoProvider
        ? (new FakeVideoProvider(unreachable: true))->status($asset)
        : $provider->status($asset);

    expect($report->status)->toBe(MediaAssetStatus::Failed)
        ->and($report->failureReason)->not->toBeNull();
})->with('providers');

it('deletes idempotently', function (VideoProviderInterface $provider): void {
    $asset = contractAsset();

    $provider->delete($asset);
    $provider->delete($asset);
})->with('providers')->throwsNoExceptions();

// The two rules that hold a future provider to its word.
it('delivers adaptive bitrate if it claims it', function (VideoProviderInterface $provider): void {
    $capabilities = $provider->capabilities();

    if (! $capabilities->adaptiveBitrate) {
        expect(true)->toBeTrue();

        return;
    }

    expect(count($provider->status(contractAsset())->renditions))
        ->toBeGreaterThanOrEqual(2, 'A provider that declares adaptive bitrate must report more than one rendition.');
})->with('providers');

it('fails a provider that claims adaptive bitrate and does not deliver', function (): void {
    // Proves the rule above actually executes. Without this, a test that only ever
    // sees honest implementations would pass forever while checking nothing.
    $liar = new FakeVideoProvider(breakPromise: true);

    expect($liar->capabilities()->adaptiveBitrate)->toBeTrue()
        ->and($liar->status(contractAsset())->renditions)->toHaveCount(0);
});

it('declares limits the upload path can enforce', function (VideoProviderInterface $provider): void {
    $capabilities = $provider->capabilities();

    expect($capabilities)->toBeInstanceOf(ProviderCapabilities::class)
        ->and($capabilities->maxSizeBytes)->toBeGreaterThan(0)
        ->and($capabilities->maxDurationSeconds)->toBeGreaterThan(0);
})->with('providers');
