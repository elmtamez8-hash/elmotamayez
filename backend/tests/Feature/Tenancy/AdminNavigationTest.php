<?php

declare(strict_types=1);

use App\Filament\NavigationGroups;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Widgets\DecisionQueueWidget;
use App\Models\User;
use App\Modules\Analytics\Filament\Widgets\EnrollmentStatsWidget;
use App\Modules\Analytics\Filament\Widgets\ExamStatsWidget;
use App\Modules\Compliance\Filament\Resources\TeacherOffboardingResource;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Courses\Filament\Pages\ReviewPromoVideos;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Filament\Resources\RedemptionResource;
use App\Modules\Gamification\Models\Redemption;
use App\Modules\Marketplace\Filament\Resources\TeacherApplicationResource;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Payments\Enums\OrderKind;
use App\Modules\Payments\Filament\Pages\GrantCreditSubscription;
use App\Modules\Payments\Filament\Pages\ReviewPlanChanges;
use App\Modules\Payments\Models\Order;
use App\Modules\Settlement\Filament\Pages\ReviewRateRequests;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/*
| قائمةُ `/admin` ومجموعةُ «ينتظر قرارك» ولوحتُها.
|
| ⚠️ القارئُ هنا موظّفُ منصّةٍ **يملكُ مساحة**، والطوابيرُ ممتلئةٌ في مساحةٍ
| **أخرى**. هذه هي التجهيزةُ الوحيدةُ التي ترى عيبَ ٠٢٤ الخامس: عدٌّ تحتَ النطاقِ
| يقرأُ «صفر» عن طابورٍ ممتلئ، ومساحةٌ واحدةٌ في التجهيزةِ تُخفي ذلك.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->home, $this->homeOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    [$this->away, $this->awayOwner] = $this->createWorkspaceWithOwner(['name' => 'مساحة المدرّس']);

    $student = $this->addWorkspaceMember($this->away, Roles::STUDENT);
    $context = app(WorkspaceContext::class);

    $order = fn (Workspace $workspace, OrderKind $kind, string $status): Order => Order::query()->withoutWorkspaceScope()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $student->getKey(),
        'kind' => $kind,
        'amount_minor' => 25000,
        'currency' => 'QAR',
        'provider' => 'manual',
        'status' => $status,
    ]);

    // ثلاثةٌ تنتظرُ القرار (واحدٌ منها في مساحةِ الموظّفِ نفسِه)، وواحدٌ يُرَدُّ، وواحدٌ منتهٍ.
    $order($this->home, OrderKind::Course, 'pending');
    $order($this->away, OrderKind::Course, 'pending');
    $order($this->away, OrderKind::Subscription, 'under_review');
    $order($this->away, OrderKind::Course, 'refund_due');
    $order($this->away, OrderKind::Course, 'approved');

    $context->forWorkspace($this->away, function (): void {
        $this->pendingVideo = Course::factory()->withPendingPromoVideo()->create([
            'workspace_id' => $this->away->getKey(),
            'created_by' => $this->awayOwner->getKey(),
        ]);
        $this->approvedVideo = Course::factory()->withApprovedPromoVideo('aaaaaaaaaaa')->create([
            'workspace_id' => $this->away->getKey(),
            'created_by' => $this->awayOwner->getKey(),
        ]);

        RateChangeRequest::factory()->create(['workspace_id' => $this->away->getKey()]);
        TeacherApplication::factory()->submitted()->create(['workspace_id' => $this->away->getKey()]);
        // ينتظرُ المدرّسَ لا المراجِع، فلا يُعَدّ.
        TeacherApplication::factory()->submitted()->create([
            'workspace_id' => $this->away->getKey(),
            'status' => TeacherApplication::STATUS_CHANGES_REQUESTED,
        ]);
        Redemption::factory()->create(['workspace_id' => $this->away->getKey()]);
        TeacherOffboarding::query()->create([
            'workspace_id' => $this->away->getKey(),
            'teacher_user_id' => $this->awayOwner->getKey(),
        ]);
    });

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function navigationAs(User $user, Workspace $home): void
{
    test()->actingAs($user);
    app()->forgetInstance(WorkspaceContext::class);
    test()->setCurrentWorkspace($home, $user);
}

/** @return array<string, string> label => value */
function decisionStats(): array
{
    /** @var list<Stat> $stats */
    $stats = (fn (): array => $this->getStats())->call(new DecisionQueueWidget);

    $out = [];

    foreach ($stats as $stat) {
        $out[(string) $stat->getLabel()] = (string) $stat->getValue();
    }

    return $out;
}

/** @return list<class-string> every resource and page the panel registers */
function panelScreens(): array
{
    $panel = Filament::getPanel('admin');

    return array_values(array_merge($panel->getResources(), $panel->getPages()));
}

function iconKey(mixed $icon): string
{
    return $icon instanceof BackedEnum ? (string) $icon->value : (string) $icon;
}

// ─── الشكل ───────────────────────────────────────────────────────────────────

it('declares the ten groups in the owner\'s order, and the menu renders them in it', function (): void {
    navigationAs(User::factory()->create(['is_super_admin' => true, 'last_workspace_id' => null]), $this->home);

    expect(Filament::getPanel('admin')->getNavigationGroups())->toBe(NavigationGroups::ordered());

    $rendered = collect(Filament::getNavigation())
        ->map(fn (NavigationGroup $group): ?string => $group->getLabel())
        ->filter()
        ->values()
        ->all();

    expect($rendered)->toBe(NavigationGroups::ordered());
});

it('puts every screen in a declared group, with a sort no sibling shares', function (): void {
    $sorts = [];

    foreach (panelScreens() as $screen) {
        $group = $screen::getNavigationGroup();

        if ($group === null) {
            continue;
        }

        expect(NavigationGroups::ordered())->toContain($group);

        $sort = $screen::getNavigationSort();
        expect($sort)->not->toBeNull("{$screen} has no explicit sort");

        $sorts[$group][] = $sort;
    }

    foreach ($sorts as $group => $values) {
        expect(count($values))->toBe(count(array_unique($values)), "a tie in «{$group}»");
    }
});

it('gives every item in the menu an icon nobody else wears', function (): void {
    $icons = array_map(fn (string $screen): string => iconKey($screen::getNavigationIcon()), panelScreens());

    foreach (Filament::getPanel('admin')->getNavigationItems() as $item) {
        /** @var NavigationItem $item */
        $icons[] = iconKey($item->getIcon());
    }

    $icons = array_values(array_filter($icons, fn (string $icon): bool => $icon !== ''));

    expect(array_diff_assoc($icons, array_unique($icons)))->toBe([]);
});

it('names every group through the constants, never as a string that can drift', function (): void {
    $dirs = array_values(array_filter([app_path('Filament'), ...glob(app_path('Modules/*/Filament')) ?: []], 'is_dir'));
    $offences = [];

    foreach (Finder::create()->files()->in($dirs)->name('*.php') as $file) {
        $source = codeWithoutComments((string) file_get_contents($file->getRealPath()));

        if (preg_match('/\$navigationGroup\s*=\s*[\'"]/u', $source) === 1
            || preg_match('/function getNavigationGroup\(\)[^{]*\{\s*return\s*[\'"]/u', $source) === 1) {
            $offences[] = $file->getFilename();
        }
    }

    expect($offences)->toBe([]);
});

it('keeps the decision queues in «ينتظر قرارك» and nowhere else', function (): void {
    $queues = [OrderResource::class, GrantCreditSubscription::class, TeacherApplicationResource::class,
        ReviewRateRequests::class, ReviewPlanChanges::class, ReviewPromoVideos::class];

    $inGroup = array_values(array_filter(
        panelScreens(),
        fn (string $screen): bool => $screen::getNavigationGroup() === NavigationGroups::DECISIONS,
    ));

    expect($inGroup)->toEqualCanonicalizing($queues);
});

// ─── العدّادات ─────────────────────────────────────────────────────────────────

it('counts every workspace\'s queue for a super admin who has one', function (): void {
    navigationAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    expect(OrderResource::getNavigationBadge())->toBe('3')
        ->and(OrderResource::getNavigationBadgeColor())->toBe('warning')
        ->and(OrderResource::getNavigationBadgeTooltip())->toBe('مبالغ يجب ردّها: 1')
        ->and(GrantCreditSubscription::getNavigationBadge())->toBe('1')
        ->and(TeacherApplicationResource::getNavigationBadge())->toBe('1')
        ->and(ReviewRateRequests::getNavigationBadge())->toBe('1')
        ->and(ReviewPlanChanges::getNavigationBadge())->toBeNull()
        ->and(ReviewPromoVideos::getNavigationBadge())->toBe('1')
        ->and(TeacherOffboardingResource::getNavigationBadge())->toBe('1')
        // يقرؤها المديرُ ولا يبتُّ فيها: في «التلعيب» وبلا عدّاد.
        ->and(RedemptionResource::getNavigationGroup())->toBe(NavigationGroups::GAMIFICATION)
        ->and(RedemptionResource::getNavigationBadge())->toBeNull();
});

it('gives the finance officer who owns a workspace the money queues, platform-wide, and nothing else', function (): void {
    navigationAs(makePlatformStaff(Roles::FINANCE_ADMIN, $this->homeOwner), $this->home);

    expect(OrderResource::getNavigationBadge())->toBe('3')
        ->and(GrantCreditSubscription::getNavigationBadge())->toBe('1')
        ->and(ReviewPlanChanges::decisionQueueVisible())->toBeTrue()
        ->and(ReviewRateRequests::getNavigationBadge())->toBeNull()
        ->and(TeacherApplicationResource::getNavigationBadge())->toBeNull()
        ->and(ReviewPromoVideos::getNavigationBadge())->toBeNull()
        ->and(TeacherOffboardingResource::getNavigationBadge())->toBeNull();
});

it('shows the compliance officer neither the payments screen nor its count, although their workspace grants orders.view.all', function (): void {
    navigationAs(makePlatformStaff(Roles::COMPLIANCE_OFFICER, $this->homeOwner), $this->home);

    // ⚠️ `ORDERS_VIEW_ALL` صلاحيّةُ مساحةٍ يحملُها هناك، ولم تعُدْ بابَ هذه الشاشة.
    expect(auth()->user()?->can(Permissions::ORDERS_VIEW_ALL))->toBeTrue()
        ->and(OrderResource::canViewAny())->toBeFalse()
        ->and(OrderResource::getNavigationBadge())->toBeNull()
        ->and(OrderResource::getNavigationBadgeTooltip())->toBeNull()
        ->and(ReviewPromoVideos::getNavigationBadge())->toBe('1')
        ->and(TeacherOffboardingResource::getNavigationBadge())->toBe('1')
        ->and(GrantCreditSubscription::getNavigationBadge())->toBeNull();

    $labels = collect(Filament::getNavigation())
        ->flatMap(fn (NavigationGroup $group): array => $group->getItems() instanceof Collection
            ? $group->getItems()->all()
            : (array) $group->getItems())
        ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
        ->all();

    expect($labels)->not->toContain('اعتماد المدفوعات')
        ->and($labels)->toContain('فيديوهات تنتظر المراجعة');

    $order = Order::query()->withoutWorkspaceScope()->where('workspace_id', $this->home->getKey())->firstOrFail();

    $this->get(OrderResource::getUrl('index'))->assertForbidden();
    $this->get(OrderResource::getUrl('edit', ['record' => $order]))->assertForbidden();
});

it('gives the finance officer who owns a workspace every workspace\'s orders, and the page opens', function (): void {
    navigationAs(makePlatformStaff(Roles::FINANCE_ADMIN, $this->homeOwner), $this->home);

    $away = Order::query()->withoutWorkspaceScope()->where('workspace_id', $this->away->getKey())->get();
    $home = Order::query()->withoutWorkspaceScope()->where('workspace_id', $this->home->getKey())->get();

    expect(OrderResource::canViewAny())->toBeTrue()
        ->and(OrderResource::getEloquentQuery()->count())->toBe(5);

    $this->get(OrderResource::getUrl('index'))->assertOk();
    $this->get(OrderResource::getUrl('edit', ['record' => $away->first()]))->assertOk();

    Livewire::test(ListOrders::class)->assertCanSeeTableRecords($away->merge($home));
});

// ─── لوحة «ينتظر قرارك» ─────────────────────────────────────────────────────────

it('shows the super admin a card per queue, with the badge\'s own numbers, first on the dashboard', function (): void {
    navigationAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    expect(DecisionQueueWidget::canView())->toBeTrue()
        ->and(DecisionQueueWidget::getSort())->toBeLessThan(-3)
        ->and(Filament::getPanel('admin')->getWidgets())->toContain(DecisionQueueWidget::class)
        ->and(decisionStats())->toBe([
            'اعتماد المدفوعات' => '3',
            'طلبات الاشتراك' => '1',
            'طلبات المدرّسين' => '1',
            'اعتماد أسعار المدرّسين' => '1',
            'طلبات تعديل الباقات' => '0',
            'فيديوهات تنتظر المراجعة' => '1',
            'مبالغ يجب ردّها' => '1',
        ]);
});

it('shows each officer only the cards for screens they can open', function (string $role, array $expected): void {
    navigationAs(makePlatformStaff($role, $this->homeOwner), $this->home);

    expect(decisionStats())->toBe($expected);
})->with([
    'finance officer' => [Roles::FINANCE_ADMIN, [
        'اعتماد المدفوعات' => '3',
        'طلبات الاشتراك' => '1',
        'طلبات تعديل الباقات' => '0',
        'مبالغ يجب ردّها' => '1',
    ]],
    'compliance officer' => [Roles::COMPLIANCE_OFFICER, [
        'فيديوهات تنتظر المراجعة' => '1',
    ]],
]);

it('says calmly that nothing is waiting when every queue is empty', function (): void {
    Course::query()->withoutWorkspaceScope()->update(['promo_video_status' => Course::PROMO_APPROVED]);
    navigationAs(makePlatformStaff(Roles::COMPLIANCE_OFFICER, $this->homeOwner), $this->home);

    expect(decisionStats())->toBe(['لا شيء ينتظرك' => '—']);
});

it('is not on the dashboard of someone who reaches no queue', function (): void {
    navigationAs($this->awayOwner, $this->away);

    expect(DecisionQueueWidget::canView())->toBeFalse();
});

it('counts enrolments and attempts across the platform for a super admin who has a workspace', function (): void {
    navigationAs(User::factory()->create(['is_super_admin' => true]), $this->home);

    $this->createEnrollment($this->away, $this->pendingVideo, $this->addWorkspaceMember($this->away, Roles::STUDENT));

    $widget = new EnrollmentStatsWidget;
    $total = (fn (): array => $this->getStats())->call($widget)[0]->getValue();

    expect($total)->toBe('1')
        ->and($widget->getHeading())->toBe('التسجيلات — على المنصّة كلّها')
        ->and((new ExamStatsWidget)->getHeading())->toBe('الاختبارات — على المنصّة كلّها');
});

// ─── الفيديوهات المعتمَدة ────────────────────────────────────────────────────────

it('lists the approved videos behind a filter and withdraws one through the review Action', function (): void {
    $officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER, $this->homeOwner);
    navigationAs($officer, $this->home);

    Livewire::test(ReviewPromoVideos::class)
        ->assertCanSeeTableRecords([$this->pendingVideo])
        ->assertCanNotSeeTableRecords([$this->approvedVideo])
        ->assertTableActionHidden('withdraw', $this->pendingVideo)
        ->filterTable('promo_video_status', Course::PROMO_APPROVED)
        ->assertCanSeeTableRecords([$this->approvedVideo])
        ->assertCanNotSeeTableRecords([$this->pendingVideo])
        ->assertTableActionHidden('approve', $this->approvedVideo)
        ->callTableAction('withdraw', $this->approvedVideo, ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect(Course::query()->withoutWorkspaceScope()->whereKey($this->approvedVideo->getKey())->value('promo_video_status'))
        ->toBe(Course::PROMO_APPROVED);

    Livewire::test(ReviewPromoVideos::class)
        ->filterTable('promo_video_status', Course::PROMO_APPROVED)
        ->callTableAction('withdraw', $this->approvedVideo, ['reason' => 'تغيّر محتوى الفيديو على القناة'])
        ->assertHasNoTableActionErrors();

    $course = Course::query()->withoutWorkspaceScope()->findOrFail($this->approvedVideo->getKey());

    expect($course->promo_video_status)->toBe(Course::PROMO_REJECTED)
        ->and($course->promo_video_reviewed_by)->toBe($officer->getKey())
        // The badge still counts what is PENDING, and only that.
        ->and(ReviewPromoVideos::pendingCount())->toBe(1);
});

it('refuses the withdrawal to a finance officer and to a workspace owner', function (): void {
    navigationAs(makePlatformStaff(Roles::FINANCE_ADMIN, $this->homeOwner), $this->home);
    Livewire::test(ReviewPromoVideos::class)->assertForbidden();

    navigationAs($this->awayOwner, $this->away);
    Livewire::test(ReviewPromoVideos::class)->assertForbidden();

    expect(Course::query()->withoutWorkspaceScope()->whereKey($this->approvedVideo->getKey())->value('promo_video_status'))
        ->toBe(Course::PROMO_APPROVED);
});
