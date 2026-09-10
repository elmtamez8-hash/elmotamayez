<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\RecordingArtifact;
use App\Modules\LiveSessions\Enums\BookingStatus;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Jobs\IngestSessionRecordingJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BunnyFixtures;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-013 — A RECORDING THAT WILL NEVER ARRIVE TELLS THE PEOPLE WAITING FOR IT.
|
| ⚠️ MEASURED IN ROWS, NEVER IN CALLS, AND THAT IS THE ENTIRE POINT OF THIS FILE.
| TemplateRenderer refuses a missing or unapproved template and DispatchNotification
| LOGS rather than fails (003 FR-037) — so a notification with no template is
| dropped in silence. An assertion that the dispatch was CALLED is green over zero
| notifications delivered, which is the strongest possible false positive: the test
| passes precisely because the feature is broken in the way it was written to catch.
|
| ⚠️ AND WHY THE SEAT HOLDER IS HERE AT ALL: `failed` is the state that RELEASES the
| teacher's held fee — correctly, the lesson was taught. So by the time this runs,
| the last person with a financial reason to chase the recording has been paid and
| has stopped chasing. The seat holder is the only one still waiting, and their only
| recourse was to guess (research §R10).
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class]);
    Storage::fake('local');
    config(BunnyFixtures::config());

    // Refused outright, so this session reaches `failed` on the first pass.
    Http::fake(fn (Request $request) => Http::response(['message' => 'Unauthorized'], 401));

    $this->provider = new FakeBroadcastProvider;
    $this->provider->pendingRecording = new RecordingArtifact(
        downloadUrl: 'https://'.BunnyFixtures::SOURCE_HOST.'/recordings/session-abc.mp4',
        sizeBytes: 1_073_741_824,
        durationSeconds: 3600,
        mimeType: 'video/mp4',
    );
    $this->app->instance(BroadcastProviderInterface::class, $this->provider);

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

    // Two seats, so "one row per holder" is distinguishable from "one row".
    $this->students = collect([
        $this->addWorkspaceMember($this->workspace),
        $this->addWorkspaceMember($this->workspace),
    ]);

    foreach ($this->students as $student) {
        SessionBooking::factory()->create([
            'class_session_id' => $this->session->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => BookingStatus::Booked,
        ]);
    }
});

function rowsOfType(NotificationType $type): int
{
    return Notification::query()->withoutGlobalScopes()->where('type', $type->value)->count();
}

it('writes a notification row for the teacher, not merely a dispatch call', function (): void {
    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    expect($this->session->refresh()->recording_status)->toBe('failed')
        ->and(rowsOfType(NotificationType::SessionRecordingFailed))->toBe(1);
});

it('writes one row per seat holder', function (): void {
    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    // Two seats, two rows. Not one, and not zero.
    expect(rowsOfType(NotificationType::SessionRecordingUnavailable))->toBe(2);
});

// A separate type from the teacher's, deliberately: a preference switches a TYPE
// off, so one shared type would mean a student who muted this also muted the
// teacher's copy of it.
it('tells the seat holders in their own words, with no provider reason', function (): void {
    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    $body = (string) Notification::query()
        ->withoutGlobalScopes()
        ->where('type', NotificationType::SessionRecordingUnavailable->value)
        ->value('body');

    expect($body)->toContain($this->session->title)
        // The provider's own words name a system the student cannot reach.
        ->and($body)->not->toContain('Unauthorized');
});

/*
| ⚠️ THE PROOF THAT COUNTING ROWS IS NOT THE SAME AS COUNTING CALLS.
|
| Without this, the file above could be measuring nothing: every assertion would
| still pass if rows happened to appear for some other reason. Remove the template
| and the dispatch still runs, still logs, still returns — and writes NO ROW. That
| difference is the whole reason SC-013 is phrased the way it is.
*/
it('drops the notification in silence when its template is missing', function (): void {
    MessageTemplate::query()
        ->withoutGlobalScopes()
        ->where('type', NotificationType::SessionRecordingUnavailable->value)
        ->delete();

    app()->call([new IngestSessionRecordingJob((int) $this->session->getKey()), 'handle']);

    // Failed as before — the pipeline did not break — and not one row reached a
    // student. This is what an assertion on the CALL would have called success.
    expect($this->session->refresh()->recording_status)->toBe('failed')
        ->and(rowsOfType(NotificationType::SessionRecordingUnavailable))->toBe(0);
});
