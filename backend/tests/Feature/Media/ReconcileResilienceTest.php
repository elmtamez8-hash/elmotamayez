<?php

declare(strict_types=1);

use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Jobs\ReconcileAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/*
| ONE POISONED ROW MAY NOT TAKE THE PLATFORM'S RECONCILIATION WITH IT.
|
| ⚠️ AND THIS JOB IS THE ONLY RESCUE THERE IS. It was scheduled for the first time in
| 019, and it is what keeps asking after IngestSessionRecordingJob has spent its
| attempt budget — a late `Ready` fires MediaAssetReady, publishes the lesson and
| overwrites `failed` with `published`. Settlement withholds a teacher's fee for any
| recording that is neither published nor failed, so this loop stopping is a wage that
| stops with it.
|
| The walk is `orderBy('id')` and the supervisor runs `tries: 1`. So an uncaught throw
| failed the job, and the schedule walked straight back into the same lowest-id row
| five minutes later, for ever: every `Processing` asset BEHIND it was never
| reconciled again, platform-wide, silently. Three real throw sources reach that line —
| an asset whose `provider` column names a provider this deployment does not have, a
| `delete()` answering neither 2xx nor 404 when a Ready asset is rejected, and
| PublishRecordingAsLesson, which is a SYNCHRONOUS listener.
|
| ⚠️ THE ORDER OF THE FIXTURES IS THE TEST. The poisoned asset must be created FIRST
| so it holds the lower id; created second, it is reached last and the assertion below
| passes over the unfixed code.
*/

beforeEach(function (): void {
    Storage::fake('local');

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    // Older than the one-minute age check the job applies, or nothing is selected.
    $stale = CarbonImmutable::now()->subMinutes(10);

    // Lower id: a provider this deployment does not have. MediaProviderResolver
    // throws on it, which is correct — the alternative is silently answering that an
    // intact lesson does not exist.
    $this->poisoned = MediaAsset::factory()->create([
        'provider' => 'a-provider-that-was-removed',
        'provider_asset_id' => 'orphan-id',
        'status' => MediaAssetStatus::Processing,
    ]);

    // Higher id: an ordinary local asset that is ready to settle.
    $this->healthy = MediaAsset::factory()->create([
        'provider' => 'local',
        'status' => MediaAssetStatus::Processing,
    ]);

    Storage::disk('local')->put(
        (string) $this->healthy->provider_asset_id,
        'a file with some bytes in it',
    );

    MediaAsset::query()->withoutWorkspaceScope()
        ->whereIn('id', [$this->poisoned->getKey(), $this->healthy->getKey()])
        ->update(['updated_at' => $stale]);
});

function reconcile(): void
{
    app()->call([new ReconcileAssetStatus, 'handle']);
}

it('reconciles the rows behind a poisoned one instead of dying on it', function (): void {
    Exceptions::fake();

    reconcile();

    // ⚠️ THE WHOLE POINT: this asset sits BEHIND the throwing row in id order.
    // Before the fix it was never reached, on this pass or on any later one.
    expect($this->healthy->refresh()->status)->not->toBe(MediaAssetStatus::Processing);
});

// Skipped, never swallowed. A row nothing can reconcile is an operator problem, and
// the exception is the only thing that says which row.
it('reports the poisoned row rather than passing over it in silence', function (): void {
    Exceptions::fake();

    reconcile();

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains(
        $e->getMessage(),
        'a-provider-that-was-removed',
    ));
});

/*
| ⚠️ NO THIRD TEST FOR "THE JOB COMPLETES", DELIBERATELY.
|
| It would read `expect(true)->toBeTrue()` — an assertion about nothing, which is one
| of the three vacuous guards this same review round found in 019's own suite. It is
| also redundant: without the try/catch the throw escapes `chunkById` and both tests
| above ERROR rather than fail. Completion is already what they rest on.
*/
