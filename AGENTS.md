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
```

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
| Notifications | `app/Modules/Notifications/` | (Laravel notifications + listeners) |
| Marketplace | `app/Modules/Marketplace/` | TeacherProfile, TeacherApplication, Subject, GradeLevel, AvailabilitySlot, Review, Complaint |
| Analytics | `app/Modules/Analytics/` | (Filament widgets) |
| CMS | `app/Modules/CMS/` | Article, Category, Tag |
