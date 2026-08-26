<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\ReleasePendingUnits;
use App\Modules\Settlement\Enums\TeachingUnitStatus;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Tests\Support\FakeBroadcastProvider;

/*
| SC-005هـ — the unit waits for the package, is released automatically, and is
| never held hostage to a failure that was not the teacher's.
|
| The seat earns because the ABSENT student still receives the recording, the
| files and the homework (Q2ج). So the earning has a premise, and until the
| premise holds the unit is not yet money. What makes this fair rather than
| punitive is that the release needs no human: nobody has to remember to look.
*/

beforeEach(function (): void {
    fakeSessionTimeline();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    SettlementRate::factory()->create(['teacher_profile_id' => $this->teacher->getKey()]);

    $this->session = ClassSession::factory()->individual()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
    ]);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    // Prepaid is the default, so a seat has to be paid for before it can be
    // taken. The subject of this file is not money; the funding is fixture.
    fundBooking($this->workspace, $student, $this->course);
    app(BookSeat::class)->handle($this->session->refresh(), $student);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();
});

/** Delivers the session, which is what puts a unit on the books at all. */
function deliver(): void
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

it('holds the unit while the recording is still on its way', function (): void {
    deliver();

    $unit = TeachingUnit::query()->first();

    // Null status too, not just "pending": that is the state between the session
    // closing and the ingest job starting, and treating it as "nothing expected"
    // would release every unit on the spot and make this whole mechanism
    // decorative.
    expect($unit->status)->toBe(TeachingUnitStatus::PendingPackage)
        ->and($unit->pending_reason)->not->toBeNull()
        ->and($unit->accrued_at)->toBeNull();
});

it('releases the unit automatically once the recording is published', function (): void {
    deliver();

    $this->session->refresh()->forceFill(['recording_status' => 'published'])->save();

    app(ReleasePendingUnits::class)->handle($this->session->refresh());

    $unit = TeachingUnit::query()->first();

    // No review step, by design (FR-008ب): a teacher's pay must not depend on
    // somebody remembering to look at a fact the system already holds.
    expect($unit->fresh()->status)->toBe(TeachingUnitStatus::Accrued)
        ->and($unit->fresh()->pending_reason)->toBeNull()
        ->and($unit->fresh()->accrued_at)->not->toBeNull()
        ->and($unit->fresh()->recording_fault)->toBeFalse();
});

it('releases the unit and marks the fault when the provider lost the recording', function (): void {
    deliver();

    $this->session->refresh()->forceFill(['recording_status' => 'failed'])->save();

    app(ReleasePendingUnits::class)->handle($this->session->refresh());

    $unit = TeachingUnit::query()->first()->fresh();

    // FR-008ج — the teacher taught the hour. A provider outage is the platform's
    // problem, and withholding pay for it would make the teacher the insurer of
    // infrastructure they do not control. Recorded rather than hidden, because a
    // provider failing often is something somebody should be able to count.
    expect($unit->status)->toBe(TeachingUnitStatus::Accrued)
        ->and($unit->recording_fault)->toBeTrue();
});

it('never holds a unit when the bound provider cannot record at all', function (): void {
    // The null provider declares recording: false. There is no file coming, ever
    // — so there is nothing to wait for, and waiting would be an indefinite
    // deduction for a capability the platform never bought.
    $this->app->instance(BroadcastProviderInterface::class, new NullBroadcastProvider);

    deliver();

    $unit = TeachingUnit::query()->first();

    expect($unit->status)->toBe(TeachingUnitStatus::Accrued)
        ->and($unit->accrued_at)->not->toBeNull();
});
