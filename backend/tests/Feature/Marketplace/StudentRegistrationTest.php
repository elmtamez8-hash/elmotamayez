<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function studentPayload(array $overrides = []): array
{
    return [
        'first_name' => 'سارة',
        'last_name' => 'المري',
        'email' => 'sara@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455512345',
        'country' => 'QA',
        'grade_level_slug' => 'secondary',
        'registered_by_parent' => false,
        'terms_accepted' => true,
        /*
        | Spec 013. An ADULT by default, so every case that was about something
        | else keeps testing that thing: a minor now lands in
        | `pending_guardian_consent` and cannot sign in, which would make a dozen
        | unrelated assertions fail for a reason none of them is about.
        |
        | The minor path has its own cases, where the date is overridden
        | deliberately.
        */
        'date_of_birth' => '1998-04-12',
        // Spec 011 · FR-042 — required since the region field landed. The slug is
        // one `RegionSeeder` writes, and `tests/Pest.php` seeds that catalogue
        // before every Feature case for exactly this reason.
        'region_slug' => 'doha',
        ...$overrides,
    ];
}

beforeEach(function (): void {
    $workspace = marketplaceWorkspace();

    app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn () => GradeLevel::factory()->create(['slug' => 'secondary']),
    );

    $this->asGuest();
});

it('registers a student and stamps the platform role', function (): void {
    $this->postJson('/api/v1/auth/register/student', studentPayload())
        ->assertCreated()
        ->assertJsonPath('user.email', 'sara@example.com')
        ->assertJsonPath('user.platform_role', 'student')
        // Nested, not flat: student-only facts live in student_profiles now, so a
        // teacher's payload does not carry a null grade that reads as missing data.
        ->assertJsonPath('user.student_profile.grade_level_slug', 'secondary')
        ->assertJsonStructure(['user', 'token', 'session_uuid']);

    $user = User::where('email', 'sara@example.com')->sole();

    expect($user->platform_role)->toBe(PlatformRole::Student)
        ->and($user->studentProfile->grade_level_slug)->toBe('secondary')
        ->and($user->studentProfile->registered_by_parent)->toBeFalse()
        ->and($user->country)->toBe('QA');
});

// FR-010: the three new signup paths create an account and nothing else. A stray
// workspace here would hand a student an academy they never asked for — and,
// because spatie runs in team mode, a role inside it.
it('creates no workspace and grants no role', function (): void {
    $before = Workspace::query()->count();

    $this->postJson('/api/v1/auth/register/student', studentPayload())->assertCreated();

    $user = User::where('email', 'sara@example.com')->sole();

    expect(Workspace::query()->count())->toBe($before)
        ->and($user->workspaces()->count())->toBe(0)
        ->and($user->last_workspace_id)->toBeNull()
        ->and(DB::table('model_has_roles')->where('model_id', $user->getKey())->count())->toBe(0);
});

// FR-065: the checkbox ships unchecked, so an omitted value must fail rather
// than default to consent.
it('refuses to register without accepted terms', function (string|bool|null $value): void {
    $this->postJson('/api/v1/auth/register/student', studentPayload(['terms_accepted' => $value]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['terms_accepted']);

    expect(User::where('email', 'sara@example.com')->exists())->toBeFalse();
})->with([false, null, '0']);

it('rejects a duplicate email', function (): void {
    User::factory()->create(['email' => 'sara@example.com']);

    $this->postJson('/api/v1/auth/register/student', studentPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects an unknown grade level', function (): void {
    $this->postJson('/api/v1/auth/register/student', studentPayload(['grade_level_slug' => 'hogwarts']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['grade_level_slug']);
});

it('accepts a grade level slug from any participating workspace', function (): void {
    $other = marketplaceWorkspace('Second Academy');

    app(WorkspaceContext::class)->forWorkspace(
        $other,
        fn () => GradeLevel::factory()->create(['slug' => 'primary']),
    );

    $this->asGuest();

    $this->postJson('/api/v1/auth/register/student', studentPayload(['grade_level_slug' => 'primary']))
        ->assertCreated();
});

it('returns Arabic validation messages', function (): void {
    $response = $this->postJson('/api/v1/auth/register/student', studentPayload(['email' => 'not-an-email']));

    $message = $response->json('errors.email.0');

    expect($message)->toBeString()
        ->and(preg_match('/\p{Arabic}/u', (string) $message))->toBe(1);
});

// FR-066 implies one submit; a jittery connection must not create two accounts.
it('replays the first response for a repeated idempotency key', function (): void {
    $headers = ['Idempotency-Key' => 'signup-abc-123'];

    $first = $this->postJson('/api/v1/auth/register/student', studentPayload(), $headers)->assertCreated();
    $second = $this->postJson('/api/v1/auth/register/student', studentPayload(), $headers)->assertCreated();

    expect($second->json('user.uuid'))->toBe($first->json('user.uuid'))
        ->and(User::where('email', 'sara@example.com')->count())->toBe(1);
});
