<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| SC-012 and SC-019 — the four counters teacher_profiles has carried since spec
| 001 with nothing writing to them.
|
| The second assertion here is the one that matters most and reads as a
| contradiction until you know the rule: `attendance_rate` measures the
| TEACHER's attendance, not their students' (FR-062 · FR-063). A class where
| everybody stayed home must not move it by a point, or one unreliable student
| could drag down a profile the marketplace ranks on.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
});

/** @param 'delivered'|'missed'|'cancelled' $outcome */
function sessionWithOutcome(string $outcome, int $daysAgo): ClassSession
{
    $startsAt = CarbonImmutable::now()->subDays($daysAgo);

    return ClassSession::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'duration_minutes' => 60,
        'status' => $outcome === 'cancelled' ? ClassSessionStatus::Cancelled : ClassSessionStatus::Completed,
        'delivered_at' => $outcome === 'delivered' ? $startsAt->addHour() : null,
    ]);
}

it('counts delivered sessions and the teacher own attendance rate', function (): void {
    sessionWithOutcome('delivered', 10);
    sessionWithOutcome('delivered', 9);
    sessionWithOutcome('delivered', 8);
    sessionWithOutcome('missed', 7);

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    $profile = $this->teacher->refresh();

    expect($profile->completed_sessions_count)->toBe(3)
        // Three of four sessions taught.
        ->and($profile->attendance_rate)->toBe(75)
        ->and($profile->first_session_at)->not->toBeNull();
});

// FR-026 — a holiday is not a failure to teach, and must not enter either side
// of the ratio.
it('keeps cancelled sessions out of the rate', function (): void {
    sessionWithOutcome('delivered', 5);
    sessionWithOutcome('cancelled', 4);

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    $profile = $this->teacher->refresh();

    expect($profile->attendance_rate)->toBe(100)
        ->and($profile->cancelled_sessions_count)->toBe(1)
        ->and($profile->completed_sessions_count)->toBe(1);
});

// A teacher who has taught nothing has not failed to turn up. Null, not zero —
// and the marketplace filters on that distinction rather than comparing against
// a number that does not exist.
it('leaves the rate null with no countable sessions', function (): void {
    sessionWithOutcome('cancelled', 3);

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    expect($this->teacher->refresh()->attendance_rate)->toBeNull();
});

/*
| SC-019 — the headline: student absence does not touch the teacher's figures.
|
| Same two sessions, both delivered. In the second run every student is marked
| absent. The teacher's numbers must be byte-identical.
*/
it('does not move a single point when every student is absent', function (): void {
    $first = sessionWithOutcome('delivered', 6);
    $second = sessionWithOutcome('delivered', 5);

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    $before = $this->teacher->refresh()->only([
        'completed_sessions_count',
        'cancelled_sessions_count',
        'attendance_rate',
    ]);

    foreach ([$first, $second] as $session) {
        Attendance::factory()->count(5)->create([
            'class_session_id' => $session->getKey(),
            'workspace_id' => $this->workspace->getKey(),
        ]);
    }

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    expect($this->teacher->refresh()->only([
        'completed_sessions_count',
        'cancelled_sessions_count',
        'attendance_rate',
    ]))->toEqual($before);
});

// SC-012 — the aggregate matches the record after a long run, which is what
// makes storing it instead of deriving it safe (FR-027).
it('matches the session record after a hundred sessions', function (): void {
    foreach (range(1, 100) as $index) {
        sessionWithOutcome($index % 4 === 0 ? 'missed' : 'delivered', 200 - $index);
    }

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    $profile = $this->teacher->refresh();

    expect($profile->completed_sessions_count)->toBe(75)
        ->and($profile->attendance_rate)->toBe(75);
});
