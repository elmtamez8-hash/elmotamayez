<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\CouponScope;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Resources\CouponResource;
use App\Modules\Payments\Filament\Resources\CouponResource\Pages\CreateCoupon;
use App\Modules\Payments\Filament\Resources\CouponResource\Pages\EditCoupon;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Modules\Payments\Models\Coupon;
use App\Modules\Payments\Models\Plan;
use App\Modules\Payments\Support\BillingSettings;
use App\Modules\Tenancy\Filament\Pages\ManagePlatformSettings;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
| ⛔ كلُّ مبلغٍ في اللوحةِ يُكتَبُ بالوحدةِ الكبرى ويُخزَّنُ بالصغرى (قرارُ المالك
| ٢٠٢٦-٠٩-٢٧).
|
| كانَ سعرُ الباقةِ حقلاً رقميّاً حرّاً يُقَصُّ بـ`(int)`: مَن كتبَ 49.99 ظانّاً أنّه
| يكتبُ ريالاً خزَّنَ 49 هللة. وسعرُ الكورسِ عددٌ صحيحٌ بلا حدٍّ أدنى — السالبُ
| يُحفَظ. ورسومُ المنصّةِ تُطلَبُ بالهللات. والكوبونُ يقبلُ ١٥٠٪.
|
| ⚠️ كلُّ توكيدٍ هنا على العمودِ المخزَّنِ لا على ما عرضَته الشاشة: الشاشةُ تُعيدُ
| ما كُتِبَ فيها، والعيبُ كانَ في المسافةِ بينَهما.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->officerWorkspace, $this->officer] = $this->createWorkspaceWithOwner(['name' => 'مساحة الموظّف']);
    makePlatformStaff(Roles::FINANCE_ADMIN, $this->officer);

    [$this->teacherWorkspace, $this->teacher] = $this->createWorkspaceWithOwner(['name' => 'أكاديمية خالد']);

    $this->officer->refresh();
    app(WorkspaceContext::class)->set($this->officerWorkspace);
});

it('stores 49.99 typed into a new plan as 4999, not 49', function (): void {
    Livewire::actingAs($this->officer)
        ->test(CreatePlan::class)
        ->fillForm([
            'teacher' => (string) $this->teacherWorkspace->uuid,
            'title' => 'باقة الشهر',
            'shape' => 'duration',
            'duration_days' => 30,
            'session_type' => ClassSessionType::Individual->value,
            'coverage_type' => PlanCoverage::Workspace->value,
            'price_minor' => '49.99',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((int) Plan::query()->withoutWorkspaceScope()->where('title', 'باقة الشهر')->value('price_minor'))->toBe(4999);
});

/*
| ⚠️ The edit screen is driven by a super admin, not the finance officer above:
| `PlanPolicy::update()` asks `belongsToCurrentWorkspace()` first, so an officer
| is refused a teacher's plan here — an authorisation question outside what this
| file measures, which is the number that reaches the column.
*/
it('opens a priced plan in major units and saves an edit back in minor ones', function (): void {
    $plan = Plan::factory()->create(['workspace_id' => $this->teacherWorkspace->getKey(), 'price_minor' => 30_000]);

    Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
        ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
        ->assertFormSet(['price_minor' => '300.00'])
        ->fillForm(['price_minor' => '49.99'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((int) $plan->refresh()->price_minor)->toBe(4999);
});

it('refuses a negative plan price and a third decimal', function (string $price): void {
    $plan = Plan::factory()->create(['workspace_id' => $this->teacherWorkspace->getKey(), 'price_minor' => 30_000]);

    Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
        ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
        ->fillForm(['price_minor' => $price])
        ->call('save')
        ->assertHasFormErrors(['price_minor']);

    expect((int) $plan->refresh()->price_minor)->toBe(30_000);
})->with(['negative' => '-1', 'three decimals' => '49.999']);

it('keeps an emptied plan price as «awaiting pricing», never zero', function (): void {
    $plan = Plan::factory()->create(['workspace_id' => $this->teacherWorkspace->getKey(), 'price_minor' => 30_000]);

    Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
        ->test(EditPlan::class, ['record' => $plan->getRouteKey()])
        ->fillForm(['price_minor' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->refresh()->price_minor)->toBeNull();
});

describe('course price', function (): void {
    beforeEach(function (): void {
        // In the workspace the context already resolved to: `CourseResource`
        // keeps the scope, and which workspace a course sits in is not the
        // question here.
        $this->course = app(WorkspaceContext::class)->forWorkspace(
            $this->officerWorkspace,
            fn (): Course => Course::factory()->create([
                'workspace_id' => $this->officerWorkspace->getKey(),
                'created_by' => $this->officer->getKey(),
                'price_minor' => 10_000,
                'currency' => 'QAR',
            ]),
        );

        $this->admin = User::factory()->create(['is_super_admin' => true]);
    });

    it('stores 49.99 as 4999', function (): void {
        Livewire::actingAs($this->admin)
            ->test(EditCourse::class, ['record' => $this->course->getRouteKey()])
            ->assertFormSet(['price_minor' => '100.00'])
            ->fillForm(['price_minor' => '49.99'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect((int) Course::query()->withoutWorkspaceScope()->whereKey($this->course->getKey())->value('price_minor'))->toBe(4999);
    });

    it('refuses a negative or an empty price instead of storing it', function (string $price): void {
        Livewire::actingAs($this->admin)
            ->test(EditCourse::class, ['record' => $this->course->getRouteKey()])
            ->fillForm(['price_minor' => $price])
            ->call('save')
            ->assertHasFormErrors(['price_minor']);

        expect((int) Course::query()->withoutWorkspaceScope()->whereKey($this->course->getKey())->value('price_minor'))->toBe(10_000);
    })->with(['negative' => '-5', 'empty' => '']);
});

describe('coupon', function (): void {
    beforeEach(function (): void {
        $this->admin = User::factory()->create(['is_super_admin' => true]);
    });

    it('stores a fixed amount of 49.99 as 4999', function (): void {
        Livewire::actingAs($this->admin)
            ->test(CreateCoupon::class)
            ->fillForm(['code' => 'FIXED', 'value_kind' => 'fixed_minor', 'value' => '49.99', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Coupon::query()->where('code', 'FIXED')->value('value'))->toBe(4999);
    });

    it('reopens a fixed coupon in major units', function (): void {
        $coupon = Coupon::factory()->create(['value_kind' => 'fixed_minor', 'value' => 2_500]);

        Livewire::actingAs($this->admin)
            ->test(EditCoupon::class, ['record' => $coupon->getRouteKey()])
            ->assertFormSet(['value' => '25.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($coupon->refresh()->value)->toBe(2_500);
    });

    it('keeps a percentage as a whole number and refuses more than 100', function (): void {
        Livewire::actingAs($this->admin)
            ->test(CreateCoupon::class)
            ->fillForm(['code' => 'HALF', 'value_kind' => 'percent', 'value' => 50, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Coupon::query()->where('code', 'HALF')->value('value'))->toBe(50);

        foreach (['150', '12.5'] as $refused) {
            Livewire::actingAs($this->admin)
                ->test(CreateCoupon::class)
                ->fillForm(['code' => 'TOOMUCH', 'value_kind' => 'percent', 'value' => $refused, 'is_active' => true])
                ->call('create')
                ->assertHasFormErrors(['value']);
        }

        expect(Coupon::query()->where('code', 'TOOMUCH')->exists())->toBeFalse();
    });

    it('refuses a negative fixed amount', function (): void {
        Livewire::actingAs($this->admin)
            ->test(CreateCoupon::class)
            ->fillForm(['code' => 'NEG', 'value_kind' => 'fixed_minor', 'value' => '-10', 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['value']);

        expect(Coupon::query()->where('code', 'NEG')->exists())->toBeFalse();
    });

    it('names teachers in the workspace picker instead of asking for an id', function (): void {
        $options = [];

        Livewire::actingAs($this->admin)
            ->test(CreateCoupon::class)
            ->assertFormFieldExists('workspace_id', function (Select $field) use (&$options): bool {
                $options = $field->getOptions();

                return true;
            });

        expect($options[$this->teacherWorkspace->getKey()] ?? null)->toBe('أكاديمية خالد');
    });

    it('offers the chosen teacher\'s courses by uuid and stores the pick', function (): void {
        [$mine, $theirs] = [
            app(WorkspaceContext::class)->forWorkspace($this->teacherWorkspace, fn (): Course => Course::factory()->create([
                'workspace_id' => $this->teacherWorkspace->getKey(),
                'created_by' => $this->teacher->getKey(),
                'title' => 'الفيزياء ١',
            ])),
            app(WorkspaceContext::class)->forWorkspace($this->officerWorkspace, fn (): Course => Course::factory()->create([
                'workspace_id' => $this->officerWorkspace->getKey(),
                'created_by' => $this->officer->getKey(),
                'title' => 'الفيزياء ٢',
            ])),
        ];

        /*
        | ⚠️ The officer's context is the OFFICER's workspace, and the list still
        | carries the teacher's course and only it: the picker names the chosen
        | teacher explicitly and drops the scope — a scoped read would offer the
        | officer's own «الفيزياء ٢» and nothing else.
        */
        $offered = CouponResource::scopeTargets(CouponScope::Course, (int) $this->teacherWorkspace->getKey(), 'الفيزياء');

        expect(array_keys($offered))->toBe([(string) $mine->uuid])
            // Platform-wide: both teachers, each named, since two may sell one title.
            ->and(CouponResource::scopeTargets(CouponScope::Course, null, 'الفيزياء'))->toBe([
                (string) $mine->uuid => 'الفيزياء ١ — أكاديمية خالد',
                (string) $theirs->uuid => 'الفيزياء ٢ — مساحة الموظّف',
            ]);

        Livewire::actingAs($this->admin)
            ->test(CreateCoupon::class)
            ->fillForm([
                'code' => 'PHYS',
                'value_kind' => 'percent',
                'value' => 10,
                'workspace_id' => $this->teacherWorkspace->getKey(),
                'scope_type' => CouponScope::Course->value,
                'scope_uuid' => (string) $mine->uuid,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $coupon = Coupon::query()->where('code', 'PHYS')->firstOrFail();

        expect($coupon->workspace_id)->toBe((int) $this->teacherWorkspace->getKey())
            ->and($coupon->scope_uuid)->toBe((string) $mine->uuid);
    });
});

it('stores platform fees typed in major units as minor units', function (): void {
    Auth::login(User::factory()->create(['is_super_admin' => true]));

    Livewire::test(ManagePlatformSettings::class)
        ->fillForm([
            'operating_fee_individual' => '5.50',
            'operating_fee_group' => '2',
            'gateway_fixed_fee_minor' => '0.75',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $billing = app(BillingSettings::class);

    expect($billing->operatingFeeMinor(ClassSessionType::Individual))->toBe(550)
        ->and($billing->operatingFeeMinor(ClassSessionType::Group))->toBe(200)
        ->and($billing->gatewayFixedFeeMinor())->toBe(75);

    Livewire::test(ManagePlatformSettings::class)
        ->assertFormSet(['operating_fee_individual' => '5.50', 'gateway_fixed_fee_minor' => '0.75'])
        ->fillForm(['operating_fee_group' => '-1'])
        ->call('save')
        ->assertHasFormErrors(['operating_fee_group']);

    expect($billing->operatingFeeMinor(ClassSessionType::Group))->toBe(200);
});
