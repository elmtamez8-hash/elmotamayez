<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Support\SessionSettings;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| A freeze's days are the PLATFORM's days (Asia/Qatar, UTC+3), not UTC's.
|
| A teacher freezing «10 October» means the 10th on the calendar they read. At
| UTC midnight the range ran three hours late at both ends: a lesson at 01:30 on
| the 10th in Doha (22:30 UTC on the 9th) was left scheduled, and one at 01:30
| on the 11th in Doha (22:30 UTC on the 10th) was suspended. Both edges are
| asserted, because each fails on the old code in the opposite direction.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'));

    expect(app(SessionSettings::class)->timezone())->toBe('Asia/Qatar');

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->profile = TeacherProfile::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->owner->getKey(),
    ]);
});

function boundarySession(string $utc): ClassSession
{
    $test = test();
    $startsAt = CarbonImmutable::parse($utc, 'UTC');

    return app(WorkspaceContext::class)->forWorkspace($test->workspace, fn (): ClassSession => ClassSession::factory()->create([
        'workspace_id' => $test->workspace->getKey(),
        'teacher_profile_id' => $test->profile->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'duration_minutes' => 60,
    ]));
}

it('suspends a lesson at 01:30 Doha on the frozen day and keeps one at 01:30 Doha on the day after', function (): void {
    $onTheFrozenDay = boundarySession('2026-10-09 22:30:00');
    $onTheDayAfter = boundarySession('2026-10-10 22:30:00');

    app(CreateFreezePeriod::class)->handle(
        $this->owner,
        CarbonImmutable::parse('2026-10-10'),
        CarbonImmutable::parse('2026-10-10'),
    );

    expect($onTheFrozenDay->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        ->and($onTheDayAfter->refresh()->status)->toBe(ClassSessionStatus::Scheduled);
});

it('reads a moment as covered by the platform day it falls on', function (): void {
    app(WorkspaceContext::class)->forWorkspace($this->workspace, fn (): FreezePeriod => FreezePeriod::query()->create([
        'student_user_id' => null,
        'starts_on' => '2026-10-10',
        'ends_on' => '2026-10-10',
        'created_by' => $this->owner->getKey(),
    ]));

    expect(FreezePeriod::query()->covering(CarbonImmutable::parse('2026-10-09 22:30:00', 'UTC'))->exists())->toBeTrue()
        ->and(FreezePeriod::query()->covering(CarbonImmutable::parse('2026-10-10 22:30:00', 'UTC'))->exists())->toBeFalse();
});
