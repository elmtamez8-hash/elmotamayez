<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replays the first successful response for a repeated `Idempotency-Key`.
 *
 * Signup forms get double-submitted — impatient taps, flaky connections, a
 * retrying service worker. The header is optional: without it the request is
 * handled normally.
 *
 * ⛔ TWO RULES MAKE THE REPLAY SAFE, AND EACH CLOSES A DIFFERENT HOLE.
 *
 * 1. The cache key carries WHO is asking — the account when there is one, the
 *    address otherwise. The key used to be method + path + the client's header
 *    alone, so anybody who sent the same header value to the same path got the
 *    first requester's body back: a key is a value the CLIENT picks, and a
 *    predictable one (a counter, a timestamp, a hash of the form) is shared by
 *    construction. The store routes answer a purchase; the signup routes answer
 *    an account.
 *
 * 2. A `token` is never stored and therefore never replayed. The signup routes
 *    answer with a freshly minted Sanctum token, and a cached copy of it is a
 *    bearer credential sitting in the cache for ten minutes, handed to whoever
 *    matches the key. The rest of the body is still replayed — the account was
 *    created once, the double-submit still lands on it, and the client that lost
 *    the first response signs in instead of receiving somebody's token.
 */
class Idempotent
{
    private const TTL_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        $cacheKey = 'idempotency:'.sha1(implode('|', [
            $request->method(),
            $request->path(),
            $this->requester($request),
            $key,
        ]));
        $replay = Cache::get($cacheKey);

        if (is_array($replay)) {
            return response()->json($replay['body'], $replay['status'])
                ->header('Idempotent-Replay', 'true');
        }

        $response = $next($request);

        // ponytail: two truly simultaneous requests both miss the cache. For
        // signup the unique email index catches the loser with a 422, which is a
        // correct answer; add a lock here if an endpoint ever lacks that backstop.
        if ($response->getStatusCode() < 400) {
            $body = json_decode((string) $response->getContent(), true);

            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => is_array($body) ? self::withoutTokens($body) : $body,
            ], self::TTL_SECONDS);
        }

        return $response;
    }

    /**
     * The account when the route is authenticated, the address when it is not.
     *
     * `$request->ip()` is the visitor's own address because `TrustProxies` is
     * configured for the docker network alone (see `AppServiceProvider`).
     */
    private function requester(Request $request): string
    {
        $user = $request->user();

        return $user !== null
            ? 'user:'.(string) $user->getAuthIdentifier()
            : 'ip:'.(string) $request->ip();
    }

    /**
     * The body with every `token` key removed, at any depth.
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    private static function withoutTokens(array $body): array
    {
        $clean = [];

        foreach ($body as $name => $value) {
            if ($name === 'token') {
                continue;
            }

            $clean[$name] = is_array($value) ? self::withoutTokens($value) : $value;
        }

        return $clean;
    }
}
