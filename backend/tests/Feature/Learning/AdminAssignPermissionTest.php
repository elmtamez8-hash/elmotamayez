<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Learning\Filament\Pages\AssignStudentToCohort;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\RolePermissionMatrix;
use App\Modules\Tenancy\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| ٠٣٤ · FR-001 — **مَن لا يحملُ `cohorts.assign` لا يرى هذه الشاشةَ ولا يكتبُ
| منها.**
|
| ⛔ **حدّانِ يُقاسانِ لا واحد، واختبارُ السماحِ ليسَ أحدَهما.** `Gate::before`
| يُمرِّرُ مديرَ المنصّةِ فوقَ كلِّ سياسةٍ وكلِّ صلاحيّة — فحالةُ «المديرُ يستطيع»
| **خضراءُ والصلاحيّةُ محذوفةٌ من الشجرةِ كلِّها**، وهو `taxonomy.manage` من جهةِ
| السماح: صلاحيّةٌ مُعلَنةٌ ومبذورةٌ ومُصنَّفةٌ ولا يقرؤُها ملفٌّ واحد.
|
| فالحدّانِ هما:
|   ١) **أعلى دورِ مستأجرٍ** (`tenant-owner`) ⇒ لا. نصُّ الدستور: دورُ مساحةٍ
|      لا يحملُ صلاحيّةً منصّيّةً أبداً، و`Tenancy\Models\Role` يرمي على محاولةِ
|      منحِها.
|   ٢) **موظَّفُ منصّةٍ ليسَ مديرَها** ⇒ لا. قَبولُ لوحةِ `/admin` هو «مديرُ
|      منصّةٍ **أو أيُّ صفٍّ في `platform_staff`**» — ومسؤولُ الامتثالِ ومسؤولُ
|      المالِ منهم، ولا يحملُ أيٌّ منهما إسنادَ الطلاب.
|
| ⚠️ **والحدُّ الثالثُ ليسَ اختبارَ سلوكٍ بل قراءةَ التصنيف**: الاسمُ غائبٌ عن
| كلِّ مصفوفةِ دورٍ في `RolePermissionMatrix`، **وذلكَ الغيابُ هو التصنيف** —
| المجموعةُ المنصّيّةُ مشتقّةٌ بالطرح. فمتى وُضِعَ في دورٍ سقطَت هذه الحالةُ قبلَ
| أن يمنحَه أحدٌ لأحد.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
});

it('classifies the permission as the platform\'s by leaving it out of every role', function (): void {
    expect(RolePermissionMatrix::platformPermissions())->toContain(Permissions::COHORTS_ASSIGN)
        ->and(RolePermissionMatrix::tenantPermissions())->not->toContain(Permissions::COHORTS_ASSIGN);
});

it('refuses the highest tenant role there is', function (): void {
    /*
    | ⚠️ `tenant-owner` عمداً، لا `teacher` ولا `assistant-teacher`. صاحبُ
    | المساحةِ يحملُ ثمانيةً وستّينَ صلاحيّةً بينَها `roles.manage` — فهو أوسعُ
    | فاعلٍ في المستأجَر، ورفضُه رفضٌ لكلِّ ما تحتَه.
    */
    /*
    | ⚠️ **السياقُ يُضبَطُ أوّلاً، وإلّا كانَ الاختبارُ فارغاً.** spatie في وضعِ
    | الفِرَق، و`team_id` الفارغُ يعني **لا أدوارَ إطلاقاً** — فرفضٌ مقيسٌ بلا
    | سياقٍ يقيسُ شخصاً لا صلاحيّةَ له أصلاً، لا صاحبَ أوسعِ دورٍ في المستأجَر.
    */
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    expect($this->owner->hasRole(Roles::TENANT_OWNER))->toBeTrue();

    $this->actingAs($this->owner);

    expect(AssignStudentToCohort::canAccess())->toBeFalse();
});

it('refuses a platform officer who is not the platform administrator', function (): void {
    /*
    | ⚠️ **الحدُّ الذي يُثبِتُ أنّ `canAccess()` تسألُ شيئاً أصلاً.** مسؤولُ
    | الامتثالِ يفتحُ `/admin` بحكمِ صفِّه في `platform_staff` — فلو كانت الحراسةُ
    | «هل يصلُ اللوحةَ؟» لمرَّ من هنا إلى شاشةٍ تحملُ أسماءَ طلابٍ عبرَ كلِّ
    | المساحات، ولا يحملُ من إسنادِهم شيئاً.
    */
    $officer = makePlatformStaff(Roles::COMPLIANCE_OFFICER);

    expect($officer->mayAccessAdminPanel())->toBeTrue();

    $this->actingAs($officer);

    expect(AssignStudentToCohort::canAccess())->toBeFalse();
});

it('refuses a signed-out visitor without reaching for a user', function (): void {
    expect(AssignStudentToCohort::canAccess())->toBeFalse();
});

it('admits the platform administrator — and this case proves nothing on its own', function (): void {
    /*
    | ⚠️ **مكتوبةٌ ومحدودةٌ صراحةً.** `Gate::before` يردُّ `true` لمديرِ المنصّةِ
    | فوقَ كلِّ صلاحيّة، فهذه الحالةُ خضراءُ ولو حُذِفَ الثابتُ من الشجرةِ كلِّها.
    | قيمتُها الوحيدةُ أن تُمسِكَ `canAccess()` لو رُدَّت `false` ثابتةً — أي أن
    | تمنعَ شاشةً لا يفتحُها أحد. الحراسةُ الحقيقيّةُ في الحالتَينِ فوقَها.
    */
    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin);

    expect(AssignStudentToCohort::canAccess())->toBeTrue();
});
