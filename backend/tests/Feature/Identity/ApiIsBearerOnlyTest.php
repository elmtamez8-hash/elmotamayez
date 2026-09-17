<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/*
| ⛔ **الجلسةُ كانت تسبقُ الرمزَ في الإجابةِ عن «مَن أنت»، فسقطَ حدُّ الأجهزةِ
| بلا صوت.**
|
| `Guard::__invoke()` في مكتبةِ البائعِ (`laravel/sanctum/src/Guard.php:31`) يدورُ
| على `config('sanctum.guard')` **أوّلاً**، ولا يبلغُ الرمزَ إلّا إذا لم يُجِبْ أحدٌ
| منها. وحينَ تُجيبُ الجلسةُ يردُّ `withAccessToken(new TransientToken)`: هُويّةٌ
| **بلا صفٍّ في `personal_access_tokens` أصلاً**.
|
| وحذفُ ذلك الصفِّ هو الإنفاذ. `TerminateAuthSession:29` هو الأداةُ الوحيدةُ في
| المنتَجِ لطردِ جهاز، فلمن يحملُ جلسةً مصادَقاً عليها لا يحذفُ شيئاً:
| `auth_sessions.status` يُكتَبُ `ended`، ويُقالُ لصاحبةِ الحسابِ إنّ الجهازَ خرج،
| **والجهازُ يُكمِل**. ولا خطأَ في أيِّ سجلّ، لأنّ شيئاً لم يفشل.
|
| ⚠️ **وقِيسَ على الإنتاجِ في ٢٠٢٦-٠٩-١٧ لا استُنتِج**: `POST /api/v1/auth/logout`
| **بلا ترويسةِ `Authorization` بتاتاً** أجابَ `204`. الكوكيُّ وحدَه صادَق.
|
| ⚠️ **ولا يُغلَقُ البابُ من `.env`**: قائمةُ النطاقاتِ ذاتِ الحالةِ مشتقّةٌ من
| `APP_URL`، و`docker/.env` على الخادمِ لا يحملُ `SANCTUM_STATEFUL_DOMAINS`
| إطلاقاً — فُحِص. المسارُ ذو الحالةِ يعملُ منذُ أوّلِ إقلاعٍ ويبقى؛ الذي تغيّرَ هو
| أنّه لم يعدْ **جواباً** عن الهُويّةِ على الـAPI.
|
| ⚠️ **وحالةُ الطردِ هي الشقُّ الثالثُ ولا يُستغنى عنه**: الأوّلُ يقولُ إنّ الجلسةَ
| لا تُصادِقُ ابتداءً، والثالثُ يقولُ إنّها لا **تُنقِذُ** رمزاً أُبطِل — وهو شكلُ
| العطلِ الذي يحدثُ فعلاً في الإنتاج، حيثُ يحملُ الجهازُ الاثنَينِ معاً.
|
| **كيفَ يُمسَك**: أعِدْ `'guard' => ['web']` في `config/sanctum.php` ⇒ يسقطُ
| الشقّانِ الأوّلُ والثالث. والثاني هو الضابطُ على الاتّجاهِ الآخَر: بلا رمزٍ يعملُ،
| يكونُ «لا أحدَ يدخل» تفسيراً كافياً لخضرةِ الشقَّينِ الآخرَين.
|
| ولوحةُ `/admin` خارجَ هذا كلِّه عمداً: Filament يُصادِقُ على حارسِ `web` مباشرةً
| ولا يمرُّ بـ`auth:sanctum` أبداً، وكذلك جسرُ تذكرةِ اللوحة.
*/

it('refuses a session-only identity on the API', function (): void {
    $user = User::factory()->create();

    // ما يفعلُه بالضبطِ دخولُ `/admin` — بفورمِ Filament أو بجسرِ التذكرة:
    // مستخدِمٌ حيٌّ على حارسِ `web`، بلا أيِّ رمزٍ في أيِّ مكان.
    Auth::guard('web')->setUser($user);

    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('still authenticates a real bearer token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('walk')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

it('does not let a session rescue a revoked token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('walk')->plainTextToken;

    // الجهازُ نفسُه يحملُ الاثنَينِ معاً، وهي الحالةُ القائمةُ على الإنتاجِ لكلِّ
    // مَن فتحَ اللوحةَ ثمّ تصفّحَ الواجهة.
    Auth::guard('web')->setUser($user);

    // الطردُ كما ينفّذُه `TerminateAuthSession`: حذفُ صفِّ الرمزِ ولا شيءَ غيرَه.
    $user->tokens()->delete();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});
