# Mteatch — Educational Multi-Tenant SaaS Platform

## Architecture Overview

**Monorepo structure:**
- `backend/` — Laravel 13 (PHP 8.5), modular monolith
- `frontend/` — Next.js 15 (TypeScript, Tailwind CSS)
- `docs/` — Design artifacts and documentation
- `docker/` — Production Docker configuration

**Multi-tenancy:** Single database + `workspace_id` global scope. Each tenant (workspace) has isolated data via `BelongsToWorkspace` trait + `WorkspaceScope`. spatie/permission in team mode (`team_id = workspace_id`).

## Module Map

| Module | Path | Key Models | API Endpoints |
|---|---|---|---|
| Identity | `app/Modules/Identity/` | User | Auth (register/login/me/change-password) |
| Tenancy | `app/Modules/Tenancy/` | Workspace, WorkspaceMember, Invitation | Workspaces (CRUD, switch, members, invitations) |
| Courses | `app/Modules/Courses/` | Course, Section, Chapter, Lesson | Courses + sections/chapters/lessons CRUD |
| Learning | `app/Modules/Learning/` | Enrollment, LessonProgress, ProgressHistory | Enrollments (enroll, lesson access, complete) |
| Assessments | `app/Modules/Assessments/` | Exam, Question, QuestionOption, Attempt, Answer | Exams CRUD + questions CRUD + attempts |
| Certificates | `app/Modules/Certificates/` | Certificate, CertificateTemplate | Certificates (list, verify, regenerate) + templates CRUD |
| Payments | `app/Modules/Payments/` | Order, Product, PaymentTransaction | Orders (create, receipt, approve, reject) |
| Notifications | `app/Modules/Notifications/` | (Laravel notifications + listeners) | Event-driven |
| Analytics | `app/Modules/Analytics/` | (Filament widgets) | Admin dashboard |
| CMS | `app/Modules/CMS/` | Article, Category, Tag | Articles CRUD + publish |

## Authentication

- **Backend:** Laravel Sanctum (bearer tokens for API)
- **Frontend:** Token stored in localStorage, sent as `Authorization: Bearer <token>`
- **Admin panel:** Filament v5 (session-based auth, restricted to super-admins + staff roles)

## Roles & Permissions

| Role | Scope | Key Permissions |
|---|---|---|
| super-admin | Global | All permissions, bypasses workspace scope |
| tenant-owner | Workspace | Full workspace management + settings + members |
| teacher | Workspace | Course/exam CRUD + publish + approve payments + certificates |
| assistant-teacher | Workspace | Course/exam CRUD (no publish/delete) + view all |
| student | Workspace | View courses, enroll, take exams, view own certificates/orders |

## Development Setup

### Backend
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve          # API at :8000
php artisan queue:listen   # Queue worker
```

### Frontend
```bash
cd frontend
npm install
npm run dev                # App at :3000 (proxies /api to :8000)
```

### Default Users (after seeding)
- **Super Admin:** admin@example.com (password printed in console)
- **Teacher:** teacher@example.com / password
- **Student:** student@example.com / password

## Testing
```bash
cd backend
php vendor/bin/pest              # 100 tests
php vendor/bin/pest --coverage   # With coverage
./vendor/bin/pint --test         # PSR-12 style check
./vendor/bin/phpstan analyse     # Larastan level 8
```

## API Documentation
```bash
cd backend
php artisan scribe:generate      # Generates OpenAPI/Scribe docs at /docs
```
API docs available at `http://localhost:8000/docs` after generation.
