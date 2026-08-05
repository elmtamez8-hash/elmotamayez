<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\AuthSession;
use App\Modules\Identity\Models\Device;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/**
 * Constitution I, for every platform-owned entity this phase adds.
 *
 * Two directions, and both matter. A platform-owned row has no global scope
 * guarding it, so it is as exposed as an unauthenticated query unless an
 * explicit guard says otherwise. And the mirror-image mistake — giving it
 * BelongsToWorkspace — quietly multiplies one person into one row per teacher.
 */
function enrolStudentWith(Workspace $workspace, User $student): void
{
    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $student): void {
        $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

        Enrollment::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'active',
        ]);
    });
}

it('shows a teacher nothing about their own student devices', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    enrolStudentWith($workspace, $student);

    $device = Device::factory()->create(['user_id' => $student->getKey()]);
    $session = AuthSession::factory()->create([
        'user_id' => $student->getKey(),
        'device_id' => $device->getKey(),
    ]);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    // Stricter than the usual rule. Elsewhere an active enrolment lets a teacher
    // read a platform-owned row about their student; a session is not one of
    // those, because which machine someone studies on is not academic
    // information. Enrolment buys nothing here.
    $this->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(0);

    $this->deleteJson("/api/v1/auth/sessions/{$session->uuid}")->assertNotFound();
});

it('gives a student one device list across all their teachers', function (): void {
    $student = User::factory()->create([
        'email' => 'noor@example.com',
        'password' => 'password',
        'platform_role' => PlatformRole::Student,
    ]);

    [$maths] = $this->createWorkspaceWithOwner(['name' => 'Maths']);
    [$physics] = $this->createWorkspaceWithOwner(['name' => 'Physics']);
    [$arabic] = $this->createWorkspaceWithOwner(['name' => 'Arabic']);

    foreach ([$maths, $physics, $arabic] as $workspace) {
        enrolStudentWith($workspace, $student);
    }

    $this->asGuest();

    $this->withHeaders(['X-Device-Id' => 'laptop'])
        ->postJson('/api/v1/auth/login', ['email' => 'noor@example.com', 'password' => 'password'])
        ->assertOk();

    // One device, not one per teacher. The opposite mistake — BelongsToWorkspace
    // on Device — would give this student three device slots and, with a limit of
    // one each, no limit at all.
    expect(Device::query()->where('user_id', $student->getKey())->count())->toBe(1);
});

it('gives a student one profile across all their teachers', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    StudentProfile::query()->create([
        'user_id' => $student->getKey(),
        'grade_level_slug' => 'secondary',
    ]);

    [$maths] = $this->createWorkspaceWithOwner(['name' => 'Maths']);
    [$physics] = $this->createWorkspaceWithOwner(['name' => 'Physics']);

    enrolStudentWith($maths, $student);
    enrolStudentWith($physics, $student);

    // A grade level per teacher would mean one person in two years at once.
    expect(StudentProfile::query()->where('user_id', $student->getKey())->count())->toBe(1);
});

it('shows an assistant nothing either', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    enrolStudentWith($workspace, $student);

    $assistant = $this->addWorkspaceMember($workspace, Roles::ASSISTANT_TEACHER);

    $device = Device::factory()->create(['user_id' => $student->getKey()]);
    AuthSession::factory()->create([
        'user_id' => $student->getKey(),
        'device_id' => $device->getKey(),
    ]);

    Sanctum::actingAs($assistant);
    $this->setCurrentWorkspace($workspace, $assistant);

    $this->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(0);
});

it('keeps two-factor state off the workspace layer', function (): void {
    $teacher = User::factory()->create();

    $teacher->saveAppAuthenticationSecret('SECRET123');

    [$first] = $this->createWorkspaceWithOwner(['name' => 'First'], []);
    $this->addOwnedWorkspace($teacher, 'Second');

    // One enrolment for the person, not one per academy they run.
    expect($teacher->securitySettings()->count())->toBe(1)
        ->and($teacher->fresh()->getAppAuthenticationSecret())->toBe('SECRET123')
        ->and($first)->not->toBeNull();
});
