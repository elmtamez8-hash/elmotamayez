<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Actions\UpdateClassSession;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| A moved session is owed a new seat count (conflicts audit C6, 2026-09-23).
|
| The freeze job was armed once, at scheduling, for the ORIGINAL deadline. Moved
| earlier, the session closed with `billable_seats` null — charged as «unknown»
| (every seat holder) and paid as an empty room (nothing). Moved later, the
| count froze days early and every booking after it went unbilled.
*/
beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addDays(20),
        'ends_at' => CarbonImmutable::now()->addDays(20)->addHour(),
        'duration_minutes' => 60,
    ]);
});

it('clears a frozen count and arms a freeze for the new time when a session moves', function (): void {
    Queue::fake([FreezeBillableSeatsJob::class]);
    $this->session->forceFill(['billable_seats' => 3, 'seats_frozen_at' => now()])->save();

    $moved = app(UpdateClassSession::class)->handle($this->session, [
        'starts_at' => CarbonImmutable::now()->addDays(5)->toIso8601String(),
    ]);

    expect($moved->billable_seats)->toBeNull()
        ->and($moved->seats_frozen_at)->toBeNull();

    Queue::assertPushed(
        FreezeBillableSeatsJob::class,
        fn (FreezeBillableSeatsJob $job): bool => $job->delay !== null
            && CarbonImmutable::instance($job->delay)->equalTo($moved->cancellationDeadline()),
    );
});

it('ignores the job armed for the start the session moved away from', function (): void {
    $originalStart = $this->session->starts_at->getTimestamp();

    $this->session->forceFill([
        'starts_at' => CarbonImmutable::now()->addDays(30),
        'ends_at' => CarbonImmutable::now()->addDays(30)->addHour(),
    ])->save();

    (new FreezeBillableSeatsJob((int) $this->session->getKey(), $originalStart))->handle(app(WorkspaceContext::class));

    expect($this->session->refresh()->seats_frozen_at)->toBeNull();
});

it('freezes when the job was armed for the start the session still has', function (): void {
    (new FreezeBillableSeatsJob((int) $this->session->getKey(), $this->session->starts_at->getTimestamp()))
        ->handle(app(WorkspaceContext::class));

    expect($this->session->refresh()->seats_frozen_at)->not->toBeNull();
});
