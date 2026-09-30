<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Data\PublishRights;
use App\Modules\LiveSessions\Enums\ClassSessionStatus;
use App\Modules\LiveSessions\Exceptions\BroadcastProviderUnavailable;
use App\Modules\LiveSessions\Jobs\CloseClassSessionJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeBroadcastProvider;

/*
| الميكروفونُ والشاشةُ صلاحيّتان، لا زرّانِ يُطفِئُهما المدرّسُ ويُعيدُهما الطالب
| (قرارُ المالك ٢٠٢٦-٠٩-٣٠).
|
| ⚠️ «كتم» كان يُسكِتُ المسارَ المنشورَ ولا شيءَ غيرَه: الطالبةُ تضغطُ زرَّها فتعود،
| أو تُعيدُ التحميلَ فتأخذُ تذكرةً تتكلّمُ بها. فالقرارُ يُخزَّنُ الآن — على المقعدِ أو
| على الحصّة — ثمّ يُطبَّقُ على الحاضرين، والتذكرةُ التاليةُ تُقرأُ من الأعمدةِ نفسِها.
|
| ⛔ وأيٌّ من هذه الأزرار لا يُنهي حصّةً ولا يُخرِجُ أحداً، ولا يمسُّ مضيفاً — والملفُّ
| يقيسُ الثلاثةَ صراحةً، لأنّ الخطأَ فيها لا يظهرُ إلا في حصّةٍ حقيقيّة.
*/

beforeEach(function (): void {
    Queue::fake([CloseClassSessionJob::class]);

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

    // Closures bound here, because the workspace helpers are protected and a
    // Pest helper is a global function.
    $this->newStudent = function (): User {
        $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
        $this->createEnrollment($this->workspace, $this->course, $student);
        $this->setCurrentWorkspace($this->workspace, $this->owner);

        app(BookSeat::class)->handle($this->session, $student);

        return $student;
    };
    $this->newStaff = fn (): User => $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
});

function mediaStudent(object $test): User
{
    return ($test->newStudent)();
}

/** An assistant who passes the host gate — and, for the worst case, also holds a seat. */
function mediaAssistantHost(object $test, bool $withSeat = true): User
{
    $assistant = ($test->newStaff)();
    $assistant->givePermissionTo([Permissions::SESSIONS_HOST]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $assistant->unsetRelation('permissions');

    if ($withSeat) {
        SessionBooking::create([
            'workspace_id' => $test->workspace->getKey(),
            'class_session_id' => $test->session->getKey(),
            'student_user_id' => $assistant->getKey(),
            'status' => 'booked',
            'is_billable' => false,
            'booked_at' => now(),
        ]);
    }

    return $assistant;
}

function joinAs(object $test, User $user): void
{
    Sanctum::actingAs($user);
    $test->postJson("/api/v1/class-sessions/{$test->session->uuid}/join")->assertOk();
}

function hostPress(object $test, string $action, ?User $target = null): TestResponse
{
    Sanctum::actingAs($test->owner);

    return $test->postJson(
        "/api/v1/class-sessions/{$test->session->uuid}/host/{$action}",
        $target === null ? [] : ['target_uuid' => $target->uuid],
    );
}

function lastTicketRights(object $test, User $user): PublishRights
{
    return $test->provider->issuedRights[(string) $user->uuid];
}

it('gives the host every source and no hand, and a student her microphone but no screen', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);
    joinAs($this, $student);

    $host = lastTicketRights($this, $this->owner);
    $pupil = lastTicketRights($this, $student);

    expect($host->microphone)->toBeTrue()
        ->and($host->screenShare)->toBeTrue()
        // No «ارفع يدك» and no «لم أفهم» for the person teaching.
        ->and($host->updateOwnSignals)->toBeFalse()
        ->and($pupil->microphone)->toBeTrue()
        ->and($pupil->screenShare)->toBeFalse()
        ->and($pupil->updateOwnSignals)->toBeTrue();
});

it('keeps a muted student muted across a reload until the host lets her speak', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);
    joinAs($this, $student);

    hostPress($this, 'mute', $student)->assertOk();

    // Applied to the live connection…
    expect(end($this->provider->appliedRights)[$student->uuid]->microphone)->toBeFalse();

    // …and — the reported defect — still true after she reloads.
    joinAs($this, $student);
    expect(lastTicketRights($this, $student)->microphone)->toBeFalse();

    hostPress($this, 'allow-mic', $student)->assertOk();
    expect(end($this->provider->appliedRights)[$student->uuid]->microphone)->toBeTrue();

    joinAs($this, $student);
    expect(lastTicketRights($this, $student)->microphone)->toBeTrue();
});

it('locks every student ticket while the room is locked, a late joiner included', function (): void {
    // Seats are taken before the room opens: a live session takes no bookings.
    $early = mediaStudent($this);
    $late = mediaStudent($this);
    joinAs($this, $this->owner);
    joinAs($this, $early);

    hostPress($this, 'mute-all')->assertOk();
    expect($this->session->refresh()->mics_locked_at)->not->toBeNull();

    // She walks in only now, after the lock.
    joinAs($this, $late);
    joinAs($this, $early);

    expect(lastTicketRights($this, $late)->microphone)->toBeFalse()
        ->and(lastTicketRights($this, $early)->microphone)->toBeFalse();
});

it('lets one student speak while the room stays locked, and a re-lock silences her again', function (): void {
    $asker = mediaStudent($this);
    $other = mediaStudent($this);
    joinAs($this, $this->owner);

    hostPress($this, 'mute-all')->assertOk();
    hostPress($this, 'allow-mic', $asker)->assertOk();

    joinAs($this, $asker);
    joinAs($this, $other);

    expect(lastTicketRights($this, $asker)->microphone)->toBeTrue()
        ->and(lastTicketRights($this, $other)->microphone)->toBeFalse()
        ->and($this->session->refresh()->mics_locked_at)->not->toBeNull();

    // «اكتم الجميع» again: the one let through for a question is silenced too.
    hostPress($this, 'mute-all')->assertOk();
    joinAs($this, $asker);

    expect(lastTicketRights($this, $asker)->microphone)->toBeFalse();
});

it('lifts the room lock and leaves a named mute standing', function (): void {
    $quiet = mediaStudent($this);
    $muted = mediaStudent($this);
    joinAs($this, $this->owner);

    hostPress($this, 'mute', $muted)->assertOk();
    hostPress($this, 'mute-all')->assertOk();
    hostPress($this, 'allow-all-mics')->assertOk();

    joinAs($this, $quiet);
    joinAs($this, $muted);

    expect($this->session->refresh()->mics_locked_at)->toBeNull()
        ->and(lastTicketRights($this, $quiet)->microphone)->toBeTrue()
        // «اسمح للجميع بالكلام» is about the ROOM; lifting a named mute is that
        // row's own «اسمح بالكلام».
        ->and(lastTicketRights($this, $muted)->microphone)->toBeFalse();
});

it('shares a screen only when the host allowed that student, and takes it back', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);

    hostPress($this, 'allow-screen-share', $student)->assertOk();
    expect(end($this->provider->appliedRights)[$student->uuid]->screenShare)->toBeTrue();

    joinAs($this, $student);
    expect(lastTicketRights($this, $student)->screenShare)->toBeTrue();

    hostPress($this, 'revoke-screen-share', $student)->assertOk();
    joinAs($this, $student);

    expect(lastTicketRights($this, $student)->screenShare)->toBeFalse()
        // The microphone was never part of it.
        ->and(lastTicketRights($this, $student)->microphone)->toBeTrue();
});

/*
| «الجميع» = من يتعلّمون. مساعدٌ يمرُّ من بوّابةِ المضيف — حتى لو كانَ يحملُ مقعداً —
| لا يُكتَمُ ولا يُخرَجُ بزرِّ الغرفة.
*/
it('never mutes or removes a co-host with a room control, even one holding a seat', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);
    $cohost = mediaAssistantHost($this);

    $this->provider->roomIdentities = [$this->owner->uuid, $cohost->uuid, $student->uuid];

    hostPress($this, 'mute-all')->assertOk();

    expect(array_keys(end($this->provider->appliedRights)))->toBe([$student->uuid]);

    hostPress($this, 'remove-all')->assertOk();

    expect(end($this->provider->hostActions)['students'])->toBe([$student->uuid])
        ->and(Attendance::query()->withoutWorkspaceScope()->whereNotNull('removed_at')
            ->pluck('student_user_id')->map(fn ($id): int => (int) $id)->all())
        ->toBe([(int) $student->getKey()]);
});

it('refuses a student control aimed at a host or at staff with no seat', function (): void {
    joinAs($this, $this->owner);
    $cohost = mediaAssistantHost($this);
    $staff = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    foreach (['mute', 'remove', 'allow-mic', 'allow-screen-share'] as $action) {
        hostPress($this, $action, $this->owner)->assertStatus(422);
        hostPress($this, $action, $cohost)->assertStatus(422);
        hostPress($this, $action, $staff)->assertStatus(422);
    }

    expect($this->provider->appliedRights)->toBe([])
        ->and($this->provider->hostActions)->toBe([])
        ->and(Attendance::query()->withoutWorkspaceScope()->whereNotNull('mic_locked_at')->count())->toBe(0)
        ->and(Attendance::query()->withoutWorkspaceScope()->whereNotNull('removed_at')->count())->toBe(0);
});

/*
| ⛔ إضافةُ المالك: أدواتُ الكتمِ لا تُنهي الحصّةَ ولا تُخرِجُ أحداً أبداً. «إنهاء الحصة»
| وحدَه يُنهيها.
*/
it('never ends the lesson or puts anybody out, whatever mute control is pressed', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);
    joinAs($this, $student);

    $this->provider->roomIdentities = [$this->owner->uuid, $student->uuid];

    hostPress($this, 'mute', $student)->assertOk();
    hostPress($this, 'mute-all')->assertOk();
    hostPress($this, 'allow-mic', $student)->assertOk();
    hostPress($this, 'allow-screen-share', $student)->assertOk();
    hostPress($this, 'revoke-screen-share', $student)->assertOk();
    hostPress($this, 'allow-all-mics')->assertOk();
    // An action nobody defined falls through to NOTHING — never to «end».
    hostPress($this, 'mute-everyone')->assertStatus(422);

    $session = $this->session->refresh();

    expect($session->status)->toBe(ClassSessionStatus::Live)
        ->and($session->room_closed_at)->toBeNull()
        ->and($this->provider->roomClosed)->toBeFalse()
        ->and($this->provider->hostActions)->toBe([])
        ->and(Attendance::query()->withoutWorkspaceScope()->whereNotNull('removed_at')->count())->toBe(0);

    // The student is still inside, by the server's own reckoning.
    Sanctum::actingAs($student);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/presence")->assertOk();
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/join")->assertOk();

    // And the host was never in any permission change.
    foreach ($this->provider->appliedRights as $change) {
        expect($change)->not->toHaveKey((string) $this->owner->uuid);
    }
});

it('keeps the decision when the provider is down, and says so', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);

    $this->provider->failWith = new BroadcastProviderUnavailable('down');

    $response = hostPress($this, 'mute', $student)->assertStatus(503)
        ->assertJsonPath('code', 'broadcast_unavailable');

    $body = (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);

    expect($body)->toContain('سُجِّل قرارُك')
        ->and(Attendance::query()->withoutWorkspaceScope()
            ->where('student_user_id', $student->getKey())->value('mic_locked_at'))->not->toBeNull();

    // And the next ticket carries it, provider or no provider.
    $this->provider->failWith = null;
    joinAs($this, $student);
    expect(lastTicketRights($this, $student)->microphone)->toBeFalse();
});

it('tells a removed student why the heartbeat refused her', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);
    joinAs($this, $student);

    hostPress($this, 'remove', $student)->assertOk();

    Sanctum::actingAs($student);
    $this->postJson("/api/v1/class-sessions/{$this->session->uuid}/presence")
        ->assertForbidden()
        ->assertJsonPath('code', 'removed_from_session');
});

it('shows the host each seat\'s media state and the room lock, and a student none of it', function (): void {
    joinAs($this, $this->owner);
    $student = mediaStudent($this);

    hostPress($this, 'mute', $student)->assertOk();
    hostPress($this, 'allow-screen-share', $student)->assertOk();
    hostPress($this, 'mute-all')->assertOk();

    Sanctum::actingAs($this->owner);
    $host = $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/participants")->assertOk();

    $row = collect($host->json('data'))->firstWhere('uuid', $student->uuid);

    expect($host->json('room.mics_locked'))->toBeTrue()
        ->and($row['mic_locked'])->toBeTrue()
        ->and($row['mic_allowed'])->toBeFalse()
        ->and($row['screen_share_allowed'])->toBeTrue();

    Sanctum::actingAs($student);
    $pupil = $this->getJson("/api/v1/class-sessions/{$this->session->uuid}/participants")->assertOk();

    expect($pupil->json())->not->toHaveKey('room');

    foreach ($pupil->json('data') as $person) {
        expect($person)->not->toHaveKey('mic_locked')
            ->and($person)->not->toHaveKey('mic_allowed')
            ->and($person)->not->toHaveKey('screen_share_allowed');
    }
});

/*
| A seat holder who passes the host gate is STAFF on the roster, so the panel
| draws no student control on their row — it drew «كتم»/«إخراج», and the server
| answered 422.
*/
it('labels a seat-holding co-host as staff on the roster, and a student as a student', function (): void {
    $student = mediaStudent($this);
    $cohost = mediaAssistantHost($this);

    Sanctum::actingAs($this->owner);
    $rows = collect($this->getJson("/api/v1/class-sessions/{$this->session->uuid}/participants")
        ->assertOk()->json('data'))->keyBy('uuid');

    expect($rows[$cohost->uuid]['role'])->toBe('staff')
        ->and($rows[$student->uuid]['role'])->toBe('student')
        ->and($rows[$this->owner->uuid]['role'])->toBe('host');
});
