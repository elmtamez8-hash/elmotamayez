<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The authenticated user of a route behind `auth:sanctum`.
     *
     * `$request->user()` is nullable for the type checker even where the route
     * guarantees a user; this narrows it once instead of at every call site.
     *
     * @throws HttpException when called from a route without authentication
     */
    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
