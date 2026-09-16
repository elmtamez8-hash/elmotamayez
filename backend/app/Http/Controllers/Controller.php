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
     * ⛔ **THIS DOCBLOCK USED TO SAY `TransientToken::getKey()` «answers `false`
     * rather than null». IT DOES NOT EXIST.** That class has exactly two
     * methods, `can()` and `cant()`, so the old body was a fatal
     * `Call to undefined method` for every session-authenticated request — the
     * Filament panel, and any SPA request made while a panel cookie is present.
     * A comment describing a crash as a value is worse than no comment: it
     * tells the next reader the case is handled.
     *
     * One spelling now, on the model: {@see User::currentTokenId()}.
     */
    protected function currentTokenId(Request $request): ?int
    {
        return $request->user()?->currentTokenId();
    }
}
