<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Accommodation;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on accommodations. `accommodations.manage` is not on the
| default assistant role; when the owner ticks it onto one, that assistant
| listed, granted and revoked for every student in the workspace — the list
| names the student and the reason for the arrangement.
|
| A confined assistant now lists, grants and revokes for the students of their
| own courses. A far student's grant is the SAME 404 as an unknown uuid.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->nearCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->farCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->near = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->far = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $this->nearCourse, $this->near);
    $this->createEnrollment($this->workspace, $this->farCourse, $this->far);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::ACCOMMODATIONS_MANAGE);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function acConfine(): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => test()->nearCourse->getKey(),
    ]);

    app()->forgetScopedInstances();
}

function acArrangement(User $student): Accommodation
{
    return Accommodation::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'student_user_id' => $student->getKey(),
        'granted_by' => test()->owner->getKey(),
    ]);
}

function acGrant(User $student): int
{
    return test()->postJson('/api/v1/manage/accommodations', [
        'student_uuid' => $student->uuid,
        'extra_time_pct' => 25,
        'extended_days' => 1,
        'reason' => 'قرار لجنة الدعم.',
    ])->getStatusCode();
}

/** @return list<string> */
function acListed(): array
{
    return collect(test()->getJson('/api/v1/manage/accommodations')->assertOk()->json('data'))
        ->pluck('student.uuid')->all();
}

it('lists, grants and revokes for a confined assistant\'s own students and for nobody else', function (): void {
    acConfine();
    $nearRow = acArrangement($this->near);
    $farRow = acArrangement($this->far);

    Sanctum::actingAs($this->assistant);

    expect(acListed())->toBe([(string) $this->near->uuid])
        ->and(acGrant($this->near))->toBe(201)
        // The same 404 an unknown uuid gets — no probe.
        ->and(acGrant($this->far))->toBe(404)
        ->and($this->deleteJson("/api/v1/manage/accommodations/{$farRow->uuid}")->getStatusCode())->toBe(403)
        ->and($this->deleteJson("/api/v1/manage/accommodations/{$nearRow->uuid}")->getStatusCode())->toBe(200);

    expect($farRow->fresh()?->revoked_at)->toBeNull();
});

it('leaves an unconfined assistant and the owner every student of the workspace', function (): void {
    acArrangement($this->near);
    acArrangement($this->far);

    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        expect(acListed())->toEqualCanonicalizing([(string) $this->near->uuid, (string) $this->far->uuid])
            ->and(acGrant($this->far))->toBe(201);
    }
});

it('never lists another workspace\'s arrangements', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();
    $stranger = $this->addWorkspaceMember($other, Roles::STUDENT);
    Accommodation::factory()->create([
        'workspace_id' => $other->getKey(),
        'student_user_id' => $stranger->getKey(),
        'granted_by' => $otherOwner->getKey(),
    ]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    acArrangement($this->near);

    Sanctum::actingAs($this->owner);

    expect(acListed())->toBe([(string) $this->near->uuid]);
});
