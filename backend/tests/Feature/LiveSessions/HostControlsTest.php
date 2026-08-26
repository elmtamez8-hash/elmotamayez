<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| FR-016 — the host controls belong to the teacher, and to nobody else.
|
| Two separate claims are checked, because they fail differently: that the
| teacher HAS the power, and that the student does not. Testing only the second
| would pass on a build where nobody has it at all.
*/

beforeEach(function (): void {
    $this->provider = new FakeBroadcastProvider;
    $this->app->instance(BroadcastProviderInterface::class, $this->provider);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);
});

it('lets the teacher mute a participant', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/mute", [
        'target_uuid' => $this->owner->uuid,
    ])->assertOk();

    expect($this->provider->hostActions)->toHaveCount(1)
        ->and($this->provider->hostActions[0]['action'])->toBe('mute');
});

it('refuses host controls to a student', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->session, $student);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/mute", [
        'target_uuid' => $student->uuid,
    ])->assertForbidden();

    expect($this->provider->hostActions)->toHaveCount(0);
});

it('closes the room on the platform side when the host ends the session', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/end")->assertOk();

    // Ending is not only a provider call: the refusal to re-enter is enforced
    // by our own room_closed_at, so a provider that leaves its room open cannot
    // reopen a door we closed (FR-015).
    expect($this->session->refresh()->room_closed_at)->not->toBeNull()
        ->and($this->provider->roomClosed)->toBeTrue();
});

it('refuses an action that names no participant', function (): void {
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/mute")
        ->assertStatus(422);
});

/*
| The honest failure. A provider that never claimed host controls must say so
| out loud — a teacher pressing "mute" on a microphone that stays open, with
| nothing reported, is worse than a provider with no mute at all.
|
| Muting and removing are claims about media the PROVIDER holds, so they are the
| capability's business. Ending is not: see the test below.
*/
it('answers 501 when the bound provider cannot mute', function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new NullBroadcastProvider);

    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/mute", [
        'target_uuid' => $this->owner->uuid,
    ])->assertStatus(501);
});

/*
| And the failure that must NOT happen.
|
| Ending is enforced on our side by room_closed_at — the provider's own
| closeRoom() is a teardown hook, and the one provider that exists implements it
| as a documented no-op for exactly that reason. Routing "end" through the
| hostControls capability made the button dead for every provider that does not
| claim it, which today is all of them: the teacher pressed "إنهاء الحصة" and got
| 501, the room never closed, and the register waited for the scheduled sweep.
*/
it('lets the host end the session even when the provider claims no host controls', function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new NullBroadcastProvider);

    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/end")->assertOk();

    expect($this->session->refresh()->room_closed_at)->not->toBeNull();
});

/*
| The bulk forms (2026-08-26).
|
| ⚠️ THEY ARE ONE REQUEST AND NOT A LOOP AT THE CALLER, and the actor travels with
| them for one reason: «الجميع» means everyone being taught and never the teacher.
| A host who mutes themselves with a room control has a puzzle; a host who removes
| themselves has left the room open, the recording running, and nobody inside who
| can close it.
*/
it('sends the whole-room actions through with the host named, so they can be excluded', function (): void {
    Sanctum::actingAs($this->owner);

    foreach (['mute-all', 'remove-all', 'lower-hands'] as $action) {
        $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/{$action}")->assertOk();
    }

    expect($this->provider->hostActions)->toHaveCount(3)
        ->and(array_column($this->provider->hostActions, 'action'))
        ->toBe(['mute-all', 'remove-all', 'lower-hands'])
        // No target: the action is about the room. The actor is what the
        // provider excludes, and without it every bulk press evicts the teacher.
        ->and(array_column($this->provider->hostActions, 'target'))->toBe([null, null, null])
        ->and(array_column($this->provider->hostActions, 'actor'))
        ->toBe(array_fill(0, 3, (int) $this->owner->getKey()));
});

it('does not ask a bulk action to name a participant', function (): void {
    // The single forms answer 422 without a target. A room action that demanded
    // one would be a button that can never be pressed.
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/mute-all")->assertOk();
});

it('refuses the whole-room actions to a student', function (): void {
    // The control, in the direction that matters. Without it the case above
    // would also pass on a build where any seat holder can clear the room.
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->session, $student);

    Sanctum::actingAs($student);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/remove-all")->assertForbidden();

    expect($this->provider->hostActions)->toHaveCount(0);
});

/*
| «أخرجه المدرّس» — والريفريشُ كان يُعيده (2026-08-26).
|
| ⚠️ الإخراجُ كان نداءَ مزوّدٍ ولا شيءَ غيره: المشاركُ يُفصَل، والغرفةُ تبقى مفتوحة،
| والمقعدُ محجوزاً، فيمنحه `IssueJoinTicket` تذكرةً جديدةً بعد ثانية. سيطرةُ المدرّسِ
| الوحيدةُ على طالبٍ مشاغبٍ كانت تُكلّفه ضغطةَ F5. أُبلغ عنه من حصّةٍ حقيقيّة.
|
| والحارسُ يُقرأُ على غيرِ المضيف وحدَه: للمدرّسِ صفُّ حضورٍ خاصٌّ به عمداً — منه
| يحكمُ `CloseClassSession` على التسليم — فحارسٌ فوقَ ذلك الفرعِ يجعلُ مضيفاً يقفلُ
| على مضيفٍ آخرَ بابَ حصّتِه بزرٍّ مُعَدٍّ لطالب.
*/
it('keeps a removed student out until the host lets them back in', function (): void {
    /*
     | ⚠️ `->delay()` RUNS IMMEDIATELY ON THE `sync` CONNECTION. Opening the room
     | dispatches `CloseClassSessionJob` for the end of the join window; with no
     | queue behind `sync` it runs inside the join itself, stamps
     | `room_closed_at`, and every later door in this test answers 403 for a
     | reason that has nothing to do with what it is measuring. Only the timeline
     | job is faked, so everything else still runs.
     */
    Queue::fake([CloseClassSessionJob::class]);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->session, $student);

    // The host opens the room: a student arriving first does not open one the
    // teacher has not started, and that refusal is not the one under test.
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();

    // ⚠️ ASSERTED BEFORE THE REMOVAL AS WELL AS AFTER IT. A 403 measured only
    // after would be green against a build where this student could never join
    // at all — the seat guard firing, not the removal.
    Sanctum::actingAs($student);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/remove", [
        'target_uuid' => $student->uuid,
    ])->assertOk();

    // The reported bug, exactly: the student refreshes.
    Sanctum::actingAs($student);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertForbidden();

    // And the heartbeat is what tells a page already open — enforcement is
    // instant on the server, and a client finds out when it next speaks.
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/presence")->assertForbidden();

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/readmit", [
        'target_uuid' => $student->uuid,
    ])->assertOk();

    Sanctum::actingAs($student);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();
});

it('never locks the host out with the button meant for a student', function (): void {
    /*
     | ⚠️ `->delay()` RUNS IMMEDIATELY ON THE `sync` CONNECTION. Opening the room
     | dispatches `CloseClassSessionJob` for the end of the join window; with no
     | queue behind `sync` it runs inside the join itself, stamps
     | `room_closed_at`, and every later door in this test answers 403 for a
     | reason that has nothing to do with what it is measuring. Only the timeline
     | job is faked, so everything else still runs.
     */
    Queue::fake([CloseClassSessionJob::class]);

    // A teacher has an attendance row of their own — `CloseClassSession` judges
    // delivery from it — so a guard read above the role branch would let
    // «أخرِج الجميع» shut the teacher out of their own lesson.
    $this->provider->roomIdentities = [$this->owner->uuid];

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/remove-all")->assertOk();

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();
});

it('records the removal for everyone a bulk clear actually reached', function (): void {
    /*
     | ⚠️ `->delay()` RUNS IMMEDIATELY ON THE `sync` CONNECTION. Opening the room
     | dispatches `CloseClassSessionJob` for the end of the join window; with no
     | queue behind `sync` it runs inside the join itself, stamps
     | `room_closed_at`, and every later door in this test answers 403 for a
     | reason that has nothing to do with what it is measuring. Only the timeline
     | job is faked, so everything else still runs.
     */
    Queue::fake([CloseClassSessionJob::class]);

    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    app(BookSeat::class)->handle($this->session, $student);

    // ⚠️ STAMPED FROM WHAT THE PROVIDER CONFIRMS, never from a list rebuilt on
    // our side: only the provider sees who was in the room, and deriving it from
    // `last_ping_at` beside `RecordPresencePing`'s arithmetic would be a second
    // spelling whose failure direction is this very bug surviving.
    $this->provider->roomIdentities = [$student->uuid, $this->owner->uuid];

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/remove-all")->assertOk();

    Sanctum::actingAs($student);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertForbidden();
});
