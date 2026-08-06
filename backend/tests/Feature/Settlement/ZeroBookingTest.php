<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Enums\SettlementBasis;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-005و — a session nobody booked.
|
| Zero earning by default (FR-008هـ), because zero seats is a suspicious state
| whatever else is true: a mis-scheduled slot, a test run, or an attempt to route
| a student around the platform. Paying for it silently is how the last of those
| becomes a habit.
|
| The switch exists because a teacher who turned up and delivered the package may
| still deserve something — and either way the session is flagged for a human to
| look at, before AND after the money decision (FR-008ح).
*/

beforeEach(function (): void {
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);

    SettlementRate::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 5000,
    ]);

    // Nobody booked. billable_seats froze at zero, which is the honest record of
    // an empty room rather than an absent value.
    $this->session = ClassSession::factory()->individual()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'billable_seats' => 0,
    ]);
});

/** The teacher turns up and teaches to an empty room, which still counts as delivery. */
function taughtToNobody(): void
{
    $test = test();

    app(OpenBroadcastRoom::class)->handle($test->session->refresh());
    app(RecordPresencePing::class)->handle($test->session, $test->owner);

    Attendance::query()
        ->where('class_session_id', $test->session->getKey())
        ->where('student_user_id', $test->owner->getKey())
        ->update(['stay_seconds' => 3000]);

    app(CloseClassSession::class)->handle($test->session->refresh());
}

it('earns nothing for a session nobody booked', function (): void {
    taughtToNobody();

    expect(TeachingUnit::query()->count())->toBe(0);
});

it('pays the configured compensation when an operator turns it on', function (): void {
    PlatformSettings::set('settlement.zero_attendance_compensation_enabled', true);
    PlatformSettings::set('settlement.zero_attendance_compensation_percent', 40);

    taughtToNobody();

    $unit = TeachingUnit::query()->first();

    expect($unit)->not->toBeNull()
        // 40% of one seat's 5000.
        ->and($unit->amount_minor)->toBe(2000)
        ->and($unit->basis)->toBe(SettlementBasis::ZeroAttendanceCompensation)
        // Flagged even though it was paid: the payment decision does not answer
        // the question of why the room was empty (FR-008ح).
        ->and($unit->needs_review)->toBeTrue();
});

it('pays nothing for an empty session the teacher never delivered', function (): void {
    PlatformSettings::set('settlement.zero_attendance_compensation_enabled', true);
    PlatformSettings::set('settlement.zero_attendance_compensation_percent', 100);

    // Room opened, teacher never joined. FR-008ز — compensation is for delivering
    // the package to an empty room, not for scheduling one.
    app(OpenBroadcastRoom::class)->handle($this->session->refresh());
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect(TeachingUnit::query()->count())->toBe(0);
});
