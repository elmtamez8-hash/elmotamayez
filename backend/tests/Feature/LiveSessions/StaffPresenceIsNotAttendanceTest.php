<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Actions\BookSeat;
use App\Modules\LiveSessions\Actions\CloseClassSession;
use App\Modules\LiveSessions\Actions\OpenBroadcastRoom;
use App\Modules\LiveSessions\Actions\ReadSessionRoster;
use App\Modules\LiveSessions\Actions\RecordPresencePing;
use App\Modules\LiveSessions\Contracts\BroadcastProviderInterface;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\LiveSessions\Jobs\SyncTeacherCountersJob;
use App\Modules\LiveSessions\Models\Attendance;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeBroadcastProvider;

/*
| Staff in the room are not students of it (owner decision 2026-09-26).
|
| An assistant holding `sessions.host` enters with no seat, and the heartbeat
| writes them an attendance row exactly as it writes the teacher's. The
| exclusion used to name the session's teacher alone, so the assistant stood in
| the class register, earned attendance points through `attendeeUserIds()`, and
| was counted among the students the teacher had taught on the public profile.
|
| ⚠️ THE ROW ITSELF STAYS, and two readers need it: the room's roster names the
| assistant from it, and delivery is judged from the teacher's own row.
|
| `fakeSessionTimeline()` for the reason every room test needs it: a `->delay()`
| on the `sync` connection runs at once, and opening the room would close it.
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

    SettlementRate::factory()->group()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'amount_minor' => 2500,
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $this->teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'type' => ClassSessionType::Group,
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
        'duration_minutes' => 60,
        'seats_total' => 10,
    ]);

    // The student, booked the way production books.
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    fundBooking($this->workspace, $this->student, $this->course);
    app(BookSeat::class)->handle($this->session->refresh(), $this->student);

    // The assistant the owner ticked «يدير الغرفة» for: host powers, no seat.
    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    $this->assistant->givePermissionTo(Permissions::SESSIONS_HOST);

    $this->session->refresh()->forceFill(['billable_seats' => 1])->save();

    // Everybody in the room, for the whole hour.
    app(OpenBroadcastRoom::class)->handle($this->session->refresh());

    foreach ([$this->owner, $this->student, $this->assistant] as $person) {
        app(RecordPresencePing::class)->handle($this->session, $person);
    }

    Attendance::query()
        ->where('class_session_id', $this->session->getKey())
        ->update([
            'stay_seconds' => 3000,
            'status' => AttendanceStatus::Present->value,
            'auto_status' => AttendanceStatus::Present->value,
        ]);
});

/** Ids, as integers, for comparisons that must not trip over a string key. */
function staffPresenceIds(iterable $ids): array
{
    $out = [];

    foreach ($ids as $id) {
        $out[] = (int) $id;
    }

    sort($out);

    return $out;
}

it('keeps the assistant\'s own row, which the roster names them from', function (): void {
    // The premise of every case below: the heartbeat DID write the row. A test
    // that never pinged the assistant would pass against the old scope too.
    expect(Attendance::query()
        ->where('class_session_id', $this->session->getKey())
        ->where('student_user_id', $this->assistant->getKey())
        ->exists())->toBeTrue();

    $roster = collect(app(ReadSessionRoster::class)->handle($this->session->refresh()))->keyBy('uuid');

    expect($roster)->toHaveKey($this->assistant->uuid)
        ->and($roster[$this->assistant->uuid]['name'])->toBe($this->assistant->name)
        ->and($roster[$this->assistant->uuid]['role'])->toBe('staff')
        ->and($roster[$this->student->uuid]['role'])->toBe('student');
});

it('leaves the assistant out of the class register', function (): void {
    Sanctum::actingAs($this->owner);

    $uuids = collect($this->getJson('/api/v1/class-sessions/'.$this->session->uuid.'/attendance')
        ->assertOk()
        ->json('data'))
        ->pluck('student.uuid')
        ->all();

    expect($uuids)->toBe([$this->student->uuid]);
});

it('gives the assistant no attendance of their own to earn points on', function (): void {
    $attendees = app(SessionAttendanceDirectory::class)->attendeeUserIds((int) $this->session->getKey());

    expect(staffPresenceIds($attendees))->toBe([(int) $this->student->getKey()]);
});

it('does not count the assistant among the students the teacher taught', function (): void {
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect($this->session->refresh()->delivered_at)->not->toBeNull();

    (new SyncTeacherCountersJob((int) $this->teacher->getKey()))->handle(app(WorkspaceContext::class));

    expect((int) $this->teacher->refresh()->students_taught_count)->toBe(1);
});

it('pays the teacher one unit for one seat, however many staff sat in', function (): void {
    /*
    | A GUARD, not the fix. `AccrueTeachingUnits` walks the seat holders, so the
    | assistant's row never produced a unit even before 2026-09-26; this pins it
    | so a rewrite that walks attendance instead cannot quietly start paying the
    | teacher for their own assistant.
    */
    app(CloseClassSession::class)->handle($this->session->refresh());

    expect(TeachingUnit::query()->count())->toBe(1)
        ->and(TeachingUnit::query()->where('student_user_id', $this->assistant->getKey())->exists())->toBeFalse()
        // Delivery is still judged from the teacher's own row.
        ->and($this->session->refresh()->delivered_at)->not->toBeNull()
        ->and((int) $this->session->attended_seats)->toBe(1);
});
