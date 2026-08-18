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

/*
| ⚠️ AND THE QUESTIONS HAVE TO STOP, WITH AN ANSWER.
|
| The sweep runs every five minutes and spends up to two provider calls per
| `Processing` row — a title search plus a GET. It had no age ceiling and no
| attempt budget of any kind, so an asset whose delivery never landed was
| interrogated 288 times a day, for ever, and the standing bill grew with every
| asset that ever got stuck.
|
| Both halves are asserted, because a ceiling alone is not a fix: an asset merely
| dropped from the poll would sit «قيد التجهيز» permanently, which is the state
| this job exists to abolish, reached by another road. Past the ceiling it is
| answered — failed, with a reason a teacher can act on.
*/
it('gives up on an abandoned asset with a reason instead of asking for ever', function (): void {
    $abandoned = MediaAsset::factory()->create([
        'provider' => 'local',
        'status' => MediaAssetStatus::Processing,
    ]);

    // Older than the ceiling. `created_at`, not `updated_at`: the sweep touches
    // the row on a pass that changes nothing, so an age measured from the last
    // touch is an age that resets itself.
    MediaAsset::query()->withoutWorkspaceScope()
        ->whereKey($abandoned->getKey())
        ->update([
            'created_at' => CarbonImmutable::now()->subHours(
                (int) config('media.reconcile_ceiling_hours') + 1,
            ),
            'updated_at' => CarbonImmutable::now()->subMinutes(10),
        ]);

    Exceptions::fake();

    reconcile();

    $abandoned->refresh();

    expect($abandoned->status)->toBe(MediaAssetStatus::Failed)
        // ⚠️ THE SENTENCE, NOT MERELY A NON-NULL ONE. Asked and refused by the
        // provider also lands `Failed` with a reason, so "there is some reason
        // here" would pass over an asset that was still being interrogated. Only
        // this wording says the questions stopped (FR-031).
        ->and($abandoned->failure_reason)->toContain('خلال المهلة');
});

/*
| The mirror image, or the ceiling would read as "stop reconciling everything".
|
| ⚠️ AND IT ASSERTS THE REASON, NOT THE STATE. This fixture's file is a few bytes
| of text, so the provider settles it and `CompleteMediaUpload` then REJECTS it on
| mime — it lands `Failed` either way, and a test comparing states would pass
| whether the row was asked about or written off unasked. What separates the two
| is which sentence is on the row.
*/
it('keeps asking about an asset still inside the window', function (): void {
    Exceptions::fake();

    reconcile();

    $this->healthy->refresh();

    expect($this->healthy->status)->not->toBe(MediaAssetStatus::Processing)
        ->and($this->healthy->failure_reason)->not->toContain('خلال المهلة');
});
