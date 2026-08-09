<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\StudentCreditAccount;
use App\Modules\Payments\Models\TermsConsent;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Shared\Support\GuardianPermission;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\BelongsToWorkspace;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * NFR-001ب, for the money.
 *
 * `student_credit_accounts` and `terms_consents` are platform-owned — one
 * account and one signed agreement per person, across every teacher they ever
 * study with. Neither carries workspace_id, so no global scope touches them and
 * a bare query returns the whole platform.
 *
 * Both failure directions are silent, so both are tested:
 *
 *   1. NO HORIZONTAL LEAK — the account spans workspaces, so showing it to a
 *      teacher tells them who else this student studies with. The teacher's
 *      legitimate view is the BALANCE inside their own workspace, and that one
 *      must not reach across.
 *
 *   2. ONE ENTITY, NOT ONE PER TEACHER — the mirror-image bug. Applying
 *      BelongsToWorkspace here would give one student three accounts, three
 *      running totals, and a consent they signed once that stops counting the
 *      moment they enrol with someone else.
 */

function billingReader(Workspace $workspace): User
{
    $teacher = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->getKey());

    $role = Role::findOrCreate('billing-reader', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::BILLING_BALANCE_VIEW, 'web'));

    $teacher->assignRole($role);
    $teacher->forceFill(['last_workspace_id' => $workspace->getKey()])->save();

    return $teacher;
}

function balanceIn(Workspace $workspace, User $student, ?StudentCreditAccount $account = null): CreditBalance
{
    return app(WorkspaceContext::class)->forWorkspace(
        $workspace,
        fn (): CreditBalance => CreditBalance::factory()->create([
            'student_credit_account_id' => ($account ?? StudentCreditAccount::factory()->create([
                'user_id' => $student->getKey(),
            ]))->getKey(),
            'student_user_id' => $student->getKey(),
            'course_id' => Course::factory()->create(['workspace_id' => $workspace->getKey()])->getKey(),
        ]),
    );
}

// Direction one ─────────────────────────────────────────────────────────────

it('refuses a teacher the credit account itself, even for their own student', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    $account = StudentCreditAccount::factory()->create(['user_id' => $student->getKey()]);

    $teacher = billingReader($workspace);

    // The permission is real and the student is theirs. The account is still not
    // theirs to read: it is the one object that spans every teacher this student
    // has, and BILLING_BALANCE_VIEW is a permission over a workspace's balances.
    expect(Gate::forUser($teacher)->allows('view', $account))->toBeFalse();
});

it('refuses a teacher a balance belonging to another workspace', function (): void {
    [$mine] = $this->createWorkspaceWithOwner(['name' => 'رياضيات']);
    [$theirs] = $this->createWorkspaceWithOwner(['name' => 'فيزياء']);

    $student = User::factory()->create();
    $foreign = balanceIn($theirs, $student);

    $teacher = billingReader($mine);
    app(WorkspaceContext::class)->set($mine);

    expect(Gate::forUser($teacher)->allows('view', $foreign))->toBeFalse();
});

it('lets a teacher read a balance inside their own workspace', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    $balance = balanceIn($workspace, $student);

    $teacher = billingReader($workspace);
    app(WorkspaceContext::class)->set($workspace);

    expect(Gate::forUser($teacher)->allows('view', $balance))->toBeTrue();
});

it('refuses a stranger with no billing permission at all', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $student = User::factory()->create();
    $balance = balanceIn($workspace, $student);

    $stranger = User::factory()->create();
    $stranger->forceFill(['last_workspace_id' => $workspace->getKey()])->save();
    app(WorkspaceContext::class)->set($workspace);

    expect(Gate::forUser($stranger)->allows('view', $balance))->toBeFalse();
});

it('refuses a guardian who is not authorised for money', function (): void {
    $student = User::factory()->create();
    $account = StudentCreditAccount::factory()->create(['user_id' => $student->getKey()]);

    $relation = ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        // Attendance, not payments. A guardian may follow the register without
        // being entitled to the financial record.
        'permissions' => [GuardianPermission::Attendance->value],
    ]);

    expect(Gate::forUser($relation->guardian)->allows('view', $account))->toBeFalse();
});

it('lets a guardian authorised for money read the account', function (): void {
    $student = User::factory()->create();
    $account = StudentCreditAccount::factory()->create(['user_id' => $student->getKey()]);

    $relation = ParentStudentRelation::factory()->create([
        'student_user_id' => $student->getKey(),
        'permissions' => [GuardianPermission::Payments->value],
    ]);

    expect(Gate::forUser($relation->guardian)->allows('view', $account))->toBeTrue();
});

// Direction two ─────────────────────────────────────────────────────────────

it('gives a student one credit account across every teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);
    [$c] = $this->createWorkspaceWithOwner(['name' => 'ج']);

    $student = User::factory()->create();
    $account = StudentCreditAccount::factory()->create(['user_id' => $student->getKey()]);

    foreach ([$a, $b, $c] as $workspace) {
        balanceIn($workspace, $student, $account);
    }

    // Three teachers, three balances, ONE account. The uniqueness is on
    // `user_id`, so a second account is not merely undesirable — it cannot be
    // written at all.
    expect(StudentCreditAccount::query()->where('user_id', $student->getKey())->count())->toBe(1)
        ->and($account->balances()->withoutWorkspaceScope()->count())->toBe(3);
});

it('lets a student read their own balance in every workspace, whatever the current one', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();
    $account = StudentCreditAccount::factory()->create(['user_id' => $student->getKey()]);

    $inA = balanceIn($a, $student, $account);
    $inB = balanceIn($b, $student, $account);

    // The student's context is workspace A. Their balance in B is still theirs —
    // this is why CreditBalancePolicy checks ownership BEFORE the workspace.
    app(WorkspaceContext::class)->set($a);

    expect(Gate::forUser($student)->allows('view', $inA))->toBeTrue()
        ->and(Gate::forUser($student)->allows('view', $inB))->toBeTrue();
});

it('keeps one consent per person, not one per teacher', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $student = User::factory()->create();

    $consent = app(WorkspaceContext::class)->forWorkspace(
        $a,
        fn (): TermsConsent => TermsConsent::factory()->create([
            'user_id' => $student->getKey(),
            'student_user_id' => $student->getKey(),
        ]),
    );

    // Read from inside the OTHER workspace. A scoped consent would vanish here,
    // and the student would be asked to sign the same agreement again for every
    // teacher they add.
    $found = app(WorkspaceContext::class)->forWorkspace(
        $b,
        fn (): int => TermsConsent::query()->where('student_user_id', $student->getKey())->count(),
    );

    expect($found)->toBe(1)
        ->and(Gate::forUser($student)->allows('view', $consent))->toBeTrue();
});

it('keeps the platform-owned billing tables unscoped', function (): void {
    foreach ([StudentCreditAccount::class, TermsConsent::class] as $model) {
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
            ->toBeFalse("{$model} must not be workspace-scoped");
    }
});
