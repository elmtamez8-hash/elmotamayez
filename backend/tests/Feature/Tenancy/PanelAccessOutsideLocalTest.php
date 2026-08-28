<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Support\Roles;
use Filament\Models\Contracts\FilamentUser;

/*
| بابُ `/admin` **خارجَ بيئةِ التطوير** — وهو الفحصُ الذي لم يكن موجوداً.
|
| ⚠️ كلُّ اختبارِ لوحةٍ في هذا المستودعِ يبدأُ بـ`config(['app.env' => 'local'])`،
| وتعليقُ `PanelFinancialWallTest` يقولُ السببَ بنصِّه: «في `testing` ترفضُ
| اللوحةُ الجميعَ — بمن فيهم المالكُ الذي يحتاجُ هذا الملفُّ أن يراه». فالحيلةُ
| التي جعلتِ الاختباراتِ خضراءَ هي نفسُها التي أخفتِ الأثرَ في الإنتاج: الشرطُ
| المرفوضُ كان «البيئةُ ليست local»، وهو صحيحٌ دائماً على الخادم.
|
| فبلا `FilamentUser` على النموذج، كلُّ تسجيلِ دخولٍ إلى `/admin` على
| `APP_ENV=production` يُرَدُّ ٤٠٣ — للمديرِ وللمدرّسِ وللمساعدِ معاً — والفحصُ
| الدخانيُّ أعمى عنه: `/admin` يرُدُّ ٣٠٢ إلى شاشةِ الدخولِ **قبلَ** المصادقة،
| فلا يظهرُ الرفضُ إلّا لمن يملكُ كلمةَ مرورٍ صحيحةً ويُطرَدُ بها.
|
| ولذلك تُترَكُ البيئةُ هنا على `testing` عمداً: أيُّ قيمةٍ غيرِ `local` تقيسُ
| الإنتاج، وتثبيتُها على `local` يُعيدُ الحيلةَ ويُفرِغُ الملفَّ من معناه.
*/

it('يقبلُ مديرَ المنصّةِ على اللوحةِ خارجَ بيئةِ التطوير', function (): void {
    expect(config('app.env'))->not->toBe('local');

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();

    $this->actingAs($admin)->get('/admin')->assertSuccessful();
});

it('يقبلُ مالكَ مساحةِ العملِ خارجَ بيئةِ التطوير', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $this->actingAs($owner)->get('/admin')->assertSuccessful();
});

/*
| والاتّجاهُ المقابل، لأنّ سماحاً يمرُّ على الجميعِ ليس حارساً. طالبٌ ليس عضواً
| في أيِّ مساحةِ عمل — وهو شكلُ كلِّ طالبٍ حقيقيٍّ في هذا المنتَج.
*/
it('يرفضُ من لا يحملُ دوراً على اللوحة', function (): void {
    $student = User::factory()->create();

    $this->actingAs($student)->get('/admin')->assertForbidden();
});

/*
| الحارسُ البنيويُّ إلى جانبِ السلوكيّ: الواجهةُ هي ما يجعلَ Filament يسألُ
| حكمَنا بدلاً من أن يقرأَ اسمَ البيئة، وإزالتُها تُعيدُ العطلَ كاملاً.
*/
it('النموذجُ يُنفِّذُ عقدَ FilamentUser', function (): void {
    expect(new User)->toBeInstanceOf(FilamentUser::class);

    // والحكمُ مكتوبٌ مرّةً واحدة: الوسيطُ يسألُ النموذجَ ولا يحملُ نسخةً ثانية.
    $middleware = file_get_contents(base_path('app/Shared/Middleware/EnsureFilamentAccess.php'));

    expect($middleware)->toContain('canAccessPanel')
        ->and($middleware)->not->toContain(Roles::TENANT_OWNER);
});
