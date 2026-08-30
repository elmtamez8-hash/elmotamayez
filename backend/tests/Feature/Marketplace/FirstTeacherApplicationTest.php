<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherProfile;
use Laravel\Sanctum\Sanctum;

/*
| SC-002 — the FIRST teacher on a platform from zero can complete an
| application (spec 022 · US2).
|
| ⚠️ THE FIXTURE IS "NOBODY IS LISTED", AND THAT IS THE WHOLE POINT. The subject
| picker was fed by `ListPublicTaxonomy`, which drops every entry with no
| publicly listed teacher — correct for a marketplace filter bar, and on a
| required signup field a CIRCULAR LOCK: no listed teacher ⇒ no subject in the
| list ⇒ nobody can ever become the first listed teacher.
|
| Every case here therefore runs against a database with zero published
| profiles, which is exactly the state the marketplace reads answer `[]` in.
|
| ⚠️ THE PAYLOAD HELPERS ARE LOCAL rather than reused from
| `TeacherApplicationTest`: a global function declared inside a test file is only
| available to files Pest happens to load AFTER it, which nothing guarantees.
*/

/** @return array<string, mixed> */
function firstTeacherStepOne(): array
{
    return [
        'first_name' => 'خالد',
        'last_name' => 'العطية',
        'email' => 'first-teacher@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455598765',
        'country' => 'QA',
        'terms_accepted' => true,
    ];
}

/** @return array<string, mixed> */
function firstTeacherStepTwo(): array
{
    return [
        'subjects' => ['math'],
        'grade_levels' => ['secondary'],
        'years_experience' => 8,
        'qualifications' => ['بكالوريوس رياضيات'],
        'teaching_languages' => ['ar', 'en'],
        'headline' => 'مدرّس رياضيات للثانوية',
        'bio' => 'أدرّس منذ ثمانية أعوام.',
    ];
}

/** Register the applicant and act as them. */
function firstTeacherApplicant(): User
{
    test()->postJson('/api/v1/auth/register/teacher/step-1', firstTeacherStepOne())->assertCreated();

    $applicant = User::query()->where('email', 'first-teacher@example.com')->firstOrFail();

    Sanctum::actingAs($applicant);

    return $applicant;
}

beforeEach(function (): void {
    $this->asGuest();

    expect(TeacherProfile::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('offers a full vocabulary while the marketplace offers none', function (): void {
    expect($this->getJson('/api/v1/marketplace/subjects')->assertOk()->json())->toBe([])
        ->and($this->getJson('/api/v1/marketplace/grade-levels')->assertOk()->json())->toBe([]);

    $subjects = collect($this->getJson('/api/v1/signup/subjects')->assertOk()->json())->pluck('slug');
    $stages = collect($this->getJson('/api/v1/signup/grade-levels')->assertOk()->json())->pluck('slug');

    expect($subjects)->toContain('math', 'science')
        ->and($stages)->toContain('secondary', 'kindergarten');
});

it('accepts the professional step from the first applicant on the platform', function (): void {
    firstTeacherApplicant();

    $this->putJson('/api/v1/teacher/application/step-2', firstTeacherStepTwo())->assertOk();
});

it('refuses a subject that is not in the vocabulary', function (): void {
    // FR-009. Before this spec the rule was `string|max:100`, so free text was
    // written into the profile and then matched no filter the marketplace could
    // ever offer — a teacher listed under a subject nobody can search for.
    firstTeacherApplicant();

    $this->putJson('/api/v1/teacher/application/step-2', [
        ...firstTeacherStepTwo(),
        'subjects' => ['quantum-astrology'],
    ])->assertStatus(422)->assertJsonValidationErrors('subjects.0');
});

it('refuses a subject that exists but was retired', function (): void {
    Subject::query()->where('slug', 'french')->update(['is_active' => false]);

    firstTeacherApplicant();

    $this->putJson('/api/v1/teacher/application/step-2', [
        ...firstTeacherStepTwo(),
        'subjects' => ['french'],
    ])->assertStatus(422);
});

it('does not widen the marketplace filter bar', function (): void {
    /*
     | SC-008, and it holds BY CONSTRUCTION rather than by this assertion — the
     | signup read is a second Action, not a flag on the marketplace one. The
     | case exists because the tempting fix was that flag, and the first caller
     | who forgets it restores the circular lock silently.
     */
    $this->getJson('/api/v1/signup/subjects')->assertOk();
    $this->getJson('/api/v1/signup/grade-levels')->assertOk();

    expect($this->getJson('/api/v1/marketplace/subjects')->assertOk()->json())->toBe([])
        ->and($this->getJson('/api/v1/marketplace/grade-levels')->assertOk()->json())->toBe([]);
});
