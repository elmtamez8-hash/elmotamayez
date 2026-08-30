<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Shared\Support\GuardianPermission;
use Laravel\Sanctum\Sanctum;

/*
| SC-001 — a student completes registration on a platform where NO teacher is
| publicly listed (spec 022 · US1).
|
| ⚠️ THERE IS NO WORKSPACE AND NO TEACHER IN THIS FIXTURE, DELIBERATELY. The
| stage picker was fed by `ListPublicTaxonomy`, which drops every entry with no
| publicly listed teacher — so on a fresh deployment the required field rendered
| an empty select and no student could register at all. A fixture that creates a
| published teacher first passes over exactly that.
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function schoolYearPayload(array $overrides = []): array
{
    return [
        'first_name' => 'ريم',
        'last_name' => 'الكواري',
        'email' => 'reem@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455512300',
        'country' => 'QA',
        'school_year_slug' => 'year-10',
        'region_slug' => 'doha',
        // An adult by default: a minor lands in `pending_guardian_consent`, which
        // would make assertions about something else fail for a reason none of
        // them is about.
        'date_of_birth' => '1998-04-12',
        'terms_accepted' => true,
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $this->asGuest();

    expect(TeacherProfile::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('registers a student on a platform with no listed teacher', function (): void {
    $this->postJson('/api/v1/auth/register/student', schoolYearPayload())
        ->assertCreated()
        ->assertJsonPath('user.student_profile.school_year_slug', 'year-10')
        // The STAGE is derived from the year, and keeps its key so every
        // existing reader goes on getting a broad stage.
        ->assertJsonPath('user.student_profile.grade_level_slug', 'secondary')
        ->assertJsonPath('user.student_profile.school_year_name', 'الصف العاشر');
});

it('stores the year on the ROW, not merely in the response', function (): void {
    /*
     | ⚠️ THE RESPONSE ECHOES WHAT WAS SUBMITTED, NOT WHAT WAS STORED — which is
     | why the assertion above cannot stand alone. A column missing from
     | `$fillable` is discarded by mass assignment in SILENCE: a 201 and a null.
     | Three columns shipped that way on THIS EXACT TABLE in spec 013, and the
     | whole suite stayed green because `SeedCommand` wraps every seeder in
     | `Model::unguarded()`, so only the request path can see it.
     */
    $this->postJson('/api/v1/auth/register/student', schoolYearPayload())->assertCreated();

    $user = User::query()->where('email', 'reem@example.com')->sole();
    $profile = StudentProfile::query()->where('user_id', $user->getKey())->sole();

    expect($profile->school_year_slug)->toBe('year-10')
        ->and($profile->stageSlug())->toBe('secondary')
        // The legacy column is NOT written: two stored answers to one question
        // is what FR-001ج forbids.
        ->and($profile->grade_level_slug)->toBeNull()
        ->and($user->platform_role)->toBe(PlatformRole::Student);
});

it('reads the old stage column for a student who registered before years existed', function (): void {
    // Edge case / SC-007. No backfill invented a year for them — «secondary»
    // does not say which year, and writing one would be inventing data.
    $legacy = User::factory()->create(['platform_role' => PlatformRole::Student]);

    $profile = StudentProfile::query()->create([
        'user_id' => $legacy->getKey(),
        'grade_level_slug' => 'preparatory',
    ]);

    expect($profile->school_year_slug)->toBeNull()
        ->and($profile->stageSlug())->toBe('preparatory');
});

it('refuses a year whose stage was retired', function (): void {
    // The one predicate: the door reads `activelyOffered()`, exactly as the
    // public list does, so it cannot accept what the screen does not show.
    GradeLevel::query()->where('slug', 'secondary')->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/register/student', schoolYearPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['school_year_slug']);
});

it('keeps the guardian half of the same fact', function (): void {
    // A guardian may add a child with NO ACCOUNT AT ALL, so this row is the only
    // place that child's year is recorded — and `student_user_id` being nullable
    // is why the derivation takes two strings rather than a profile.
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);
    Sanctum::actingAs($guardian);

    $this->postJson('/api/v1/family/relations', [
        'student_name' => 'سلمى',
        'age' => 14,
        'school_year_slug' => 'year-9',
        'relation_type' => 'parent',
        'permissions' => [GuardianPermission::Attendance->value],
    ])->assertStatus(201)
        ->assertJsonPath('student_school_year_slug', 'year-9')
        ->assertJsonPath('student_grade_level_slug', 'preparatory');

    $relation = ParentStudentRelation::query()
        ->where('guardian_user_id', $guardian->getKey())
        ->sole();

    // The row again, not the echo — `$fillable` is the silent failure here too.
    expect($relation->student_user_id)->toBeNull()
        ->and($relation->student_school_year_slug)->toBe('year-9')
        ->and($relation->stageSlug())->toBe('preparatory');
});

it('agrees with the screen on every signup field it validates', function (): void {
    /*
     | SC-003 / SC-009 — the door and the screen give ONE answer, and it is
     | asserted on ALL FOUR pickers rather than on one. The two used to disagree:
     | `RegisterStudentRequest` validated stages against `is_active` alone while
     | the form was fed a participation-filtered list, so the server accepted
     | what the screen never offered.
     */
    $offeredYears = collect($this->getJson('/api/v1/signup/school-years')->assertOk()->json())
        ->pluck('slug')->sort()->values();
    $offeredSubjects = collect($this->getJson('/api/v1/signup/subjects')->assertOk()->json())
        ->pluck('slug')->sort()->values();
    $offeredStages = collect($this->getJson('/api/v1/signup/grade-levels')->assertOk()->json())
        ->pluck('slug')->sort()->values();

    // 1 · the student's own year, and 2 · the guardian's field for their child —
    // both read the same scope, so both are compared against the same list.
    $doorYears = SchoolYear::query()->activelyOffered()->pluck('slug')->sort()->values();

    // 3 and 4 · the teacher's two fields.
    $doorSubjects = Subject::query()->where('is_active', true)->pluck('slug')->sort()->values();
    $doorStages = GradeLevel::query()->where('is_active', true)->pluck('slug')->sort()->values();

    expect($doorYears->all())->toBe($offeredYears->all())
        ->and($doorSubjects->all())->toBe($offeredSubjects->all())
        ->and($doorStages->all())->toBe($offeredStages->all());

    /*
     | And the agreement survives a retirement, which is where two spellings part
     | company: the year list must lose `year-10` on BOTH sides at once.
     |
     | ⚠️ RETIRED THROUGH THE MODEL, NOT A BULK `update()`. The reads above have
     | already warmed the cache, and a mass update retrieves no models — so
     | `GradeLevel::saved` never fires, `MarketplaceCache::flush()` is never
     | called, and the endpoint would answer from the stale key for up to a
     | minute. That is exactly what the operator does from `/admin`, and exactly
     | the shape of the `Region::saved` hook this spec had to add.
     */
    $stage = GradeLevel::query()->where('slug', 'secondary')->sole();
    $stage->is_active = false;
    $stage->save();

    $after = collect($this->getJson('/api/v1/signup/school-years')->assertOk()->json())->pluck('slug');

    expect($after)->not->toContain('year-10')
        ->and(SchoolYear::query()->activelyOffered()->pluck('slug'))->not->toContain('year-10');
});
