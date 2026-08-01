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

        $cacheKey = 'idempotency:'.sha1($request->method().'|'.$request->path().'|'.$key);
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
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => json_decode((string) $response->getContent(), true),
            ], self::TTL_SECONDS);
        }

        return $response;
    }
}
