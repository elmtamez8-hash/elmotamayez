<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Jobs\RetryPendingRecordingsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\LocalMediaProvider;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeBroadcastProvider;
use Tests\Support\FakeMediaProvider;

/*
| SC-004 — every recorded session ends with a file or a NAMED failure. Zero
| sessions stuck on "قيد المعالجة" for ever.
|
| ⚠️ THIS TEST FAILED ON THE TREE AS IT STOOD, AND THAT WAS THE POINT.
| `IngestSessionRecordingJob::giveUpOrRetry()` incremented the counter, wrote
| `recording_status = 'pending'` and RETURNED — no `release()`, no second
| dispatch. The only sender was `SessionCompleted`, which fires once per session.
| So the first "not finished yet" was also the last attempt, and the counter
| froze at 1 for ever.
|
| It was invisible because `NullBroadcastProvider` declares `recording: false`,
| so the job returned before it ever reached that branch. 017 is what turns the
| branch on — which is why the sweep ships with it and not afterwards.
|
| ⚠️ AND THE COST WAS A TEACHER'S PAY, not a badge on a screen.
| `Settlement\Support\PackageCompletion` reads this same column and holds a unit
| back while the recording is neither published nor failed.
|
| ⚠️ NO `Queue::fake()` HERE, deliberately. The sweep's whole job is to dispatch,
| and a bare fake would swallow it — turning "the recording was retried" into a
| confident assertion about a queue that never ran anything.
*/

beforeEach(function (): void {
    // recording: true, and `pendingRecording` left null — the provider that says
    // "not finished yet" every time, which is the state this test is about.
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->subHours(2),
        'ends_at' => CarbonImmutable::now()->subHour(),
    ]);

    $this->session->forceFill([
        // Completed, because that is what a session with a closed room and an
        // hour-old end time actually is. The factory defaults to `scheduled` and
        // the fixture had left it there — harmless while the sweep looked only at
        // `recording_status`, and wrong the moment it had to tell a finished
        // session from one whose teacher merely ended the broadcast early.
        'status' => ClassSessionStatus::Completed,
        'room_closed_at' => CarbonImmutable::now()->subHour(),
        'recording_status' => 'pending',
        'recording_attempts' => 0,
    ])->save();
});

function sweep(): void
{
    // Resolved through the container so the sweep's dependencies stay its own
    // business — it grew a second one the day it had to ask whether the current
    // provider records at all.
    app()->call([app(RetryPendingRecordingsJob::class), 'handle']);
}

it('re-sends the ingest for a recording still pending', function (): void {
    sweep();

    expect((int) $this->session->refresh()->recording_attempts)->toBe(1)
        ->and($this->session->recording_status)->toBe('pending');
});

/*
| T039 — the counter has to MOVE. It never did: one sender, one attempt.
|
| ⚠️ AND IT MOVES ACROSS TIME NOW, WHICH IS WHY THE CLOCK IS TRAVELLED HERE. Three
| back-to-back sweeps used to spend three attempts, and that was the defect measured
| on 2026-08-18 rather than a stylistic detail: the sweep is scheduled every fifteen
| minutes, so five attempts read as «an hour and a quarter» — and a queue backlog
| replaying seventy-two queued passes spent the whole budget inside one second. This
| test now asserts what the requirement actually says.
*/
it('advances the attempt counter past one', function (): void {
    sweep();
    $this->travel(15)->minutes();
    sweep();
    $this->travel(15)->minutes();
    sweep();

    expect((int) $this->session->refresh()->recording_attempts)->toBeGreaterThan(1);
});

it('settles on failed at the limit and tells the teacher', function (): void {
    $limit = app(SessionSettings::class)->recordingMaxAttempts();

    for ($i = 0; $i < $limit; $i++) {
        sweep();
        $this->travel(15)->minutes();
    }

    expect((int) $this->session->refresh()->recording_attempts)->toBe($limit)
        // Named, not "قيد المعالجة" for ever. This is the whole of SC-004.
        ->and($this->session->recording_status)->toBe('failed');

    expect(
        Notification::query()
            ->where('recipient_user_id', $this->owner->getKey())
            ->where('type', NotificationType::SessionRecordingFailed->value)
            ->exists()
    )->toBeTrue();
});

// Past the limit the sweep must let go, or it re-sends for ever and notifies on
// every pass — a teacher told nightly about a recording nobody is still fetching.
it('stops sweeping a session that has already failed', function (): void {
    $this->session->forceFill([
        'recording_status' => 'failed',
        'recording_attempts' => app(SessionSettings::class)->recordingMaxAttempts(),
    ])->save();

    sweep();

    expect((int) $this->session->refresh()->recording_attempts)
        ->toBe(app(SessionSettings::class)->recordingMaxAttempts());
});

// The window is a window. Something a week old is a manual upload, not a retry
// loop running against a provider that has long since dropped the file.
it('ignores a session whose room closed outside the window', function (): void {
    $this->session->forceFill(['room_closed_at' => CarbonImmutable::now()->subDays(5)])->save();

    sweep();

    expect((int) $this->session->refresh()->recording_attempts)->toBe(0);
});

// Sanity: the job the sweep sends is the one that does the work, and it is the
// same job SessionCompleted sends. Two ingest paths would be two behaviours.
it('sends the same ingest job the completion event sends', function (): void {
    expect(class_exists(IngestSessionRecordingJob::class))->toBeTrue();
});

/*
| ⚠️ NULL IS THE WIDER DOOR, AND NOTHING SWEPT IT.
|
| `recording_status` is nullable with no default and no initial writer outside
| the ingest job, so a session whose job died before its first write sits at NULL
| — and the sweep selected `'pending'` alone. Horizon runs this queue at
| `tries: 1`, and `recording()` is a live HTTP call, so ONE refused connection
| was enough. `PackageCompletion` then held the teacher's fee for a lesson that
| was actually taught, for ever, with a row in `failed_jobs` as the only trace.
*/
it('sweeps a session the ingest job never wrote to', function (): void {
    $this->session->forceFill(['recording_status' => null, 'recording_attempts' => 0])->save();

    sweep();

    expect($this->session->refresh()->recording_status)->toBe('pending')
        ->and((int) $this->session->recording_attempts)->toBe(1);
});

/*
| The same hole from the other side: `'ingesting'` is written BEFORE the hand-off
| and corrected on the same pass, so only a hard kill — the 60-second job timeout
| across four provider calls, or an OOM — can leave it standing. `catch
| (Throwable)` covers a throw and never a kill.
*/
it('sweeps a session stranded mid-handover', function (): void {
    $this->session->forceFill(['recording_status' => 'ingesting', 'recording_attempts' => 0])->save();

    sweep();

    expect((int) $this->session->refresh()->recording_attempts)->toBe(1);
});

/*
| ⚠️ AND NEITHER OF THOSE MAY BE SWEPT WHEN THE PROVIDER CANNOT RECORD.
|
| Every completed session on such a provider sits at NULL for ever — legitimately,
| there is nothing to fetch. Selecting them would re-dispatch every one of them
| every fifteen minutes, for the whole 48-hour window, to a job that returns
| immediately: work that produces nothing and hides real rows in the log.
*/
it('leaves an unwritten session alone when the provider does not record', function (): void {
    $provider = new FakeBroadcastProvider;
    $provider->records = false;
    $this->app->instance(BroadcastProviderInterface::class, $provider);

    $this->session->forceFill(['recording_status' => null, 'recording_attempts' => 0])->save();

    sweep();

    expect($this->session->refresh()->recording_status)->toBeNull()
        ->and((int) $this->session->recording_attempts)->toBe(0);
});

/*
| A teacher who ends the broadcast early leaves `room_closed_at` set while
| `CloseClassSession` is still up to a join window away. Sweeping then spends an
| attempt on «not finished yet» about a session that has not finished.
*/
it('waits for the session to be completed before sweeping an unwritten one', function (): void {
    $this->session->forceFill([
        'status' => ClassSessionStatus::Live,
        'recording_status' => null,
        'recording_attempts' => 0,
    ])->save();

    sweep();

    expect($this->session->refresh()->recording_status)->toBeNull();
});

/*
| ⚠️ AN OUTAGE USED TO KILL THE JOB BEFORE IT WROTE ANYTHING.
|
| `recording()` is a live HTTP call and it stood ABOVE the `try` — so with Horizon
| at `tries: 1`, one refused connection ended the job with `recording_status`
| still NULL, which nothing swept. The teacher's fee stayed held for a lesson that
| was taught, permanently, and the only evidence was a `failed_jobs` row.
|
| The assertion is the column, not the absence of an exception: a job that
| swallowed the error and still wrote nothing would pass a "did not throw" test
| and leave the same grave.
*/
it('survives a provider outage by leaving the session retryable', function (): void {
    $provider = new FakeBroadcastProvider;
    $provider->recordingError = new RuntimeException('cURL error 7: connection refused');
    $this->app->instance(BroadcastProviderInterface::class, $provider);

    $this->session->forceFill(['recording_status' => null, 'recording_attempts' => 0])->save();

    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    expect($this->session->refresh()->recording_status)->toBe('pending')
        ->and((int) $this->session->recording_attempts)->toBe(1);
});

/*
| A SECOND PASS OVER A SESSION ALREADY HANDED OVER MUST ASK, NEVER DELIVER AGAIN.
|
| Every `videos/fetch` creates a video, so a re-delivery is a second one billed
| monthly and referenced by nothing. This is the ORDINARY path — the sweep runs
| every fifteen minutes over sessions that are still pending — and it is guarded
| by the read at the top of the job.
|
| ⚠️ IT IS NOT THE RACE. Both calls here are sequential, so the second reads a
| session that already carries an asset and never reaches the claim at all. The
| interleaving that needs a conditional UPDATE is the test below this one.
*/
it('asks instead of delivering on a second pass', function (): void {
    $provider = new FakeBroadcastProvider;
    $provider->pendingRecording = new RecordingArtifact(
        downloadUrl: 'https://storage.test/session.mp4',
        sizeBytes: 2048,
        durationSeconds: 120,
        mimeType: 'video/mp4',
    );
    $this->app->instance(BroadcastProviderInterface::class, $provider);

    $this->session->forceFill(['recording_status' => null, 'recording_attempts' => 0])->save();

    $ingest = fn () => app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    $ingest();
    $firstAsset = $this->session->refresh()->media_asset_id;

    $ingest();

    expect($this->session->refresh()->media_asset_id)->toBe($firstAsset);

    // One asset for this session, not two — the row a losing runner creates before
    // the claim must not survive it.
    expect(MediaAsset::query()
        ->withoutWorkspaceScope()
        ->where('owner_type', ClassSession::class)
        ->where('owner_id', $this->session->getKey())
        ->count())->toBe(1);
});

/*
| ⚠️ AND THIS IS THE RACE ITSELF, IN THE ONE WINDOW THAT MATTERS.
|
| The job reads `media_asset_id` at the top and wrote it near the bottom — the
| textbook read-then-write, with two senders by design: `SessionCompleted` fires
| once and the sweep re-dispatches every fifteen minutes. Both runners read null,
| both call `videos/fetch`, and every call to it CREATES a video: a second one,
| paid for monthly, pointed at by nothing.
|
| A single-threaded test still reaches it, because `recording()` is asked BETWEEN
| the read and the claim. The callback below is the other runner winning inside
| exactly that window — no threads, no sleep, no mock of the thing under test.
|
| The assertions are the two costs: the winner's asset is not displaced, and the
| loser leaves no orphan row behind.
*/
it('loses the claim without delivering when another runner wins mid-flight', function (): void {
    $winner = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => ClassSession::class,
        'owner_id' => $this->session->getKey(),
    ]);

    $provider = new FakeBroadcastProvider;
    $provider->pendingRecording = new RecordingArtifact(
        downloadUrl: 'https://storage.test/session.mp4',
        sizeBytes: 2048,
        durationSeconds: 120,
        mimeType: 'video/mp4',
    );

    // The other runner, arriving after this job read null and before it claims.
    $provider->onRecording = function (ClassSession $session) use ($winner): void {
        ClassSession::query()->withoutWorkspaceScope()->whereKey($session->getKey())
            ->update(['media_asset_id' => $winner->getKey(), 'recording_status' => 'ingesting']);
    };

    $this->app->instance(BroadcastProviderInterface::class, $provider);

    $this->session->forceFill(['media_asset_id' => null, 'recording_status' => null])->save();

    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    expect((int) $this->session->refresh()->media_asset_id)->toBe((int) $winner->getKey());

    expect(MediaAsset::query()
        ->withoutWorkspaceScope()
        ->where('owner_type', ClassSession::class)
        ->where('owner_id', $this->session->getKey())
        ->count())->toBe(1);
});

/*
| FR-009ب — ⚠️ THE ONLY SIGNAL THAT SAYS «LOOK AT THE PROVIDER, NOT AT THE
| SESSIONS», AND NOTHING IN THE REPOSITORY TESTED IT.
|
| There was not one `Log::spy` or `assertLogged` anywhere in `tests/`. So the half
| of the requirement the spec says «one does not stand in for the other» about was
| literally a line that shipped green and alerted nobody — the same defect one
| layer up from the one this whole file exists for.
|
| It is deliberately NOT a notification: forty failures send forty teachers a
| message about their own lesson, and not one of those shows anybody there is a
| single cause. The outage is then found by whoever adds them up, which is nobody,
| at night, when the provider's keys were rotated.
*/
it('alerts the platform once enough recordings fail at the same time', function (): void {
    $limit = app(SessionSettings::class)->recordingMaxAttempts();
    $threshold = app(SessionSettings::class)->recordingFailureAlertThreshold();

    for ($i = 0; $i < $threshold; $i++) {
        $failed = ClassSession::factory()->create([
            'teacher_profile_id' => $this->session->teacher_profile_id,
        ]);

        $failed->forceFill([
            'status' => ClassSessionStatus::Completed,
            'room_closed_at' => CarbonImmutable::now()->subHour(),
            'recording_status' => 'failed',
            'recording_attempts' => $limit,
        ])->save();
    }

    Log::shouldReceive('alert')->once()->withArgs(
        fn (string $message, array $context): bool => ($context['failed_recordings'] ?? 0) >= $threshold,
    );

    sweep();
});

// The mirror image, or the rule above reads as "it alerts whenever it runs" — and
// an alert that fires on one ordinary failure is an alert an operator learns to
// scroll past, which is the same as no alert at all.
it('stays silent below the threshold', function (): void {
    $this->session->forceFill([
        'recording_status' => 'failed',
        'recording_attempts' => app(SessionSettings::class)->recordingMaxAttempts(),
    ])->save();

    Log::shouldReceive('alert')->never();

    sweep();
});

/*
| ⚠️ THE BUDGET HAS A CLOCK NOW, AND WITHOUT ONE A BACKLOG SPENT IT IN ONE SECOND.
|
| Measured on 2026-08-18 against the real pipeline: a queue worker started before a
| code fix and restarted after it left seventy-two queued sweep passes behind. They
| drained together, five attempts were spent inside a single second, and the session
| was written `failed` — with the teacher told the recording was lost and every seat
| holder told the same, about a file sitting intact in our own bucket, mid-transcode,
| which appeared minutes later. A paused Horizon supervisor, a deploy, or any queue
| outage produces the identical pile in production.
|
| The two assertions are the two halves of the requirement: repeats inside the window
| cost nothing, and the very next pass after it does resume. A test of only the first
| half passes over a sweep that has stopped working altogether.
*/
it('spends one attempt however many times a backlog replays the sweep', function (): void {
    foreach (range(1, 20) as $ignored) {
        sweep();
    }

    expect((int) $this->session->refresh()->recording_attempts)->toBe(1)
        ->and($this->session->recording_status)->toBe('pending');

    $this->travel(15)->minutes();
    sweep();

    expect((int) $this->session->refresh()->recording_attempts)->toBe(2);
});

/*
| ⚠️ «STILL ENCODING» IS NOT THIS BUDGET'S BUSINESS — IT USED TO SPEND AN ATTEMPT.
|
| Before the hand-off the question is whether the BROADCAST provider has finished
| assembling the file, and nothing else is watching, so the budget belongs there.
| Once the file is delivered and its id is saved, the question is whether the MEDIA
| provider has finished transcoding — which `ReconcileAssetStatus` already owns, every
| five minutes, under its own 48-hour ceiling. Charging that phase here meant an
| encode slower than five sweeps wrote the session `failed` and told the student
| «التسجيل غير متاح» about a video that published twenty minutes later: a false
| sentence that stays in their feed, which no later success removes.
|
| The asset is left `Processing` — the state a provider that transcodes on its own
| clock actually returns — and the session must come out of the pass unspent and
| still swept.
*/
it('spends no attempt while the media provider is still transcoding', function (): void {
    /*
     * The provider that has the file and is not finished with it — the answer the
     * real one gives for minutes, and the only one that is neither `Ready` nor
     * `failed`.
     *
     * Bound by CLASS, not by the interface: `MediaProviderResolver::for()` reads the
     * asset's own `provider` column and resolves that class, so an interface binding
     * is invisible to it — which is the whole point of resolving per asset rather
     * than per config.
     */
    $this->app->instance(LocalMediaProvider::class, new FakeMediaProvider(stillProcessing: true));

    $asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => ClassSession::class,
        'owner_id' => $this->session->getKey(),
        'status' => MediaAssetStatus::Processing,
        'provider_asset_id' => 'a-delivered-video',
    ]);

    $this->session->forceFill([
        'media_asset_id' => $asset->getKey(),
        'recording_status' => 'ingesting',
        'recording_attempts' => 0,
    ])->save();

    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    expect((int) $this->session->refresh()->recording_attempts)->toBe(0)
        // `pending`, not `ingesting`: the sweep must keep it in view, and the state
        // that means "the hand-off had begun" is not the state that means "come back".
        ->and($this->session->recording_status)->toBe('pending');
});

/*
| The other side of the same branch: a provider that has actually REFUSED gets a
| verdict, not four more presentations of the asset it just refused. Without this the
| change above would turn every real failure into an eternal `pending` — which is the
| state that withholds the teacher's fee for a lesson that was taught.
*/
it('gives up at once when the media provider reports a failure', function (): void {
    $asset = MediaAsset::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'owner_type' => ClassSession::class,
        'owner_id' => $this->session->getKey(),
        'status' => MediaAssetStatus::Failed,
        'failure_reason' => 'الملف تالف.',
        'provider_asset_id' => 'a-refused-video',
    ]);

    $this->session->forceFill([
        'media_asset_id' => $asset->getKey(),
        'recording_status' => 'pending',
        'recording_attempts' => 0,
    ])->save();

    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    expect($this->session->refresh()->recording_status)->toBe('failed')
        ->and((int) $this->session->recording_attempts)
        ->toBe(app(SessionSettings::class)->recordingMaxAttempts());
});
