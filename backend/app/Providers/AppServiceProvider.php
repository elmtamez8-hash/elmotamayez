<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Community\Support\CommunitySettings;
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

        /*
        | The floor under the whole `api` group (applied in `bootstrap/app.php`).
        |
        | ⚠️ IT IS NOT A SECURITY CEILING AND MUST NOT BE READ AS ONE. Every route
        | that deserves a real limit still names its own; this exists so that a
        | route which names NOTHING is bounded rather than open, which is what the
        | Tenancy writes and the whole of Analytics were.
        |
        | ⚠️ AND IT IS DELIBERATELY LOOSE. A floor that bites a real flow is a
        | floor somebody raises to infinity a week later — so it sits far above
        | anything the product does: the busiest authenticated caller on the
        | platform is a presence heartbeat at two a minute, whose own limiter is
        | 240. Keyed on the account when there is one, and only then on the
        | address: a school behind one NAT is many legitimate people, and every
        | public route already carries `throttle:public` by ip beneath this.
        */
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(300)->by('user:'.(string) $user->getKey())
                : Limit::perMinute(120)->by('ip:'.$request->ip());
        });

        // Account creation — costlier than a login and worth a wider window.
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        // Public browsing. Generous: a visitor opening several teacher profiles in
        // a row is the behaviour the marketplace exists for.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));

        /*
        | Asking to be somebody's guardian (spec 030 · FR-012).
        |
        | TWO LIMITS, and the second is the one that matters. A key carrying the
        | TARGET gives an attacker a fresh bucket per victim — so the per-pair
        | limit alone would bound nothing but a double tap, while three hundred
        | children a minute each get their own untouched counter and their own
        | notification carrying an attacker-chosen display name.
        |
        | ⚠️ NO `student_name` FALLBACK. That field is free text the requester
        | controls, so a per-student limit keyed on it is bypassed by changing one
        | letter. A request with no `student_uuid` is a name-only child: it is
        | active at once, there is nobody to notify and nothing to re-request.
        |
        | Named, never inline: `ThrottleRequests` keys guests on `domain|ip` with
        | no route in the hash, so every inline limit in the app shares one counter.
        */
        RateLimiter::for('family-link', fn (Request $request) => [
            Limit::perMinute(3)->by(
                'pair:'.(string) $request->user()?->getKey().'|'.(string) $request->input('student_uuid'),
            ),
            Limit::perHour(20)->by('user:'.(string) $request->user()?->getKey()),
        ]);

        // Contact verification. Keyed by user as well as IP: sending codes costs
        // money at the provider, and one account looping the endpoint should not
        // be able to spend the whole office's allowance.
        RateLimiter::for('contact-verification', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('user:'.(string) $request->user()?->getKey()),
        ]);

        // Changing the public profile URL. Keyed by account, not IP: the
        // endpoint answers "is this slug taken?" as a side effect of validating,
        // so an unthrottled one is a way to enumerate the namespace. Ten an hour
        // is far more than anyone renames themselves and far too few to walk a
        // dictionary with.
        RateLimiter::for('profile-slug', fn (Request $request) => [
            Limit::perMinute(5)->by('user:'.(string) $request->user()?->getKey()),
            Limit::perHour(10)->by('user:'.(string) $request->user()?->getKey()),
        ]);

        /*
        | Data-rights requests (spec 013).
        |
        | ⚠️ KEYED ON THE ACCOUNT, NEVER ON THE ADDRESS. A family behind one home
        | router shares an address, and a guardian may hold several children — so
        | an IP limit here refuses the second child because the first one's request
        | was already made. That is the population this whole phase exists to
        | protect, refused by its own guard.
        |
        | Deliberately narrow all the same: each accepted request queues a job that
        | assembles everything the platform knows about one person, and the
        | download route carries this limiter too — the file is the widest single
        | object in the product.
        */
        RateLimiter::for('data-rights', fn (Request $request) => [
            Limit::perMinute(6)->by('user:'.(string) $request->user()?->getKey()),
            Limit::perDay(30)->by('user:'.(string) $request->user()?->getKey()),
        ]);

        // Playback grants. Keyed by user, not IP: a classroom behind one NAT is
        // many legitimate viewers, and the grant is already scoped to one account.
        // Generous because a viewer opening a course renews once a minute.
        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
        | Provider webhooks.
        |
        | ⚠️ TWO KEYS, NOT ONE. The provider comes from the ROUTE, and the
        | network address beside it — the shape `auth` (ip + email) and `billing`
        | (user + ip) already use. Keyed by provider alone, the bucket is one
        | counter for the entire planet: an attacker drains it, the real provider
        | is answered 429, and everyone who paid stays withheld until the nightly
        | sweep — the worst case the spec names.
        |
        | ⚠️ AND THE AUTHENTICATED SHAPE MUST NOT BE COPIED HERE. This route has
        | no user, so `by('user:'.$request->user()?->getKey())` collapses to the
        | constant `'user:'` — one bucket for everybody.
        |
        | Generous on purpose: a gateway recovering from its own outage sends its
        | backlog in one burst, and that burst is the moment refusing it hurts
        | most.
        */
        RateLimiter::for('webhook', fn (Request $request) => [
            Limit::perMinute(300)->by('provider:'.(string) $request->route('provider')),
            Limit::perMinute(120)->by('ip:'.$request->ip()),
        ]);

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

        /*
        | Cohort writes: joining, asking to transfer, approving, assigning
        | sessions (spec 021).
        |
        | ⚠️ REGISTERED AHEAD OF ITS ROUTES, WHICH ARRIVE WITH US3. A named
        | limiter costs nothing until something carries its name, and the
        | alternative is what the file's own docblock is about: an inline
        | `throttle:20,1` written in a hurry on the first cohort route shares one
        | bucket with every other inline limit on the platform, and the strictest
        | one wins.
        |
        | By account, like `sessions` above and for the same reason: a class
        | choosing their group from one school address is many legitimate people.
        */
        RateLimiter::for('cohort-write', fn (Request $request) => Limit::perMinute(20)
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

        /*
         * Store writes (spec 011 · US1): saving a product, buying one, advancing
         * a shipment.
         *
         * Keyed by USER for the reason the `billing` limiter above spells out —
         * `auth`'s second bucket is `by('email:'.$request->input('email'))`, and
         * a purchase carries no `email` field, so its key collapses to the
         * constant `'email:'`: one five-per-minute bucket for every write on the
         * platform, drainable by a single account. The IP bucket is the second
         * line, and it is looser because a school buying from one address is many
         * students.
         */
        RateLimiter::for('store-write', fn (Request $request) => [
            Limit::perMinute(30)->by('user:'.(string) $request->user()?->getKey()),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);

        /*
         * Applying a coupon code (spec 011 · FR-016).
         *
         * ⚠️ THE TIGHTEST LIMITER IN THIS FILE, because it is the only route
         * whose whole purpose is to be GUESSED AT: a code is a short string, the
         * answer says whether it exists, and the reward is somebody else's
         * discount. Ten a minute is generous for a person typing one code and
         * useless for a script walking a keyspace.
         *
         * Both buckets, and the IP one is the narrower of the two here rather
         * than the wider: an attacker with one address and a thousand accounts is
         * the shape this endpoint attracts.
         */
        RateLimiter::for('coupon', fn (Request $request) => [
            Limit::perMinute(10)->by('user:'.(string) $request->user()?->getKey()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
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

        /*
         * Self-generated practice exams (spec 008, FR-026). Keyed by user and NOT
         * by ip, which matters more here than anywhere else in this file: the
         * people hitting it are students, and students sit in classrooms behind
         * one address. An ip key would let one bored student in the back row lock
         * their entire class out of practising. Each request costs a bank query
         * plus a written attempt with a row per question, so the ceiling is low.
         */
        RateLimiter::for('practice', fn (Request $request) => Limit::perMinute(10)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Adaptive practice, one question at a time (spec 012 · US1).
         *
         * ⚠️ ITS OWN BUCKET, NOT `practice`. A named limiter is one counter per
         * user, so reusing `practice` here would make a twenty-question session
         * — twenty writes by design — spend the whole allowance for STARTING
         * anything, and cut the student off in the middle of the tenth question.
         * That is the shared-counter effect inline limits are banned for in this
         * file, reached again under a name.
         *
         * Sixty a minute is one answer a second: far above a person reading a
         * question, far below a script walking the bank.
         */
        RateLimiter::for('adaptive-step', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Study-room writes (spec 012 · US3): creating a room, joining one,
         * answering inside one.
         *
         * Ten a minute, an order of magnitude below `adaptive-step`, because
         * each of these costs a broadcast to everyone else in the room — the
         * cost is not this caller's alone. Keyed by user for the reason
         * `practice` is: the callers are students, and students sit in
         * classrooms behind one address.
         */
        RateLimiter::for('study-room-write', fn (Request $request) => Limit::perMinute(10)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Gamification writes (spec 009, NFR-014): redeeming a reward, starting
         * and ending a focus session. Keyed by user for the same reason as
         * `practice` — the callers are students, and students sit in classrooms
         * behind one address.
         */
        RateLimiter::for('gamification-write', fn (Request $request) => Limit::perMinute(20)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Reading a leaderboard.
         *
         * ⚠️ A READ WITH A LIMIT ON IT, WHICH NFR-014 DID NOT ASK FOR. It is the
         * one endpoint in this phase that is worth enumerating: the scope key
         * names a lesson, a course, a teacher, a subject or a grade, so an
         * unthrottled board is a walk of the whole space collecting who is active
         * where. Everything else here is either a write or already scoped to the
         * caller's own row. Generous — a student refreshing their board is the
         * behaviour the feature exists for.
         */
        RateLimiter::for('gamification-board', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Chat writes (spec 010): opening a conversation, posting a message.
         *
         * ⚠️ THE CEILING IS A SETTING AND NOT A LITERAL — the only limiter in this
         * file that reads one. It is the number a spam wave moves, and
         * `PlatformSettings` answers from `rememberForever`, so the cost is a
         * cache hit rather than a query on every write.
         *
         * Keyed by user, not ip, for the reason `practice` is: the callers are
         * students, and students sit in classrooms behind one address.
         */
        RateLimiter::for('chat-write', fn (Request $request) => Limit::perMinute(
            CommunitySettings::maxMessagesPerMinute()
        )->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Reporting abusive content (spec 010, FR-024).
         *
         * ⚠️ A SEPARATE BUCKET FROM `chat-write`, AND THAT SEPARATION IS THE WHOLE
         * POINT OF NAMING IT. Somebody throttled for writing must still be able to
         * report what is being written at them — share one counter and the loudest
         * participant in a room silences the complaint about themselves. Tight in
         * its own right: a report opens a moderation row, and a loop over it is a
         * way to bury the queue the teacher reads.
         */
        RateLimiter::for('chat-report', fn (Request $request) => Limit::perMinute(10)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Broadcast channel authorisation (spec 010, NFR-014).
         *
         * ⚠️ A READ, LIMITED, BECAUSE IT IS AN AUTHENTICATED ORACLE. Every
         * subscription attempt names a channel, and the difference between 200
         * and 403 is an answer about a conversation the caller does not hold —
         * cheap to loop, and the one endpoint on the platform whose whole job is
         * to say yes or no about somebody else's row.
         *
         * Generous all the same: one browser tab resubscribes on every reconnect,
         * and a phone moving between networks reconnects often.
         */
        RateLimiter::for('broadcast-auth', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Moderation writes (spec 010, FR-021): hiding a message, banning and
         * lifting a ban. Tight because none of them is repeated work, and every
         * one appends a row to an audit log that is never deleted from.
         */
        RateLimiter::for('moderation-write', fn (Request $request) => Limit::perMinute(20)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Publishing an announcement (spec 010, FR-048).
         *
         * ⚠️ THE TIGHTEST LIMIT IN THIS FILE, AND IT GUARDS OUR REPUTATION RATHER
         * THAN OUR CPU. One publish fans out to every student in scope through the
         * notification centre — for an urgent one, past quiet hours and past
         * digesting. A teacher who can publish sixty times a minute is a teacher
         * who can make three hundred families mute the channel that also carries
         * the attendance alert.
         */
        RateLimiter::for('announcement-publish', fn (Request $request) => Limit::perMinute(6)
            ->by('user:'.(string) $request->user()?->getKey()));

        /*
         * Report-card rendering and download (spec 010, FR-039).
         *
         * ⚠️ BY THE MINUTE AND BY THE DAY, the shape `data-rights` uses, because
         * this is the other endpoint in the product that queues a document
         * assembling everything about one student. A per-minute limit alone lets a
         * patient loop spend the whole day's render budget.
         *
         * Keyed on the account, never the address: a guardian may hold several
         * children and a family shares one router, so an ip limit refuses the
         * second child because the first one's card was fetched.
         */
        RateLimiter::for('report-card-render', fn (Request $request) => [
            Limit::perMinute(6)->by('user:'.(string) $request->user()?->getKey()),
            Limit::perDay(60)->by('user:'.(string) $request->user()?->getKey()),
        ]);
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
