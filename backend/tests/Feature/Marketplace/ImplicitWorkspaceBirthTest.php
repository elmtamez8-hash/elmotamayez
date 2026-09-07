<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Tenancy\Actions\CreateWorkspace;
use App\Modules\Tenancy\DTOs\CreateWorkspaceDTO;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spec 025 · US1 — the workspace is born with the account and nobody sees it.
 *
 * ⚠️ EVERY ASSERTION HERE READS A RE-FETCHED ROW, never the model an Action
 * returned. `participates_in_marketplace` is not in `Workspace::$fillable`, so a
 * returned model echoes what was *sent* while the database holds what was
 * *saved* — the shape that made all of spec 013's US1 assertions true over three
 * empty columns.
 *
 * ⚠️ AND NOTHING HERE CALLS A BARE `Event::fake()`. It would swallow
 * `TeacherRegistered` along with everything else, so no workspace would be
 * created, the application row would be written with a null workspace, and every
 * test below would pass or fail for a reason that has nothing to do with what it
 * claims to measure.
 */
beforeEach(function (): void {
    $this->asGuest();
});

/** @return array<string, mixed> */
function birthStepOne(array $overrides = []): array
{
    return [
        'first_name' => 'خالد',
        'last_name' => 'عبد الباسط',
        'email' => 'khaled.birth@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'phone' => '+97455598765',
        'country' => 'QA',
        'terms_accepted' => true,
        ...$overrides,
    ];
}

it('births exactly one workspace, owned by the teacher, with its roles seeded', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', birthStepOne())->assertCreated();

    $user = User::where('email', 'khaled.birth@example.com')->sole();
    $workspaces = Workspace::query()->where('owner_user_id', $user->getKey())->get();

    expect($workspaces)->toHaveCount(1);

    $workspace = $workspaces->sole();

    /*
    | FR-016's evidence, and the reason the backfill may not INSERT a row by hand:
    | `SeedDefaultRoles` runs once, at creation, off `WorkspaceCreated`. A
    | workspace written directly is born with no roles and stays that way for
    | ever — which is not a hypothetical. The production row `slug = platform`
    | was written with `forceFill` and carries zero roles to this day.
    */
    expect(Role::query()->where('team_id', $workspace->getKey())->count())->toBe(4)
        ->and($user->fresh()?->last_workspace_id)->toBe($workspace->getKey())
        ->and($workspace->members()->where('users.id', $user->getKey())->exists())->toBeTrue();

    // FR-005 — the name is the teacher's own; no field in the form, no type asked.
    expect($workspace->name)->toBe('خالد عبد الباسط')
        ->and($workspace->type)->toBe('teacher');
});

it('writes the application into the teacher own workspace, not a shared container', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', birthStepOne())->assertCreated();

    $user = User::where('email', 'khaled.birth@example.com')->sole();
    $workspace = Workspace::query()->where('owner_user_id', $user->getKey())->sole();
    $application = TeacherApplication::withoutWorkspaceScope()->where('user_id', $user->getKey())->sole();

    // The whole reason FR-024 becomes executable: nothing lands in the orphan any
    // more, so there is finally nothing to empty out of it next time.
    expect($application->workspace_id)->toBe($workspace->getKey());
});

it('leaves the new workspace outside the marketplace until somebody approves it', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', birthStepOne())->assertCreated();

    $user = User::where('email', 'khaled.birth@example.com')->sole();

    /*
    | ⚠️ THE SHARPEST LINE IN THIS FILE, and the first design of 025 had it the
    | other way round. `participates_in_marketplace` gates three readers; two of
    | them also demand `approval_status = approved`, and the third —
    | `Article::publicListingConstraints()` — demands only `status = published`.
    | So a workspace born participating puts a brand-new, unreviewed account's
    | writing on the platform's own blog, under the platform's own domain, pushed
    | to search engines. Participation alone IS the gate there.
    |
    | Re-fetched from the database on purpose: the column is not `$fillable`.
    */
    $stored = Workspace::query()->where('owner_user_id', $user->getKey())->sole();

    expect($stored->participates_in_marketplace)->toBeFalse();
});

it('leaves no account and no workspace behind when the workspace cannot be created', function (): void {
    /*
    | FR-003 — «they succeed together or fail together».
    |
    | The listener is a plain class with no `ShouldQueue` precisely so a throw
    | inside it reaches `RegisterTeacher`'s transaction. Queue it and this test
    | still passes while production quietly commits accounts whose workspace job
    | never ran — the account-with-no-workspace this whole spec exists to abolish.
    |
    | (The mirror requirement, FR-004, is already measured: StudentRegistrationTest
    | counts workspaces across a student signup. It holds by construction here —
    | the student and parent paths never dispatch `TeacherRegistered` at all, so
    | there is no `platform_role` branch anywhere that somebody could forget.)
    */
    $this->app->bind(CreateWorkspace::class, fn (): CreateWorkspace => new class extends CreateWorkspace
    {
        public function handle(CreateWorkspaceDTO $dto, User $owner): Workspace
        {
            throw new RuntimeException('نصف الطريق');
        }
    });

    $usersBefore = User::query()->count();
    $workspacesBefore = Workspace::query()->count();

    try {
        $this->withoutExceptionHandling()
            ->postJson('/api/v1/auth/register/teacher/step-1', birthStepOne());
    } catch (RuntimeException) {
        // the point of the test
    }

    expect(User::query()->count())->toBe($usersBefore)
        ->and(Workspace::query()->count())->toBe($workspacesBefore)
        ->and(TeacherApplication::withoutWorkspaceScope()->count())->toBe(0);
});

it('gives the owner the tenant-owner role inside the workspace it just made', function (): void {
    $this->postJson('/api/v1/auth/register/teacher/step-1', birthStepOne())->assertCreated();

    $user = User::where('email', 'khaled.birth@example.com')->sole();
    $workspace = Workspace::query()->where('owner_user_id', $user->getKey())->sole();

    // SC-001's real content: the teacher can work immediately. Without the team
    // id there is no role, and without a role every `can()` below it is false —
    // which reads on screen as a 403 on their own first course.
    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    expect($user->fresh()?->hasRole(Roles::TENANT_OWNER))->toBeTrue();
});
