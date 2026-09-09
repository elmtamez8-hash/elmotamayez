<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| Spec 030 · R11 — WHO MAY ASK TO BE SOMEBODY'S GUARDIAN.
|
| ⚠️ THIS FILE GUARDS A HOLE THE ACCEPT ROUTE OPENED IN ALREADY-SHIPPED CODE.
| `LinkGuardianRequest::authorize()` returns true, `FamilyController::store` asks
| no policy, and `LinkGuardian` used to check only the TARGET — so, in
| `DataRequestPolicy`'s own words, "a pending link … any user can create for any
| student uuid". Those uuids are not secret: `ReadSessionRoster` hands one to every
| seat holder in a live room.
|
| It was inert while `pending` granted nothing. With a button on the student's
| screen it becomes: a teacher or a classmate requests a link carrying
| `data_rights`, the child taps accept once, and the requester can open a
| data-rights request that assembles everything the platform knows about them —
| with the constitutional teacher-visibility guard bypassed, because they are no
| longer reading as a teacher.
*/

function linkPayload(User $student): array
{
    return [
        'student_name' => $student->name,
        'student_uuid' => $student->uuid,
        'relation_type' => 'guardian',
        'permissions' => [GuardianPermission::DataRights->value],
    ];
}

it('lets a guardian account attach an existing student', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs(User::factory()->create(['platform_role' => PlatformRole::Parent]));

    $this->postJson('/api/v1/family/relations', linkPayload($student))->assertCreated();

    expect(ParentStudentRelation::query()->where('student_user_id', $student->getKey())->count())->toBe(1);
});

/*
| ⚠️ THE REFUSAL IS THE SAME SENTENCE `resolveStudent` ALREADY USED, deliberately.
| A distinct message for "you are not a guardian account" would turn the endpoint
| into an oracle for platform roles instead of one for accounts — the same reason
| "no such uuid" and "not a student" have always answered identically here.
*/
it('refuses a teacher naming a student by uuid, in the same words as an unknown account', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs(User::factory()->create(['platform_role' => PlatformRole::Teacher]));

    $this->postJson('/api/v1/family/relations', linkPayload($student))
        ->assertStatus(422)
        ->assertJsonPath('message', 'لم نجد حساب طالب بهذا المعرّف.');

    expect(ParentStudentRelation::query()->count())->toBe(0);
});

it('refuses a fellow student naming a classmate by uuid', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);

    Sanctum::actingAs(User::factory()->create(['platform_role' => PlatformRole::Student]));

    $this->postJson('/api/v1/family/relations', linkPayload($student))
        ->assertStatus(422)
        ->assertJsonPath('message', 'لم نجد حساب طالب بهذا المعرّف.');

    expect(ParentStudentRelation::query()->count())->toBe(0);
});

/*
| The name-only path is untouched: it names no account, so there is nobody to
| impersonate and nobody to notify — which is the same fact that makes such a row
| `active` at once (FR-010).
*/
it('leaves a name-only child open to any account, active at once', function (): void {
    Sanctum::actingAs(User::factory()->create(['platform_role' => PlatformRole::Teacher]));

    $this->postJson('/api/v1/family/relations', [
        'student_name' => 'كريم',
        'relation_type' => 'guardian',
        'permissions' => [GuardianPermission::Attendance->value],
    ])->assertCreated();

    expect(ParentStudentRelation::query()->first()->status)->toBe(RelationStatus::Active->value);
});
