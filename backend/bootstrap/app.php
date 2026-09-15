<?php

use App\Modules\Courses\Exceptions\ContentLockedException;
use App\Shared\Middleware\EnsureCurrentWorkspace;
use App\Shared\Middleware\Idempotent;
use App\Shared\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    /*
    | Spec 010 — the broadcast authorisation endpoint.
    |
    | ⚠️ REGISTERED HERE RATHER THAN THROUGH `withRouting(channels:)`, WHICH TAKES
    | NO ATTRIBUTES. That form calls `Broadcast::routes(null)`, which defaults to
    | `['middleware' => ['web']]` — session auth, no rate limit, and no `/api`
    | prefix. Three consequences, each of which shows up as a silent 403 in a
    | browser and nowhere in a log:
    |
    |  - the frontend holds a Sanctum BEARER token in `localStorage`, so `web`
    |    alone identifies nobody and every private subscription is refused;
    |  - `NFR-014` wants a named limiter on this route, and it is authenticated
    |    and exposed — an inline `throttle:N,M` would share one bucket with every
    |    other inline limit on the platform;
    |  - the Next dev server rewrites `/api/*` to the API and nothing else, so
    |    without the prefix Echo's auth request leaves the origin and is a CORS
    |    failure rather than an authorisation one.
    |
    | ⚠️ AND `EnsureCurrentWorkspace` IS THE FOURTH, FOUND ONLY BY A LIVE SOCKET.
    | This list is NOT the `api` group — naming `auth:sanctum` here replaces the
    | group, it does not extend it — so the middleware that pushes the workspace
    | into spatie's `PermissionRegistrar` team id never ran. spatie is in team
    | mode, so with no team id a teacher holds NO ROLES: `hasPermissionTo(
    | 'chat.reply')` is false, `ConversationPolicy::view()` denies, and every
    | private subscription is answered `403` — while the same teacher reads the
    | very same thread over HTTP without trouble, because THAT route is in the
    | group. Measured against a running Reverb on 2026-08-23; no test could see
    | it, since the suite's `subscribeToChannel()` helper posts to this route with
    | the team id already set by `Sanctum::actingAs()` in the test's own process.
    */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => ['auth:sanctum', EnsureCurrentWorkspace::class, 'throttle:broadcast-auth'],
        ],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies are configured in AppServiceProvider::boot(), not here.
        // This closure runs while the application is still being built, before
        // the config repository is bound — and env() is not an alternative,
        // because a cached config means .env is never loaded at all.
        $middleware->statefulApi();

        /*
        | ⛔ THE `api` GROUP HAD NO DEFAULT LIMIT AT ALL, so a route that did not
        | name one carried NONE — and «did not name one» covered every write in
        | Tenancy (create a workspace, invite, accept, remove a member), all of
        | Analytics, and the unauthenticated invitation lookup. Named limiters are
        | still the guard on anything worth its own ceiling; this is the floor
        | under the routes nobody remembered, so the next one added starts guarded
        | instead of open.
        |
        | Deliberately loose: it must never be the limit that bites a legitimate
        | flow, or the named ones stop being where the thinking happens. See
        | `AppServiceProvider::registerRateLimiters()` for the numbers and why.
        */
        $middleware->throttleApi();

        /*
        | ⛔ **بلا هذا السطرِ يردُّ كلُّ طريقٍ محميٍّ على المنصّةِ ٥٠٠ بدلَ ٤٠١
        | لأيِّ طالبٍ لا يُرسِلُ `Accept: application/json`.**
        |
        | `ApplicationBuilder::withMiddleware()` **يضعُ افتراضاً قبلَ أن يعملَ
        | هذا الإغلاق**: `redirectGuestsTo(fn () => route('login'))`. ولا وجودَ
        | لطريقٍ اسمُه `login` في هذا المشروعِ — هذه واجهةُ برمجةٍ ولوحةُ
        | Filament، ولوحةُ Filament تُعيدُ تعريفَ `redirectTo()` في صنفِها
        | فتذهبُ إلى `filament.admin.auth.login` بنفسِها. فيُرمى
        | `RouteNotFoundException` **داخلَ الوسيطِ نفسِه**، قبلَ أن يُبنى
        | `AuthenticationException` أصلاً، ولا يبلغُ المعالِجُ سطرَ الـ٤٠١ الذي
        | كانَ سيردُّه.
        |
        | ⚠️ **و`shouldRenderJsonWhen` أعلاه يُخفي العطلَ بدلَ أن يكشفَه**: هو
        | يجعلُ جوابَ الـ٥٠٠ **JSON**، فيقرؤُه العابرُ «خطأُ خادمٍ حقيقيّ» لا
        | «إعدادُ إعادةِ توجيهٍ ناقص».
        |
        | ⚠️ **ولا يراه المتصفّحُ ولا الواجهة**: `lib/api.ts` يُرسِلُ الهيدر في
        | كلِّ طلب، فمَن يقعُ في العطلِ هو ما يأتي من خارجِه — فاحصُ مراقبةٍ،
        | أو `curl` في تشخيصِ عُطلٍ آخر، فيُقرَأُ الموقعُ «واقعاً» وهو يعمل.
        |
        | و`null` تعني «لا إعادةَ توجيهٍ لضيف»: المعالِجُ يردُّ ٤٠١ JSON لكلِّ
        | ما تحتَ `api/*` و٤٠١ بلا متنٍ لما عداه.
        */
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias([
            'workspace' => EnsureCurrentWorkspace::class,
            'idempotent' => Idempotent::class,
            'stateful' => EnsureFrontendRequestsAreStateful::class,
            // Named, and applied route by route — never to a group. See the
            // middleware's own docblock for why.
            '2fa.required' => RequireTwoFactor::class,
        ]);
        $middleware->appendToGroup('api', [
            EnsureCurrentWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Registered BEFORE the DomainException handler it extends: Laravel walks
        // these newest-first, and the general rule would otherwise answer 422 for
        // a case that has its own status and its own alternative.
        $exceptions->render(function (ContentLockedException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json([
                    'message' => $e->getMessage(),
                    'alternative' => $e->alternative,
                ], 423)
                : null;
        });

        // Actions signal a broken business rule with DomainException (attempt limit
        // reached, invitation expired, ...) — that's a 422, not a server error.
        $exceptions->render(function (DomainException $e, Request $request) {
            return $request->is('api/*')
                ? response()->json(['message' => $e->getMessage()], 422)
                : null;
        });
    })->create();
