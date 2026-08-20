<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Events\MistakeResolved;
use App\Modules\Gamification\Models\AwardEntry;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Events\AttendanceConfirmed;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Illuminate\Support\Facades\Queue;

/**
 * The events are actually wired (NFR-003).
 *
 * ⚠️ `Queue::fake()` WITH NO ARGUMENTS IS THE TRAP THIS FILE EXISTS AROUND. Every
 * listener here is `ShouldQueue`, so a bare fake swallows all of them — and then
 * "the event fired, therefore the award exists" becomes a confident assertion
 * about an empty table. Fake the specific jobs a test needs to hold back, and let
 * the awards run on `sync`.
 *
 * ⚠️ AND CALLING THE ACTION DIRECTLY WOULD PROVE NOTHING HERE. AwardPoints has its
 * own tests; what is under test on this page is the WIRING — that the listener is
 * registered, receives the event, and translates it to the right action key.
 */
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->student = User::factory()->create();
});

it('awards when the real event is dispatched', function (): void {
    event(new MistakeResolved(
        studentId: (int) $this->student->getKey(),
        questionId: 42,
        workspaceId: (int) $this->workspace->getKey(),
    ));

    expect(AwardEntry::query()
        ->where('student_user_id', $this->student->getKey())
        ->where('action_key', 'mistake_resolved')
        ->count())->toBe(1);
});

/*
 * The trap, written down as a test so nobody has to rediscover it.
 *
 * This is not asserting a requirement — it is pinning the reason every other
 * assertion in this file is written the way it is. Under a bare fake the award
 * never runs, and an author who reached for one would conclude the feature was
 * broken, or worse, would "fix" the assertion to expect zero.
 */
it('is swallowed entirely by a bare Queue::fake(), which is why nothing uses one', function (): void {
    Queue::fake();

    event(new MistakeResolved(
        studentId: (int) $this->student->getKey(),
        questionId: 42,
        workspaceId: (int) $this->workspace->getKey(),
    ));

    expect(AwardEntry::query()->count())->toBe(0);
});

/*
 * ⚠️ THE HOST DOES NOT EARN ATTENDANCE.
 *
 * The teacher has an attendance row on purpose — CloseClassSession judges
 * delivery, and therefore their pay, from it. Award from it and the teacher
 * collects experience for every lesson they teach and sits permanently at the top
 * of their own students' board. The exclusion lives in the contract, so the
 * register and the session report read the same rule.
 */
it('awards the attendees and never the host', function (): void {
    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $profile->getKey(),
    ]);

    attendanceRow($this->workspace, $session, $this->student, AttendanceStatus::Present);
    // The host's own row, exactly as CloseClassSession writes it.
    attendanceRow($this->workspace, $session, $this->owner, AttendanceStatus::Present);

    event(new AttendanceConfirmed($session));

    expect(AwardEntry::query()->where('action_key', 'session_attended')->count())->toBe(1)
        ->and(AwardEntry::query()->where('student_user_id', $this->student->getKey())->exists())->toBeTrue()
        ->and(AwardEntry::query()->where('student_user_id', $this->owner->getKey())->exists())->toBeFalse();
});

it('does not award an absent student', function (): void {
    $profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);

    $session = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => $profile->getKey(),
    ]);

    attendanceRow($this->workspace, $session, $this->student, AttendanceStatus::Absent);

    event(new AttendanceConfirmed($session));

    expect(AwardEntry::query()->count())->toBe(0);
});
