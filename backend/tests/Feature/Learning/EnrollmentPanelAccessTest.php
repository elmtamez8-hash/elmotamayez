<?php

declare(strict_types=1);

use App\Filament\Resources\EnrollmentResource;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Actions\ChangeEnrollmentStatus;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Events\CourseAccessEnded;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

/*
| Who opens the enrolments screen in `/admin`, and who may change a row there.
|
| ⚠️ The list is platform-wide and the door fell to `enrollments.view.all` — a
| TENANT permission that a finance officer who owns a workspace holds there. And
| `EnrollmentPolicy` has no `update()`, which Filament reads as «allowed».
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    [$away, $teacher] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدرّس']);

    $course = app(WorkspaceContext::class)->forWorkspace(
        $away,
        fn (): Course => Course::factory()->create(['workspace_id' => $away->getKey(), 'created_by' => $teacher->getKey()]),
    );

    $this->enrollment = Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $away->getKey(),
        'course_id' => $course->getKey(),
        'student_user_id' => User::factory()->create()->getKey(),
        'source' => 'purchase',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function enrollmentPanelAs(User $user, Workspace $home): void
{
    test()->actingAs($user);
    app()->forgetInstance(WorkspaceContext::class);
    test()->setCurrentWorkspace($home, $user);
}

it('stays shut to platform staff who hold enrollments.view.all in their own workspace', function (string $role): void {
    $officer = makePlatformStaff($role, $this->homeOwner);
    enrollmentPanelAs($officer, $this->home);

    expect($officer->can(Permissions::ENROLLMENTS_VIEW_ALL))->toBeTrue()
        ->and(EnrollmentResource::canViewAny())->toBeFalse()
        ->and(EnrollmentResource::canEdit($this->enrollment))->toBeFalse();
})->with([
    'finance officer' => [Roles::FINANCE_ADMIN],
    'compliance officer' => [Roles::COMPLIANCE_OFFICER],
]);

it('opens for a super admin who has a workspace, listing the other workspace\'s rows', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    enrollmentPanelAs($admin, $this->home);

    expect(EnrollmentResource::canViewAny())->toBeTrue()
        ->and(EnrollmentResource::canEdit($this->enrollment))->toBeTrue()
        ->and(EnrollmentResource::getEloquentQuery()->whereKey($this->enrollment->getKey())->exists())->toBeTrue();
});

it('refuses the Action to anybody but the super admin', function (): void {
    $officer = makePlatformStaff(Roles::FINANCE_ADMIN, $this->homeOwner);
    enrollmentPanelAs($officer, $this->home);

    expect(fn () => app(ChangeEnrollmentStatus::class)->handle($officer, $this->enrollment, EnrollmentStatus::Expired))
        ->toThrow(AuthorizationException::class);

    expect(Enrollment::query()->withoutWorkspaceScope()->whereKey($this->enrollment->getKey())->value('status'))->toBe('active');
});

it('closes a granted enrolment once, announcing the end of access once', function (): void {
    Event::fake([CourseAccessEnded::class]);

    $admin = User::factory()->create(['is_super_admin' => true]);
    enrollmentPanelAs($admin, $this->home);

    app(ChangeEnrollmentStatus::class)->handle($admin, $this->enrollment, EnrollmentStatus::Expired);
    app(ChangeEnrollmentStatus::class)->handle($admin, $this->enrollment->refresh(), EnrollmentStatus::Expired);

    expect(Enrollment::query()->withoutWorkspaceScope()->whereKey($this->enrollment->getKey())->value('status'))->toBe('expired');
    Event::assertDispatchedTimes(CourseAccessEnded::class, 1);
});
