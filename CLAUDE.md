# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Educational multi-tenant SaaS (courses → enrollment → exams → certificates → manual payments).
Monorepo: `backend/` (Laravel 13, PHP 8.5, modular monolith), `frontend/` (Next.js 15 App Router), `docker/`, `docs/`.
`AGENTS.md` holds the same command reference; `docs/README.md` has the module/endpoint/permission tables and `docs/erd.md` the schema.

## Commands

All backend commands run from `backend/`:

```bash
php vendor/bin/pest                              # all tests (120)
php vendor/bin/pest tests/Feature/Learning       # one directory
php vendor/bin/pest --filter="lesson"            # one test by name
php vendor/bin/pest --coverage                   # needs pcov/xdebug
./vendor/bin/pint                                # fix PSR-12 style (--test to check only)
./vendor/bin/phpstan analyse                     # Larastan level 8 (phpstan.neon) — see note below
php artisan migrate:fresh --seed                 # reset DB + roles/permissions + demo data
php artisan storage:link                         # once per checkout — serves uploaded receipts at /storage
php artisan serve                                # API on :8000, Filament on /admin, Horizon on /horizon
php artisan scribe:generate                      # regenerate /docs from route annotations
```

Frontend (`frontend/`): `npm run dev` (:3000, rewrites `/api/*` → `localhost:8000`), `npm run build`, `npx tsc --noEmit`.

Local dev uses SQLite; tests use in-memory SQLite with `SCOUT_DRIVER=null` (see `phpunit.xml`). Production is MySQL + Redis + Meilisearch. **SQLite hides column-width bugs** (it stores any integer in an `unsignedInteger` column) — check the migration when writing numeric values that MySQL would reject in strict mode.

`phpstan.neon` runs Larastan at level 8 over `app/` and `database/`, and the tree is clean — keep it that way. It reads every module's `Database/Migrations` directory to type model properties, so a new module must be listed there too.

## Architecture

### Module system

Modules live in `backend/app/Modules/{Name}/` and are **auto-discovered** by `App\Shared\Modules\ModulesServiceProvider` — it scans the directory and registers each `{Name}ServiceProvider` extending `App\Shared\Modules\Module`. Never register a module provider manually in `bootstrap/providers.php`.

The base `Module` class auto-loads, per module:
- `routes/api.php` → auto-prefixed `/api/v1` with the `api` middleware group (root `routes/api.php` is intentionally empty)
- `routes/web.php` → `web` group
- `Database/migrations/`, `Database/factories/`

Model factories currently live centrally in `backend/database/factories/Modules/{Module}/` (namespace `Database\Factories\Modules\{Module}`), not inside the module.

Cross-module coupling goes through **events**, not direct calls into another module's actions. Listeners are wired with `Event::listen()` in the subscribing module's provider `boot()` — there is no `EventServiceProvider`. Key chains:

- `PaymentApproved` → `Payments\Listeners\CreateEnrollmentFromOrder` → `EnrollmentCreated` → `Notifications\Listeners\NotifyStudentEnrolled` → `DispatchNotification`
- `CourseCompleted` / `ExamPassed` → `Certificates\Listeners\IssueCertificateIfEligible` (idempotent) → `CertificateIssued` → notification
- `WorkspaceCreated` → `Tenancy\Listeners\SeedDefaultRoles`

### Multi-tenancy

Single database, partitioned by `workspace_id`. Three moving parts:

1. `BelongsToWorkspace` trait — adds the `WorkspaceScope` global scope and auto-fills `workspace_id` on create. **Every tenant-owned model must use it.**
2. `WorkspaceScope` — filters queries by the current workspace; skipped entirely when the context resolves to `null` (Super Admin operating globally). Bypass deliberately with `withoutWorkspaceScope()` or `WorkspaceContext::withoutScope()`.
3. `WorkspaceContext` — resolves the current workspace from the session key `workspace_id`, falling back to `users.last_workspace_id`. The `EnsureCurrentWorkspace` middleware (in the `api` group and the Filament panel's `authMiddleware`) also pushes that id into spatie's `PermissionRegistrar` team id.

`WorkspaceContext` is an **application-wide singleton that caches its resolution**, so outside an HTTP request never call `set()`: a queued job or console command that does leaks its workspace into whatever the same worker handles next. Use `forWorkspace($workspace, fn () => ...)`, which restores the previous context (and team id) afterwards. In tests, the first call to `id()` freezes the resolution — query tenant models only after `Sanctum::actingAs()` / `setCurrentWorkspace()`.

Validation is a second bypass route: Laravel's `exists` rule is a raw query, so use `App\Shared\Support\WorkspaceRules::exists('table')` instead of `'exists:table,id'` for any tenant-owned table. Scout is a third — `Model::search()` hits the engine outside global scopes and must be given an explicit `->where('workspace_id', ...)`.

spatie/permission runs in **team mode with `team_id = workspace_id`**, so role/permission checks are meaningless until the team id is set — that is why tests must go through `WorkspaceContext::set()` or the `WithWorkspace` helpers. Super Admin is the platform-level `users.is_super_admin` flag, not a tenant role.

### Conventions

- Business logic lives in `Actions/` (`App\Shared\Actions\Action`, one public `handle()`), never in controllers. Controllers: validate via a Form Request → build a DTO → call an Action → return an API Resource. Controllers extend `App\Http\Controllers\Controller` and read the authenticated user via `$this->currentUser($request)`.
- Factories are resolved centrally by `AppServiceProvider::guessFactoryName()` (`App\Modules\X\Models\Y` → `Database\Factories\Modules\X\YFactory`); models don't override `newFactory()`.
- DTOs extend `App\Shared\Data\DataTransferObject` (readonly promoted props, `fromArray()` named constructor).
- Every file is `declare(strict_types=1);`.
- Business rules configured on a model (attempt limits, timers, sequencing) must be enforced in the Action that performs the operation, not only validated on the way in — the Action is the single entry point that seeders, Filament and the API share.
- Models use `HasUuid`: `getRouteKeyName()` is `uuid`, so routes and API payloads expose `uuid`, never the autoincrement id.
- Authorization is policy-based (`{Module}/Policies/`) and permission names come from `Tenancy\Support\Permissions` / role names from `Tenancy\Support\Roles` constants — do not hardcode permission strings.
- Payments go through `Payments\Contracts\PaymentProviderInterface`; `ManualTransferProvider` is the only MVP implementation. Adding a provider must not require changing order/enrollment logic.
- **Notifications go through `Notifications\Actions\DispatchNotification` and nothing else.** Business logic names a recipient and a `NotificationType` — never a channel. Channels implement `NotificationChannelInterface` and are registered with one `->tag('notification.channels')` line; `InAppChannel` is the only implemented one (WhatsApp/email/Telegram/SMS/push are known values marked unimplemented). `ProviderAgnosticTest` fails the build if any `Actions/` file names a channel or provider.
- API auth is Sanctum bearer tokens; the Filament `/admin` panel is session-based and gated by `EnsureFilamentAccess`.

### Tests

Pest, `RefreshDatabase` for all suites, plus the `Tests\Support\WithWorkspace` trait auto-applied to `Feature` (`createWorkspaceWithOwner()`, `addWorkspaceMember()`). Feature tests are the primary safety net — the critical paths listed in `AGENTS.md` (workspace isolation, permissions, enrollment/lesson gating, exam grading, certificate issuance + verification, payment approval → enrollment) must stay green.

## Gotchas

- **Platform-owned entities have no scope at all — the Action is the guard.** Since spec 003 the constitution classifies every model into one of three layers, and `notifications`, `notification_preferences`, `parent_student_relations`, `message_templates` and `contact_verifications` are **not** workspace-scoped by design (a student has one feed and one set of preferences across every teacher they study with). That means no global scope touches them, so they are as exposed as an unauthenticated marketplace query. The guard is written explicitly: ownership of the row, plus — for a teacher reading a student's guardians — an **active enrollment in that teacher's own workspace** (`ParentStudentRelationPolicy`). `tests/Feature/Notifications/PlatformOwnershipTest.php` tests both failure directions, including the mirror-image bug of adding `BelongsToWorkspace` where it does not belong, which silently duplicates one person per teacher.
- **`Notifiable` stays on `User`, and that is deliberate.** Email is an unimplemented *notification channel*, but Laravel's password-reset and email-verification mails still go through `notify()`. They are authentication primitives, not domain notifications — a reset link delivered in-app is unreachable by definition, since the person asking for it cannot sign in to read it. `User::notifications()` is overridden to point at our own model; Laravel's `notifications` table was replaced.
- **A notification with no template is silently dropped.** `TemplateRenderer` refuses to render a missing or unapproved template (FR-037) and `DispatchNotification` logs it rather than failing the enrollment/payment that triggered it. `NotificationTemplateSeeder` is therefore reference data, not fixtures — `tests/Pest.php` seeds it before every Feature test, or every notification assertion would pass vacuously against zero.
- **`WorkspaceScope` is inert for guests.** `WorkspaceScope::apply()` returns early adding no condition when `WorkspaceContext::id()` is `null`, which it always is without an authenticated user. So an unauthenticated request has **no tenant isolation at all** — a public query without a guard returns every workspace's rows, including drafts. The replacement guard is the `IsPubliclyListed` trait's `publiclyListed()` scope (workspace participation + the model's own `publicListingConstraints()`). Every Action reached from a public route must start from it, and `PublicExposureTest` walks each public payload against `PublicFieldAllowlist` to catch what slips through. Never bind a public route to a model implicitly: `Route::get('/teachers/{teacher}')` resolves by uuid without the guard.
- **The trust score is derived, and so is `is_publicly_listed`.** Approval and workspace participation are two separate decisions; `is_publicly_listed` is computed from both and never assigned directly. A trust score below the data threshold is `null` with band `building`, never `0` — filters must exclude those teachers rather than compare against a number they do not have.
- **Never call `WorkspaceContext::set()` from a job or console command.** It is an application-wide singleton that caches its resolution, so the workspace leaks into whatever the same worker handles next. Use `forWorkspace($workspace, fn () => ...)`. `tests/Feature/Marketplace/TrustScoreJobIsolationTest.php` fails the build if a `set()` appears under `Modules/Marketplace/Jobs/`.
- Module directory names are matched literally by `Module::registerMigrations()`/`registerFactories()`. Migrations must live in `Database/Migrations` (capital M) — a casing mismatch resolves fine on Windows/macOS and silently loads **zero** migrations on Linux.
- Adding a tenant-scoped model without `BelongsToWorkspace` leaks data across workspaces and no test will catch it unless you add one to `tests/Feature/Tenancy/WorkspaceIsolationTest.php`.
- Frontend stores the Sanctum token in `localStorage` and sends `Authorization: Bearer` (`frontend/src/lib/api.ts`); it relies on the Next.js rewrite, so the backend must be running on :8000.
- Don't run `npm run build` while `npm run dev` is up — both write `.next/`, and the dev server then dies with `Cannot find module './NNN.js'`. Stop dev first (or accept that you must `rm -rf .next` and restart it).
- **The frontend is Arabic-only and RTL-only** (spec 002). One root layout, `frontend/src/app/layout.tsx`, declares `lang="ar" dir="rtl"`, loads Cairo, and runs the pre-paint theme script; `(public)` and `(app)` are nested layouts that add chrome only. Adding a second `<html>` anywhere re-splits the product.
- **Colours come from `@theme` in `globals.css`, never from a class.** No `bg-gray-*`, no `bg-white`, no hex in a component. `text-white` is allowed *only* as the foreground of `bg-primary`/`bg-secondary`/`bg-danger`; `accent` and `trust-medium` have their own `-foreground` tokens because white fails 4.5:1 on them. Layout uses logical properties (`ms-*`, `start-*`, `text-start`) — never `ml-*`/`left-*`. Direction lives in icon *names* (`ChevronStartIcon`), not in a CSS flip.
- **Shared UI lives in `frontend/src/components/ui/`** (`Button`, `Card`, `Badge`, `Alert`, `Table`, the `Field` family, `states/`). Components there take no free-form `className` — appearance is a closed set of variants. `components/marketplace/` is now marketplace-specific only. Arabic status labels and money/date formatting go through `src/lib/labels.ts`.
- **Never show a raw error to the user.** 422 goes through `fieldErrors()` and lands under its field; everything else goes through `userMessage()` in `src/lib/errors.ts`. Validation text itself is Arabic from `backend/lang/ar/`, and every new `FormRequest` field needs an entry in that file's `attributes` array or it renders as `phone_number`.
- **Rate limiters are named** (`throttle:auth` · `throttle:registration` · `throttle:public`, defined in `AppServiceProvider::registerRateLimiters()`). Inline `throttle:5,1` is banned: `ThrottleRequests` keys guests on `domain|ip` with no route in the hash, so every inline limit shares one counter and the strictest one wins — browsing the marketplace used to lock a visitor out of logging in.
- Playwright projects carry an authenticated `storageState` produced by `e2e/auth.setup.ts` so the panel can be audited. A spec that tests an anonymous visitor must opt out with `test.use({ storageState: { cookies: [], origins: [] } })`, or it silently tests a signed-in one.
