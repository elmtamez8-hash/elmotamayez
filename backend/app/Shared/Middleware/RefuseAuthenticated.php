<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * الأبوابُ التي تُنشِئُ حساباً لا تُفتَحُ لمن يحملُ حساباً.
 *
 * ⛔ **أربعةُ مساراتٍ تسكُّ حسابات، ولا واحدَ منها كانَ يسألُ مَن الطارق.**
 * `/auth/register` و`/auth/register/student` و`/auth/register/parent`
 * و`/auth/register/teacher/step-1` تحملُ `throttle:registration` و`idempotent`
 * وحدَهما، وكلاهما عن المعدَّلِ والتكرارِ لا عن الهُويّة. فحاملُ رمزٍ صالحٍ
 * يستطيعُ أن يسكَّ حساباً ثانياً — وثالثاً — من جلستِه هو، بلا شيءٍ يمنعُه ولا
 * سطرٍ يُسجّلُ أنّه فعل.
 *
 * ⚠️ **والواجهةُ لم تكنْ حارساً، ولا يمكنُ أن تكون.** صفحاتُ التسجيلِ تعيدُ
 * التوجيهَ لمن هو داخلٌ بالفعل — وهذا صحيحٌ ومفيدٌ للقارئ — لكنّه في المتصفّحِ
 * وحدَه: `curl` واحدٌ يتجاوزُه. «إخفاءُ الزرِّ ليس حراسةً» قاعدةٌ يسجّلُها هذا
 * المستودعُ بنصِّها، وهذه هي البوّابةُ المقابلةُ للشاشةِ التي تُخفي النموذج.
 *
 * ⚠️ **و`auth('sanctum')->check()` لا `$request->user()`**: هذه المساراتُ لا
 * تحملُ `auth:sanctum`، فالحارسُ لم يُطلَبْ منه أن يحلَّ الرمزَ بعد و`user()`
 * تُجيبُ `null` ولو كانَ الرمزُ في الترويسةِ صالحاً — أي حارسٌ يمرُّ دائماً.
 * السؤالُ للحارسِ باسمِه هو ما يجعلُه يقرأُ `Authorization` فعلاً.
 *
 * ⚠️ **ولا `auth:sanctum` تُضافُ إليها**: تلك تردُّ ٤٠١ على الضيف — أي تُغلِقُ
 * التسجيلَ في وجهِ مَن وُضِعَ له.
 *
 * ٤٠٩ لا ٤٠٣: الطلبُ ليس ممنوعاً على صاحبِه، بل يتعارضُ مع حالةٍ قائمة. والرسالةُ
 * تقولُ المخرَج — تسجيلُ الخروج — لأنّ رفضاً بلا طريقٍ إلى الأمامِ يُقرَأُ عطلاً.
 */
class RefuseAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth('sanctum')->check()) {
            return new JsonResponse([
                'message' => 'أنت مسجَّل الدخول بالفعل. سجّل الخروج أوّلاً إن أردت حساباً آخر.',
                'code' => 'already_authenticated',
            ], 409);
        }

        return $next($request);
    }
}
