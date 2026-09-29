<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\FreezePeriod;
use App\Modules\LiveSessions\Models\PrivateSessionRequest;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\LiveSessions\Models\SessionRescheduleRequest;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeBroadcastProvider;

/*
| Spec 010 · FR-005 on a CLASS SESSION — every staff door of `ClassSessionPolicy`
| (view · create · update/cancel · host), the rows that hang off a session
| (a booking, a private-hour request, a reschedule request), the freeze, and the
| three staff lists (the calendar, both request queues).
|
| ⛔ Until 2026-09-29 a confined assistant holding `sessions.host` /
| `sessions.manage` hosted, moved, cancelled and scheduled the sessions of every
| course in the workspace.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST — «far is refused» alone is green against an
| assistant refused everything; «near is allowed» beside it is what makes the
| refusal mean «outside your scope». A session with NO course is refused to a
| confined assistant, like an exam set for no course.
*/

beforeEach(function (): void {
    Queue::fake();
    $this->app->instance(BroadcastProviderInterface::class, new FakeBroadcastProvider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearSession = billableSession($this->workspace, $this->owner, $this->near);
    $this->farSession = billableSession($this->workspace, $this->owner, $this->far);
    $this->courseless = ClassSession::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'teacher_profile_id' => TeacherProfile::query()->where('user_id', $this->owner->getKey())->value('id'),
        'course_id' => null,
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);

    // The assistant role carries neither; the owner ticks them on.
    $this->assistant->givePermissionTo([
        Permissions::SESSIONS_HOST,
        Permissions::SESSIONS_MANAGE,
        Permissions::FREEZE_MANAGE,
        Permissions::ATTENDANCE_VIEW,
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->assistant->unsetRelation('permissions');
});

function confineSessionAssistantTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

/** @return list<string> the uuids `/class-sessions` answers the caller */
function listedSessionUuids(): array
{
    return collect(test()->getJson('/api/v1/class-sessions')->assertOk()->json('data'))
        ->pluck('uuid')
        ->all();
}

/** @return array<string, bool> every session ability, for one session */
function sessionAbilities(User $user, ClassSession $session): array
{
    $gate = Gate::forUser($user);

    return [
        'view' => $gate->allows('view', $session),
        'update' => $gate->allows('update', $session),
        'cancel' => $gate->allows('cancel', $session),
        'host' => $gate->allows('host', $session),
    ];
}

it('lets a confined assistant host and manage their own course\'s session and refuses every other', function (): void {
    confineSessionAssistantTo($this->near);

    expect(sessionAbilities($this->assistant, $this->nearSession))->each->toBeTrue();

    foreach (['far' => $this->farSession, 'course-less' => $this->courseless] as $label => $session) {
        foreach (sessionAbilities($this->assistant, $session) as $ability => $allowed) {
            expect($allowed)->toBeFalse("{$label} {$ability}");
        }
    }

    Sanctum::actingAs($this->assistant);

    // The room: the join ticket as host, and the host controls.
    $this->postJson("/api/v1/class-sessions/{$this->nearSession->uuid}/join")
        ->assertOk()
        ->assertJsonPath('role', 'host');
    $this->postJson("/api/v1/class-sessions/{$this->nearSession->uuid}/host/mute", ['target_uuid' => $this->owner->uuid])
        ->assertOk();

    foreach ([$this->farSession, $this->courseless] as $session) {
        // Not a host there, and holding no seat — the door's uniform refusal.
        $this->postJson("/api/v1/class-sessions/{$session->uuid}/join")->assertForbidden();
        $this->postJson("/api/v1/class-sessions/{$session->uuid}/host/mute", ['target_uuid' => $this->owner->uuid])
            ->assertForbidden();
        $this->getJson("/api/v1/class-sessions/{$session->uuid}")->assertForbidden();
        $this->getJson("/api/v1/class-sessions/{$session->uuid}/attendance")->assertForbidden();
        $this->putJson("/api/v1/class-sessions/{$session->uuid}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/class-sessions/{$session->uuid}/cancel")->assertForbidden();
    }

    $this->getJson("/api/v1/class-sessions/{$this->nearSession->uuid}")->assertOk();
    $this->getJson("/api/v1/class-sessions/{$this->nearSession->uuid}/attendance")->assertOk();
    // The room above made it live, so the Action refuses the cancel — past the
    // policy, which is the point.
    expect($this->postJson("/api/v1/class-sessions/{$this->nearSession->uuid}/cancel")->status())->toBe(422);

    expect($this->farSession->refresh()->status->isTerminal())->toBeFalse();
});

it('schedules in a confined assistant\'s own courses only', function (): void {
    confineSessionAssistantTo($this->near);

    $gate = Gate::forUser($this->assistant);

    expect($gate->allows('create', [ClassSession::class, $this->near]))->toBeTrue()
        ->and($gate->allows('create', [ClassSession::class, $this->far]))->toBeFalse()
        // No course named: a session is never scheduled outside one.
        ->and($gate->allows('create', ClassSession::class))->toBeFalse();

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/manage/courses/{$this->near->uuid}/unassigned-sessions")->assertOk();
    $this->getJson("/api/v1/manage/courses/{$this->far->uuid}/unassigned-sessions")->assertForbidden();
    $this->postJson("/api/v1/manage/courses/{$this->far->uuid}/assign-sessions", [
        'cohort_uuid' => 'x',
        'session_uuids' => [$this->farSession->uuid],
    ])->assertForbidden();
    $this->postJson('/api/v1/class-sessions', [
        'course_uuid' => $this->far->uuid,
        'title' => 'حصة',
        'type' => 'individual',
        'starts_at' => CarbonImmutable::now()->addDays(3)->toIso8601String(),
        'duration_minutes' => 60,
        'seats_total' => 1,
    ])->assertForbidden();
});

it('lists a confined assistant\'s own courses\' sessions and everyone else\'s whole calendar', function (): void {
    [$otherWorkspace, $otherOwner] = $this->createWorkspaceWithOwner();
    $foreign = billableSession(
        $otherWorkspace,
        $otherOwner,
        Course::factory()->published()->create(['workspace_id' => $otherWorkspace->getKey()]),
    );
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $everything = [$this->nearSession->uuid, $this->farSession->uuid, $this->courseless->uuid];

    // Owner, then the assistant before any confinement: the whole workspace.
    Sanctum::actingAs($this->owner);
    expect(listedSessionUuids())->toEqualCanonicalizing($everything);

    Sanctum::actingAs($this->assistant);
    expect(listedSessionUuids())->toEqualCanonicalizing($everything)
        ->and(sessionAbilities($this->assistant, $this->courseless))->each->toBeTrue()
        ->and(sessionAbilities($this->assistant, $this->farSession))->each->toBeTrue()
        ->and(sessionAbilities($this->owner, $this->courseless))->each->toBeTrue();

    confineSessionAssistantTo($this->near);

    expect(listedSessionUuids())->toBe([$this->nearSession->uuid]);

    // Another workspace's session is nobody's here, confined or not.
    expect(sessionAbilities($this->assistant, $foreign))->each->toBeFalse()
        ->and(sessionAbilities($this->owner, $foreign))->each->toBeFalse();
});

it('confines the rows that hang off a session: a seat, a private-hour request, a reschedule request', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $profileId = (int) $this->nearSession->teacher_profile_id;

    $seat = fn (ClassSession $session): SessionBooking => SessionBooking::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $student->getKey(),
    ]);
    $ask = fn (Course $course, int $days): PrivateSessionRequest => PrivateSessionRequest::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'teacher_profile_id' => $profileId,
        'starts_at' => CarbonImmutable::now()->addDays($days),
        'duration_minutes' => 60,
        'expires_at' => CarbonImmutable::now()->addDay(),
    ]);
    $move = fn (ClassSession $session): SessionRescheduleRequest => SessionRescheduleRequest::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'student_user_id' => $student->getKey(),
        'from_starts_at' => $session->starts_at,
        'to_starts_at' => CarbonImmutable::now()->addDays(4),
    ]);

    $nearSeat = $seat($this->nearSession);
    $farSeat = $seat($this->farSession);
    $nearAsk = $ask($this->near, 5);
    $farAsk = $ask($this->far, 6);
    $nearMove = $move($this->nearSession);
    $farMove = $move($this->farSession);
    $courselessMove = $move($this->courseless);

    confineSessionAssistantTo($this->near);
    $gate = Gate::forUser($this->assistant);

    expect($gate->allows('delete', $nearSeat))->toBeTrue()
        ->and($gate->allows('delete', $farSeat))->toBeFalse()
        ->and($gate->allows('decide', $nearAsk))->toBeTrue()
        ->and($gate->allows('decide', $farAsk))->toBeFalse()
        ->and($gate->allows('decide', $nearMove))->toBeTrue()
        ->and($gate->allows('decide', $farMove))->toBeFalse()
        ->and($gate->allows('decide', $courselessMove))->toBeFalse();

    Sanctum::actingAs($this->assistant);

    expect(collect($this->getJson('/api/v1/manage/private-session-requests')->assertOk()->json('data'))->pluck('uuid')->all())
        ->toBe([$nearAsk->uuid])
        ->and(collect($this->getJson('/api/v1/manage/session-reschedule-requests')->assertOk()->json('data'))->pluck('uuid')->all())
        ->toBe([$nearMove->uuid]);

    $this->postJson("/api/v1/manage/private-session-requests/{$farAsk->uuid}/decide", ['accept' => false, 'decision_reason' => 'لا'])
        ->assertForbidden();
    $this->postJson("/api/v1/manage/session-reschedule-requests/{$farMove->uuid}/decide", ['approve' => false, 'decision_reason' => 'لا'])
        ->assertForbidden();
    $this->deleteJson("/api/v1/bookings/{$farSeat->uuid}")->assertForbidden();

    // The owner still answers every one of them.
    Sanctum::actingAs($this->owner);

    expect($this->getJson('/api/v1/manage/private-session-requests')->assertOk()->json('data'))->toHaveCount(2)
        ->and($this->getJson('/api/v1/manage/session-reschedule-requests')->assertOk()->json('data'))->toHaveCount(3)
        ->and(Gate::forUser($this->owner)->allows('decide', $farAsk))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('delete', $farSeat))->toBeTrue();
});

it('refuses a confined assistant the freeze, which reaches every course, and leaves it to everyone else', function (): void {
    $period = FreezePeriod::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    expect(Gate::forUser($this->assistant)->allows('create', FreezePeriod::class))->toBeTrue()
        ->and(Gate::forUser($this->assistant)->allows('delete', $period))->toBeTrue();

    confineSessionAssistantTo($this->near);

    expect(Gate::forUser($this->assistant)->allows('create', FreezePeriod::class))->toBeFalse()
        ->and(Gate::forUser($this->assistant)->allows('delete', $period))->toBeFalse()
        // Reading stays: explaining a suspended session is their job too.
        ->and(Gate::forUser($this->assistant)->allows('viewAny', FreezePeriod::class))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('create', FreezePeriod::class))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('delete', $period))->toBeTrue();

    Sanctum::actingAs($this->assistant);

    $this->postJson('/api/v1/freeze-periods', [
        'starts_on' => now()->addDay()->toDateString(),
        'ends_on' => now()->addDays(3)->toDateString(),
        'reason' => 'سفر',
    ])->assertForbidden();
});

it('leaves a student\'s seat, join and heartbeat in a far course untouched by the confinement', function (): void {
    confineSessionAssistantTo($this->near);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->far, $student);
    fundBooking($this->workspace, $student, $this->far, 5);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->farSession->refresh(), $student);

    // The teacher opens the room; the confined assistant cannot.
    Sanctum::actingAs($this->assistant);
    $this->postJson("/api/v1/class-sessions/{$this->farSession->uuid}/join")->assertForbidden();

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->farSession->uuid}/join")->assertOk()->assertJsonPath('role', 'host');

    Sanctum::actingAs($student);
    $this->postJson("/api/v1/class-sessions/{$this->farSession->uuid}/join")
        ->assertOk()
        ->assertJsonPath('role', 'participant');
    $this->postJson("/api/v1/class-sessions/{$this->farSession->uuid}/presence")->assertOk();
    $this->getJson("/api/v1/class-sessions/{$this->farSession->uuid}")->assertOk();
});
