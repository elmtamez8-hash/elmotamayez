<?php

declare(strict_types=1);

namespace App\Shared\Middleware;

use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request whose page believes it is in a different workspace than the
 * one the server resolves — before any binding, validation or controller runs.
 *
 * ⛔ THE CURRENT WORKSPACE IS PER ACCOUNT (or per browser session), NEVER PER TAB.
 * `WorkspaceContext` resolves the session key and falls back to
 * `users.last_workspace_id`, and `POST /workspaces/{uuid}/switch` rewrites both.
 * The tab that switched reloads; every OTHER tab and device keeps showing
 * workspace A while the server now answers — and WRITES — in workspace B: a
 * course created from A's screen lands in B, a list read under A's heading is
 * B's list. So the client names the workspace it is showing in `X-Workspace`
 * (the `current_workspace.uuid` it was given by `/auth/me`), and a mismatch is a
 * 409 with the stable code `workspace_changed`, on which `lib/api.ts` reloads.
 * Reads and writes alike: a read answered in B under A's chrome is the same lie.
 *
 * ⚠️ IN THE PRIORITY LIST BEFORE `SubstituteBindings` (bootstrap/app.php). Group
 * middleware that is not in the list runs AFTER implicit binding, and a scoped
 * `{course}` of workspace A resolved under context B is a 404 — the stale tab
 * would be told «not found» about a course on its own screen instead of being
 * reloaded. It also runs before `Idempotent`, so a 409 is never cached as a key's
 * answer, and before any FormRequest.
 *
 * NO HEADER, NO CHECK — deliberately:
 *  - guests (no user) and any client that does not send it (mobile, curl,
 *    monitoring): the header is the page's claim, and without one there is
 *    nothing to compare;
 *  - a student or guardian, a super admin operating globally: `/auth/me` gives
 *    them `current_workspace: null`, and the client sends nothing then;
 *  - broadcasting auth (`/api/broadcasting/auth`) is outside the `api` group and
 *    Echo builds its own headers, so it is exempt by construction.
 *
 * Exempted by route (`withoutMiddleware`), because the context is not what they
 * act on: `POST /workspaces/{uuid}/switch` names its target explicitly (a stale
 * tab pressing it is doing exactly the right thing) and `POST /auth/logout`
 * must always work from any tab. `/auth/me` stays guarded: its permissions and
 * workspace list describe the resolved workspace, so a mismatch there is real.
 *
 * A present header with a NULL resolved context (the account was removed from
 * the workspace the page shows) is a mismatch too: under a null context the
 * scope is inert, so letting it through is the wider failure.
 */
final class RefuseStaleWorkspace
{
    public const HEADER = 'X-Workspace';

    public const CODE = 'workspace_changed';

    public function __construct(
        private readonly WorkspaceContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $claimed = $request->headers->get(self::HEADER);

        if ($claimed === null || $claimed === '' || $request->user() === null) {
            return $next($request);
        }

        $resolvedId = $this->context->id();

        $resolvedUuid = $resolvedId === null
            ? null
            : Workspace::query()->whereKey($resolvedId)->value('uuid');

        if ($resolvedUuid !== null && hash_equals((string) $resolvedUuid, $claimed)) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'تغيّر مكان العمل الحالي من نافذة أخرى. أعد تحميل الصفحة.',
            'code' => self::CODE,
        ], 409);
    }
}
