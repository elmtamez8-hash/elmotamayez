<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Models\User;
use App\Modules\Tenancy\Support\Roles;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFilamentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user === null) {
            return $next($request);
        }

        // Super Admins always have access.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Tenant staff (owner, teacher, assistant-teacher) have access.
        $staffRoles = [Roles::TENANT_OWNER, Roles::TEACHER, Roles::ASSISTANT_TEACHER];

        if ($user->roles()->whereIn('name', $staffRoles)->exists()) {
            return $next($request);
        }

        abort(403, 'You are not authorized to access the admin panel.');
    }
}
