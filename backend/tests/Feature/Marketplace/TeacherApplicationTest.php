<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Support\PlatformRole;
use App\Modules\Marketplace\Events\TeacherApplicationSubmitted;
use App\Modules\Marketplace\Models\GradeLevel;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Support\NotificationType;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function teacherStepOne(array $overrides = []): array
{
    return [
        'first_name' => 'خالد',
        'last_name' => 'العطية',
        'email' => 'khaled@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455598765',
        'country' => 'QA',
        'terms_accepted' => true,
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function teacherStepTwo(): array
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

/** @return array<string, mixed> */
function teacherStepFour(): array
{
    return [
        'hourly_rate' => '120.00',
        'currency' => 'QAR',
        'availability' => [
            ['day_of_week' => 0, 'start_time' => '16:00', 'end_time' => '18:00'],
            ['day_of_week' => 2, 'start_time' => '16:00', 'end_time' => '18:00'],
        ],
    ];
}

/**
 * Seed the taxonomy a submission resolves its slugs against.
 *
 * ⚠️ NO WORKSPACE, and none was ever needed. This used to run inside
 * `forWorkspace(PlatformWorkspace::resolve())` — but since spec 009 the taxonomy
 * is PLATFORM reference data with no `BelongsToWorkspace` on either model (its
 * own docblock says it must never regain one), so the wrapper scoped nothing and
 * the workspace it resolved existed only to be passed to a scope that ignored it.
 * Spec 025 deletes that class; this is what it was actually doing.
 */
function seedPlatformTaxonomy(): void
{
    Subject::query()->firstOrCreate(['slug' => 'math'], ['name_ar' => 'الرياضيات', 'sort_order' => 0, 'is_active' => true]);
    GradeLevel::query()->firstOrCreate(['slug' => 'secondary'], ['name_ar' => 'المرحلة الثانوية', 'sort_order' => 0, 'is_active' => true]);
}

/**
 * Notifications of one type that actually reached a user.
 *
 * Since spec 003 there is no Notification::fake() to assert against: dispatch
 * writes a row and queues per-channel jobs, so the row IS the evidence. This also
 * asserts something the fake could not — that exactly one record exists however
 * many channels carried it (FR-007).
 *
 * @return Collection<int, Notification>
 */
function notificationsFor(User $user, NotificationType $type)
{
    return Notification::query()
        ->forRecipient($user)
        ->where('type', $type->value)
        ->get();
}

/** A reviewer holding the marketplace review permissions inside a workspace. */
function academicReviewer(Workspace $workspace): User
{
    $user = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $role = Role::findOrCreate('academic-reviewer', 'web');

    foreach ([
        Permissions::MARKETPLACE_TEACHERS_REVIEW,
        Permissions::MARKETPLACE_TEACHERS_APPROVE,
        Permissions::MARKETPLACE_TEACHERS_SUSPEND,
    ] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user->assignRole($role);
    $user->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

    Sanctum::actingAs($user);

    // WorkspaceContext caches its first resolution for the whole app instance, and
    // the earlier guest requests in this test froze it at null. Without a fresh
    // instance the middleware pushes a null team id and every permission check
    // fails — the trap CLAUDE.md warns about, hit from the test side.
    test()->asGuest();

    return $user;
}

/** Walk the whole wizard and return the resulting application. */
function completeWizard(): TeacherApplication
{
    test()->postJson('/api/v1/auth/register/teacher/step-1', teacherStepOne())->assertCreated();

    $user = User::where('email', 'khaled@example.com')->sole();
    Sanctum::actingAs($user);

    test()->putJson('/api/v1/teacher/application/step-2', teacherStepTwo())->assertOk();
    test()->putJson('/api/v1/teacher/application/step-3', ['documents_acknowledged' => true])->assertOk();
    test()->putJson('/api/v1/teacher/application/step-4', teacherStepFour())->assertOk();

    return TeacherApplication::withoutWorkspaceScope()->where('user_id', $user->getKey())->sole();
}

beforeEach(function (): void {
    seedPlatformTaxonomy();
    $this->asGuest();
});

it('creates a teacher account and a draft application at step 1', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', teacherStepOne())
        ->assertCreated()
        ->assertJsonPath('application.status', TeacherApplication::STATUS_DRAFT)
        ->assertJsonPath('application.current_step', 2)
        ->assertJsonStructure(['application', 'token']);

    $user = User::where('email', 'khaled@example.com')->sole();

    /*
    | ⚠️ INVERTED ON PURPOSE — spec 025 · FR-001 repeals 001 · FR-010 («the three
    | new signup paths must not create a workspace and grant no role») for the
    | teacher path alone. It used to read `toBe(0)`.
    |
    | The rule was written when a workspace was a thing the user chose to make.
    | It is not one any more: a teacher IS a workspace, born with the account, and
    | the application row lands inside it rather than in a shared container. The
    | student and parent paths are untouched and the old rule still governs them —
    | which is enforced rather than asserted, by `TeacherRegistered` being the
    | only dispatcher and Marketplace's teacher path its only caller.
    */
    expect($user->platform_role)->toBe(PlatformRole::Teacher)
        ->and($user->workspaces()->count())->toBe(1);
});

it('saves each step and resumes at the furthest one reached', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', teacherStepOne())->assertCreated();

    Sanctum::actingAs(User::where('email', 'khaled@example.com')->sole());

    $this->putJson('/api/v1/teacher/application/step-2', teacherStepTwo())->assertOk();

    // FR-070: closing the browser after step 2 must not restart the wizard.
    $this->getJson('/api/v1/teacher/application')
        ->assertOk()
        ->assertJsonPath('application.current_step', 3)
        ->assertJsonPath('application.step_data.step_2.headline', 'مدرّس رياضيات للثانوية');
});

it('rejects an incomplete submission', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', teacherStepOne())->assertCreated();

    Sanctum::actingAs(User::where('email', 'khaled@example.com')->sole());

    $this->putJson('/api/v1/teacher/application/step-2', teacherStepTwo())->assertOk();

    $this->postJson('/api/v1/teacher/application/submit')->assertStatus(422);
});

it('rejects overlapping availability windows', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', teacherStepOne())->assertCreated();

    Sanctum::actingAs(User::where('email', 'khaled@example.com')->sole());

    $this->putJson('/api/v1/teacher/application/step-2', teacherStepTwo())->assertOk();
    $this->putJson('/api/v1/teacher/application/step-3', ['documents_acknowledged' => true])->assertOk();
    $this->putJson('/api/v1/teacher/application/step-4', [
        'hourly_rate' => '120.00',
        'availability' => [
            ['day_of_week' => 0, 'start_time' => '16:00', 'end_time' => '18:00'],
            ['day_of_week' => 0, 'start_time' => '17:00', 'end_time' => '19:00'],
        ],
    ])->assertOk();

    // FR-028 is enforced in SetAvailability, which runs at submission — not only
    // as a validation rule, so seeders and Filament hit it too.
    $this->postJson('/api/v1/teacher/application/submit')->assertStatus(422);
});

it('accepts back-to-back windows that only touch', function (): void {
    $application = completeWizard();

    $this->putJson('/api/v1/teacher/application/step-4', [
        'hourly_rate' => '120.00',
        'availability' => [
            ['day_of_week' => 0, 'start_time' => '10:00', 'end_time' => '12:00'],
            ['day_of_week' => 0, 'start_time' => '12:00', 'end_time' => '14:00'],
        ],
    ])->assertOk();

    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    expect($application->fresh()?->status)->toBe(TeacherApplication::STATUS_SUBMITTED);
});

it('submits, builds an unlisted profile and fires the event', function (): void {
    Event::fake([TeacherApplicationSubmitted::class]);

    completeWizard();

    $this->postJson('/api/v1/teacher/application/submit')
        ->assertOk()
        ->assertJsonPath('status', TeacherApplication::STATUS_SUBMITTED)
        ->assertJsonPath('message', 'طلبك قيد المراجعة من فريقنا الأكاديمي')
        ->assertJsonPath('expected_review_days', 3);

    $profile = TeacherProfile::withoutWorkspaceScope()->sole();

    expect($profile->approval_status)->toBe(TeacherProfile::STATUS_PENDING)
        ->and($profile->is_publicly_listed)->toBeFalse()
        ->and($profile->subjects()->count())->toBe(1)
        ->and($profile->availabilitySlots()->count())->toBe(2);

    Event::assertDispatched(TeacherApplicationSubmitted::class);
});

it('freezes the application once submitted', function (): void {
    completeWizard();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    $this->putJson('/api/v1/teacher/application/step-2', teacherStepTwo())->assertStatus(422);
});

it('approves an application, lists the teacher and notifies them', function (): void {

    $application = completeWizard();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    $reviewer = academicReviewer($application->workspace);

    $this->postJson("/api/v1/admin/teacher-applications/{$application->uuid}/approve")
        ->assertOk()
        ->assertJsonPath('approval_status', TeacherProfile::STATUS_APPROVED)
        ->assertJsonPath('is_publicly_listed', true);

    expect($application->fresh()?->reviewed_by)->toBe($reviewer->getKey());

    expect(notificationsFor($application->user, NotificationType::TeacherApplicationApproved))->toHaveCount(1);
});

// The rule that keeps FR-001 honest: approval is about the person, participation
// is about the workspace, and one must not silently grant the other.
it('approves without listing when the workspace has not joined the marketplace', function (): void {
    $application = completeWizard();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    /*
    | ⚠️ THE FIXTURE MOVED, THE RULE DID NOT — spec 025.
    |
    | Approval now stamps participation, but ONLY on the teacher's own workspace:
    | the marketplace wizard IS the opt-in, so refusing to list somebody who just
    | applied to be listed would be the product arguing with itself.
    |
    | Where the rule this test guards still means something is a profile living
    | inside SOMEBODY ELSE'S academy — there, participation is the owner's
    | decision and one member's approval must not make it for them, publicly
    | listing every other teacher in that workspace. Handing the workspace to
    | another owner is the whole difference, and it is one line.
    */
    $application->workspace->forceFill([
        'participates_in_marketplace' => false,
        'owner_user_id' => User::factory()->create()->getKey(),
    ])->save();

    academicReviewer($application->workspace);

    $this->postJson("/api/v1/admin/teacher-applications/{$application->uuid}/approve")
        ->assertOk()
        ->assertJsonPath('approval_status', TeacherProfile::STATUS_APPROVED)
        ->assertJsonPath('is_publicly_listed', false);
});

it('rejects an application with a reason and notifies the applicant', function (): void {

    $application = completeWizard();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    academicReviewer($application->workspace);

    $this->postJson("/api/v1/admin/teacher-applications/{$application->uuid}/reject", [
        'reason' => 'المؤهلات المرفقة غير كافية.',
    ])->assertOk()->assertJsonPath('status', TeacherApplication::STATUS_REJECTED);

    expect($application->fresh()?->rejection_reason)->toBe('المؤهلات المرفقة غير كافية.');

    expect(notificationsFor($application->user, NotificationType::TeacherApplicationRejected))->toHaveCount(1);
});

it('refuses a rejection with no reason', function (): void {
    $application = completeWizard();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    academicReviewer($application->workspace);

    $this->postJson("/api/v1/admin/teacher-applications/{$application->uuid}/reject", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('reopens the wizard when changes are requested (FR-072)', function (): void {

    $application = completeWizard();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    academicReviewer($application->workspace);

    $this->postJson("/api/v1/admin/teacher-applications/{$application->uuid}/request-changes", [
        'reason' => 'أضف شهادة الخبرة.',
    ])->assertOk()->assertJsonPath('status', TeacherApplication::STATUS_CHANGES_REQUESTED);

    expect(notificationsFor($application->user, NotificationType::TeacherApplicationChangesRequested))->toHaveCount(1);

    // The applicant can edit and resubmit rather than starting a new application.
    Sanctum::actingAs($application->user);

    $this->putJson('/api/v1/teacher/application/step-2', teacherStepTwo())->assertOk();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();
});

it('refuses review actions without the permission', function (): void {
    $application = completeWizard();
    $this->postJson('/api/v1/teacher/application/submit')->assertOk();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/admin/teacher-applications/{$application->uuid}/approve")->assertForbidden();
});
