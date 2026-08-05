<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Notifications\Models\ContactVerification;
use App\Modules\Notifications\Models\MessageTemplate;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Support\NotificationChannel;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Traits\BelongsToWorkspace;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Constitution I, the half with no automatic gate.
 *
 * Every entity this spec adds is platform-owned or a bridge, which means none of
 * them carries workspace_id and no global scope touches them. A query against
 * parent_student_relations returns every family on the platform — the same total
 * exposure an unauthenticated marketplace query has, for the same reason.
 *
 * So both failure directions are tested here, because both are silent:
 *
 *   1. NO HORIZONTAL LEAK — a teacher must not read a student they do not teach.
 *      Missing this, the maths teacher reads the physics teacher's families.
 *
 *   2. ONE ENTITY, NOT ONE PER TEACHER — a student studying with three teachers
 *      has one preference, one guardian relation, one feed. Missing this is the
 *      mirror-image bug: BelongsToWorkspace applied where it does not belong,
 *      which shows up months later as duplicated people.
 */
function teacherIn(Workspace $workspace): User
{
    $teacher = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $role = Role::findOrCreate('relations-reader', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::RELATIONS_VIEW_STUDENT, 'web'));

    $teacher->assignRole($role);
    $teacher->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

    return $teacher;
}

function enrol(Workspace $workspace, User $student): Enrollment
{
    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

    return Enrollment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(),
        'status' => 'active',
    ]);
}

it('lets a teacher read the guardians of a student enrolled with them', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    enrol($workspace, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $teacher = teacherIn($workspace);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertOk();
});

// The constitutional guard. The permission alone must not be enough — every
// teacher holds it, so it would gate nothing.
it('refuses a teacher the guardians of a student not enrolled with them', function (): void {
    [$mine] = $this->createWorkspaceWithOwner(['name' => 'رياضيات']);
    [$theirs] = $this->createWorkspaceWithOwner(['name' => 'فيزياء']);

    $student = User::factory()->create();
    enrol($theirs, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $teacher = teacherIn($mine);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertStatus(403);
});

it('refuses a teacher who lost the enrollment', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    $enrollment = enrol($workspace, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $teacher = teacherIn($workspace);
    Sanctum::actingAs($teacher);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertOk();

    $enrollment->forceFill(['status' => 'cancelled'])->save();

    // Access follows the enrollment, not a snapshot taken when it started.
    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertStatus(403);
});

it('refuses a teacher reading guardians without the permission at all', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    enrol($workspace, $student);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);

    $stranger = User::factory()->create();
    $stranger->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/family/relations/{$relation->uuid}")->assertStatus(403);
});

// Direction two: the entity must not multiply per teacher.
it('gives a student one preference set across every teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);
    [$c] = $this->createWorkspaceWithOwner(['name' => 'ج']);

    $student = User::factory()->create();

    foreach ([$a, $b, $c] as $workspace) {
        enrol($workspace, $student);
    }

    Sanctum::actingAs($student);

    $this->putJson('/api/v1/notifications/preferences', [
        'preferences' => [[
            'type' => NotificationType::EnrollmentCreated->value,
            'channels' => [NotificationChannel::InApp->value],
        ]],
    ])->assertOk();

    expect(NotificationPreference::query()->where('user_id', $student->getKey())->count())->toBe(1);
});

it('gives a student one guardian relation across every teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();
    enrol($a, $student);
    enrol($b, $student);

    ParentStudentRelation::factory()->parent()->create(['student_user_id' => $student->getKey()]);

    Sanctum::actingAs($student);

    expect($this->getJson('/api/v1/family/relations')->json())->toHaveCount(1);
});

it('gives a student one feed across every teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();

    Notification::factory()->create([
        'recipient_user_id' => $student->getKey(),
        'workspace_id' => $a->getKey(),
    ]);
    Notification::factory()->create([
        'recipient_user_id' => $student->getKey(),
        'workspace_id' => $b->getKey(),
    ]);

    Sanctum::actingAs($student);

    // Two notifications, one stream. Not "two per workspace", and not "only the
    // current workspace's".
    expect($this->getJson('/api/v1/notifications')->json('data'))->toHaveCount(2);
});

it('keeps none of the new tables workspace-scoped', function (): void {
    $models = [
        Notification::class,
        NotificationPreference::class,
        ParentStudentRelation::class,
        NotificationDelivery::class,
        MessageTemplate::class,
        ContactVerification::class,
    ];

    foreach ($models as $model) {
        expect(in_array(
            BelongsToWorkspace::class,
            class_uses_recursive($model),
            true,
        ))->toBeFalse("{$model} must not be workspace-scoped");
    }
});
