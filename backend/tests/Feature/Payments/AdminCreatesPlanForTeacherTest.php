<?php

declare(strict_types=1);

use App\Modules\Compliance\Enums\OffboardingStatus;
use App\Modules\Compliance\Models\TeacherOffboarding;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Payments\Actions\CreatePlanForTeacher;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Resources\PlanResource;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/*
| ٠٣٤ · FR-017 … FR-019 — الإدارةُ تُنشئُ باقةً باسمِ مدرّسٍ منصوصٍ عليه.
|
| ⚠️ **مساحتانِ وموظَّفٌ يملكُ إحداهما، وإلّا لم يُقَسْ شيء.** `WorkspaceContext::id()`
| يرجعُ إلى `users.last_workspace_id` للموظَّفِ كأيِّ مستخدمٍ آخر، فتركيبةٌ بمساحةٍ
| واحدةٍ — أو بموظَّفٍ لا مساحةَ له — تجعلُ الاستعلامَ المُنطَّقَ والمُتجاوِزَ
| متّفقَينِ تماماً. وهذه هي الحالةُ التي يسقطُ عليها `T038`: سطرٌ واحدٌ في
| `StaffCreditGrantTest` كشفَ خمسَ طبقاتٍ من العطبِ نفسِه في ٠٢٤.
*/
beforeEach(function (): void {
    // الصلاحيّاتُ التي تقفُ خلفَ `finance-admin` تُزرَعُ هنا وحدَها؛ وبدونِها
    // يحملُ الموظَّفُ لا شيءَ وكلُّ رفضٍ أدناه يقعُ للسببِ الخطأ.
    $this->seed(RolesAndPermissionsSeeder::class);

    // مساحةُ الموظَّفِ نفسِه — وهي ما سيَحشُرُه النطاقُ في كلِّ استعلامٍ لو تُرِك.
    [$this->officerWorkspace, $this->officer] = $this->createWorkspaceWithOwner();
    makePlatformStaff(Roles::FINANCE_ADMIN, $this->officer);

    // ومساحةُ المدرّسِ التي تُكتَبُ الباقةُ فيها.
    [$this->teacherWorkspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->course = Course::factory()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
    ]);

    $this->officer->refresh();
    app(WorkspaceContext::class)->set($this->officerWorkspace);
});

/** الباقاتُ تُعَدُّ بتجاوزِ النطاق: سياقُ الاختبارِ مجمَّدٌ على مساحةِ الموظَّف. */
function adminPlanCount(): int
{
    return Plan::query()->withoutWorkspaceScope()->count();
}

it('writes a single-course plan into the named teacher\'s workspace, not the officer\'s', function (): void {
    /*
    | ⚠️ **من الصفحةِ لا من الفعل.** الفخُّ الذي تحرسُه هذه الحالةُ يعيشُ في
    | `handleRecordCreation()`: الافتراضيُّ في Filament إسنادٌ شامل، فيملأُ
    | `BelongsToWorkspace` مساحةَ **الموظَّف**، ويُسقِطُ `price_minor` صامتاً،
    | ولا يَحُلُّ التغطية. واستدعاءُ الفعلِ مباشرةً لا يرى شيئاً من ذلك.
    */
    $this->actingAs($this->officer);

    $page = new CreatePlan;
    $method = new ReflectionMethod($page, 'handleRecordCreation');

    /** @var Plan $plan */
    $plan = $method->invoke($page, [
        'teacher' => (string) $this->teacherWorkspace->uuid,
        'title' => 'اشتراك الكيمياء',
        'duration_days' => 30,
        'session_type' => ClassSessionType::Group->value,
        'coverage_type' => PlanCoverage::Course->value,
        'coverage_uuid' => (string) $this->course->uuid,
        'price_minor' => '30000',
        'is_active' => true,
    ]);

    expect((int) $plan->workspace_id)->toBe((int) $this->teacherWorkspace->getKey())
        ->and((int) $plan->workspace_id)->not->toBe((int) $this->officerWorkspace->getKey())
        // ⚠️ هذا ما يسقطُ بلا `T038`: الكورسُ موجودٌ عندَ المدرّسِ، والنطاقُ
        // يُضيفُ مساحةَ الموظَّفِ فيردُّ صفرَ صفوفٍ و«هذا الكورس غير موجود عندك».
        ->and($plan->coverage_uuid)->toBe((string) $this->course->uuid)
        ->and($plan->coverage_type)->toBe(PlanCoverage::Course)
        // ⚠️ والسعرُ يُكتَبُ فعلاً: العمودُ غيرُ قابلٍ للإسناد، فالإسنادُ الشاملُ
        // يتركُه `null` بلا خطأٍ ولا سطرٍ في سجلّ.
        ->and((int) $plan->price_minor)->toBe(30_000)
        ->and($plan->isSellable())->toBeTrue();

    // والمدرّسُ يُبلَّغ — فهي منتَجُه ويُحاسَبُ عليه (FR-019).
    expect(Notification::query()
        ->where('recipient_user_id', $this->teacher->getKey())
        ->where('type', 'plan_created_for_you')
        ->exists())->toBeTrue();
});

it('refuses a teacher who has left the platform, and writes nothing', function (): void {
    $offboarding = TeacherOffboarding::query()->create([
        'workspace_id' => $this->teacherWorkspace->getKey(),
        'teacher_user_id' => $this->teacher->getKey(),
        'notice_ends_at' => now()->subDay(),
    ]);

    $offboarding->forceFill(['status' => OffboardingStatus::Completed->value])->save();

    expect(fn () => app(CreatePlanForTeacher::class)->handle(
        $this->officer,
        $this->teacherWorkspace,
        adminPlanData(),
        30_000,
    ))->toThrow(DomainException::class);

    // الرفضُ قبلَ أوّلِ كتابة — لا باقةٌ نصفُ مكتوبةٍ تنتظرُ تسعيراً.
    expect(adminPlanCount())->toBe(0);
});

it('refuses a workspace with no teaching member, and writes nothing', function (): void {
    /*
    | ⚠️ والسؤالُ بالنفي (`role != student`): مساحةٌ لا عضوَ فيها إلّا طلاباً —
    | أو لا عضوَ فيها أصلاً — ليسَ فيها مَن تُنسَبُ إليه الباقةُ ويُدرِّسَها.
    */
    $empty = Workspace::factory()->create();

    expect(fn () => app(CreatePlanForTeacher::class)->handle(
        $this->officer,
        $empty,
        adminPlanData(coverage: PlanCoverage::Workspace),
        30_000,
    ))->toThrow(DomainException::class);

    expect(adminPlanCount())->toBe(0);
});

it('refuses anyone without the pricing permission', function (): void {
    // ⚠️ الاتّجاهُ الآخر: هذا الفعلُ هو البابُ الوحيدُ، ولا سياسةَ خلفَه تحرسُه.
    expect(fn () => app(CreatePlanForTeacher::class)->handle(
        $this->teacher,
        $this->teacherWorkspace,
        adminPlanData(coverage: PlanCoverage::Workspace),
        30_000,
    ))->toThrow(DomainException::class);

    expect(adminPlanCount())->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function adminPlanData(PlanCoverage $coverage = PlanCoverage::Workspace): array
{
    return [
        'title' => 'اشتراك الكيمياء',
        'duration_days' => 30,
        'session_type' => ClassSessionType::Group->value,
        'coverage_type' => $coverage->value,
    ];
}

/*
| ⛔ **البابُ مفتوحٌ منذُ ٠٣٤، ولم يكنْ إليه طريق.**
|
| `canCreate()` صارَ `true` وصفحةُ `CreatePlan` كُتِبَت وسُجِّلَت في `getPages()` —
| بينما `ListPlans::getHeaderActions()` بقيَ فارغاً، وتحتَه تعليقٌ يدافعُ عن
| القاعدةِ التي ألغاها ٠٣٤ نفسُه. فالصفحةُ لا تُفتَحُ إلّا بكتابةِ عنوانِها.
|
| قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦: `plans` فيه صفرُ صفوفٍ واحتاجَ مشغِّلٌ أوّلَ
| باقةٍ على المنصّةِ فلم يجدْ زرّاً. وهي ثالثةُ مرّةٍ يُبنى فيها سطحٌ ولا يصلُ
| إليه شيء.
|
| ⚠️ **وشقٌّ ضدٌّ معه**: الزرُّ يقرأُ `canCreate()` نفسَها، فمن لا يملكُ
| `plans.price` لا يراه — وإخفاءُ الزرِّ ليسَ حراسةً، لذلك يبقى الفعلُ يسألُ
| الصلاحيّةَ داخلَه كما هو.
*/
it('offers a way into the create screen instead of hiding it behind its address', function (): void {
    Livewire::actingAs($this->officer)
        ->test(ListPlans::class)
        ->assertActionExists('create');
});

it('offers it to nobody without the pricing permission', function (): void {
    [, $stranger] = $this->createWorkspaceWithOwner();

    // ⚠️ الشقُّ الموجَبُ أوّلاً وبحسابٍ محدَّد: `canCreate()` تسألُ
    // `auth()->user()`، فسؤالُها بلا حسابٍ يُجيبُ `false` دائماً — وحالةٌ
    // مبنيّةٌ على ذلك تمرُّ خضراءَ فوقَ بناءٍ يُخفي الزرَّ عن الجميع.
    Livewire::actingAs($this->officer);
    expect(PlanResource::canCreate())->toBeTrue();

    Livewire::actingAs($stranger);
    expect(PlanResource::canCreate())->toBeFalse();
});
