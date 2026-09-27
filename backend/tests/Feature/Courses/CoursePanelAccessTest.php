<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Models\User;
use App\Modules\Analytics\Filament\Widgets\EnrollmentStatsWidget;
use App\Modules\Analytics\Filament\Widgets\ExamStatsWidget;
use App\Modules\Courses\Filament\Pages\ReviewPromoVideos;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/*
| The courses screen and the promo-video queue in `/admin`, read by people who
| HAVE a workspace — the fixture that hid every defect here.
|
| ⚠️ A platform officer who owns a workspace holds `courses.*` there; the list
| is platform-wide; so a tenant permission could never be its door. And the
| permission the compliance officer DOES hold for courses —
| `marketplace.promo.review` — had its only button on this super-admin screen.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    [$this->away, $this->awayOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدرّس']);

    $this->awayStudent = $this->addWorkspaceMember($this->away, Roles::STUDENT);

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->away,
        fn (): Course => Course::factory()->withPendingPromoVideo()->create([
            'workspace_id' => $this->away->getKey(),
            'created_by' => $this->awayOwner->getKey(),
        ]),
    );

    Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->awayStudent->getKey(),
        'source' => 'purchase',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function coursePanelAs(User $user, Workspace $home): void
{
    test()->actingAs($user);
    app()->forgetInstance(WorkspaceContext::class);
    test()->setCurrentWorkspace($home, $user);
}

it('stays shut to platform staff who hold courses.view in their own workspace', function (string $role): void {
    $officer = makePlatformStaff($role, $this->homeOwner);
    coursePanelAs($officer, $this->home);

    expect($officer->can(Permissions::COURSES_VIEW))->toBeTrue()
        ->and(CourseResource::canViewAny())->toBeFalse()
        ->and(CourseResource::canEdit($this->course))->toBeFalse()
        ->and(CourseResource::canDelete($this->course))->toBeFalse()
        // The two stats widgets now carry the platform widgets' door.
        ->and(EnrollmentStatsWidget::canView())->toBeFalse()
        ->and(ExamStatsWidget::canView())->toBeFalse();
})->with([
    'finance officer' => [Roles::FINANCE_ADMIN],
    'compliance officer' => [Roles::COMPLIANCE_OFFICER],
]);

it('lists another workspace\'s course, with its enrolments counted, for a super admin who has a workspace', function (): void {
    coursePanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    $row = CourseResource::getEloquentQuery()->whereKey($this->course->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row?->getAttribute('enrollments_count'))->toBe(1);

    Livewire::test(ListCourses::class)->assertActionDoesNotExist('create');
});

it('offers the course\'s owners and teachers as its author, never its students', function (): void {
    coursePanelAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    Livewire::test(EditCourse::class, ['record' => $this->course->getRouteKey()])
        ->assertFormFieldExists('created_by', fn (Select $field): bool => array_key_exists($this->awayOwner->getKey(), $field->getOptions())
            && ! array_key_exists($this->awayStudent->getKey(), $field->getOptions()))
        ->fillForm(['created_by' => $this->awayStudent->getKey()])
        ->call('save')
        ->assertHasFormErrors(['created_by']);

    expect(Course::query()->withoutWorkspaceScope()->whereKey($this->course->getKey())->value('created_by'))
        ->toBe($this->awayOwner->getKey());
});

it('gives the compliance officer who owns a workspace the promo queue — every workspace\'s', function (): void {
    $officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER, $this->homeOwner);
    coursePanelAs($officer, $this->home);

    // `Livewire::test()` never touches panel discovery — a page nobody discovers
    // has no route and no menu entry, and every other line here would still pass.
    expect(Filament::getPanel('admin')->getPages())->toContain(ReviewPromoVideos::class)
        ->and(ReviewPromoVideos::canAccess())->toBeTrue()
        ->and(ReviewPromoVideos::getNavigationBadge())->toBe('1');

    Livewire::test(ReviewPromoVideos::class)
        ->assertCanSeeTableRecords([$this->course])
        ->callTableAction('approve', $this->course);

    $course = Course::query()->withoutWorkspaceScope()->findOrFail($this->course->getKey());

    expect($course->promo_video_status)->toBe(Course::PROMO_APPROVED)
        ->and($course->promo_video_reviewed_by)->toBe($officer->getKey())
        ->and(ReviewPromoVideos::getNavigationBadge())->toBeNull();
});

it('refuses a rejection with no reason, and keeps the video pending', function (): void {
    coursePanelAs(makePlatformStaff(Roles::COMPLIANCE_OFFICER, $this->homeOwner), $this->home);

    Livewire::test(ReviewPromoVideos::class)
        ->callTableAction('reject', $this->course, ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect(Course::query()->withoutWorkspaceScope()->whereKey($this->course->getKey())->value('promo_video_status'))
        ->toBe(Course::PROMO_PENDING);
});

it('keeps the promo queue shut to the finance officer and to a workspace owner', function (): void {
    coursePanelAs(makePlatformStaff(Roles::FINANCE_ADMIN, $this->homeOwner), $this->home);
    expect(ReviewPromoVideos::canAccess())->toBeFalse();

    coursePanelAs($this->awayOwner, $this->away);
    expect(ReviewPromoVideos::canAccess())->toBeFalse();
});
