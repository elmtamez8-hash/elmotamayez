<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Media\Actions\CompleteMediaUpload;
use App\Modules\Media\Contracts\MediaProviderInterface;
use App\Modules\Media\Enums\MediaAssetStatus;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Notifications\Actions\DispatchNotification;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-007 — a finished session's recording becomes a protected lesson without a
| human opening an upload screen.
|
| Run against FakeBroadcastProvider, which declares recording: true, and against
| a faked HTTP client — NFR-011 forbids a real network call, and the fake is what
| makes this whole story provable before a broadcast contract exists. What that
| does NOT prove is written in the plan's Deferred Verification table rather than
| left for a reader to assume from a green tick.
*/

/** The smallest byte sequence finfo recognises as video/mp4. */
function mp4Bytes(): string
{
    return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 512);
}

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    // Real MP4 magic bytes, not the string "video". CompleteMediaUpload settles
    // the asset from what actually arrived rather than from what was declared,
    // so a placeholder body is correctly refused as not-a-video — which is the
    // pipeline working, and would make this test prove the opposite of what it
    // is for.
    Http::fake(['*' => Http::response(mp4Bytes(), 200)]);

    $this->provider = new FakeBroadcastProvider;
    $this->app->instance(BroadcastProviderInterface::class, $this->provider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    // Prepaid is the default, so a seat has to be paid for before it can be
    // taken. The subject of this file is not money; the funding is fixture.
    fundBooking($this->workspace, $this->student, $this->course);

    app(BookSeat::class)->handle($this->session, $this->student);
});

function ingest(): void
{
    app(IngestSessionRecordingJob::class, ['classSessionId' => (int) test()->session->getKey()])
        ->handle(
            app(WorkspaceContext::class),
            app(BroadcastProviderInterface::class),
            app(MediaProviderInterface::class),
            app(SessionSettings::class),
            app(CompleteMediaUpload::class),
            app(DispatchNotification::class),
        );
}

it('publishes a ready recording as a lesson tied to the session', function (): void {
    $this->provider->pendingRecording = new RecordingArtifact(
        downloadUrl: 'https://fake.test/recordings/1.mp4',
        sizeBytes: 1024,
        durationSeconds: 3600,
        mimeType: 'video/mp4',
    );

    ingest();

    expect([
        'status' => $this->session->refresh()->recording_status,
        'attempts' => $this->session->recording_attempts,
        'assets' => MediaAsset::query()->withoutWorkspaceScope()->count(),
    ])->toBe(['status' => 'published', 'attempts' => 0, 'assets' => 1]);

    $lesson = Lesson::query()->where('class_session_id', $this->session->getKey())->first();

    expect($lesson)->not->toBeNull()
        ->and($lesson->type)->toBe('video')
        // Never free and never preview: both flags bypass entitlement entirely,
        // and this answers to a seat.
        ->and($lesson->is_free)->toBeFalse()
        ->and($lesson->is_preview)->toBeFalse()
        ->and($this->session->refresh()->recording_status)->toBe('published');

    $asset = MediaAsset::query()->where('owner_id', $lesson->getKey())->first();

    expect($asset)->not->toBeNull()
        ->and($asset->status)->toBe(MediaAssetStatus::Ready)
        ->and($asset->owner_type)->toBe(Lesson::class);
});

// "Not ready yet" is the expected answer the first time, not a failure to report.
it('waits quietly while the recording is still being assembled', function (): void {
    $this->provider->pendingRecording = null;

    ingest();

    expect(Lesson::query()->where('class_session_id', $this->session->getKey())->count())->toBe(0)
        ->and($this->session->refresh()->recording_status)->toBe('pending')
        ->and($this->session->recording_attempts)->toBe(1);
});

// FR-031 — after the limit the teacher hears about it, and manual upload stays
// open. A pipeline that gives up quietly leaves a class waiting for a video
// nobody knows is not coming.
it('tells the teacher once it has given up', function (): void {
    $this->provider->pendingRecording = null;

    foreach (range(1, 5) as $ignored) {
        ingest();
    }

    expect($this->session->refresh()->recording_status)->toBe('failed')
        ->and(Notification::query()->where('type', 'session_recording_failed')->count())->toBe(1);
});

it('does not chase a recording from a provider that cannot record', function (): void {
    $this->app->instance(
        BroadcastProviderInterface::class,
        new NullBroadcastProvider,
    );

    ingest();

    // No attempt is counted: there is nothing to wait for, so nothing has failed.
    expect($this->session->refresh()->recording_attempts)->toBe(0)
        ->and($this->session->recording_status)->toBeNull();
});

// Re-ingesting must not leave the class with two copies of the same hour.
it('updates the same lesson when a recording is ingested twice', function (): void {
    $this->provider->pendingRecording = new RecordingArtifact(
        downloadUrl: 'https://fake.test/recordings/1.mp4',
        sizeBytes: 1024,
        durationSeconds: 3600,
        mimeType: 'video/mp4',
    );

    ingest();
    $this->session->refresh()->forceFill(['recording_status' => 'pending'])->save();
    ingest();

    expect(Lesson::query()->where('class_session_id', $this->session->getKey())->count())->toBe(1);
});
