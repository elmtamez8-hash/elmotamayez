<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ `student_profiles` WAS WRITTEN ONCE, AT REGISTRATION, AND NEVER AGAIN.
|
| A student in year 9 is in year 10 twelve months later, so a field that changes
| BY DEFINITION and has no door is not a stable field — it is a field that becomes
| wrong with the passage of time, on the payload that decides which board they
| appear on and which teachers the marketplace suggests.
*/
beforeEach(function (): void {
    $this->student = User::factory()->create(['last_workspace_id' => null]);

    StudentProfile::query()->create([
        'user_id' => $this->student->getKey(),
        'school_year_slug' => SchoolYear::query()->activelyOffered()->value('slug'),
    ]);

    $this->region = Region::query()->where('is_active', true)->firstOrFail();
    $this->year = SchoolYear::query()->activelyOffered()->orderByDesc('id')->firstOrFail();
});

it('lets a student correct the year they are in and the region they are from', function (): void {
    Sanctum::actingAs($this->student);

    $this->patchJson('/api/v1/me/student-profile', [
        'school_year_slug' => $this->year->slug,
        'region_slug' => $this->region->slug,
    ])
        ->assertOk()
        ->assertJsonPath('student_profile.school_year_slug', $this->year->slug)
        ->assertJsonPath('student_profile.region_slug', $this->region->slug);

    expect($this->student->studentProfile->refresh()->region_id)->toBe($this->region->getKey());
});

it('derives the stage rather than accepting one', function (): void {
    /*
    | Two answers to one question part company at the first edit of the mapping,
    | which FR-001ج forbids in words. The stage in the payload comes from the YEAR
    | that was saved — so sending a stage cannot make the two disagree, because
    | nothing reads it.
    */
    Sanctum::actingAs($this->student);

    $body = $this->patchJson('/api/v1/me/student-profile', [
        'school_year_slug' => $this->year->slug,
        'region_slug' => $this->region->slug,
        'grade_level_slug' => 'primary',
    ])->assertOk()->json('student_profile');

    expect($body['grade_level_slug'])->toBe(SchoolYear::stageFor($this->year->slug, null))
        ->and($body['grade_level_slug'])->not->toBe('primary');
});

it('refuses a year outside the offered catalogue', function (): void {
    // ⚠️ THE SAME PREDICATE THE SIGNUP FORM IS FED (`activelyOffered`). A door
    // that validated a wider list than the screen offers is two answers to one
    // question from opposite sides — the shape spec 022 already paid for once.
    Sanctum::actingAs($this->student);

    $this->patchJson('/api/v1/me/student-profile', [
        'school_year_slug' => 'year-99',
        'region_slug' => $this->region->slug,
    ])->assertStatus(422)->assertJsonValidationErrors('school_year_slug');
});

it('refuses an account with no student profile, with a sentence', function (): void {
    Sanctum::actingAs(User::factory()->create(['last_workspace_id' => null]));

    $this->patchJson('/api/v1/me/student-profile', [
        'school_year_slug' => $this->year->slug,
        'region_slug' => $this->region->slug,
    ])->assertStatus(403)->assertJsonPath('message', 'لا يوجد ملف طالب لهذا الحساب.');
});

it('leaves the date of birth and the guardian contact alone', function (): void {
    /*
    | ⛔ DELIBERATELY OUT OF REACH. The date of birth drives the guardian-consent
    | gate (FR-009) and 013's coming-of-age sweep, and the guardian's number is
    | the address that consent is requested at — so a minor who could edit either
    | from a settings screen would be switching off the gate from inside it.
    */
    $profile = $this->student->studentProfile;
    $profile->forceFill([
        'date_of_birth' => '2012-01-01',
        'guardian_contact' => '+97455512345',
    ])->save();

    Sanctum::actingAs($this->student);

    $this->patchJson('/api/v1/me/student-profile', [
        'school_year_slug' => $this->year->slug,
        'region_slug' => $this->region->slug,
        'date_of_birth' => '1990-01-01',
        'guardian_contact' => '+97455599999',
    ])->assertOk();

    $fresh = $profile->refresh();

    expect($fresh->date_of_birth?->toDateString())->toBe('2012-01-01')
        ->and($fresh->guardian_contact)->toBe('+97455512345');
});
