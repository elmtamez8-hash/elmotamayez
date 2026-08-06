<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CreateFreezePeriod;
use App\Modules\LiveSessions\Jobs\FreezeBillableSeatsJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
| FR-042 — a freeze must not move a due date or an existing financial obligation.
|
| Written now, before billing exists, for the same reason
| AttendanceHasNoFinancialEffectTest was: spec 006 will READ freeze periods, and
| a rule that lives only in prose is a rule the phase that consumes it reads
| differently. The proof available today is that `billable_seats` — the one
| settled financial fact this phase produces (FR-060) — does not move when a
| period is declared over a session whose seats were already frozen.
|
| A seat frozen before the holiday was declared stays frozen at its value: it
| answers a question about a moment that has passed, and a holiday declared
| afterwards cannot change what was true then.
*/

beforeEach(function (): void {
    Queue::fake();

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);
});

it('does not rewrite a seat count that was already settled', function (): void {
    $startsAt = CarbonImmutable::now()->addDays(3)->startOfDay()->setHour(10);

    $session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'duration_minutes' => 60,
        'seats_total' => 4,
    ]);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($session->refresh(), $student);

    // The deadline passes and the figure is settled.
    (new FreezeBillableSeatsJob((int) $session->getKey()))->handle(app(WorkspaceContext::class));

    $settled = $session->refresh()->billable_seats;
    $frozenAt = $session->seats_frozen_at;

    app(CreateFreezePeriod::class)->handle(
        $this->owner,
        $startsAt->startOfDay(),
        $startsAt->addDays(2),
        null,
        'إجازة طارئة',
    );

    expect($session->refresh()->billable_seats)->toBe($settled)
        ->and($settled)->toBe(1)
        ->and($session->seats_frozen_at?->toIso8601String())->toBe($frozenAt?->toIso8601String());
});
