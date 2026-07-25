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

- `PaymentApproved` → `Payments\Listeners\CreateEnrollmentFromOrder` → `EnrollmentCreated` → enrollment notification
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
- API auth is Sanctum bearer tokens; the Filament `/admin` panel is session-based and gated by `EnsureFilamentAccess`.

### Tests

Pest, `RefreshDatabase` for all suites, plus the `Tests\Support\WithWorkspace` trait auto-applied to `Feature` (`createWorkspaceWithOwner()`, `addWorkspaceMember()`). Feature tests are the primary safety net — the critical paths listed in `AGENTS.md` (workspace isolation, permissions, enrollment/lesson gating, exam grading, certificate issuance + verification, payment approval → enrollment) must stay green.

## Gotchas

- Module directory names are matched literally by `Module::registerMigrations()`/`registerFactories()`. Migrations must live in `Database/Migrations` (capital M) — a casing mismatch resolves fine on Windows/macOS and silently loads **zero** migrations on Linux.
- Adding a tenant-scoped model without `BelongsToWorkspace` leaks data across workspaces and no test will catch it unless you add one to `tests/Feature/Tenancy/WorkspaceIsolationTest.php`.
- Frontend stores the Sanctum token in `localStorage` and sends `Authorization: Bearer` (`frontend/src/lib/api.ts`); it relies on the Next.js rewrite, so the backend must be running on :8000.
