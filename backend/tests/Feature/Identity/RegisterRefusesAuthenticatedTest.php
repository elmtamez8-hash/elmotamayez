<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ **أربعةُ أبوابٍ تسكُّ حسابات، ولا واحدَ منها كانَ يسألُ مَن الطارق.**
|
| صفحاتُ التسجيلِ في الواجهةِ تُعيدُ توجيهَ مَن هو داخلٌ بالفعل — وذلك صحيحٌ
| ومفيدٌ للقارئ، لكنّه في المتصفّحِ وحدَه. «إخفاءُ الزرِّ ليس حراسةً» قاعدةٌ
| يسجّلُها هذا المستودعُ بنصِّها، وهذا الملفُّ هو البوّابةُ المقابلةُ لتلك الشاشة:
| حاملُ رمزٍ صالحٍ يستطيعُ أن يسكَّ حساباً ثانياً بـ`curl` واحدٍ مهما فعلَتِ
| الواجهة.
*/
it('refuses to mint a second account for a caller who already has one', function (string $route): void {
    $before = User::query()->count();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson($route, [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_authenticated');

    // ⚠️ التوكيدُ على الجدولِ لا على الحالةِ وحدَها: ٤٠٩ فوقَ صفٍّ كُتِبَ رفضٌ
    // في الورقِ لا في قاعدةِ البيانات.
    expect(User::query()->count())->toBe($before + 1);
})->with([
    '/api/v1/auth/register',
    '/api/v1/auth/register/student',
    '/api/v1/auth/register/parent',
    '/api/v1/auth/register/teacher/step-1',
]);

/*
| ⚠️ **والاتّجاهُ المعاكسُ يحرسُ الحارس.** وسيطٌ يردُّ ٤٠٩ للجميعِ يُغلِقُ التسجيلَ
| على المنصّةِ كلِّها، وحالةُ الرفضِ أعلاه تمرُّ فوقَه سعيدة. والضيفُ هنا يصلُ
| إلى التحقّقِ من الحقول — ٤٢٢ لا ٤٠٩ — وهو الدليلُ على أنّ البابَ فُتِحَ له.
*/
it('lets a guest through to the form itself', function (string $route): void {
    $this->postJson($route, [])->assertStatus(422);
})->with([
    '/api/v1/auth/register',
    '/api/v1/auth/register/student',
    '/api/v1/auth/register/parent',
    '/api/v1/auth/register/teacher/step-1',
]);

/*
| ⛔ **وهذه الحالةُ هي التي تُثبِتُ التهجئة، والأولى لا تستطيع.**
|
| `Sanctum::actingAs()` يضعُ المستخدِمَ على الطلبِ مباشرةً، فـ`$request->user()`
| و`auth('sanctum')->check()` يتّفقانِ تحتَه دائماً — استبدلتُ إحداهما بالأخرى
| وبقيَت الحالاتُ الثمانُ خضراء. والفرقُ يظهرُ مع رمزٍ حقيقيٍّ في الترويسةِ وحدَه:
| الحارسُ الافتراضيُّ في هذا التطبيقِ `web` (جلسة)، و`$request->user()` تسألُه هو،
| فتُجيبُ `null` ولو كانَ الرمزُ صالحاً — أي حارسٌ يمرُّ دائماً على هذه المسارات،
| إذ لا تحملُ `auth:sanctum` أصلاً. السؤالُ للحارسِ **باسمِه** هو ما يقرأُ
| `Authorization`.
*/
it('reads the bearer token itself, not the default guard', function (): void {
    $token = User::factory()->create()->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/register/student', [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_authenticated');
});
