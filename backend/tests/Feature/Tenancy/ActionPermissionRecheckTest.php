<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Marketplace\Actions\ApproveTeacherApplication;
use App\Modules\Marketplace\Actions\ReinstateTeacher;
use App\Modules\Marketplace\Actions\SuspendTeacher;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Settlement\Actions\DecideRateChange;
use App\Modules\Settlement\Actions\RecordTeacherPayout;
use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;

/*
| Defence in depth: the Actions behind the platform's money and marketplace
| decisions ask the permission THEMSELVES, not only through the screen that
| happens to call them.
|
| ⚠️ The actor here is the case that matters: a platform officer who OWNS a
| workspace — they hold every tenant permission there and none of these. Each
| refusal is asked before the row is even looked at, so an unsaved model is
| enough to reach it.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$home, $owner] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);

    // Compliance holds none of the settlement or marketplace permissions below.
    $this->officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER, $owner);
    $this->actingAs($this->officer);
    app()->forgetInstance(WorkspaceContext::class);
    $this->setCurrentWorkspace($home, $this->officer);
});

it('refuses each decision to an officer without its permission', function (Closure $decide): void {
    expect(fn () => $decide($this->officer))->toThrow(AuthorizationException::class);
})->with([
    'approve a rate' => [fn (User $by) => app(DecideRateChange::class)->approve(new RateChangeRequest, $by)],
    'reject a rate' => [fn (User $by) => app(DecideRateChange::class)->reject(new RateChangeRequest, $by, 'سبب')],
    'pay a period' => [fn (User $by) => app(RecordTeacherPayout::class)->handle(new SettlementPeriod, $by)],
    'reverse a unit' => [fn (User $by) => app(ReverseTeachingUnit::class)->handle(new TeachingUnit, 'سبب', $by)],
    'approve a teacher' => [fn (User $by) => app(ApproveTeacherApplication::class)->handle(new TeacherApplication, $by)],
    'suspend a teacher' => [fn (User $by) => app(SuspendTeacher::class)->handle(new TeacherProfile, $by)],
    'reinstate a teacher' => [fn (User $by) => app(ReinstateTeacher::class)->handle(new TeacherProfile, $by)],
]);

it('lets the finance officer past the payout check it holds', function (): void {
    [$home, $owner] = $this->createWorkspaceWithOwner(['name' => 'مساحة ثانية']);
    $finance = makePlatformStaff(Roles::FINANCE_ADMIN, $owner);
    $this->actingAs($finance);
    app()->forgetInstance(WorkspaceContext::class);
    $this->setCurrentWorkspace($home, $finance);

    // Past the permission, the Action's own rules answer: an unsaved period is
    // not closed, which is a DomainException and not an authorisation refusal.
    expect(fn () => app(RecordTeacherPayout::class)->handle(new SettlementPeriod, $finance))
        ->toThrow(DomainException::class);
});
