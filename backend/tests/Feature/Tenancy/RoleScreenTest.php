<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Filament\Resources\RoleResource\Pages\EditRole;
use App\Modules\Tenancy\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Modules\Tenancy\Models\Role;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/*
| شاشةُ الأدوار، بعدَ أن حلَّتْ محلَّ شاشةِ الحزمة.
|
| ⚠️ لم تُبنَ لأنّ الإنجليزيّةَ أزعجَت. بُنيَتْ لأنّ القائمةَ كانت تعرضُ
| «Tenant Owner» ثلاثَ مرّاتٍ متطابقةً في الشكل — دورٌ لكلِّ مساحةِ عمل، و
| `TeamRoleScope` يُبطِلُ تصفيتَه عمداً حينَ لا مساحةَ في السياق — بلا عمودٍ يقولُ
| لمن كلُّ صفّ. فتعديلُ الصفِّ الخطأ يُعيدُ ترتيبَ سلطةِ مدرّسٍ آخَر.
|
| ولذلك **يحتاجُ هذا الملفُّ مساحتَي عمل**: القاعدةُ المكتوبةُ في `CLAUDE.md`
| لقراءاتِ المنصّة — فحصٌ على مساحةٍ واحدةٍ لا يُثبِتُ شيئاً عن شاشةٍ وجودُها كلُّه
| للتمييزِ بينَ مساحتَين.
*/

beforeEach(function (): void {
    [$this->first] = $this->createWorkspaceWithOwner();
    [$this->second] = $this->createWorkspaceWithOwner();

    $this->platform = User::factory()->create(['is_super_admin' => true]);
});

it('يُظهر مساحةَ العمل لكلّ دور، فلا يتشابه صفّان', function (): void {
    $this->actingAs($this->platform);

    // سياقُ مديرِ المنصّةِ فارغ، فيرى أدوارَ المساحتَين معاً — وهو الوضعُ الذي كان
    // يُنتِجُ صفوفاً لا يُفرَّقُ بينها.
    Livewire::test(ListRoles::class)
        ->assertSuccessful()
        ->assertSee($this->first->name)
        ->assertSee($this->second->name);
});

it('يعرض اسمَ الدور بالعربيّة ويُبقي المفتاحَ الخامَّ تحته', function (): void {
    $this->actingAs($this->platform);

    Livewire::test(ListRoles::class)
        ->assertSuccessful()
        ->assertSee('مالك مساحة العمل')
        ->assertSee(Roles::TENANT_OWNER);
});

/*
| ⚠️ الجدار، ونصفاه.
|
| **الأوّل**: قائمةُ الخياراتِ لا تحملُ صلاحيّةَ منصّةٍ أصلاً، فالنموذجُ يُسقِطُ أيَّ
| قيمةٍ خارجَها قبلَ أن تبلغَ الحفظ. **والثاني**: `Role::syncPermissions()` يرفضُها
| مهما جاءت — وهو الذي يُعوَّلُ عليه، لأنّ المرشِّحَ واجهةٌ تُشكِّلُ طلباً واحداً ولا
| تُشكِّلُ الثاني (وهذا نصُّ ما كُتِبَ على النموذجِ نفسِه).
|
| والخطرُ الذي يحرسُه هذان الفحصان: أن يُستبدَلَ الحقلُ يوماً بـ`->relationship()`.
| ساعتَها يكتبُ Filament على جدولِ الوصلِ بنفسِه فيتجاوزُ الجدارَ **ويتجاوزُ مسحَ
| ذاكرةِ spatie** معاً، ويصيرُ الرفضُ سطراً لا يمرُّ به أحد.
*/
it('يرفض النموذجُ صلاحيّةَ منصّةٍ لدورِ مساحةِ عمل، ولا يكتب شيئاً', function (): void {
    $role = Role::query()->create([
        'name' => 'course-reviewer',
        'guard_name' => 'web',
        'team_id' => $this->first->getKey(),
    ]);

    $platformPermission = RolePermissionMatrix::platformPermissions()[0];

    Permission::findOrCreate($platformPermission, 'web');
    Permission::findOrCreate(Permissions::COURSES_VIEW, 'web');

    // الطلبُ كلُّه يُرفَض، لا الجزءُ الممنوعُ منه وحدَه.
    expect(fn () => $role->syncPermissions([Permissions::COURSES_VIEW, $platformPermission]))
        ->toThrow(DomainException::class);

    expect($role->fresh()?->permissions)->toHaveCount(0);
});

it('يحفظ الصلاحيّاتِ بأسمائها من خلال البابِ المحروس', function (): void {
    $this->actingAs($this->platform);

    $role = Role::query()->create([
        'name' => 'course-reviewer',
        'guard_name' => 'web',
        'team_id' => $this->first->getKey(),
    ]);

    Permission::findOrCreate(Permissions::COURSES_VIEW, 'web');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permissions' => [Permissions::COURSES_VIEW]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()?->permissions->pluck('name')->all())
        ->toBe([Permissions::COURSES_VIEW]);

    /*
    | ⚠️ **حالةُ الحقلِ أسماءٌ لا مُعرِّفات، وهذا هو ما يكشفُ التبديل.** حقلٌ
    | مربوطٌ بـ`->relationship()` يُملأُ من العلاقةِ بمفاتيحَ رقميّة؛ حقلُنا يُملأُ
    | من `permissions->pluck('name')` في `mutateFormDataBeforeFill()`. فسطرٌ
    | واحدٌ يُبدِّلُ الطريقةَ يُسقِطُ هذا الفحصَ فوراً بدلَ أن يمرَّ صامتاً.
    */
    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertFormSet(['permissions' => [Permissions::COURSES_VIEW]]);
});

it('لا يُعيد تسميةَ دورٍ افتراضيّ، ولو جاء الاسمُ في الطلب', function (): void {
    $this->actingAs($this->platform);

    app(WorkspaceContext::class)->set($this->first);

    $owner = Role::query()->withoutTeamScope()
        ->where('team_id', $this->first->getKey())
        ->where('name', Roles::TENANT_OWNER)
        ->firstOrFail();

    /*
    | ⚠️ الحقلُ معطَّلٌ في النموذج، وذلك حاجزُ واجهةٍ يتجاوزُه طلبٌ مصنوعٌ باليد.
    | الرفضُ الحقيقيُّ في `handleRecordUpdate()`، وهو ما يقيسُه هذا السطر: إعادةُ
    | تسميةِ `tenant-owner` تكسرُ كلَّ `hasRole()` في الشجرة، و`SeedDefaultRoles`
    | لا يُعيدُه — وهو سببُ رفضِ حذفِه نفسِه.
    */
    Livewire::test(EditRole::class, ['record' => $owner->getKey()])
        ->fillForm(['name' => 'renamed-owner', 'permissions' => []])
        ->call('save');

    expect($owner->fresh()?->name)->toBe(Roles::TENANT_OWNER);
});
