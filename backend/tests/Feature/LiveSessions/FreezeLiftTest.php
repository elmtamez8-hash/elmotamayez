<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\DeleteFreezePeriod;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ `Suspended` HAD NO WAY OUT.
|
| Deleting a freeze period removed the row and nothing else, so every session it
| had suspended stayed suspended for ever: unbookable, invisible to the clash
| check, and announced to its students as cancelled. Lifting a freeze now hands
| its still-future sessions back to the calendar — without their seats, which
| were released and whose holders were told — and reports the ones that cannot
| come back.
*/

beforeEach(function (): void {
    // Only the seat-count job: a delay runs immediately on `sync`, and the
    // assertion below is that it is ARMED, not what it would compute.
    Queue::fake([FreezeBillableSeatsJob::class]);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    $this->liftStart = CarbonImmutable::now()->addDays(5)->startOfDay();
});

function liftableSession(CarbonImmutable $startsAt): ClassSession
{
    return ClassSession::factory()->create([
        'teacher_profile_id' => test()->teacher->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
    ]);
}

function declareLiftableFreeze(CarbonImmutable $from, CarbonImmutable $to): FreezePeriod
{
    return app(CreateFreezePeriod::class)->handle(test()->owner, $from, $to, null, 'إجازة')['period'];
}

it('returns the future sessions of a lifted freeze to the calendar', function (): void {
    $session = liftableSession($this->liftStart->addDay()->setHour(10));

    $period = declareLiftableFreeze($this->liftStart, $this->liftStart->addDays(3));

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Suspended);

    $result = app(DeleteFreezePeriod::class)->handle($period);

    expect(FreezePeriod::query()->count())->toBe(0)
        ->and($session->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($session->seats_taken)->toBe(0)
        ->and($result['restored'])->toHaveCount(1)
        ->and($result['kept'])->toBe([]);

    // A revived session is owed a fresh seat count (the job does not skip a
    // suspended session, so the old count may already be a frozen zero).
    Queue::assertPushed(FreezeBillableSeatsJob::class);
});

it('clears a seat count frozen while the session was suspended', function (): void {
    $session = liftableSession($this->liftStart->addDay()->setHour(10));
    $period = declareLiftableFreeze($this->liftStart, $this->liftStart->addDays(3));

    // What `FreezeBillableSeatsJob` writes when it reaches a suspended session
    // past its cancellation deadline.
    $session->refresh()->forceFill([
        'billable_seats' => 0,
        'seats_frozen_at' => now(),
        'interruption_note' => 'zero_attendance',
    ])->save();

    app(DeleteFreezePeriod::class)->handle($period);

    expect($session->refresh()->billable_seats)->toBeNull()
        ->and($session->seats_frozen_at)->toBeNull()
        ->and($session->interruption_note)->toBeNull();
});

it('does not reopen a session that has already started', function (): void {
    $period = declareLiftableFreeze(CarbonImmutable::now()->subDay()->startOfDay(), $this->liftStart);

    // Suspended by the freeze, and the hour has since passed.
    $past = liftableSession(CarbonImmutable::now()->subHours(3));
    $past->forceFill(['status' => ClassSessionStatus::Suspended])->save();

    $result = app(DeleteFreezePeriod::class)->handle($period);

    expect($past->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        ->and($result['restored'])->toBe([]);
});

it('keeps a session another freeze still covers', function (): void {
    $session = liftableSession($this->liftStart->addDay()->setHour(10));

    $lifted = declareLiftableFreeze($this->liftStart, $this->liftStart->addDays(3));
    declareLiftableFreeze($this->liftStart->addDay(), $this->liftStart->addDays(2));

    $result = app(DeleteFreezePeriod::class)->handle($lifted);

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        ->and($result['restored'])->toBe([])
        ->and($result['kept'])->toHaveCount(1);
});

// The hour looked free while the session sat suspended — `SessionClash` ignores
// a suspended session — so the teacher (or an academy scheduling for them) may
// have filled it. Reviving on top of that would put them in two rooms at once.
it('keeps a session whose hour has been filled since, and says so', function (): void {
    $startsAt = $this->liftStart->addDay()->setHour(10);
    $session = liftableSession($startsAt);

    $period = declareLiftableFreeze($this->liftStart, $this->liftStart->addDays(3));

    $filler = liftableSession($startsAt->addMinutes(30));

    $result = app(DeleteFreezePeriod::class)->handle($period);

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Suspended)
        ->and($filler->refresh()->status)->toBe(ClassSessionStatus::Scheduled)
        ->and($result['restored'])->toBe([])
        ->and(array_map(fn (ClassSession $kept): int => (int) $kept->getKey(), $result['kept']))
        ->toBe([(int) $session->getKey()]);
});

it('lifts a freeze over HTTP and lists what came back', function (): void {
    $session = liftableSession($this->liftStart->addDay()->setHour(10));
    $period = declareLiftableFreeze($this->liftStart, $this->liftStart->addDays(3));

    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/v1/freeze-periods/{$period->uuid}")
        ->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('restored.0.uuid', $session->uuid)
        ->assertJsonCount(0, 'kept_suspended');

    expect($session->refresh()->status)->toBe(ClassSessionStatus::Scheduled);
});
