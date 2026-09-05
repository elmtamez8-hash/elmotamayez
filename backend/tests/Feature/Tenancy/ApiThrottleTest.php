<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Invitation;
use Illuminate\Support\Facades\Route;

/*
| ⛔ THE `api` GROUP HAD NO DEFAULT LIMIT, so a route that named none carried NONE.
|
| `bootstrap/app.php` called `statefulApi()` and `appendToGroup()` and never
| `throttleApi()`. That left every write in Tenancy (create a workspace, invite,
| accept, remove a member) and the whole of Analytics unlimited — and, sharpest,
| `GET /workspaces/invitations/{token}`: unauthenticated, hits the database, and
| answers with the INVITEE'S EMAIL, one line under a route that does carry
| `throttle:public`.
|
| ⚠️ THE GROUP DEFAULT IS A FLOOR, NOT THE GUARD. Anything worth a real ceiling
| still names its own limiter — an inline `throttle:n,m` shares one counter with
| every other inline limit in the app, and browsing the marketplace used to lock a
| visitor out of logging in. This file asserts the floor EXISTS; the named limiters
| have their own tests.
*/

it('applies a default limiter to every route in the api group', function (): void {
    /*
    | Read from the GROUP, not from a route: `gatherMiddleware()` returns the
    | unexpanded group name `api`, so asserting on a route's own list would pass
    | against a group with nothing in it.
    */
    // Removing `throttleApi()` from bootstrap/app.php fails here, and it is the
    // only thing in the tree that would notice.
    expect(Route::getMiddlewareGroups()['api'])->toContain('throttle:api');
});

it('keeps the named public limiter on the unauthenticated invitation lookup', function (): void {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->uri() === 'api/v1/workspaces/invitations/{token}'
            && in_array('GET', $r->methods(), true));

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:public');
});

it('refuses the sixty-first invitation lookup from one address', function (): void {
    /*
    | The behavioural half. `throttle:public` is 60 a minute by ip — the right
    | key here, because there is no actor to key on: the caller has no account
    | yet, which is the whole point of the route.
    |
    | The token is deliberately one that does not exist: a miss and a hit cost the
    | same query, and enumeration is what the limit is for.
    */
    expect(Invitation::query()->count())->toBe(0);

    foreach (range(1, 60) as $ignored) {
        $this->getJson('/api/v1/workspaces/invitations/nonexistent-token')
            ->assertNotFound();
    }

    $this->getJson('/api/v1/workspaces/invitations/nonexistent-token')
        ->assertStatus(429);
});
