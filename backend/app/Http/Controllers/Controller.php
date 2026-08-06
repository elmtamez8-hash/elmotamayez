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

    /**
     * The row id of the token making this request, if there is one.
     *
     * A session-authenticated request (the Filament panel, or a test using
     * `Sanctum::actingAs`) carries a TransientToken, whose `getKey()` answers
     * `false` rather than null — so anything that hands this to a typed
     * parameter has to normalise it here rather than trust the call.
     */
    protected function currentTokenId(Request $request): ?int
    {
        $key = $request->user()?->currentAccessToken()?->getKey();

        return is_numeric($key) ? (int) $key : null;
    }
}
