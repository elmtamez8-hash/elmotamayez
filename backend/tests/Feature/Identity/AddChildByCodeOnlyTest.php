<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Shared\Support\GuardianPermission;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Owner decision (2026-09-24): a guardian adding a child who ALREADY HAS AN
| ACCOUNT gives the code and nothing else — no name, no age, no year.
|
| ⛔ AND THE GUARDIAN LEARNS NOTHING BEFORE THE CHILD ACCEPTS. So the three are
| not read off the account at request time and masked on the way out (a screen
| hiding a field — the export walk reads `student_name` straight off the row);
| they are not on the row at all until `AcceptRelation` fills them, inside the
| claim, from the student's own account.
|
| ⚠️ ASSERTIONS ON DECODED JSON, NEVER `getContent()`: the body escapes Arabic,
| so «the name is not in the response» would be vacuously true.
*/

function codeOnlyStudent(): User
{
    $student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'first_name' => 'Karim',
        'last_name' => 'Codeonly',
    ]);

    StudentProfile::query()->create([
        'user_id' => $student->getKey(),
        'date_of_birth' => now()->subYears(14)->subMonth()->toDateString(),
        'school_year_slug' => 'year-10',
    ]);

    return $student;
}

function codeOnlyParent(): User
{
    return User::factory()->create(['platform_role' => PlatformRole::Parent]);
}

/** @return array<string, mixed> */
function codeOnlyPayload(string $uuid): array
{
    return [
        'student_uuid' => $uuid,
        'relation_type' => 'parent',
        'permissions' => [GuardianPermission::Attendance->value, GuardianPermission::Results->value],
    ];
}

it('creates a pending request from the code alone, holding nothing about the child', function (): void {
    $student = codeOnlyStudent();
    Sanctum::actingAs(codeOnlyParent());

    $response = $this->postJson('/api/v1/family/relations', codeOnlyPayload($student->uuid))
        ->assertCreated()
        ->assertJsonPath('status', RelationStatus::Pending->value);

    expect($response->json('student_name'))->toBe('')
        ->and($response->json('student_age'))->toBeNull()
        ->and($response->json('student_school_year_slug'))->toBeNull()
        ->and($response->json('student_school_year_name'))->toBeNull()
        ->and($response->json('student_grade_level_slug'))->toBeNull();

    $row = ParentStudentRelation::query()->sole();

    expect($row->student_user_id)->toBe($student->getKey())
        ->and($row->student_name)->toBe('')
        ->and($row->student_age)->toBeNull()
        ->and($row->student_school_year_slug)->toBeNull();
});

it('drops a name, age or year sent beside the code rather than storing the guardian\'s guess', function (): void {
    $student = codeOnlyStudent();
    Sanctum::actingAs(codeOnlyParent());

    $this->postJson('/api/v1/family/relations', codeOnlyPayload($student->uuid) + [
        'student_name' => 'WRONG_NAME_SENTINEL',
        'age' => 9,
        'school_year_slug' => 'year-3',
    ])->assertCreated();

    $row = ParentStudentRelation::query()->sole();

    expect($row->student_name)->toBe('')
        ->and($row->student_age)->toBeNull()
        ->and($row->student_school_year_slug)->toBeNull();
});

it('lists the pending request to the guardian with no name, age or year', function (): void {
    $student = codeOnlyStudent();
    $parent = codeOnlyParent();
    Sanctum::actingAs($parent);

    $this->postJson('/api/v1/family/relations', codeOnlyPayload($student->uuid))->assertCreated();

    $row = $this->getJson('/api/v1/family/relations')->assertOk()->json('0');

    expect($row['viewer_side'])->toBe('guardian')
        ->and($row['status'])->toBe(RelationStatus::Pending->value)
        ->and($row['student_name'])->toBe('')
        ->and($row['student_age'])->toBeNull()
        ->and($row['student_school_year_slug'])->toBeNull()
        ->and($row['student_school_year_name'])->toBeNull()
        ->and($row['student_grade_level_slug'])->toBeNull();
});

it('fills the name, age and year from the account the moment the child accepts', function (): void {
    $student = codeOnlyStudent();
    $parent = codeOnlyParent();
    Sanctum::actingAs($parent);

    $uuid = $this->postJson('/api/v1/family/relations', codeOnlyPayload($student->uuid))->json('uuid');

    Sanctum::actingAs($student);
    $this->postJson("/api/v1/family/relations/{$uuid}/accept")->assertOk();

    Sanctum::actingAs($parent);
    $row = $this->getJson('/api/v1/family/relations')->assertOk()->json('0');

    expect($row['status'])->toBe(RelationStatus::Active->value)
        ->and($row['student_name'])->toBe('Karim Codeonly')
        ->and($row['student_age'])->toBe(14)
        ->and($row['student_school_year_slug'])->toBe('year-10')
        ->and($row['student_grade_level_slug'])->toBe('secondary');
});

it('keeps a name already on the row when the request is accepted (coalesce, never overwrite)', function (): void {
    $student = codeOnlyStudent();
    $parent = codeOnlyParent();

    // A link made before the code-only rule, carrying the name the guardian typed.
    $relation = ParentStudentRelation::query()->create([
        'guardian_user_id' => $parent->getKey(),
        'student_user_id' => $student->getKey(),
        'student_name' => 'TYPED_NAME_SENTINEL',
        'relation_type' => 'parent',
        'permissions' => [GuardianPermission::Attendance->value],
        'status' => RelationStatus::Pending->value,
        'requested_by_user_id' => $parent->getKey(),
    ]);

    Sanctum::actingAs($student);
    $this->postJson("/api/v1/family/relations/{$relation->uuid}/accept")->assertOk();

    $relation->refresh();

    expect($relation->student_name)->toBe('TYPED_NAME_SENTINEL')
        ->and($relation->student_age)->toBe(14)
        ->and($relation->student_school_year_slug)->toBe('year-10');
});

it('still requires a name for a child with no account', function (): void {
    Sanctum::actingAs(codeOnlyParent());

    $this->postJson('/api/v1/family/relations', [
        'relation_type' => 'parent',
        'permissions' => [GuardianPermission::Attendance->value],
    ])->assertStatus(422)->assertJsonValidationErrors('student_name');

    expect(ParentStudentRelation::query()->count())->toBe(0);
});

it('answers an unknown code exactly as a non-student one, with no name sent', function (): void {
    $teacher = User::factory()->create(['platform_role' => PlatformRole::Teacher]);
    Sanctum::actingAs(codeOnlyParent());

    $unknown = $this->postJson('/api/v1/family/relations', codeOnlyPayload((string) Str::uuid()))
        ->assertStatus(422);
    $notStudent = $this->postJson('/api/v1/family/relations', codeOnlyPayload($teacher->uuid))
        ->assertStatus(422);

    expect($unknown->json())->toBe($notStudent->json())
        ->and($unknown->json('message'))->toBe('لم نجد حساب طالب بهذا المعرّف.')
        ->and(ParentStudentRelation::query()->count())->toBe(0);
});
