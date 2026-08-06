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
