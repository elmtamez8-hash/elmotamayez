<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| مَن يُعرَضُ له طريقٌ إلى لوحةِ المنصّة.
|
| ⛔ اللوحةُ مبنيّةٌ منذُ زمنٍ ولم يكنْ إليها رابطٌ واحدٌ من المنتَج — قِيسَ على
| الشجرةِ ٢٠٢٦-٠٩-١٦ أنّ الذِّكرَ الوحيدَ لـ`/admin` في `robots.ts`. فصاحبُها
| يصلُها بكتابةِ العنوان، وموظّفُ المنصّةِ — وهي شاشاتُه الوحيدة — بأن يُقالَ له.
|
| ⚠️ **والحقلُ يُجيبُه `mayAccessAdminPanel()` نفسُها التي يسألُها الباب**
| (`EnsureFilamentAccess`)، لا اشتقاقٌ من `is_super_admin`. والحالةُ الثانيةُ هنا
| هي التي تحرسُ ذلك: مدرّسٌ كاملٌ لا يدخُلُ اللوحةَ — أُخرِجَ منها عمداً بعدَ أن
| سلّمَ `OrderResource` مساعِداً بريدَ كلِّ طالبٍ والمبلغَ الذي دفعَه — فحقلٌ
| يقولُ «نعم» له وعدٌ كاذبٌ يوصِّلُ إلى ٤٠٣.
*/

it('tells a platform owner they may enter the panel', function (): void {
    $owner = User::factory()->create(['is_super_admin' => true]);

    Sanctum::actingAs($owner);

    expect($this->getJson('/api/v1/auth/me')->json('may_access_admin_panel'))->toBeTrue();
});

it('tells a teacher they may not — the panel is the platform, not their workspace', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $teacher);

    Sanctum::actingAs($teacher);

    expect($this->getJson('/api/v1/auth/me')->json('may_access_admin_panel'))->toBeFalse();
});

/*
| ⚠️ التوكيدُ على أنّ الحقلَ والبابَ يقولانِ الشيءَ نفسَه، لا على قيمةٍ مكتوبةٍ
| هنا بيدٍ ثانية. تهجئتانِ لسؤالٍ واحدٍ تفترقانِ عندَ أوّلِ تعديلٍ في أحدِهما،
| وحينَها يُعرَضُ لينكٌ يوصِّلُ إلى ٤٠٣ — أو يُخفى عمَّن اللوحةُ شاشاتُه الوحيدة.
*/
it('answers with the same predicate the door asks', function (): void {
    [$workspace, $teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $teacher);

    $owner = User::factory()->create(['is_super_admin' => true]);

    foreach ([$owner, $teacher] as $user) {
        Sanctum::actingAs($user);

        expect($this->getJson('/api/v1/auth/me')->json('may_access_admin_panel'))
            ->toBe($user->mayAccessAdminPanel());
    }
});
