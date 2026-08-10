<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Shared\Scopes\WorkspaceScope;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkspaceContext::class);
        $this->app->singleton(WorkspaceScope::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Super Admin is a platform-level flag, not a tenant role, so it holds no
        // spatie permissions. BasePolicy::before() already lets it through policy
        // checks; this covers the bare `can('some.permission')` calls that Form
        // Requests use for authorization.
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);

        // Module models live in App\Modules\{Module}\Models and their factories in
        // Database\Factories\Modules\{Module} — resolve that here instead of having
        // every model override newFactory().
        Factory::guessFactoryNamesUsing($this->guessFactoryName(...));

        $this->registerRateLimiters();
        $this->trustConfiguredProxies();
    }

    /**
     * Who is allowed to tell us where a request came from.
     *
     * ⚠️ AN UNCONFIGURED APPLICATION BEHIND A LOAD BALANCER RECORDS THE BALANCER
     * (FR-049). `$request->ip()` then answers the same address for every person
     * on the platform — including in `terms_consents.ip_address`, the column that
     * exists to be relied on in a dispute.
     *
     * ⚠️ AND THE DEFAULT IS TO TRUST NOTHING, never `'*'`. A wildcard makes
     * `X-Forwarded-For` client-supplied, so anyone can put any address in that
     * record; a forgeable address is worse than an honest wrong one, because it
     * looks right. The deployment names its own proxies in `TRUSTED_PROXIES`.
     *
     * Here rather than in `bootstrap/app.php`, where it belongs by convention:
     * that closure runs while the application is still being built and the config
     * repository is not bound yet. env() is not the way round it either — a
     * cached config means .env is never loaded, so the one deployment that needs
     * this would silently read null.
     */
    private function trustConfiguredProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (! is_string($proxies) || trim($proxies) === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
    }

    /**
     * Named limiters, one bucket per concern.
     *
     * Inline limits (`throttle:5,1`) all resolve to the SAME key for a guest —
     * `ThrottleRequests::resolveRequestSignature()` hashes only `domain|ip`, with
     * no route in it. So every throttled route shared one counter and the
     * strictest limit won: browsing 5 marketplace pages consumed the login
     * allowance and locked the visitor out of signing in. Naming the limiter puts
     * its name in the key, which is what separates the buckets.
     */
    private function registerRateLimiters(): void
    {
        // Credential guessing. Keyed by email as well as IP so one attacker
        // cannot lock a shared-NAT office out of its own accounts.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('email:'.(string) $request->input('email')),
        ]);

        // Account creation — costlier than a login and worth a wider window.
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        // Public browsing. Generous: a visitor opening several teacher profiles in
        // a row is the behaviour the marketplace exists for.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));

        // Contact verification. Keyed by user as well as IP: sending codes costs
        // money at the provider, and one account looping the endpoint should not
        // be able to spend the whole office's allowance.
        RateLimiter::for('contact-verification', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('user:'.(string) $request->user()?->getKey()),
        ]);

        // Playback grants. Keyed by user, not IP: a classroom behind one NAT is
        // many legitimate viewers, and the grant is already scoped to one account.
        // Generous because a viewer opening a course renews once a minute.
        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.(string) $request->user()?->getKey()));

        // Two-factor setup and challenge. By account as well as IP: the challenge
        // is a six-digit code, so a per-IP limit alone leaves it brute-forceable
        // from a botnet.
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(5)->by('challenge:'.(string) $request->input('challenge', $request->user()?->getKey())),
        ]);

        // Session writes: booking, cancelling, issuing a join ticket. By user for
        // the same reason as playback — a school behind one address is many
        // legitimate students racing for the same seats.
        RateLimiter::for('sessions', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.(string) $request->user()?->getKey()));

        // The presence heartbeat. One participant sends two a minute; the ceiling
        // leaves room for several rooms and reconnection storms without leaving
        // the endpoint open. It is a write on every call, so it is not unlimited.
        RateLimiter::for('presence', fn (Request $request) => Limit::perMinute(240)
            ->by('user:'.(string) $request->user()?->getKey()));

        // Settlement writes: asking for a rate, approving one, closing a period,
        // recording a payout. Tight because none of them is a thing anyone does
        // repeatedly — a teacher asks for a new rate once a month, and an admin
        // closes a period once a period. Keyed by user: these all require
        // authentication, and an office behind one address is many admins.
        RateLimiter::for('settlement-write', fn (Request $request) => Limit::perMinute(20)
            ->by('user:'.(string) $request->user()?->getKey()));

        // Billing writes: buying credits, recording a consent, adjusting credits,
        // moving a limit, opening an exam window, switching the mode.
        //
        // Keyed by USER, and that is the whole point of not reusing `auth` here.
        // The `auth` limiter's second bucket is `by('email:'.$request->input('email'))`,
        // and a billing write carries no `email` field — so the key collapses to
        // the constant string 'email:', one bucket for the entire platform. An
        // attacker looping POST /billing/consents would stop every student on the
        // site from buying anything.
        RateLimiter::for('billing', fn (Request $request) => [
            Limit::perMinute(30)->by('user:'.(string) $request->user()?->getKey()),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);

        // Course authoring: creating, renaming, reordering, publishing, deleting.
        // Looser than the settlement writes because this is the opposite kind of
        // work — a teacher building a unit saves dozens of times in an hour, and
        // a limit that interrupts that is a limit that loses their text. Keyed by
        // user: a school authoring from one address is many teachers.
        RateLimiter::for('authoring', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Upload tickets. Tighter than authoring because each one reserves a row
         * AND, for a primary, deletes the file that was there — so a loop over
         * this endpoint is a loop over someone's stored work, not over writes to
         * a title. The contract named this limiter as existing; it did not, and
         * the ticket route carried no limit at all.
         */
        RateLimiter::for('upload', fn (Request $request) => Limit::perMinute(20)
            ->by('user:'.(string) $request->user()?->getKey()));
    }

    /**
     * Map a model to its factory class.
     *
     * @param  class-string<Model>  $model
     * @return class-string<Factory<Model>>
     */
    private function guessFactoryName(string $model): string
    {
        $factory = preg_match('/^App\\\\Modules\\\\(\w+)\\\\Models\\\\(\w+)$/', $model, $matches) === 1
            ? "Database\\Factories\\Modules\\{$matches[1]}\\{$matches[2]}Factory"
            : 'Database\\Factories\\'.class_basename($model).'Factory';

        if (! is_subclass_of($factory, Factory::class)) {
            throw new RuntimeException("No factory found for model [{$model}] (looked for [{$factory}]).");
        }

        return $factory;
    }
}
