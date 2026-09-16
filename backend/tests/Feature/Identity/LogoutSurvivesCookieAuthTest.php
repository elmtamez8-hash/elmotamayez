<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;

/*
| ⛔ **`currentAccessToken()` ليس توكناً دائماً، وافتراضُ ذلك أسقطَ الطلبَ الذي
| لا يجوزُ أن يسقط.**
|
| قِيسَ على الإنتاج ٢٠٢٦-٠٩-١٦: `POST /auth/logout` ⇐ **٥٠٠**،
| `Call to undefined method Laravel\Sanctum\TransientToken::getKey()`,
| `userId: 1`. سببُه أنَّ `statefulApi()` يُوثِّقُ الطلبَ من النطاقِ نفسِه
| **بالكوكي** متى وُجِدَت — وكلُّ مدرّسٍ هنا عندَه واحدةٌ لأنَّ `/admin` بالجلسة —
| فيُسلِّمُ `TransientToken`: علامةٌ فيها دالّتانِ فقط، `can()` و`cant()`.
|
| ⚠️ **والـ٥٠٠ هو النصفُ الصغير.** السطرُ رمى **قبلَ** أن يُبطِلَ شيئاً، فـ«تسجيل
| الخروج» تركَ الجلسةَ حيّةً بينما مسحَ المتصفّحُ تخزينَه وانتقلَ إلى `/login`:
| الحسابُ باقٍ داخلاً على جهازٍ طلبَ صاحبُه الخروجَ منه.
|
| ⚠️ **و`?->` لا يحرسُ من هذا**: المُعامِلُ يحرسُ من `null` لا من صنفٍ آخَر.
| وكانت خمسةُ مواضعَ تحملُ الافتراضَ نفسَه، وتعليقُ `Controller::currentTokenId()`
| يقولُ إنَّ `getKey()` «يردُّ `false`» — وهو لا وجودَ له أصلاً. تعليقٌ يصفُ
| انهياراً على أنّه قيمةٌ أسوأُ من لا تعليق.
|
| **كيفَ يُمسَك**: أعِدْ `$token->getKey()` بلا الفرعِ ⇒ يسقطُ الشقُّ الأوّل.
*/

it('logs out a cookie-authenticated request instead of answering 500', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);
    // ما يفعلُه `statefulApi()` بالضبطِ حينَ تُوجَدُ كوكي الجلسة. و`Sanctum::actingAs`
    // وحدَه يضعُ نسخةً مُقلَّدةً من `PersonalAccessToken`، فلا يبلغُ هذا الفرعَ أبداً.
    $user->withAccessToken(new TransientToken);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('answers null for the token id of a cookie-authenticated request', function (): void {
    $user = User::factory()->create();
    $user->withAccessToken(new TransientToken);

    expect($user->currentTokenId())->toBeNull();
});

/*
| ⚠️ **والضابطُ على الاتّجاهِ الآخَر**: توكنٌ حقيقيٌّ ما زالَ يُعرَفُ ويُلغى.
| بلا هذا الشقِّ يمرُّ بناءٌ يردُّ `null` دائماً — أي تسجيلُ خروجٍ لا يُبطِلُ شيئاً.
*/
it('still revokes a real bearer token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('walk');

    expect($user->tokens()->count())->toBe(1);

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});
