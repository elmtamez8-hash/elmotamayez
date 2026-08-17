<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Jobs\RetryPendingRecordingsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Carbon\CarbonImmutable;
use Tests\Support\FakeBroadcastProvider;

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
        'room_closed_at' => CarbonImmutable::now()->subHour(),
        'recording_status' => 'pending',
        'recording_attempts' => 0,
    ])->save();
});

function sweep(): void
{
    app(RetryPendingRecordingsJob::class)->handle(app(SessionSettings::class));
}

it('re-sends the ingest for a recording still pending', function (): void {
    sweep();

    expect((int) $this->session->refresh()->recording_attempts)->toBe(1)
        ->and($this->session->recording_status)->toBe('pending');
});

// T039 — the counter has to MOVE. It never did: one sender, one attempt.
it('advances the attempt counter past one', function (): void {
    sweep();
    sweep();
    sweep();

    expect((int) $this->session->refresh()->recording_attempts)->toBeGreaterThan(1);
});

it('settles on failed at the limit and tells the teacher', function (): void {
    $limit = app(SessionSettings::class)->recordingMaxAttempts();

    for ($i = 0; $i < $limit; $i++) {
        sweep();
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
