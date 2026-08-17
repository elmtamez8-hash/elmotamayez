# AGENTS.md

## Project: Educational Multi-Tenant SaaS Platform

Monorepo: `D:\mteatch`
- `backend/` — Laravel 13, PHP 8.5
- `frontend/` — Next.js 15 (TypeScript, Tailwind CSS, App Router)
- `docker/` — Production Docker configuration (docker-compose, Dockerfiles, nginx)
- `docs/` — Design artifacts and documentation

## Architecture

Modular monolith. Modules live under `app/Modules/{Module}/`. Each module auto-discovers its own:
- Service provider (`{Module}ServiceProvider.php`)
- Routes (`routes/api.php`, `routes/web.php`)
- Migrations (`Database/Migrations/`)
- Factories (`Database/factories/`)

Shared infrastructure lives under `app/Shared/` (traits, scopes, support classes, middleware).

Multi-tenancy: single DB + `workspace_id` global scope (`BelongsToWorkspace` trait + `WorkspaceScope`). spatie/permission in team mode (`team_id = workspace_id`).

## Commands

### Testing
```bash
cd backend
php vendor/bin/pest                    # Run all tests
php vendor/bin/pest --compact          # Compact output
php vendor/bin/pest --coverage         # With coverage (requires pcov/xdebug)
```

### Code Style
```bash
cd backend
./vendor/bin/pint --test               # Check style (PSR-12)
./vendor/bin/pint                      # Fix style
```

### Static Analysis
```bash
cd backend
./vendor/bin/phpstan analyse           # Larastan level 8
```

### Database
```bash
cd backend
php artisan migrate:fresh --seed       # Fresh DB + seed
php artisan migrate:fresh              # Fresh DB only
```

### API Documentation
```bash
cd backend
php artisan scribe:generate            # Generate OpenAPI/Scribe docs at /docs
```

### Development Server
```bash
cd backend
php artisan serve                      # API at :8000
php artisan queue:listen               # Queue worker
```

### Frontend Development
```bash
cd frontend
npm install
npm run dev                            # App at :3000 (proxies /api to :8000)
npm run build                          # Production build
npx tsc --noEmit                       # Type check
npm run test:e2e                       # Playwright — needs the backend up and seeded
```

Since spec 002 the frontend is **Arabic-only, RTL-only**:

- One root layout (`src/app/layout.tsx`) owns `<html lang="ar" dir="rtl">`, the Cairo
  font and the pre-paint theme script. `(public)` and `(app)` add chrome only.
- Colours come from `@theme` in `globals.css`. No palette classes, no `bg-white`, no hex
  in components. Layout uses logical properties (`ms-*`, `start-*`), never `ml-*`/`left-*`.
- Shared UI is `src/components/ui/`; it accepts no free-form `className`.
- User-facing errors: 422 → `fieldErrors()` under the field, everything else →
  `userMessage()`. Validation text is Arabic from `backend/lang/ar/`.
- Playwright projects run signed in. An anonymous-visitor spec must declare
  `test.use({ storageState: { cookies: [], origins: [] } })`.

## Environment

- PHP 8.5.3 (Laragon)
- DB: SQLite (local dev), MySQL 8+ (production)
- Cache/Queue: `database` (local dev), Redis (production)
- Search: Meilisearch (disabled in tests via `SCOUT_DRIVER=null`)
- Horizon: installed (requires Redis + pcntl for production)

## Test Database

Tests use in-memory SQLite (`DB_DATABASE=:memory:` in `phpunit.xml`).
`SCOUT_DRIVER=null` disables Meilisearch during tests.

## Critical Test Paths (must stay green)

1. Workspace isolation — `tests/Feature/Tenancy/WorkspaceIsolationTest.php`
2. Permission enforcement — `tests/Feature/Tenancy/WorkspaceIsolationTest.php`
3. Enrollment + lesson gating — `tests/Feature/Learning/LearningTest.php`
4. Course completion — `tests/Feature/Learning/LearningTest.php`
5. Exam submission + grading — `tests/Feature/Assessments/AssessmentTest.php`
6. Certificate generation + idempotency — `tests/Feature/Assessments/AssessmentTest.php`
7. Certificate verification — `tests/Feature/Assessments/AssessmentTest.php`
8. Payment approval → enrollment auto-creation — `tests/Feature/Payments/PaymentTest.php`
9. Public marketplace leak guard — `tests/Feature/Marketplace/PublicExposureTest.php`
10. Trust-score job workspace isolation — `tests/Feature/Marketplace/TrustScoreJobIsolationTest.php`
11. Platform-owned entity guard — `tests/Feature/Notifications/PlatformOwnershipTest.php`
12. Provider-agnostic notifications — `tests/Feature/Notifications/ProviderAgnosticTest.php`
13. Playback grants — `tests/Feature/Media/PlaybackGrantTest.php`
14. Video provider contract — `tests/Feature/Media/ProviderContractTest.php`
15. Device limit and session eviction — `tests/Feature/Auth/DeviceLimitTest.php`
16. Devices and sessions are platform-owned — `tests/Feature/Auth/PlatformOwnershipTest.php`
17. Watermark identity and phone masking — `tests/Feature/Media/WatermarkTest.php`
18. Two-factor challenge, recovery codes and the sensitive-route guard — `tests/Feature/Auth/TwoFactorTest.php`
19. Caption validation and grant-scoped delivery — `tests/Feature/Media/CaptionsTest.php`
20. Seat concurrency — `tests/Feature/LiveSessions/SeatConcurrencyTest.php`
21. Room-entry guard — `tests/Feature/LiveSessions/RoomAccessTest.php`
22. When absence is recorded — `tests/Feature/LiveSessions/AttendanceSheetTest.php`
23. Attendance has no financial effect — `tests/Feature/LiveSessions/AttendanceHasNoFinancialEffectTest.php`
24. Recording reaches its seats and nobody else — `tests/Feature/LiveSessions/RecordingAccessTest.php`
25. Broadcast provider contract — `tests/Feature/LiveSessions/BroadcastProviderContractTest.php`
26. Freeze counts nothing — `tests/Feature/LiveSessions/FreezePeriodTest.php`
27. The register is not public to the class — `tests/Feature/LiveSessions/RegisterPrivacyTest.php`
28. Lists cost a fixed number of queries — `tests/Feature/LiveSessions/QueryBudgetTest.php`
29. Settlement and billing stay separate contexts — `tests/Feature/Settlement/ContextIsolationTest.php`
30. A period closes once and pays once — `tests/Feature/Settlement/PeriodCloseTest.php`
31. Nothing outside the allowlist reaches a teacher — `tests/Feature/Settlement/StatementPayloadTest.php`
32. A refund never touches the ledger — `tests/Feature/Settlement/RefundDoesNotTouchLedgerTest.php`

### Read before touching sessions

Attendance never passes through the broadcast provider. The register is built from a
heartbeat on our own route and the server does the arithmetic —
`stay_seconds += min(now − last_ping_at, 2 × interval)` — which is why two devices do
not double a stay, a return aggregates into one stay, and an hour of silence is not
credited as attendance.

Seats are claimed by an atomic conditional UPDATE, never `count()` then `insert()` and
never `lockForUpdate()`: the latter is a no-op on SQLite, so a test written around it
passes locally and proves nothing about MySQL.

`SessionCompleted` is not `SessionDelivered`. Two events rather than one with a flag,
because a flag makes the condition optional for the listener — and only delivery
carries the frozen seat count spec 006 will bill against.

Jobs in this module dispatch with `->delay()`. On the `sync` connection a delay runs
IMMEDIATELY (a delay is a queue-driver feature and sync has no queue), so a test that
exercises a session timeline must `Queue::fake()` or the close will land before the
teacher has joined.

### Read before touching playback

A playback grant is a row, not a signed URL, because it must be able to die early —
when the session ends, when an enrolment lapses, when the watermark stops renewing.
`PlaybackGuard::resolve()` is re-run on **every byte-range request**, which is what
makes a video stop mid-file rather than at the next page load. The stream route is
deliberately unauthenticated (a `<video>` element cannot send a bearer token) and
deliberately not model-bound (implicit binding would resolve before the guard runs).

`ProviderContractTest` holds every implementation to what it *claims*: a provider
declaring adaptive bitrate must report more than one rendition or the build fails.
That check is what makes deferring the commercial provider choice safe rather than
optimistic.

### Read before touching any public marketplace route

`WorkspaceScope::apply()` returns early adding **no condition** when there is no
authenticated user, so an unauthenticated request has no tenant isolation at all.
A public query without `publiclyListed()` returns every workspace's rows, drafts
included. `IsPubliclyListed` is the replacement guard and is mandatory on every
Action reached from `Modules/Marketplace/routes/api.php`'s public group. Implicit
route-model binding bypasses it — resolve public uuids inside the Action.

In the queue, `WorkspaceContext::set()` is forbidden: the singleton caches its
resolution and leaks the workspace into the next job on the same worker. Use
`forWorkspace()`. Test 10 above fails the build if a `set()` reappears.

### Read before adding any model (spec 003 onward)

The constitution classifies every entity into one of three ownership layers, and
the classification must be stated in the feature spec before the migration is
written:

- **Platform-owned** — follows the student across every teacher (feed,
  preferences, guardians). **No** `BelongsToWorkspace`; the guard is row ownership,
  written explicitly in the Action or Policy. There is no global scope on these.
- **Workspace-owned** — what a teacher produces. `BelongsToWorkspace`,
  `WorkspaceRules::exists()`, plus a case in `WorkspaceIsolationTest`.
- **Bridge** — carries `workspace_id` for context and points at the platform user.

A teacher reading a platform-owned row about a student needs an **active
enrollment in their own workspace**, not just a permission. Both failure
directions are covered by `PlatformOwnershipTest`, including the mirror-image bug:
`BelongsToWorkspace` applied where it does not belong duplicates one person per
teacher, and shows up months later as several accounts for one child.

### Notifications

Business logic calls `DispatchNotification` with a recipient and a
`NotificationType`. It never names a channel — channels live behind
`NotificationChannelInterface` and are added with one `->tag()` line.
`ProviderAgnosticTest` fails the build if a provider name appears under any
`Actions/`. `Notifiable` stays on `User` for password reset and email
verification only.

## Module Map

| Module | Path | Key Models |
|---|---|---|
| Identity | `app/Modules/Identity/` | User |
| Tenancy | `app/Modules/Tenancy/` | Workspace, WorkspaceMember, Invitation |
| Courses | `app/Modules/Courses/` | Course, Section, Chapter, Lesson |
| Learning | `app/Modules/Learning/` | Enrollment, LessonProgress, ProgressHistory |
| Assessments | `app/Modules/Assessments/` | Exam, Question, QuestionOption, Attempt, Answer |
| Certificates | `app/Modules/Certificates/` | Certificate, CertificateTemplate |
| Payments | `app/Modules/Payments/` | Order, Product, PaymentTransaction |
| Notifications | `app/Modules/Notifications/` | Notification, NotificationDelivery, NotificationPreference, MessageTemplate, ContactVerification |
| Marketplace | `app/Modules/Marketplace/` | TeacherProfile, TeacherApplication, Subject, GradeLevel, AvailabilitySlot, Review, Complaint |
| Analytics | `app/Modules/Analytics/` | (Filament widgets) |
| CMS | `app/Modules/CMS/` | Article, Category, Tag |
| LiveSessions | `app/Modules/LiveSessions/` | ClassSession, SessionBooking, Attendance, ClassSessionFeedback, FreezePeriod |
| Settlement | `app/Modules/Settlement/` | SettlementRate, RateChangeRequest, TeachingUnit, SettlementPeriod, LedgerEntry, TeacherPayout |

### Read before touching settlement

Settlement answers "what is the teacher owed". Billing answers "what did the student pay".
They share **no foreign key and no query**, and the only bridge is the `SessionDelivered`
event from 005. That absence is the deliverable, not an omission — `ContextIsolationTest`
fails the build over a violation in either direction.

Inside the context, one rule decides the rest: **counts come from `teaching_units`, money
comes from `ledger_entries`**. `Deduction` and `Bonus` have no unit behind them, so a
units-derived total omits the first manual adjustment anyone writes. The ledger is
append-only, enforced on the model; the bulk update that stamps a period is the one
sanctioned exception and stays the only one.

Amounts are **integers in minor units**, unlike Payments' `decimal(12,2)` — Laravel's
`decimal:2` cast returns a string, so every sum would go through a float.

A close claims units with `< ends_on + 1 day`, never `<= ends_on` (the column is a
timestamp, the bound is a date). Standalone ledger lines carry no date filter at all: an
adjustment is typed after the window ends, and filtering it by date pushes every deduction
into the next period.

### Read before touching course authoring

**Reordering is a write to access rights.** `accessTo()` derives what a student may open
from the section/chapter/lesson positions, so moving an item changes who can reach what.
Reorders send the complete sibling list plus `structure_version`, and
`StructureVersion::claim()` is one conditional UPDATE that both checks and bumps — compare
a loaded model and increment later and two editors both write, neither 409s.

**An item that enters the progress denominator and can never be completed caps every
enrolled student below 100% — so no `CourseCompleted`, no certificate, permanently.** Six
roads led there (`docs/README.md` lists them). Recordings are excluded from the denominator
*and* from the prerequisite chain — one without the other still bricks the course. Anything
that moves the countable set, including archive and DELETE, fires `CourseStructureChanged`;
`progress_pct` is otherwise written only when a lesson is completed.

**The impact preview runs the publish's own code.** `PreviewPublishImpact` borrows
`resolve()`, `assertReady()`, `progressEligible()`, `CourseProgress::percentage()` and
`ExamGateSatisfaction`, simulating only the three status conditions. It returns the item
list it costed and the client publishes that list — a second estimate, or a draft set
derived on both sides, is how the teacher is shown a number no student ever had.

**Migration order and `chunkById`.** Densify duplicate orders BEFORE adding
`unique(chapter_id, order)`, or the deploy fails on live data. Backfill with `chunkById`,
never `chunk`: OFFSET paging under a shrinking predicate skips rows and reports success.

**Authored text is Markdown, rendered per response.** Raw HTML is stripped rather than
escaped, so the allowlist is the Markdown feature set. A stored `content_html` would be a
second copy that drifts, and an XSS sink with a teacher's keyboard attached.
### Read before touching billing (spec 006)

**A credit is one session at ONE teacher's rate, and the account it hangs off is
platform-wide.** `student_credit_accounts`, `credit_packages` and `terms_consents` carry no
`workspace_id` — one person, one account, one catalogue, one signature. The BALANCE beneath
is workspace-scoped, because a credit is not portable. Adding `BelongsToWorkspace` to the
account duplicates one person per teacher, silently.

**The floor guards booking, never the recording of a debt.** `enforceFloor` defaults to
false: a delivered session is owed whether or not it can be paid for, and 014 has already
earned the teacher their fee from the same event. A test expecting
`InsufficientCreditsException` must pass `enforceFloor: true`.

**`is_withheld` is derived from five inputs and stored as none of them** — balance, ceiling,
mode, exam window, and a CURRENT terms consent. A lift is instantaneous, a published new
version of the terms stops deferral on the next booking with nothing to sweep, and no
`access_holds` table can drift from it. `WithholdingReader::stamp()` is the bulk form; a
per-row read inside a Resource is an N+1 by construction.

**The demotion IS the switch to prepaid for that student.** The floor is `−limit`, so a
ceiling of zero and a prepaid mode are the same predicate. The limit moves by DELTAS with the
earning counter consumed — recomputed from scratch it hands the defaulted student their
ceiling back on their first payment. The initial `0 → 1` is an EVENT (first consent, and the
birth of any later balance), never derived state. And `isBlocked` asks the BALANCE too:
`$floor < 0` stops reporting the debt the moment the demotion zeroes the ceiling.

**Withholding is per course; balances are never summed.** +10 in maths and −6 in physics
reads as +4 and unblocked, while the design withholds per course so the paid-up one stays
open. And no money reaches either side's screen: a credit's price is the teacher's approved
rate plus two platform constants, so a total is solvable for the rate across two package
sizes.

**Approving a purchase and moving a ceiling are PLATFORM permissions**, held by no tenant
role. `billing.credits.adjust` has no HTTP surface at all yet — the Action exists, the screen
does not.

**Reconciliation is a job, and its obvious check is blind.** Both sides of a
ledger-vs-balance comparison are written by the same path in the same transaction, so an
uncharged session leaves them agreeing. The invariants that see it come from outside: one
consumption entry per seat of a charged session, and a positive balance equalling what its
lots hold. Nothing is repaired automatically.

**An expiry claims its lot, then posts with `drawsFromLots: false`** — otherwise the drawer
takes the same credits again out of lots that have not expired. Expiry is off at launch;
the soonest-expiring-first order is live anyway, so switching it on cannot rewrite which lot
paid for which past session. **The dormancy notice needs `notified_dormant_at`**: it moves
no credits, so a predicate on `last_transaction_at` alone re-sends it every night for ever.

**`insertOrIgnore` skips the model, so `HasUuid` never fires** and the row lands with an
empty uuid — the column every route exposes. A rule about the insert ARRAY, not a ban on the
call: `CreditLedger::writeEntry()` uses it deliberately and passes `uuid` and `created_at`
explicitly. `create()` in a try/catch was rejected in R7 — it cannot tell a duplicate from a
real failure. Zero rows is therefore read back by the idempotency key, and a miss **throws**.

**The floor comparison is CAST to signed.** `remaining + limit >= n` at `remaining = −3` on
unsigned columns is MySQL **ERROR 1690**, and SQLite has no unsigned arithmetic to overflow —
no local test can reproduce it. **And the entry is written before the lots are drawn and
before the balance moves**: decrement first and a redelivered event debits twice while the
duplicate entry is ignored once.

**`Queue::fake()` with no arguments makes half the billing suite assert zero rows** — the
charge listener is queued, so "four attendance states ⇒ four entries" becomes a confident
claim about an empty table. Fake the timeline jobs only
(`CloseClassSessionJob`, `SendSessionReportsJob`) and let the charge run on `sync`.

**A signature is not a check on the amount.** A provider can sign a perfectly correct
notification saying 100 for a payment of 500: the signature proves who sent the body, not
that the body agrees with the order. `HandleProviderCallback` compares separately and
answers `mismatch` — no charge, no credits, withholding intact, and the case enters the
reconciliation report. **And `manual` accepts no notification at all**, having no gateway:
anything arriving under its name is impersonation, refused with zero effect.

**One capture per order is a unique COLUMN, not a partial index.** `captured_order_id` is
nullable, carries `unique()`, and is written `= order_id` inside the same conditional UPDATE
that sets the status — NULL does not collide with NULL. MySQL has no partial indexes, so the
`WHERE status = 'captured'` form of this guard does not exist on the database this ships to.
It is deliberately not `$fillable`. **The callback's key is two columns**, `(provider,
external_id)`: an id is unique within the provider that issued it and nowhere else. The
sweep replays the notification rather than capturing by hand, so a sweep and a late callback
for one payment collapse onto that index instead of racing.

**A Horizon supervisor needs `defaults` AND `environments`** — the first supplies values, the
second decides what STARTS, so a queue named only in defaults never drains. A pair absent
from `waits` is not watched at a default; it is not watched.

**A platform-wide read declares `withoutWorkspaceScope()` in every eager load too.**
`WorkspaceContext::id()` falls back to `users.last_workspace_id` for every user including a
super admin, so a scoped platform report shows one teacher's money as the platform's total —
and passes on a single-workspace fixture. `->with('order')` runs the relation's own global
scope: that shipped in the audit chain and answered "nothing was bought" with a 200. Test
platform reads with TWO workspaces or they prove nothing.

**A workspace role may never hold a platform permission.** Roles are editable from
`/admin`, and the picker is not the guard — a filtered form shapes one request,
not the next. `Tenancy\Models\Role` throws on `givePermissionTo()`/`syncPermissions()`
when a platform permission reaches a role with a `team_id`, and the platform set
is DERIVED (`all()` minus what any workspace role holds) so a new permission is
closed by default. Both seeders import that class rather than spatie's: a class
named directly is the class that runs.

**`roles` is workspace-scoped, because every teacher can reach `/admin`.**
`TeamRoleScope` keys on spatie's team id and is inert when it is null;
`RolePolicy` is the row-level half, since a filtered list and a record fetched by
id are different questions. Default roles cannot be deleted — `SeedDefaultRoles`
runs once, at workspace creation.

**Platform standing is `platform_staff`, not a role assignment.** `model_has_roles`
puts `team_id` in its primary key and forbids NULL, so a teamless role can be
seeded and given to nobody. A `Gate::before` turns the standing into that role's
permissions — returning **null, never false**, because false short-circuits every
policy behind it — and only for names in `Permissions::all()`. Shield generates
nothing: both generators off, and `format_custom_permission_keys` false or our
dotted names get pascal-cased into strings no policy knows.

**A unique index over a nullable column never bites.** `concept_stats.lesson_id`
and `unlock_rules.course_id` are `NOT NULL` with **0** as the "no narrower scope"
sentinel — NULL never equals NULL, so `upsert()` would INSERT a new row every
night and the screen would show whichever came back first, silently. The price is
`(int) null === 0`: a failed uuid resolve addresses the default row, so
`UnlockRuleController` guards it with `abort_if`.

**`attempt_items` is the denominator, written at attempt START.** Grading reads
the snapshot and never the live question. A backfill had to build those rows for
every pre-existing attempt, or each one's denominator is zero and a test that
measures `score` alone passes blind.

**Seeders run inside `Model::unguarded()`.** `$fillable` does not protect them, so
"nothing writes this column any more" must be verified against
`database/seeders/` separately.

**`getContent()` escapes non-ASCII.** A `not->toContain('عربي')` assertion against
a raw response body is vacuously true. Re-encode with `JSON_UNESCAPED_UNICODE`, or
use an ASCII sentinel.

**The recording retry loop did not exist.** `giveUpOrRetry()` writes `pending`
and returns — no re-dispatch — and the only sender was `SessionCompleted`. The
counter froze at 1 and the session stayed pending for ever. It was invisible
while `NullBroadcastProvider` declared `recording: false`; 017 switches it on, so
`RetryPendingRecordingsJob` sweeps every fifteen minutes. The cost is not a badge:
`Settlement\Support\PackageCompletion` withholds a teacher's fee for any session
whose recording is not one of `published`, `failed`, `no_course` — and `null` and
`ingesting` are both in the withholding set, so a session that never reached the
ingest job at all holds a wage indefinitely.

**`AccessToken` defaults to a FOUR-hour ttl; the SDK's own docblock says six and is
wrong.** Read the assignment, not the comment above it. Separately,
`$future->diffInHours(now())` is NEGATIVE, so the contract test's `< 24` passed for
any ttl at all. Measure `now()->diffInMinutes($expiresAt)` against the setting.

**The provider's API key is in every ticket by protocol; only the secret is a
credential.** The key is the JWT `iss` claim. A test banning both cannot pass.

**Ask `Gate::allows('host', $session)` before answering a student-eligibility
question.** No teacher holds an enrolment in their own workspace, so the honest
answer for a host is "nothing is refusing you" — not «لست مسجّلاً عند هذا المدرّس».

**Resolve a media asset's provider from its own `provider` column, beside the
binding — not instead of it.** An upload ticket has no row to read. `SC-011`
needs two assets for two providers in one database; one asset cannot see the bug.

**`videos/fetch` DOES return the video's id** — verified live 2026-08-17;
the OpenAPI `StatusModel` is wrong and the narrative docs page was right. Read
it and save it inside `ingestFromUrl`. **The title stays the RECOVERY key** for a
response that never came back, so changing `BUNNY_TITLE_PREFIX` still orphans
every asset not yet recovered. Every call to `fetch` creates another paid video —
so never deliver twice for one session.

**Bunny MIRRORS the origin's status.** `403 {"success":false,"message":"Origin
returned HTTP 403…"}` is the SOURCE refusing Bunny — retryable, and the retry
signs a fresh url. Only 401 (PascalCase `Message`) and a 400 carrying `errors`
are permanent; classifying an origin mirror as permanent loses the recording.

**`token_path` or the segments are public — and it is SIGNED as well as sent.** Sign
the video's DIRECTORY with the advanced scheme: `HS256-` + Base64URL(HMAC-SHA256(key,
`signature_path` + `expires` + `token_path=`+`signature_path`)). Only `token` and
`expires` are excluded from `signing_data`. Put the token in the PATH
(`/bcdn_token=…`), never the query: a relative segment URI inherits the base query
only when its own path is empty, and an HLS master playlist's references are not.
A test that asks for the playlist and stops passes over both defects; so does one
that asserts the token's shape. Pin it to the vendor's published vector
(`BunnyTokenVectorTest`).

**Send our own route as `manifest_url`.** A redirect provider's manifest url
carries `provider_asset_id`, which FR-011 forbids in a payload.

**`Http::fake()` appends and the first match wins.** One closure fake driven by
state — never re-fake inside a test. `Http::preventStrayRequests()` where the
requirement is that nothing is called.

**Provider secrets in the environment, ceilings in `platform_settings`.** A row
in that table is readable by anyone who can open the admin panel.

**The npm advisories are accepted, not fixed** — they only move with Next 16.
Unreachable here **by call-site discipline, not by config**: `next/image` optimises
relative and same-origin paths with no `images` config at all, so the guard is that
all three call sites pass literal `/public` paths and no user upload ever reaches it.
⚠️ **Passing any user-supplied or remote image to `next/image` makes the sharp
advisory live** — that is the trigger, and `remotePatterns` is only one way to do it.
Re-count the advisories before quoting a number; the list has grown once already.
