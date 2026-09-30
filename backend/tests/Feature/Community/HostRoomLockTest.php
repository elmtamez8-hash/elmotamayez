<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Modules\LiveSessions\Models\SessionBooking;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| نقاشُ الحصّةِ لمضيفِها (قرارُ المالك ٢٠٢٦-٠٩-٣٠).
|
| ⚠️ مساعدٌ يستضيفُ الحصّةَ كانَ يستطيعُ أن يكتمَ الفصلَ كلَّه ولا يستطيعُ أن يُغلِقَ
| نقاشَه، لأنّ القفلَ كانَ يسألُ `chat.moderate` وحدَها. الغرفةُ نفسُها بجوابَين. فمضيفُ
| الحصّةِ — من يمرُّ من `ClassSessionPolicy::host` — يُغلِقُ نقاشَ **تلك** الحصّة
| ويُعفى من قفلِه، وكلُّ غرفةٍ أخرى تبقى على الصلاحيّةِ والنطاق.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $teacher = TeacherProfile::factory()->create(['user_id' => $this->owner->getKey()]);
    $this->course = Course::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    $this->session = ClassSession::factory()->create([
        'teacher_profile_id' => $teacher->getKey(),
        'course_id' => $this->course->getKey(),
        'starts_at' => CarbonImmutable::now()->addMinutes(5),
        'ends_at' => CarbonImmutable::now()->addMinutes(65),
    ]);

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->course, $this->student);
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    SessionBooking::create([
        'workspace_id' => $this->workspace->getKey(),
        'class_session_id' => $this->session->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => 'booked',
        'is_billable' => true,
        'booked_at' => now(),
    ]);

    // An assistant who may answer in the room — and may NOT moderate chat.
    $this->newAssistant = function (array $permissions): User {
        $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
        $assistant->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $assistant->unsetRelation('permissions');
        $this->setCurrentWorkspace($this->workspace, $assistant);

        return $assistant;
    };
});

function hostLockRoom(object $test): array
{
    return $test->getJson('/api/v1/class-sessions/'.$test->session->uuid.'/chat')
        ->assertOk()
        ->json();
}

it('lets an assistant who hosts the session lock its room without chat.moderate', function (): void {
    $assistant = ($this->newAssistant)([Permissions::SESSIONS_HOST, Permissions::CHAT_REPLY]);

    expect($assistant->can(Permissions::CHAT_MODERATE))->toBeFalse();

    Sanctum::actingAs($assistant);
    $room = hostLockRoom($this);

    // The screen offers the control…
    expect($room['can_moderate'])->toBeTrue();

    // …and the door agrees.
    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])
        ->assertOk()
        ->assertJsonPath('is_locked', true);

    // Whoever may lift it is exempt from it.
    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'انتبهوا للشرح'])
        ->assertCreated();

    Sanctum::actingAs($this->student);
    $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'سؤال'])
        ->assertForbidden();

    Sanctum::actingAs($assistant);
    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => false])
        ->assertOk()
        ->assertJsonPath('is_locked', false);
});

/*
| ⚠️ `can_moderate` DRAWS THREE CONTROLS IN THE ROOM, NOT ONE — the lock, the
| per-thread silence and «مفيدة». A button the screen shows and the door refuses
| is the dead-end defect #304 cleaned up, so each door is pressed here as the
| same assistant the lock test uses.
*/
it('opens every control can_moderate draws to that assistant, not the lock alone', function (): void {
    $assistant = ($this->newAssistant)([Permissions::SESSIONS_HOST, Permissions::CHAT_REPLY]);

    Sanctum::actingAs($this->student);
    $room = hostLockRoom($this);
    $message = $this->postJson("/api/v1/conversations/{$room['uuid']}/messages", ['body' => 'سؤال عن الدرس'])
        ->assertCreated()
        ->json();

    Sanctum::actingAs($assistant);
    expect(hostLockRoom($this)['can_moderate'])->toBeTrue();

    $this->postJson("/api/v1/conversations/{$room['uuid']}/write-bans", [
        'user_uuid' => $this->student->uuid,
        'reason' => 'مقاطعة الشرح',
        'minutes' => 10,
    ])->assertCreated();

    $this->deleteJson("/api/v1/conversations/{$room['uuid']}/write-bans", [
        'user_uuid' => $this->student->uuid,
    ])->assertOk();

    $this->postJson('/api/v1/messages/'.($message['uuid'] ?? $message['data']['uuid']).'/helpful')
        ->assertSuccessful();
});

it('keeps the lock from an assistant who does not host the session', function (): void {
    // The control: without it the case above passes on a build where every
    // assistant who may reply can close every room.
    $assistant = ($this->newAssistant)([Permissions::CHAT_REPLY]);

    Sanctum::actingAs($assistant);
    $room = hostLockRoom($this);

    expect($room['can_moderate'])->toBeFalse();

    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])
        ->assertForbidden();
});

it('never hands the lock to a student of the room', function (): void {
    Sanctum::actingAs($this->student);
    $room = hostLockRoom($this);

    expect($room['can_moderate'])->toBeFalse();

    $this->postJson("/api/v1/conversations/{$room['uuid']}/lock", ['locked' => true])
        ->assertForbidden();
});
