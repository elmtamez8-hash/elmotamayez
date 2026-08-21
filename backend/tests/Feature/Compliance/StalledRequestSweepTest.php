<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Compliance\Actions\CreateDataRequest;
use App\Modules\Compliance\Enums\DataRequestStatus;
use App\Modules\Compliance\Enums\DataRequestType;
use App\Modules\Compliance\Jobs\FulfilDataRequestJob;
use App\Modules\Compliance\Jobs\PruneExpiredExportsJob;
use App\Modules\Compliance\Jobs\RetryStalledDataRequestsJob;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Support\ComplianceSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * SC-021 — zero requests stuck in `processing` past a declared timeout.
 *
 * ⚠️ THE JOB RUNS WITH `tries: 1` AND NOTHING ELSE SWEEPS THIS STATE. So a worker
 * killed mid-export — a deploy, an OOM, a restart — leaves the request `processing`
 * for ever: `due_at` passes, a legal deadline is missed, and nobody is told,
 * because nothing is broken enough to log. That is the
 * `recording_status = 'ingesting'` family exactly — a state written just before a
 * killable call, and a sweep that asked about a different value.
 *
 * ⚠️ AND THE SWEEP MUST RESET BEFORE IT RE-DISPATCHES. `FulfilDataRequestJob`
 * claims with `WHERE status = 'pending'`, so re-sending a request still marked
 * `processing` queues a job that refuses itself on arrival — for ever, every ten
 * minutes, a sweep that reports success and moves nothing. The second assertion
 * below is the one that fails if the reset is dropped.
 */
function stalledRequestFor(User $subject, int $minutesAgo): DataRequest
{
    $request = app(CreateDataRequest::class)->handle($subject, (string) $subject->uuid, DataRequestType::Export);

    // The shape a killed worker leaves: claimed, stamped, never finished.
    $request->forceFill([
        'status' => DataRequestStatus::Processing->value,
        'last_attempt_at' => now()->subMinutes($minutesAgo),
    ])->save();

    return $request->refresh();
}

beforeEach(function (): void {
    Storage::fake('local');

    $this->subject = User::factory()->create();
});

it('returns a stalled request to pending and sends it again', function (): void {
    Queue::fake();

    $request = stalledRequestFor($this->subject, ComplianceSettings::stalledAfterMinutes() + 5);

    (new RetryStalledDataRequestsJob)->handle();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Pending);

    Queue::assertPushed(
        FulfilDataRequestJob::class,
        fn (FulfilDataRequestJob $job): bool => (new ReflectionClass($job))
            ->getProperty('dataRequestId')
            ->getValue($job) === (int) $request->getKey(),
    );
});

it('leaves a request that is merely slow alone', function (): void {
    Queue::fake();

    $request = stalledRequestFor($this->subject, 1);

    (new RetryStalledDataRequestsJob)->handle();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Processing);

    Queue::assertNothingPushed();
});

/*
 * ⚠️ AND THE RE-SENT JOB MUST ACTUALLY RUN. Asserting only that something was
 * pushed passes against a sweep that re-dispatches without resetting the status —
 * the queue fills, every job refuses itself on arrival, and the request is stuck
 * exactly as before with a busier queue. This runs the whole loop for real.
 */
it('completes the request end to end after the sweep', function (): void {
    $request = stalledRequestFor($this->subject, ComplianceSettings::stalledAfterMinutes() + 5);

    (new RetryStalledDataRequestsJob)->handle();

    expect($request->refresh()->status)->toBe(DataRequestStatus::Completed)
        ->and($request->export_path)->not->toBeNull();
});

/*
 * FR-018 — the archive does not outlive its link.
 *
 * ⚠️ THE FILE GOES AND THE REQUEST ROW STAYS. FR-026 wants a record that a request
 * was made and answered; what is deleted is the copy of the data, not the audit of
 * it. Without this job the archives accumulate for ever, and each one is everything
 * the platform knows about one person in a single object.
 */
it('deletes an expired archive and keeps the record of it', function (): void {
    $request = app(CreateDataRequest::class)->handle($this->subject, (string) $this->subject->uuid, DataRequestType::Export);

    FulfilDataRequestJob::dispatchSync((int) $request->getKey());

    $path = (string) $request->refresh()->export_path;

    expect(Storage::disk(ComplianceSettings::exportDisk())->exists($path))->toBeTrue();

    $request->forceFill(['export_expires_at' => now()->subHour()])->save();

    (new PruneExpiredExportsJob)->handle();

    expect(Storage::disk(ComplianceSettings::exportDisk())->exists($path))->toBeFalse()
        ->and($request->refresh()->export_path)->toBeNull()
        ->and(DataRequest::query()->whereKey($request->getKey())->exists())->toBeTrue()
        ->and($request->status)->toBe(DataRequestStatus::Completed);
});
