<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Region;
use App\Shared\Support\WorkspaceContext;

/*
| FR-042 adds a REQUIRED field to the front door of the product, and this file is
| what says the door still opens.
|
| ⚠️ EVERY ASSERTION HERE READS THE STORED ROW, NEVER THE RESPONSE ECHO. Spec 013
| shipped three columns on THIS EXACT TABLE that mass assignment discarded in
| silence — a 201 and three nulls — and every assertion written about them passed,
| because they were made against the response body, which echoes what was
| submitted rather than what was saved. `region_id` is in `$fillable`; this is how
| we know.
|
| ⚠️ AND THE CATALOGUE MUST NOT BE EMPTY. A required field validated against zero
| rows refuses every registration on the platform, in front of a picker with
| nothing in it — which is why the seeder ships with a backfill migration and why
| the first case below asserts the catalogue exists at all.
*/

beforeEach(function (): void {
    $workspace = marketplaceWorkspace();

    app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        // firstOrCreate, not create: TaxonomySeeder now runs before every Feature
        // test and the slug is unique platform-wide (spec 022 · T008).
        fn () => GradeLevel::query()->firstOrCreate(['slug' => 'secondary'], ['name' => 'المرحلة الثانوية', 'sort_order' => 0, 'is_active' => true]),
    );

    $this->asGuest();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function regionRegistrationPayload(array $overrides = []): array
{
    return [
        'first_name' => 'نورة',
        'last_name' => 'العطية',
        'email' => 'noura@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455512399',
        'country' => 'QA',
        'school_year_slug' => 'year-10',
        'region_slug' => 'doha',
        'date_of_birth' => '1997-02-02',
        'terms_accepted' => true,
        ...$overrides,
    ];
}

it('ships a catalogue with rows in it, because a required field needs options', function (): void {
    expect(Region::query()->where('is_active', true)->count())->toBeGreaterThan(1);
});

it('stores the region on the profile ROW, not merely in the response', function (): void {
    $this->postJson('/api/v1/auth/register/student', regionRegistrationPayload())->assertCreated();

    $user = User::query()->where('email', 'noura@example.com')->sole();
    $profile = StudentProfile::query()->where('user_id', $user->getKey())->sole();
    $doha = Region::query()->where('slug', 'doha')->sole();

    expect($profile->region_id)->toBe($doha->getKey());
});

it('refuses a registration with no region at all', function (): void {
    $payload = regionRegistrationPayload();
    unset($payload['region_slug']);

    $this->postJson('/api/v1/auth/register/student', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['region_slug']);
});

it('refuses a region that is not in the catalogue', function (): void {
    $this->postJson('/api/v1/auth/register/student', regionRegistrationPayload(['region_slug' => 'atlantis']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['region_slug']);
});

it('refuses a retired region, which is kept for the students already filed under it', function (): void {
    Region::query()->where('slug', 'al-khor')->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/register/student', regionRegistrationPayload(['region_slug' => 'al-khor']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['region_slug']);
});

it('publishes the catalogue to a visitor with no account', function (): void {
    // Public by necessity: the field is required to CREATE an account, so there
    // is no account to authenticate when the list is read.
    $payload = $this->getJson('/api/v1/marketplace/regions')->assertOk()->json();

    expect(collect($payload)->pluck('slug'))->toContain('doha');
});
