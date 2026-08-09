<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Data\CreditMovement;
use App\Modules\Payments\Enums\CreditTransactionType;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Payments\Support\CreditLedger;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Q-7 · T097 — what a rate approval is about to be applied to.
|
| The one loss spec 006 cannot design away: a rate approved BETWEEN a purchase
| and its delivery. The purchase's price is frozen (FR-021ز) and the delivered
| session settles at the approved rate, and both of those are right — so the
| difference falls on the platform. What can be changed is whether the person
| pressing "approve" knows the size of it first.
|
| ⚠️ It is a BILLING endpoint the settlement screen calls, not a field on the
| settlement payload. The obvious version is a Settlement response computed from
| `credit_purchases`, which ContextIsolationTest fails the build over.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->course = courseWithRate((int) $this->workspace->getKey(), 5000);
    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    $this->package = CreditPackage::query()->create([
        'name' => 'حزمة',
        'credits' => 8,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
    ]);
});

function rateApprover(): User
{
    $test = test();

    app(PermissionRegistrar::class)->setPermissionsTeamId($test->workspace->getKey());

    $role = Role::findOrCreate('rate-approver', 'web');
    $role->givePermissionTo(Permission::findOrCreate(Permissions::SETTLEMENT_RATE_APPROVE, 'web'));

    $approver = $test->addWorkspaceMember($test->workspace, Roles::TENANT_OWNER);
    $approver->assignRole($role);
    $test->setCurrentWorkspace($test->workspace, $approver);

    return $approver;
}

it('separates what was sold from what is still owed in sessions', function (): void {
    $balance = billingBalance($this->workspace, $this->student, $this->course);
    grantCredits($balance, 8, 'bought');

    // Three delivered. Those settled at the rate in force when they were taught,
    // so a new rate cannot reach them — which is why the headline number is the
    // REMAINING credits, not the purchased ones.
    app(CreditLedger::class)->post(new CreditMovement(
        balance: $balance,
        type: CreditTransactionType::Consume,
        credits: -3,
        sourceType: 'test_consume',
        sourceId: 1,
    ));

    Sanctum::actingAs(rateApprover());

    $this->getJson("/api/v1/admin/billing/outstanding?workspace={$this->workspace->uuid}")
        ->assertOk()
        ->assertJsonPath('credits_outstanding', 5);
});

it('refuses a reader who cannot approve a rate', function (): void {
    $member = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->setCurrentWorkspace($this->workspace, $member);

    Sanctum::actingAs($member);

    $this->getJson("/api/v1/admin/billing/outstanding?workspace={$this->workspace->uuid}")
        ->assertForbidden();
});

it('answers about the workspace it was asked about, not the one the reader is in', function (): void {
    [$other] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية أخرى']);

    $otherCourse = courseWithRate((int) $other->getKey(), 4000);
    $otherStudent = $this->addWorkspaceMember($other, Roles::STUDENT);

    grantCredits(billingBalance($other, $otherStudent, $otherCourse), 6, 'other');
    grantCredits(billingBalance($this->workspace, $this->student, $this->course), 2, 'mine');

    // The approver is a platform operator signed into one workspace and deciding
    // about another. Left to the global scope this would answer about the wrong
    // teacher entirely — and plausibly, which is the dangerous kind of wrong.
    Sanctum::actingAs(rateApprover());

    $this->getJson("/api/v1/admin/billing/outstanding?workspace={$other->uuid}")
        ->assertOk()
        ->assertJsonPath('credits_outstanding', 6)
        ->assertJsonPath('workspace', $other->uuid);
});
