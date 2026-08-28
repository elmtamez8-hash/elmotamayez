<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
| بابُ لوحةِ `/admin`.
|
| ⚠️ الحكمُ نفسُه لا يُكتَبُ هنا: هذا الملفُّ كان يحملُ نسخةً ثانيةً من شرطِ
| `User::canAccessPanel()` — «مديرُ منصّةٍ أو موظّفُ مساحةِ عمل» — وFilament يسألُ
| النسخةَ التي على النموذجِ في `Filament\Http\Middleware\Authenticate`. هجاءان
| لسؤالٍ واحدٍ يتباعدان عندَ أوّلِ دورٍ يُضاف، فيُسمَحُ عندَ بابٍ ويُمنَعُ عندَ الآخر.
| يبقى هذا الوسيطُ لأنّه في `authMiddleware` منذُ ٠٠١ وحذفُه تغييرٌ لا يطلبُه أحد —
| لكنّه يسألُ ولا يُقرِّر.
*/
class EnsureFilamentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user === null) {
            return $next($request);
        }

        // ⚠️ `mayAccessAdminPanel()` لا `canAccessPanel()`: الثانيةُ تطلبُ
        // `Panel` و`Filament::getCurrentOrDefaultPanel()` يرُدُّ `null` محتمَلاً.
        // والحكمُ واحدٌ — الأولى تستدعي الثانيةَ — فلا هجاءَ ثانياً هنا.
        if (! $user->mayAccessAdminPanel()) {
            abort(403, 'You are not authorized to access the admin panel.');
        }

        return $next($request);
    }
}
