<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Identity\Support\RelationStatus;
use App\Modules\Identity\Support\RelationType;
use App\Modules\Identity\Support\UserStatus;
use App\Modules\Payments\Enums\ConsentDocument;
use App\Modules\Payments\Support\ConsentRegistry;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * The consent gate, driven over HTTP from both sides (spec 013 · FR-003 ·
 * FR-009ب · FR-009د).
 *
 * ⚠️ THE BLOCK IS ONE DOOR ON PURPOSE. `StartAuthSession` refuses to mint a
 * token for a pending minor, and every student route is `auth:sanctum` — so
 * orders, enrolments, booking, lessons, chat and study rooms are unreachable by
 * construction and carry no check of their own. What this file proves is that
 * the one door holds, that it OPENS through the route a guardian can actually
 * reach, and that an adult never meets it.
 *
 * ⚠️ AND IT IS PROVED FOR BOTH SHAPES OF STUDENT (CLAUDE.md): one with a null
 * `last_workspace_id` — the self-registered student — and one stamped with a
 * workspace, the shape a teacher or seeder produces. `forceFill`, because the
 * column is in `User::$guarded` and `create([...])` drops it in silence.
 */
function consentDoorsStudent(bool $stamped, string $status): User
{
    $student = User::factory()->create([
        'platform_role' => PlatformRole::Student,
        'status' => $status,
    ]);

    if ($stamped) {
        [$workspace] = test()->createWorkspaceWithOwner();
        $student->forceFill(['last_workspace_id' => $workspace->getKey()])->save();
    }

    return $student;
}

/** @param list<GuardianPermission> $permissions */
function consentDoorsGuardian(User $student, array $permissions = [GuardianPermission::DataRights]): User
{
    $guardian = User::factory()->create(['platform_role' => PlatformRole::Parent]);

    ParentStudentRelation::query()->create([
        'guardian_user_id' => $guardian->getKey(),
        'student_user_id' => $student->getKey(),
        'student_name' => 'طالب',
        'relation_type' => RelationType::Parent->value,
        'permissions' => array_map(fn (GuardianPermission $p): string => $p->value, $permissions),
        'status' => RelationStatus::Active->value,
    ]);

    return $guardian;
}

function consentDoorsLogin(User $user): TestResponse
{
    // A request as somebody else earlier in the case leaves the guard resolved.
    app('auth')->forgetGuards();
    app()->forgetInstance(WorkspaceContext::class);

    return test()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
}

function consentDoorsGrant(User $guardian, User $student): TestResponse
{
    Sanctum::actingAs($guardian);
    app()->forgetInstance(WorkspaceContext::class);

    return test()->putJson('/api/v1/privacy/consents/categories', [
        'student_uuid' => $student->uuid,
        'categories' => [],
        'version' => app(ConsentRegistry::class)->currentVersion(ConsentDocument::DataProcessing),
    ]);
}

dataset('student shapes', [
    'null context' => [false],
    'stamped with a workspace' => [true],
]);

it('refuses a pending minor at sign-in and mints no token', function (bool $stamped): void {
    $student = consentDoorsStudent($stamped, UserStatus::PendingGuardianConsent->value);

    consentDoorsLogin($student)
        ->assertForbidden()
        ->assertJsonPath('code', 'pending_guardian_consent')
        ->assertJsonMissingPath('token');

    expect(PersonalAccessToken::query()->where('tokenable_id', $student->getKey())->count())->toBe(0);
})->with('student shapes');

it('opens sign-in and the student routes once a DataRights guardian consents from their own screen', function (bool $stamped): void {
    $student = consentDoorsStudent($stamped, UserStatus::PendingGuardianConsent->value);
    $guardian = consentDoorsGuardian($student);

    consentDoorsGrant($guardian, $student)->assertOk();

    expect($student->fresh()?->status)->toBe(UserStatus::Active->value);

    $token = consentDoorsLogin($student)->assertOk()->json('token');

    expect($token)->toBeString();

    app('auth')->forgetGuards();
    app()->forgetInstance(WorkspaceContext::class);

    test()->withToken($token)->getJson('/api/v1/enrollments')->assertOk();
})->with('student shapes');

/** @return array<string, mixed> */
function consentDoorsRegistration(string $email, string $dateOfBirth): array
{
    return [
        'first_name' => 'سارة',
        'last_name' => 'المري',
        'email' => $email,
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455512345',
        'country' => 'QA',
        'school_year_slug' => 'year-10',
        'registered_by_parent' => false,
        'terms_accepted' => true,
        'date_of_birth' => $dateOfBirth,
        'guardian_contact' => '+97455598765',
        'region_slug' => 'doha',
    ];
}

/*
 * ⚠️ THE ACCOUNT EXISTS AND THE SIGN-IN IS REFUSED — BOTH, AND THE SIGNUP SCREEN
 * DEPENDS ON THE PAIR. `registerStudent` saves the row and then asks
 * `StartAuthSession`, which refuses; the form tells the minor «your account was
 * created, your guardian must approve». Wrap the controller in a transaction one
 * day and that sentence becomes a lie, so the row is asserted, not assumed.
 */
it('creates a minor pending at signup and signs them in to nothing', function (): void {
    marketplaceWorkspace();
    $this->asGuest();

    $this->postJson('/api/v1/auth/register/student', consentDoorsRegistration('minor@example.com', now()->subYears(14)->toDateString()))
        ->assertForbidden()
        ->assertJsonPath('code', 'pending_guardian_consent')
        ->assertJsonMissingPath('token');

    $student = User::query()->where('email', 'minor@example.com')->sole();

    expect($student->status)->toBe(UserStatus::PendingGuardianConsent->value)
        ->and(PersonalAccessToken::query()->where('tokenable_id', $student->getKey())->count())->toBe(0);
});

it('signs an adult straight in at signup', function (): void {
    marketplaceWorkspace();
    $this->asGuest();

    $this->postJson('/api/v1/auth/register/student', consentDoorsRegistration('adult@example.com', now()->subYears(25)->toDateString()))
        ->assertCreated()
        ->assertJsonStructure(['token']);

    expect(User::query()->where('email', 'adult@example.com')->sole()->status)->toBe(UserStatus::Active->value);
});

it('never blocks an adult', function (bool $stamped): void {
    $adult = consentDoorsStudent($stamped, UserStatus::Active->value);

    consentDoorsLogin($adult)->assertOk()->assertJsonStructure(['token']);
})->with('student shapes');

it('refuses a guardian without DataRights and leaves the account pending', function (): void {
    $student = consentDoorsStudent(false, UserStatus::PendingGuardianConsent->value);
    $guardian = consentDoorsGuardian($student, [GuardianPermission::Payments, GuardianPermission::Attendance]);

    consentDoorsGrant($guardian, $student)->assertForbidden();

    expect($student->fresh()?->status)->toBe(UserStatus::PendingGuardianConsent->value);
});

/*
 * ⚠️ THE SECOND DOOR ASKED THE WRONG PERMISSION. `POST /billing/consents`
 * resolved the child through `childrenOf(..., Payments)` for EVERY document, so
 * the guardian `GuardianInvitation` creates — `DataRights` alone — was refused at
 * the door while `RecordTermsConsent` one call further in would have allowed them.
 */
it('lets a DataRights-only guardian sign the data agreement on the terms door too', function (): void {
    $student = consentDoorsStudent(false, UserStatus::PendingGuardianConsent->value);
    $guardian = consentDoorsGuardian($student);

    Sanctum::actingAs($guardian);
    app()->forgetInstance(WorkspaceContext::class);

    test()->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DataProcessing->value,
        'student' => $student->uuid,
    ])->assertCreated();

    expect($student->fresh()?->status)->toBe(UserStatus::Active->value);

    // The payment terms still ask for payment authority.
    test()->postJson('/api/v1/billing/consents', [
        'document' => ConsentDocument::DeferredPaymentTerms->value,
        'student' => $student->uuid,
    ])->assertForbidden();
});

it('tells the guardian — and only the guardian who can give it — that consent is awaited', function (): void {
    $student = consentDoorsStudent(true, UserStatus::PendingGuardianConsent->value);
    $guardian = consentDoorsGuardian($student);
    $paymentsOnly = consentDoorsGuardian($student, [GuardianPermission::Payments]);

    $flagFor = function (User $reader): mixed {
        Sanctum::actingAs($reader);
        app()->forgetInstance(WorkspaceContext::class);

        // A bare array: `JsonResource::withoutWrapping()` is on globally.
        return test()->getJson('/api/v1/family/relations')->assertOk()->json('0.student_awaiting_consent');
    };

    expect($flagFor($guardian))->toBeTrue()
        ->and($flagFor($paymentsOnly))->toBeFalse();

    consentDoorsGrant($guardian, $student)->assertOk();

    expect($flagFor($guardian))->toBeFalse();
});
