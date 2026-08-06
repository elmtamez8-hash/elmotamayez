<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;

/*
| FR-024 — one stay, measured in wall-clock seconds.
|
| Everything here follows from a single line in RecordPresencePing:
|
|     stay_seconds += min(now − last_ping_at, 2 × interval)
|
| The interval is 30s by default, so the cap is 60s.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->session = ClassSession::factory()->live()->create(['teacher_profile_id' => $teacher->getKey()]);
    $this->student = User::factory()->create();
});

it('credits nothing for the first ping', function (): void {
    $attendance = app(RecordPresencePing::class)->handle($this->session, $this->student);

    // No time has been spent yet. Crediting the interval up front would pay for
    // attendance that has not happened.
    expect($attendance->stay_seconds)->toBe(0)
        ->and($attendance->first_joined_at)->not->toBeNull();
});

it('accumulates the seconds between pings', function (): void {
    $start = CarbonImmutable::now();

    CarbonImmutable::setTestNow($start);
    app(RecordPresencePing::class)->handle($this->session, $this->student);

    CarbonImmutable::setTestNow($start->addSeconds(30));
    app(RecordPresencePing::class)->handle($this->session, $this->student);

    CarbonImmutable::setTestNow($start->addSeconds(60));
    $attendance = app(RecordPresencePing::class)->handle($this->session, $this->student);

    expect($attendance->stay_seconds)->toBe(60);

    CarbonImmutable::setTestNow();
});

/*
| The headline property: two devices at once do NOT double the time.
|
| Each ping measures from the last ping by ANY device, so however many machines
| the same person has open, the sum is the wall clock.
*/
it('does not double the stay when the same student pings from two devices', function (): void {
    $start = CarbonImmutable::now();

    CarbonImmutable::setTestNow($start);
    app(RecordPresencePing::class)->handle($this->session, $this->student);

    // Two devices, alternating every 15 seconds for a minute — four pings for
    // 60 seconds of real time.
    foreach ([15, 30, 45, 60] as $offset) {
        CarbonImmutable::setTestNow($start->addSeconds($offset));
        app(RecordPresencePing::class)->handle($this->session, $this->student);
    }

    $attendance = app(RecordPresencePing::class)->handle($this->session, $this->student);

    expect($attendance->stay_seconds)->toBe(60);

    CarbonImmutable::setTestNow();
});

it('aggregates a return into the same stay rather than a second row', function (): void {
    $start = CarbonImmutable::now();

    CarbonImmutable::setTestNow($start);
    app(RecordPresencePing::class)->handle($this->session, $this->student);

    CarbonImmutable::setTestNow($start->addSeconds(30));
    app(RecordPresencePing::class)->handle($this->session, $this->student);

    // Away for ten minutes, then back.
    CarbonImmutable::setTestNow($start->addMinutes(10));
    $attendance = app(RecordPresencePing::class)->handle($this->session, $this->student);

    expect($this->session->attendances()->count())->toBe(1)
        // 30 seconds present, then the gap capped at two intervals — the ten
        // minutes away are not attendance.
        ->and($attendance->stay_seconds)->toBe(90);

    CarbonImmutable::setTestNow();
});

it('caps a long silence at two intervals', function (): void {
    $start = CarbonImmutable::now();

    CarbonImmutable::setTestNow($start);
    app(RecordPresencePing::class)->handle($this->session, $this->student);

    CarbonImmutable::setTestNow($start->addHour());
    $attendance = app(RecordPresencePing::class)->handle($this->session, $this->student);

    expect($attendance->stay_seconds)->toBe(60);

    CarbonImmutable::setTestNow();
});
