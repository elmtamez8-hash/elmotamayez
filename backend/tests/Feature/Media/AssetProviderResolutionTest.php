<?php

declare(strict_types=1);

use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\BunnyMediaProvider;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Media\Support\MediaProviderResolver;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BunnyFixtures;

/*
| SC-011 — TWO ASSETS FOR TWO DIFFERENT PROVIDERS IN ONE DATABASE.
|
| ⚠️ AND ONE ASSET CANNOT SEE THE BUG THIS EXISTS FOR. `media_assets.provider` has
| been written on every row since 004 and read by nothing, which was harmless with
| one provider and becomes silent data loss with two: the container binds ONE
| provider from `media.provider`, so on the day production flips, every recording
| made before it is handed to the new provider — and answered "file not found",
| because those bytes are on our own disk and always will be.
|
| A test with a single asset is green whatever the code does, because the fixture's
| column always agrees with the test's config. The disagreement IS the test.
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(BunnyFixtures::config());

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

/** An asset stored at a named provider, with bytes only the local one can have. */
function assetFor(string $provider, ?string $providerAssetId): MediaAsset
{
    return MediaAsset::factory()->create([
        'provider' => $provider,
        'provider_asset_id' => $providerAssetId,
    ]);
}

it('hands each asset to its own provider even when the config names one of them', function (): void {
    // The configured provider is bunny — so the local asset is the one that would
    // break, which is the direction that matters: it is the asset that already
    // exists on the day of the switch.
    $legacy = assetFor('local', 'media/legacy.mp4');
    $current = assetFor('bunny', 'abc-123-guid');

    $resolver = app(MediaProviderResolver::class);

    expect($resolver->for($legacy))->toBeInstanceOf(LocalMediaProvider::class)
        ->and($resolver->for($current))->toBeInstanceOf(BunnyMediaProvider::class);
});

// The mirror image, so the test is not accidentally asserting "bunny is
// configured" rather than "the column is read".
it('still reads the column when the config names the other provider', function (): void {
    config(['media.provider' => 'local']);

    $resolver = app(MediaProviderResolver::class);

    expect($resolver->for(assetFor('bunny', 'abc-123-guid')))->toBeInstanceOf(BunnyMediaProvider::class)
        ->and($resolver->for(assetFor('local', 'media/x.mp4')))->toBeInstanceOf(LocalMediaProvider::class);
});

/*
| FR-003ب — an unknown provider is refused out loud.
|
| The alternative is not "nothing happens": it is falling back to whatever is
| configured and reporting that a perfectly intact lesson does not exist. A
| deployment that dropped a provider needs to hear about it from the deploy, not
| from a student.
*/
it('refuses an asset whose provider this deployment does not have', function (): void {
    $orphan = assetFor('cloudflare', 'some-id');

    expect(fn () => app(MediaProviderResolver::class)->for($orphan))
        ->toThrow(RuntimeException::class);
});

// The binding keeps its own job: an upload ticket is asked for before there is a
// row, so there is no column to read and the configured provider is the only
// possible answer. Replacing the binding with the resolver would leave the
// inversion point with none.
it('leaves the container binding answering for an asset that does not exist yet', function (): void {
    config(['media.provider' => 'local']);

    expect(app(MediaProviderInterface::class))
        ->toBeInstanceOf(LocalMediaProvider::class);
});

// A typo in MEDIA_PROVIDER must not quietly send every new recording to our own
// disk in production — which is the load this whole phase exists to remove,
// arriving silently.
it('refuses to boot a provider name it does not know', function (): void {
    config(['media.provider' => 'bunnny']);

    expect(fn () => app(MediaProviderInterface::class))
        ->toThrow(RuntimeException::class);
});
