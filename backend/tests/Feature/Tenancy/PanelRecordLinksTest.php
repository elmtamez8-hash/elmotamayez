<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\CourseResource\RelationManagers\ExamsRelationManager;
use App\Filament\Resources\EnrollmentResource;
use App\Filament\Resources\EnrollmentResource\Pages\ListEnrollments;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\EnrollmentsRelationManager as UserEnrollmentsRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\OrdersRelationManager as UserOrdersRelationManager;
use App\Filament\Resources\UserResource\RelationManagers\SubscriptionsRelationManager as UserSubscriptionsRelationManager;
use App\Filament\Resources\WorkspaceResource;
use App\Filament\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Filament\Resources\WorkspaceResource\RelationManagers\CoursesRelationManager as WorkspaceCoursesRelationManager;
use App\Filament\Resources\WorkspaceResource\RelationManagers\MembersRelationManager as WorkspaceMembersRelationManager;
use App\Filament\Resources\WorkspaceResource\RelationManagers\StudentsRelationManager as WorkspaceStudentsRelationManager;
use App\Filament\Support\RecordLink;
use App\Models\User;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Filament\Resources\TeacherApplicationResource\Pages\ListTeacherApplications;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages\ListTeacherProfiles;
use App\Modules\Marketplace\Filament\Resources\TeacherProfileResource\Pages\ViewTeacherProfile;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
| Links between panel screens, the relation managers that read across
| workspaces, and global search — read by the three kinds of `/admin` reader.
|
| ⚠️ EVERY FIXTURE HAS TWO WORKSPACES AND A READER WHO OWNS ONE OF THEM
| (`docs/gotchas/tenancy.md`). The records live in `away`; the reader's context
| resolves to `home`. A one-workspace fixture passes every assertion here
| against a build that leaves the scope on.
|
| ⚠️ AND BOTH DIRECTIONS OF EVERY LINK. A «hidden for the finance officer»
| assertion alone is green against a build that draws no link for anybody.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeOwner] = $this->createWorkspaceWithOwner(['name' => 'Home Zone']);
    [$this->away, $this->awayOwner] = $this->createWorkspaceWithOwner(['name' => 'Away Zeta Academy']);

    $this->awayStudent = $this->addWorkspaceMember(
        $this->away,
        Roles::STUDENT,
        User::factory()->create(['email' => 'zeta.student@example.test']),
    );

    $context = app(WorkspaceContext::class);

    $this->course = $context->forWorkspace($this->away, fn (): Course => Course::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'created_by' => $this->awayOwner->getKey(),
    ]));

    $this->exam = $context->forWorkspace($this->away, fn (): Exam => Exam::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
    ]));

    $this->enrollment = Enrollment::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $this->away->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->awayStudent->getKey(),
        'source' => 'purchase',
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $this->order = Order::create([
        'workspace_id' => $this->away->getKey(),
        'user_id' => $this->awayStudent->getKey(),
        'course_id' => $this->course->getKey(),
        'kind' => OrderKind::Course,
        'amount_minor' => 22_000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => 'under_review',
    ]);

    $this->profile = $context->forWorkspace($this->away, fn (): TeacherProfile => TeacherProfile::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'user_id' => $this->awayOwner->getKey(),
    ]));

    $this->application = $context->forWorkspace($this->away, fn (): TeacherApplication => TeacherApplication::factory()->create([
        'workspace_id' => $this->away->getKey(),
        'user_id' => $this->awayOwner->getKey(),
        'teacher_profile_id' => $this->profile->getKey(),
        'status' => TeacherApplication::STATUS_SUBMITTED,
        'submitted_at' => now(),
    ]));

    $this->plan = Plan::factory()->create(['workspace_id' => $this->away->getKey()]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function panelLinksAs(User $user, Workspace $home): void
{
    test()->actingAs($user);
    app()->forgetInstance(WorkspaceContext::class);
    test()->setCurrentWorkspace($home, $user);
}

function panelLinksSuperAdmin(Workspace $home): User
{
    $admin = User::factory()->create(['is_super_admin' => true]);
    panelLinksAs($admin, $home);

    return $admin;
}

/** A column's url on one row, or null. */
function panelLinkUrlIs(?string $expected): Closure
{
    return fn (TextColumn $column): bool => $column->getUrl() === $expected;
}

/**
 * @return array<string, list<GlobalSearchResult>>
 */
function panelGlobalSearch(string $query): array
{
    $results = Filament::getGlobalSearchProvider()?->getResults($query);

    return $results === null
        ? []
        : $results->getCategories()->map(fn ($rows): array => collect($rows)->values()->all())->all();
}

// ─── links: the super admin, who can open every target ─────────────────────

it('links a super admin from an order to its course and its buyer, across workspaces', function (): void {
    panelLinksSuperAdmin($this->home);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$this->order])
        ->assertTableColumnExists('course.title', panelLinkUrlIs(CourseResource::getUrl('edit', ['record' => $this->course])), $this->order)
        ->assertTableColumnExists('user.email', panelLinkUrlIs(UserResource::getUrl('edit', ['record' => $this->awayStudent])), $this->order);
});

it('links a super admin from an enrolment to its course, its workspace and its student', function (): void {
    panelLinksSuperAdmin($this->home);

    Livewire::test(ListEnrollments::class)
        ->assertTableColumnExists('course.title', panelLinkUrlIs(CourseResource::getUrl('edit', ['record' => $this->course])), $this->enrollment)
        ->assertTableColumnExists('course.workspace.name', panelLinkUrlIs(WorkspaceResource::getUrl('view', ['record' => $this->away])), $this->enrollment)
        ->assertTableColumnExists('student.email', panelLinkUrlIs(UserResource::getUrl('edit', ['record' => $this->awayStudent])), $this->enrollment);
});

it('links a super admin from a course, a teacher profile and a plan to the workspace view', function (): void {
    panelLinksSuperAdmin($this->home);
    $workspaceUrl = WorkspaceResource::getUrl('view', ['record' => $this->away]);

    Livewire::test(ListCourses::class)
        ->assertTableColumnExists('workspace.name', panelLinkUrlIs($workspaceUrl), $this->course);

    Livewire::test(ListTeacherProfiles::class)
        ->assertTableColumnExists('workspace.name', panelLinkUrlIs($workspaceUrl), $this->profile);

    Livewire::test(ListPlans::class)
        ->assertTableColumnExists('workspace.name', panelLinkUrlIs($workspaceUrl), $this->plan);
});

it('gives a super admin the order page\'s links to its buyer, workspace and course', function (): void {
    panelLinksSuperAdmin($this->home);

    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertActionVisible('openUser')
        ->assertActionHasUrl('openUser', UserResource::getUrl('edit', ['record' => $this->awayStudent]))
        ->assertActionVisible('openWorkspace')
        ->assertActionHasUrl('openWorkspace', WorkspaceResource::getUrl('view', ['record' => $this->away]))
        ->assertActionVisible('openCourse')
        ->assertActionHasUrl('openCourse', CourseResource::getUrl('edit', ['record' => $this->course]));

    Livewire::test(ViewTeacherProfile::class, ['record' => $this->profile->getRouteKey()])
        ->assertActionVisible('openUser')
        ->assertActionVisible('openWorkspace');
});

// ─── links: platform staff who own a workspace, and cannot open the target ──

it('draws no course or buyer link for the finance officer, who reads orders but opens neither', function (): void {
    panelLinksAs(makePlatformStaff(Roles::FINANCE_ADMIN, $this->homeOwner), $this->home);

    expect(OrderResource::canViewAny())->toBeTrue()
        ->and(CourseResource::canViewAny())->toBeFalse()
        ->and(UserResource::canViewAny())->toBeFalse();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$this->order])
        ->assertTableColumnExists('course.title', panelLinkUrlIs(null), $this->order)
        ->assertTableColumnExists('user.email', panelLinkUrlIs(null), $this->order);

    Livewire::test(EditOrder::class, ['record' => $this->order->getRouteKey()])
        ->assertActionHidden('openUser')
        ->assertActionHidden('openWorkspace')
        ->assertActionHidden('openCourse');

    Livewire::test(ListPlans::class)
        ->assertTableColumnExists('workspace.name', panelLinkUrlIs(null), $this->plan);
});

it('links a super admin from a teacher application to its profile', function (): void {
    panelLinksSuperAdmin($this->home);

    Livewire::test(ListTeacherApplications::class)
        ->assertTableColumnExists(
            'teacherProfile.approval_status',
            panelLinkUrlIs(TeacherProfileResource::getUrl('view', ['record' => $this->profile])),
            $this->application,
        );
});

/*
| The compliance officer holds none of these screens' doors — not even the
| teacher review, which the matrix gives to no platform role — so every target
| answers «no link» to them, even while they hold every TENANT permission in
| the workspace they own.
*/
it('builds no link at all for the compliance officer who owns a workspace', function (): void {
    panelLinksAs(makePlatformStaff(Roles::COMPLIANCE_OFFICER, $this->homeOwner), $this->home);

    expect(RecordLink::to(CourseResource::class, $this->course))->toBeNull()
        ->and(RecordLink::to(UserResource::class, $this->awayStudent))->toBeNull()
        ->and(RecordLink::to(WorkspaceResource::class, $this->away))->toBeNull()
        ->and(RecordLink::to(WorkspaceResource::class, $this->home))->toBeNull()
        ->and(RecordLink::to(EnrollmentResource::class, $this->enrollment))->toBeNull()
        ->and(RecordLink::to(OrderResource::class, $this->order))->toBeNull()
        ->and(RecordLink::to(TeacherProfileResource::class, $this->profile))->toBeNull();

    // And the super admin gets every one of them — the direction that proves the helper draws links at all.
    panelLinksSuperAdmin($this->home);

    expect(RecordLink::to(TeacherProfileResource::class, $this->profile))
        ->toBe(TeacherProfileResource::getUrl('view', ['record' => $this->profile]))
        ->and(RecordLink::to(OrderResource::class, $this->order))
        ->toBe(OrderResource::getUrl('edit', ['record' => $this->order]));
});

it('draws no link to a soft-deleted course, whose page would answer 404', function (): void {
    panelLinksSuperAdmin($this->home);

    Course::query()->withoutWorkspaceScope()->whereKey($this->course->getKey())->update(['deleted_at' => now()]);
    $order = Order::query()->withoutWorkspaceScope()->with('course')->findOrFail($this->order->getKey());

    expect($order->course)->not->toBeNull()
        ->and(RecordLink::to(CourseResource::class, $order->course))->toBeNull();
});

// ─── relation managers ─────────────────────────────────────────────────────

it('shows a super admin a student\'s orders, enrolments and subscriptions from another workspace', function (): void {
    panelLinksSuperAdmin($this->home);
    $owner = ['ownerRecord' => $this->awayStudent, 'pageClass' => EditUser::class];

    Livewire::test(UserOrdersRelationManager::class, $owner)
        ->assertCanSeeTableRecords([$this->order])
        ->assertTableColumnExists('course.title', panelLinkUrlIs(CourseResource::getUrl('edit', ['record' => $this->course])), $this->order);

    Livewire::test(UserEnrollmentsRelationManager::class, $owner)
        ->assertCanSeeTableRecords([$this->enrollment]);

    Livewire::test(UserSubscriptionsRelationManager::class, $owner)->assertSuccessful();
});

it('shows a super admin another workspace\'s courses and staff, and its students only on request', function (): void {
    panelLinksSuperAdmin($this->home);
    $owner = ['ownerRecord' => $this->away, 'pageClass' => ViewWorkspace::class];

    Livewire::test(WorkspaceCoursesRelationManager::class, $owner)
        ->assertCanSeeTableRecords([$this->course]);

    // `workspace_members` carries student rows: the team table never shows one,
    // and the students have a table of their own.
    Livewire::test(WorkspaceMembersRelationManager::class, $owner)
        ->assertCanSeeTableRecords([$this->awayOwner])
        ->assertCanNotSeeTableRecords([$this->awayStudent]);

    Livewire::test(WorkspaceStudentsRelationManager::class, $owner)
        ->assertCanSeeTableRecords([$this->awayStudent])
        ->assertCanNotSeeTableRecords([$this->awayOwner]);

    Livewire::test(ExamsRelationManager::class, ['ownerRecord' => $this->course, 'pageClass' => EditCourse::class])
        ->assertCanSeeTableRecords([$this->exam]);
});

it('opens the workspace page for a super admin, naming the team and never a student as staff', function (): void {
    panelLinksSuperAdmin($this->home);

    Livewire::test(ViewWorkspace::class, ['record' => $this->away->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($this->awayOwner->email)
        ->assertDontSee($this->awayStudent->email)
        ->assertActionVisible('owner');
});

it('shows no relation manager and no workspace page to platform staff who own a workspace', function (string $role): void {
    panelLinksAs(makePlatformStaff($role, $this->homeOwner), $this->home);

    foreach ([UserOrdersRelationManager::class, UserEnrollmentsRelationManager::class, UserSubscriptionsRelationManager::class] as $manager) {
        expect($manager::canViewForRecord($this->awayStudent, EditUser::class))->toBeFalse();
    }

    expect(WorkspaceCoursesRelationManager::canViewForRecord($this->away, ViewWorkspace::class))->toBeFalse()
        ->and(WorkspaceMembersRelationManager::canViewForRecord($this->away, ViewWorkspace::class))->toBeFalse()
        ->and(WorkspaceStudentsRelationManager::canViewForRecord($this->away, ViewWorkspace::class))->toBeFalse()
        ->and(ExamsRelationManager::canViewForRecord($this->course, EditCourse::class))->toBeFalse()
        ->and(WorkspaceResource::canView($this->away))->toBeFalse();

    $this->get(WorkspaceResource::getUrl('view', ['record' => $this->away]))->assertForbidden();
})->with([
    'finance officer' => [Roles::FINANCE_ADMIN],
    'compliance officer' => [Roles::COMPLIANCE_OFFICER],
]);

// ─── global search ─────────────────────────────────────────────────────────

it('finds an order, a user, an enrolment and a workspace across workspaces for a super admin', function (): void {
    panelLinksSuperAdmin($this->home);

    $byEmail = panelGlobalSearch('zeta.student');

    expect(collect($byEmail[UserResource::getPluralModelLabel()] ?? [])->pluck('url')->all())
        ->toContain(UserResource::getUrl('edit', ['record' => $this->awayStudent]))
        ->and(collect($byEmail[OrderResource::getPluralModelLabel()] ?? [])->pluck('url')->all())
        ->toContain(OrderResource::getUrl('edit', ['record' => $this->order]))
        ->and(collect($byEmail[EnrollmentResource::getPluralModelLabel()] ?? [])->pluck('url')->all())
        ->toContain(EnrollmentResource::getUrl('edit', ['record' => $this->enrollment]));

    $byUuid = panelGlobalSearch(substr((string) $this->order->uuid, 0, 13));

    expect(collect($byUuid[OrderResource::getPluralModelLabel()] ?? [])->pluck('url')->all())
        ->toContain(OrderResource::getUrl('edit', ['record' => $this->order]));

    // The course title reaches the enrolment through `whereHas('course')`.
    $byCourse = panelGlobalSearch((string) $this->course->title);

    expect(collect($byCourse[EnrollmentResource::getPluralModelLabel()] ?? [])->pluck('url')->all())
        ->toContain(EnrollmentResource::getUrl('edit', ['record' => $this->enrollment]));

    $byWorkspace = panelGlobalSearch('Zeta Academy');

    expect(collect($byWorkspace[WorkspaceResource::getPluralModelLabel()] ?? [])->pluck('url')->all())
        ->toBe([WorkspaceResource::getUrl('view', ['record' => $this->away])]);
});

it('searches only the screens a finance officer can open', function (): void {
    panelLinksAs(makePlatformStaff(Roles::FINANCE_ADMIN, $this->homeOwner), $this->home);

    $results = panelGlobalSearch('zeta');

    expect(array_keys($results))
        ->toContain(OrderResource::getPluralModelLabel())
        ->not->toContain(UserResource::getPluralModelLabel())
        ->not->toContain(EnrollmentResource::getPluralModelLabel())
        ->not->toContain(WorkspaceResource::getPluralModelLabel());
});

// ─── no hand-written admin URL ─────────────────────────────────────────────

it('builds every panel url with getUrl(), never a hand-written path or route name', function (): void {
    $offenders = [];

    // Module route files hold the API's own `/admin/...` endpoints, which are not panel urls.
    $files = Finder::create()->files()->in(app_path())->name('*.php')->notPath('#(^|[/\\\\])routes[/\\\\]#');

    foreach ($files as $file) {
        // Comments stripped first: the rule written beside the code is not a use of it.
        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('#[\'"]/admin(/|[\'"])|route\(\s*[\'"]filament\.#', $code) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
