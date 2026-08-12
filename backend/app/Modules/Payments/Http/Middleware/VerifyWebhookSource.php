<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Reachable only from known sources" — the half of FR-011 a rate limiter does
 * not provide.
 *
 * A limit makes a route hard to flood; it does not make it unreachable. This
 * does, for a deployment that configures it.
 *
 * ⚠️ IT IS DEFENCE IN DEPTH BESIDE THE SIGNATURE, NEVER INSTEAD OF IT. An
 * address is spoofable and a gateway's egress ranges change without notice, so a
 * request from an allowed address with a bad signature is still refused.
 *
 * ⚠️ EMPTY MEANS "NOT CONFIGURED", AND IT ALLOWS THE REQUEST THROUGH — because
 * the signature is the guard, and a list nobody filled in must not take the
 * platform's payments offline on the day it is deployed. The list is opt-in
 * hardening; the refusal it produces when it IS set is absolute.
 *
 * ⚠️ AND IT IS WORTHLESS WITHOUT TRUSTED PROXIES. Behind a load balancer,
 * `$request->ip()` is the balancer for every request, so the list would allow
 * everyone or nobody. AppServiceProvider::trustConfiguredProxies() is shipped and
 * defaults to trusting nothing; the deployment names its own.
 *
 * It runs BEFORE the rate limiter (see the route definition): otherwise a
 * refused address's junk consumes the provider's bucket, and a real resend burst
 * arrives to a 429.
 */
class VerifyWebhookSource
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = config('payments.webhook_allowed_ips', []);

        if ($allowed === []) {
            return $next($request);
        }

        if (! in_array((string) $request->ip(), $allowed, true)) {
            // 404, not 403: the caller learns nothing about whether the route
            // exists, which is all an unauthenticated prober was asking.
            abort(404);
        }

        return $next($request);
    }
}
