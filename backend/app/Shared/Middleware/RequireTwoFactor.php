<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a sensitive operation once the grace period for enrolling has run out.
 *
 * Applied to named routes one at a time, never to a whole group: a blanket rule
 * is either too wide — locking a teacher out of work that was never sensitive —
 * or grows a list of exceptions that nobody prunes. The five routes it guards
 * are listed in contracts/api.md and each carries the middleware in its own
 * definition, where it is visible when the route is read.
 *
 * Before the deadline this passes. Nagging is the frontend's job; refusing work
 * from someone who still has time is not.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        $deadline = $user->securitySettings?->two_factor_required_at;

        if ($deadline === null || $deadline->isFuture()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'انتهت مهلة تفعيل التحقق بخطوتين. فعّله من إعدادات الأمان لمتابعة هذه العملية.',
            'code' => 'two_factor_required',
        ], 403);
    }
}
