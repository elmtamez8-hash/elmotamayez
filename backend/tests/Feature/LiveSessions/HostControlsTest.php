<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Providers\NullBroadcastProvider;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
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
*/
it('answers 501 when the bound provider cannot host', function (): void {
    $this->app->instance(BroadcastProviderInterface::class, new NullBroadcastProvider);

    Sanctum::actingAs($this->owner);

    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/host/end")
        ->assertStatus(501);
});
