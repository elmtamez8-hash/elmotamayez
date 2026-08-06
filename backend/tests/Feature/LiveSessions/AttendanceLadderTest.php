<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Support\AttendanceLadder;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\PlatformSettings;
use Carbon\CarbonImmutable;

/*
| SC-020 — the ladder produces the right rung in all four positions.
|
| A 60-minute session with the seeded defaults:
|   grace          = 5 minutes  → Present up to T+5
|   threshold      = 50% = T+30 → Late between T+5 and T+30, Absent after
|   required stay  = 50% = 1800 seconds
|
| Tested against the pure class rather than through a room, because the rule is
| arithmetic and staging four live sessions to check arithmetic hides which of
| the two is broken when it fails.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    $this->start = CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC');
    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'starts_at' => $this->start,
        'ends_at' => $this->start->addHour(),
        'duration_minutes' => 60,
    ]);

    $this->ladder = app(AttendanceLadder::class);
});

it('marks a punctual student who stayed as present', function (): void {
    expect($this->ladder->statusFor($this->session, $this->start->addMinutes(2), 1800))
        ->toBe(AttendanceStatus::Present);
});

// On time for the register, gone before the lesson. Late is the honest middle.
it('marks a punctual student who left early as late', function (): void {
    expect($this->ladder->statusFor($this->session, $this->start->addMinutes(2), 120))
        ->toBe(AttendanceStatus::Late);
});

it('marks arrival after grace but before the threshold as late', function (): void {
    expect($this->ladder->statusFor($this->session, $this->start->addMinutes(20), 1800))
        ->toBe(AttendanceStatus::Late);
});

it('marks a seat with no arrival as absent', function (): void {
    expect($this->ladder->statusFor($this->session, null, 0))
        ->toBe(AttendanceStatus::Absent);
});

// FR-021ج — arriving after the threshold does not flip the mark back. Letting it
// would make the threshold mean nothing at all.
it('keeps a student who arrived after the threshold absent', function (): void {
    expect($this->ladder->statusFor($this->session, $this->start->addMinutes(45), 900))
        ->toBe(AttendanceStatus::Absent);
});

// The thresholds are settings, not constants (FR-021أ). Changing the grace
// period must move the boundary without a deploy.
it('follows the configured grace period rather than a hard-coded one', function (): void {
    PlatformSettings::set('sessions.grace_minutes', 30);

    expect($this->ladder->statusFor($this->session, $this->start->addMinutes(20), 1800))
        ->toBe(AttendanceStatus::Present);

    PlatformSettings::set('sessions.grace_minutes', 5);
});

it('measures the teacher stay against its own higher bar', function (): void {
    // 80% of 60 minutes = 2880 seconds.
    expect($this->ladder->teacherStayed($this->session, 2880))->toBeTrue()
        ->and($this->ladder->teacherStayed($this->session, 1800))->toBeFalse();
});
