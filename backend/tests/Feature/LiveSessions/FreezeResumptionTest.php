<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Actions\ScheduleClassSession;
use App\Modules\LiveSessions\Data\ScheduleSessionData;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\Marketplace\Models\TeacherProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| FR-043 — after the period ends the counters come back with the values they had,
| losing nothing.
|
| The interesting part of this file is how little it has to do. There is no
| resumption code in the module and nothing here calls one, because the freeze
| never wrote to a counter in the first place: it is READ by scheduling, booking
| and the counting jobs (research §R11). "Resuming" is the absence of an
| operation, not an operation that can fail halfway — and the way to prove that
| is to record the counters, let a period pass, and read them again.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create([
        'user_id' => $this->owner->getKey(),
        'completed_sessions_count' => 12,
        'cancelled_sessions_count' => 2,
        'attendance_rate' => 86,
    ]);
});

it('leaves every counter exactly as it found them', function (): void {
    $before = $this->teacher->only([
        'completed_sessions_count',
        'cancelled_sessions_count',
        'attendance_rate',
        'first_session_at',
    ]);

    $start = CarbonImmutable::now()->addDay()->startOfDay();

    app(CreateFreezePeriod::class)->handle(
        $this->owner,
        $start,
        $start->addDays(10),
        null,
        'إجازة',
    );

    // The whole period passes.
    $this->travelTo($start->addDays(11));

    expect($this->teacher->refresh()->only(array_keys($before)))->toBe($before);
});

it('takes new sessions again the day the period ends', function (): void {
    $start = CarbonImmutable::now()->addDay()->startOfDay();
    $end = $start->addDays(7);

    app(CreateFreezePeriod::class)->handle($this->owner, $start, $end, null, 'إجازة');

    $session = app(ScheduleClassSession::class)->handle(
        new ScheduleSessionData(
            teacherProfileId: (int) $this->teacher->getKey(),
            title: 'حصة بعد الإجازة',
            type: ClassSessionType::Individual,
            startsAt: $end->addDay()->setHour(10),
            durationMinutes: 60,
            seatsTotal: 1,
        ),
        $this->owner,
    );

    expect($session)->toBeInstanceOf(ClassSession::class);
});

// Lifting a freeze early is deleting the row, and that is the whole operation.
it('needs no undo when a period is lifted', function (): void {
    $start = CarbonImmutable::now()->addDay()->startOfDay();

    $result = app(CreateFreezePeriod::class)->handle($this->owner, $start, $start->addDays(3), null, 'إجازة');

    $result['period']->delete();

    expect(FreezePeriod::query()->count())->toBe(0)
        ->and($this->teacher->refresh()->completed_sessions_count)->toBe(12);
});
