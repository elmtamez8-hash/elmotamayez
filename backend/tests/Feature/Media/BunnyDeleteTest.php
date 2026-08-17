<?php

declare(strict_types=1);

use App\Modules\Media\Actions\DeleteMediaAsset;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\BunnyMediaProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BunnyFixtures;

/*
| FR-017 — DELETING MEANS DELETING, AND DELETING TWICE IS FREE.
|
| The reason this is its own file rather than a line in the contract test: a
| commercial provider bills for storage. An asset whose row we removed but whose
| video we left behind is paid for every month, for ever, and nothing in the product
| points at it — so nothing will ever notice. The second delete is the one that has
| to be free, because a retry after a network blip is the normal case.
*/

beforeEach(function (): void {
    config(BunnyFixtures::config());

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->deleted = [];
    // ⚠️ STATE, NOT A SECOND `Http::fake()`. Laravel APPENDS stub sets and uses the
    // first pattern that matches, so re-faking inside a test leaves the earlier
    // stub winning — a wrong red that reads as a missing feature.
    $this->deleteStatus = 200;

    Http::fake(function (Request $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'DELETE') {
            if ($this->deleteStatus !== 200) {
                return Http::response(['message' => 'refused'], $this->deleteStatus);
            }

            $guid = basename($path);

            // A video already gone answers 404, which is what a second delete sees.
            if (in_array($guid, $this->deleted, true)) {
                return Http::response(['message' => 'Not found'], 404);
            }

            $this->deleted[] = $guid;

            return Http::response(['success' => true]);
        }

        return Http::response([], 404);
    });
});

it('removes the video at the provider, not only our row', function (): void {
    $asset = MediaAsset::factory()->create([
        'provider' => 'bunny',
        'provider_asset_id' => 'to-delete-guid',
    ]);

    app(DeleteMediaAsset::class)->handle($asset);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), 'to-delete-guid'));

    expect(MediaAsset::query()->withoutWorkspaceScope()->whereKey($asset->getKey())->exists())
        ->toBeFalse();
});

it('treats a second delete as a success', function (): void {
    $asset = MediaAsset::factory()->make([
        'provider' => 'bunny',
        'provider_asset_id' => 'twice-guid',
    ]);

    $provider = app(BunnyMediaProvider::class);

    $provider->delete($asset);
    // The provider now answers 404. A retry after a network blip is the normal
    // case, and it must not throw.
    $provider->delete($asset);
})->throwsNoExceptions();

/*
| SC-014 in the direction of deletion.
|
| ⚠️ NOT "nothing to do, report success". An asset whose id was never recovered is
| the one case where a video may exist at the provider that we cannot address — so
| the honest behaviour is to send no delete rather than to pretend one succeeded.
| Claiming success is how the reconciliation report loses the only lead it had.
*/
it('sends no delete for an asset whose provider id was never recovered', function (): void {
    $orphan = MediaAsset::factory()->make([
        'provider' => 'bunny',
        'provider_asset_id' => null,
    ]);

    app(BunnyMediaProvider::class)->delete($orphan);

    Http::assertNothingSent();
});

// A failure that is not a 404 must surface. Swallowing everything would turn a
// provider outage into a storage bill nobody can explain.
it('raises a delete the provider refused for any other reason', function (): void {
    $this->deleteStatus = 500;

    $asset = MediaAsset::factory()->make([
        'provider' => 'bunny',
        'provider_asset_id' => 'broken-guid',
    ]);

    expect(fn () => app(BunnyMediaProvider::class)->delete($asset))
        ->toThrow(RuntimeException::class);
});
