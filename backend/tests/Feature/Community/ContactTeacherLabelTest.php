<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\TeacherContactName;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| The name on «راسِل …» / «تواصل مع المدرّس» (owner decision 2026-09-28).
|
| ⛔ It was the WORKSPACE's name, so inside an academy the button read «راسل Nour
| Academy». Now: the COURSE's teacher (its author, `created_by`), with the academy
| in brackets — and a solo teacher's own workspace, which IS the teacher, is not
| repeated. The conversation itself is unchanged: still keyed on the workspace.
*/

it('builds the label: teacher (academy), teacher alone when solo, the workspace when the author is gone', function (): void {
    expect(TeacherContactName::of('Sara Adel', 'Nour Academy', false))->toBe('Sara Adel (Nour Academy)')
        ->and(TeacherContactName::of('Sara Adel', 'Sara Adel Math', true))->toBe('Sara Adel')
        ->and(TeacherContactName::of('Sara Adel', 'Sara Adel', false))->toBe('Sara Adel')
        ->and(TeacherContactName::of(null, 'Nour Academy', false))->toBe('Nour Academy')
        ->and(TeacherContactName::of('  ', '', false))->toBe('المدرّس');
});

it('names the course teacher inside an academy on the student enrolment list', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Nour Academy', 'type' => 'academy']);
    $this->setCurrentWorkspace($workspace, $owner);

    $teacher = $this->addWorkspaceMember($workspace, Roles::TEACHER);
    $teacher->forceFill(['first_name' => 'Sara', 'last_name' => 'Adel'])->save();

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'created_by' => $teacher->getKey()]);
    $student = User::factory()->create();
    $this->createEnrollment($workspace, $course, $student);

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/enrollments')
        ->assertOk()
        ->assertJsonPath('data.0.contact_name', 'Sara Adel (Nour Academy)')
        ->assertJsonPath('data.0.workspace_uuid', (string) $workspace->uuid);
});

it('names a solo teacher once on the enrolment list', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner(['name' => 'Math with Omar', 'type' => 'teacher']);
    $owner->forceFill(['first_name' => 'Omar', 'last_name' => 'Hany'])->save();
    $this->setCurrentWorkspace($workspace, $owner);

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey()]);
    $student = User::factory()->create();
    $this->createEnrollment($workspace, $course, $student);

    Sanctum::actingAs($student);

    $this->getJson('/api/v1/enrollments')->assertOk()->assertJsonPath('data.0.contact_name', 'Omar Hany');
});

it('publishes the contact on the public course page and the public teacher page', function (): void {
    $workspace = marketplaceWorkspace('Nour Academy');
    $profile = marketplaceTeacher($workspace);
    $name = (string) User::query()->find($profile->user_id)?->name;

    $course = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): Course => Course::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'created_by' => $profile->user_id,
    ]));

    $this->asGuest();

    $this->getJson('/api/v1/marketplace/courses/'.$course->uuid)
        ->assertOk()
        ->assertJsonPath('data.contact.workspace_uuid', (string) $workspace->uuid)
        ->assertJsonPath('data.contact.name', $name.' (Nour Academy)');

    $this->getJson('/api/v1/marketplace/teachers/'.$profile->uuid)
        ->assertOk()
        ->assertJsonPath('data.contact.workspace_uuid', (string) $workspace->uuid)
        ->assertJsonPath('data.contact.name', $name.' (Nour Academy)');
});
