<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;

/*
| ⛔ EVERY SERVER-RENDERED PUBLIC PAGE SHARED ONE `throttle:public` BUCKET.
|
| The Next server fetches the API at `http://nginx:8081/api/v1` and forwards no
| visitor address (doing so needs `headers()`, which makes every ISR page
| dynamic). So Laravel saw one caller — the frontend container — for every
| visitor, and one person opening sixty distinct public URLs in a minute made
| every other visitor's server-rendered page fail.
|
| The fix gives a request whose RESOLVED address is inside `TRUSTED_PROXIES`
| (only our own SSR can produce one) its own, larger budget. These cases pin
| both directions: our render is not throttled at 60, and a visitor — direct,
| forwarded by a trusted proxy, or forging a header — still is.
|
| The docker bridge range and addresses below mirror production: nginx reaches
| PHP by fastcgi with `REMOTE_ADDR = $remote_addr`, so a browser arrives with its
| own public address and our render arrives with the frontend container's.
*/

const SSR_TRUSTED = '172.16.0.0/12';
const SSR_CONTAINER = '172.18.0.7';
const SSR_VISITOR = '41.200.1.7';
const SSR_LOOKUP = '/api/v1/workspaces/invitations/nonexistent-token';

beforeEach(function (): void {
    config(['app.trusted_proxies' => SSR_TRUSTED]);
    TrustProxies::at([SSR_TRUSTED]);
});

afterEach(function (): void {
    TrustProxies::flushState();
});

it('does not throttle our own server render at a visitor\'s sixty — nor at the api floor\'s 120', function (): void {
    // 130 crosses both ceilings a guest would hit: `public` (60) and the `api`
    // group floor (120). Reverting either limiter's SSR branch fails here.
    foreach (range(1, 130) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => SSR_CONTAINER])
            ->getJson(SSR_LOOKUP)
            ->assertNotFound();
    }
});

it('still refuses the sixty-first request from a visitor reaching the API directly', function (): void {
    foreach (range(1, 60) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => SSR_VISITOR])->getJson(SSR_LOOKUP)->assertNotFound();
    }

    $this->withServerVariables(['REMOTE_ADDR' => SSR_VISITOR])->getJson(SSR_LOOKUP)->assertStatus(429);
});

it('keys a visitor forwarded by a trusted proxy on the visitor, not on the proxy', function (): void {
    // Case 3 of `isOwnServerRender()`: a trusted peer that DOES name a client.
    // The resolved address is the visitor's, so the budget is the visitor's.
    $forwarded = fn () => $this->withServerVariables([
        'REMOTE_ADDR' => SSR_CONTAINER,
        'HTTP_X_FORWARDED_FOR' => SSR_VISITOR,
    ])->getJson(SSR_LOOKUP);

    foreach (range(1, 60) as $i) {
        $forwarded()->assertNotFound();
    }

    $forwarded()->assertStatus(429);
});

it('ignores a visitor who writes the container address into X-Forwarded-For', function (): void {
    // The visitor's own address is not a trusted proxy, so the header is never
    // read and they cannot claim the SSR budget by typing it.
    $forged = fn () => $this->withServerVariables([
        'REMOTE_ADDR' => SSR_VISITOR,
        'HTTP_X_FORWARDED_FOR' => SSR_CONTAINER,
    ])->getJson(SSR_LOOKUP);

    foreach (range(1, 60) as $i) {
        $forged()->assertNotFound();
    }

    $forged()->assertStatus(429);
});

it('grants nothing extra under the wildcard, where every caller would look trusted', function (): void {
    config(['app.trusted_proxies' => '*']);
    TrustProxies::at('*');

    foreach (range(1, 60) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => SSR_CONTAINER])->getJson(SSR_LOOKUP)->assertNotFound();
    }

    $this->withServerVariables(['REMOTE_ADDR' => SSR_CONTAINER])->getJson(SSR_LOOKUP)->assertStatus(429);
});

it('refuses TRUSTED_PROXIES=* at boot in production', function (): void {
    config(['app.trusted_proxies' => '*']);
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new AppServiceProvider($this->app))->boot())
        ->toThrow(RuntimeException::class, 'TRUSTED_PROXIES=* is refused in production');
});

it('accepts a named proxy network in production, and the wildcard outside it', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['app.trusted_proxies' => SSR_TRUSTED]);
    (new AppServiceProvider($this->app))->boot();

    $this->app->detectEnvironment(fn (): string => 'testing');
    config(['app.trusted_proxies' => '*']);
    (new AppServiceProvider($this->app))->boot();

    expect(true)->toBeTrue();
});
