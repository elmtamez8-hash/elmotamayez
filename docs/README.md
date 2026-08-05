# Mteatch — Educational Multi-Tenant SaaS Platform

> **Roadmap**: [`roadmap.md`](./roadmap.md) — the Madarik requirements document mapped to 12
> phased spec-kit features (`specs/002` … `specs/013`), with a coverage matrix and the
> architectural decisions behind the ordering.

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
| Notifications | `app/Modules/Notifications/` | Notification, NotificationDelivery, NotificationPreference, MessageTemplate, ContactVerification | Notification centre + preferences + contact verification |
| Analytics | `app/Modules/Analytics/` | (Filament widgets) | Admin dashboard |
| CMS | `app/Modules/CMS/` | Article, Category, Tag | Articles CRUD + publish |
| Marketplace | `app/Modules/Marketplace/` | TeacherProfile, TeacherApplication, Subject, GradeLevel, AvailabilitySlot, Review, Complaint | Public listings (no auth) + teacher application + academic review + reviews/complaints |

### Marketplace endpoints

Public — **no authentication**, throttled 60/min per IP. `WorkspaceScope` contributes
nothing on these requests; the guard is `publiclyListed()` inside each Action.

| Method | Path | Notes |
|---|---|---|
| GET | `/marketplace/home` | Stats + featured teachers/courses + subjects + testimonials |
| GET | `/marketplace/stats` | Platform counters |
| GET | `/marketplace/subjects` · `/marketplace/grade-levels` | Taxonomy, matched by slug across workspaces |
| GET | `/marketplace/teachers` | Filters: subject, grade_level, price, min_rating, min_trust_score, language, available_now, q; sorts: rating_desc, price_asc, trust_desc |
| GET | `/marketplace/teachers/{uuid}` | One 404 for missing / unapproved / unlisted / withdrawn |
| GET | `/marketplace/courses` | Filters: subject, grade_level, type, price; sorts: popular, price_asc, newest |

Authenticated:

| Method | Path | Guard |
|---|---|---|
| POST | `/auth/register/student` · `/auth/register/parent` · `/auth/register/teacher/step-1` | Public, `throttle:10,1` + `idempotent` |
| GET/PUT | `/teacher/application`, `/teacher/application/step-2..4` | Applicant's own token |
| POST | `/teacher/application/submit` | Applicant, `idempotent` |
| GET/POST | `/admin/teacher-applications`, `.../approve`, `.../reject`, `.../request-changes` | `marketplace.teachers.review` / `.approve` |
| POST | `/admin/teachers/{uuid}/suspend` · `/reinstate` | `marketplace.teachers.suspend` |
| PUT | `/workspace/marketplace-participation` | `marketplace.participation.manage` |
| POST | `/teachers/{uuid}/reviews` | Student with a completed session; 201 create / 200 update |
| DELETE | `/admin/reviews/{uuid}` | `marketplace.reviews.moderate` — hides, never deletes |
| POST | `/admin/complaints/{uuid}/confirm` · `/dismiss` | `marketplace.complaints.manage` |
| GET/POST | `/parent/children`, `/parent/children/{uuid}` | Own links only (`ParentChildLinkPolicy`, 403 not 404) |
| GET/PUT | `/parent/notification-preferences` | Own preferences |

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

### Marketplace permissions

Constants in `Tenancy\Support\Permissions` — never string literals.

| Permission | Grants |
|---|---|
| `marketplace.teachers.review` | List and read teacher applications |
| `marketplace.teachers.approve` | Approve, reject, request changes |
| `marketplace.teachers.suspend` | Suspend and reinstate a listed teacher |
| `marketplace.reviews.moderate` | Hide a review (`is_visible = false`) and trigger recalculation |
| `marketplace.complaints.manage` | Confirm or dismiss a complaint |
| `marketplace.participation.manage` | Toggle the workspace's marketplace participation |

### Notification permissions

| Permission | Grants |
|---|---|
| `notifications.logs.view` | Read the delivery log in the admin panel (super admin) |
| `notifications.templates.manage` | Edit message wording without a deploy (super admin) |
| `relations.view.student` | May *ever* read a student's guardians — teacher and assistant |

`relations.view.student` is **not sufficient on its own.** `parent_student_relations`
is platform-owned and carries no `workspace_id`, so nothing scopes it implicitly.
`ParentStudentRelationPolicy` additionally requires the student to hold an **active
enrollment in the reader's own workspace** (Constitution I, teacher-visibility
guard). The permission answers "may this role ever look?"; the policy answers "at
this student?". Both, or 403.

### Notification endpoints

All behind `auth:sanctum`; there is no public route in this module.

| Method | Path | Notes |
|---|---|---|
| GET | `/notifications` | Feed, newest first. `?unread=1` · `?workspace={uuid}` · `?type=` |
| GET | `/notifications/unread-count` | Served by the `(recipient_user_id, read_at, id)` index |
| POST | `/notifications/{uuid}/read` | Idempotent. 404 (not 403) for someone else's row |
| POST | `/notifications/read-all` | One UPDATE |
| GET | `/notifications/types` | Types + **implemented** channels only |
| GET·PUT | `/notifications/preferences` | 422 on a mandatory type or an unimplemented channel |
| PUT | `/notifications/quiet-hours` | Both ends or neither |
| GET·POST | `/family/relations` | Guardians; POST refuses a second active parent |
| GET·PATCH·DELETE | `/family/relations/{uuid}` | DELETE revokes, never deletes |
| POST | `/contact-verifications` | `throttle:contact-verification` — never an inline limit |
| POST | `/contact-verifications/{uuid}/confirm` | Code is hashed; never returned over HTTP |

`?workspace=` is a **filter, not a scope**: omitting it returns the whole feed
across every teacher, which is what lets one parent follow one child in one place.

### Notification events

`NotificationRequested` → `NotificationQueued` → `NotificationDelivered` |
`NotificationFailed`. Everything after dispatch runs in a queue worker, so this
chain is the only window into a message that never arrived.

Channels sit behind `Notifications\Contracts\NotificationChannelInterface`. Adding
one is a class plus a `->tag('notification.channels')` line — no listener, action
or type changes. `ProviderAgnosticTest` fails the build if any `Actions/` file
names a channel or provider.

### Platform roles (orthogonal to workspace roles)

`users.platform_role` (`student` · `teacher` · `parent`, nullable) marks accounts
created through the marketplace signup paths. They belong to **no** workspace and
hold **no** spatie role — `/register` remains the academy path and is unaffected.

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
