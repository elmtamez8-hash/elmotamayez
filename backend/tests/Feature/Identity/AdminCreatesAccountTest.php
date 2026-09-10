<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Filament\Pages\CreateAccount;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\SchoolYear;
use App\Modules\Tenancy\Support\Roles;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
| ⚠️ FILAMENT MUST PRODUCE EXACTLY WHAT THE ENDPOINT PRODUCES — the standard
| `TeacherApplicationResource` already sets in its own docblock. So these tests
| read the ROW, never the notification: a screen that reports success over a
| half-written account is the failure they exist to catch.
|
| ⚠️ AND THE DOOR IS TESTED IN THE ALLOW DIRECTION AS WELL AS THE DENY ONE. A
| deny-only test passes just as happily against a page that refuses everybody,
| and Laravel fails OPEN when no policy is registered — the `taxonomy.manage`
| lesson, where a permission classified by absence guarded zero files while
| passing every test written about it.
*/

beforeEach(function (): void {
    $stage = GradeLevel::query()->firstOrCreate(
        ['slug' => 'secondary'],
        ['name' => 'الثانويّة', 'sort_order' => 1, 'is_active' => true],
    );

    SchoolYear::query()->firstOrCreate(
        ['slug' => 'year-10'],
        [
            'name' => 'الصفّ العاشر',
            'grade_level_id' => $stage->getKey(),
            'sort_order' => 10,
            'is_active' => true,
        ],
    );

    Region::query()->firstOrCreate(
        ['slug' => 'doha'],
        ['name' => 'الدوحة', 'sort_order' => 1, 'is_active' => true],
    );

    $this->admin = User::factory()->create(['is_super_admin' => true]);
});

/** @param array<string, mixed> $overrides */
function adminAccountForm(array $overrides = []): array
{
    return [
        'platform_role' => PlatformRole::Student->value,
        'first_name' => 'سلمى',
        'last_name' => 'أحمد',
        'email' => 'salma@example.test',
        'phone' => '+97455512345',
        'country' => 'QA',
        'password' => 'temporary-pass',
        'school_year_slug' => 'year-10',
        'region_slug' => 'doha',
        // An adult by default, so the minor gate is neutralised and each test
        // below measures the one condition it names (the US6 lesson).
        'date_of_birth' => CarbonImmutable::now()->subYears(22)->toDateString(),
        ...$overrides,
    ];
}

it('creates an adult student that matches the public signup door', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateAccount::class)
        ->fillForm(adminAccountForm())
        ->call('create')
        ->assertHasNoFormErrors();

    $student = User::query()->where('email', 'salma@example.test')->sole();

    expect($student->platform_role)->toBe(PlatformRole::Student)
        ->and($student->status)->toBe(UserStatus::Active->value)
        // ⚠️ FR-010: no workspace, no membership, no tenant role — the same row
        // `/signup/student` writes. A panel that quietly added one would hand a
        // student a role they never asked for.
        ->and($student->last_workspace_id)->toBeNull()
        ->and($student->workspaces()->count())->toBe(0)
        ->and($student->getRoleNames()->all())->toBe([])
        ->and($student->studentProfile?->school_year_slug)->toBe('year-10')
        // The staff operator is neither the child nor the parent.
        ->and($student->studentProfile?->registered_by_parent)->toBeFalse();
});

it('leaves a minor blocked until a guardian consents', function (): void {
    /*
     * ⚠️ NOT A BUG TO BE FIXED LATER. `RegisterStudent` writes
     * `pending_guardian_consent`, a status that cannot sign in (FR-009), and the
     * page must not have grown a bypass for the operator's convenience. This is
     * the assertion that fails if anybody adds one.
     */
    $this->actingAs($this->admin);

    Livewire::test(CreateAccount::class)
        ->fillForm(adminAccountForm([
            'email' => 'child@example.test',
            'date_of_birth' => CarbonImmutable::now()->subYears(12)->toDateString(),
            'guardian_contact' => '+97455599999',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $child = User::query()->where('email', 'child@example.test')->sole();

    expect($child->status)->toBe(UserStatus::PendingGuardianConsent->value)
        ->and($child->studentProfile?->guardian_contact)->toBe('+97455599999');
});

it('creates a guardian with no student profile', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateAccount::class)
        ->fillForm(adminAccountForm([
            'platform_role' => PlatformRole::Parent->value,
            'email' => 'mona@example.test',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $parent = User::query()->where('email', 'mona@example.test')->sole();

    expect($parent->platform_role)->toBe(PlatformRole::Parent)
        ->and($parent->status)->toBe(UserStatus::Active->value)
        ->and($parent->studentProfile)->toBeNull();
});

it('refuses a duplicate address instead of throwing a query exception', function (): void {
    /*
     * ⚠️ THE FORM REQUEST NEVER RUNS ON THIS PATH. `RegisterStudentRequest`
     * guards the HTTP door and nothing else, so the `unique` rule is spelled on
     * the Filament field too — without it the most ordinary operator mistake
     * there is becomes a 500 page.
     */
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($this->admin);

    Livewire::test(CreateAccount::class)
        ->fillForm(adminAccountForm(['email' => 'taken@example.test']))
        ->call('create')
        ->assertHasFormErrors(['email']);

    expect(User::query()->where('email', 'taken@example.test')->count())->toBe(1);
});

it('is closed to a teacher, who reaches the panel like everybody else', function (): void {
    // ⚠️ NOT A SIGNED-OUT BROWSER. Every teacher can open `/admin`
    // (`EnsureFilamentAccess`), so the reader this guard exists for is one who
    // is already inside — and a test against a guest passes for the wrong reason.
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $teacher = $this->addWorkspaceMember($workspace, Roles::TEACHER);

    $this->setCurrentWorkspace($workspace, $teacher);

    expect(CreateAccount::canAccess())->toBeFalse();

    $this->actingAs($owner);
    expect(CreateAccount::canAccess())->toBeFalse();

    // The allow direction, or the two lines above are true of a wall.
    $this->actingAs($this->admin);
    expect(CreateAccount::canAccess())->toBeTrue();
});
