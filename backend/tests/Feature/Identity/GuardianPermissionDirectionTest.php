<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| Spec 030 · US3 · FR-009 — narrowing is either party's right, widening is the
| student's alone.
|
| Without this, accepting a link is a blank cheque: the student agrees to
| «attendance» and the guardian then grants themselves «payments» and «results»
| with one request against their own row, because `ParentStudentRelationPolicy::update`
| is `isParty` — both sides.
*/

function narrowLink(User $guardian, User $student): ParentStudentRelation
{
    return ParentStudentRelation::factory()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'requested_by_user_id' => $guardian->getKey(),
        'permissions' => [GuardianPermission::Attendance->value, GuardianPermission::Payments->value],
    ]);
}

it('refuses a guardian widening their own reach (SC-005)', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    $relation = narrowLink($guardian, $student);

    Sanctum::actingAs($guardian);

    $this->patchJson("/api/v1/family/relations/{$relation->uuid}", [
        'permissions' => [
            GuardianPermission::Attendance->value,
            GuardianPermission::Payments->value,
            GuardianPermission::Results->value,
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكنك منح نفسك صلاحية جديدة. التوسيع قرار الطالب.');

    expect($relation->fresh()->permissions)
        ->toBe([GuardianPermission::Attendance->value, GuardianPermission::Payments->value]);
});

it('lets a guardian give a permission up', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    $relation = narrowLink($guardian, $student);

    Sanctum::actingAs($guardian);

    $this->patchJson("/api/v1/family/relations/{$relation->uuid}", [
        'permissions' => [GuardianPermission::Attendance->value],
    ])->assertOk();

    expect($relation->fresh()->permissions)->toBe([GuardianPermission::Attendance->value]);
});

it('lets the student widen it', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    $relation = narrowLink($guardian, $student);

    Sanctum::actingAs($student);

    $this->patchJson("/api/v1/family/relations/{$relation->uuid}", [
        'permissions' => [
            GuardianPermission::Attendance->value,
            GuardianPermission::Payments->value,
            GuardianPermission::Results->value,
        ],
    ])->assertOk();

    expect($relation->fresh()->permissions)->toContain(GuardianPermission::Results->value);
});

/*
| ⚠️ AND THE SUPER ADMIN, WHO IS NEITHER PARTY.
|
| `Gate::before` waves them past `update()` — which is `isParty` — and the
| direction rule only fires «if the actor is the guardian». So a rule written in
| the policy alone leaves exactly one actor able to widen a guardian's reach over a
| student who never agreed to it: FR-009 broken by the one actor R6 was written
| about, on the sibling endpoint.
*/
it('refuses a super admin editing what a guardian may see', function (): void {
    $student = User::factory()->create(['platform_role' => PlatformRole::Student]);
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    $relation = narrowLink($guardian, $student);

    Sanctum::actingAs(User::factory()->create(['is_super_admin' => true]));

    $this->patchJson("/api/v1/family/relations/{$relation->uuid}", [
        'permissions' => [GuardianPermission::DataRights->value],
    ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'لا يمكنك تعديل صلاحيّات هذا الرابط.');

    expect($relation->fresh()->permissions)
        ->toBe([GuardianPermission::Attendance->value, GuardianPermission::Payments->value]);
});
