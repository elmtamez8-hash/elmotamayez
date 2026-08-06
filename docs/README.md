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
| Identity | `app/Modules/Identity/` | User, StudentProfile, UserSecuritySettings, Device, AuthSession, ParentStudentRelation | Auth (register/login/me/change-password), sessions & devices, two-factor |
| Tenancy | `app/Modules/Tenancy/` | Workspace, WorkspaceMember, Invitation | Workspaces (CRUD, switch, members, invitations) |
| Courses | `app/Modules/Courses/` | Course, Section, Chapter, Lesson | Courses + sections/chapters/lessons CRUD |
| Learning | `app/Modules/Learning/` | Enrollment, LessonProgress, ProgressHistory | Enrollments (enroll, lesson access, complete) |
| Assessments | `app/Modules/Assessments/` | Exam, Question, QuestionOption, Attempt, Answer | Exams CRUD + questions CRUD + attempts |
| Certificates | `app/Modules/Certificates/` | Certificate, CertificateTemplate | Certificates (list, verify, regenerate) + templates CRUD |
| Payments | `app/Modules/Payments/` | Order, Product, PaymentTransaction | Orders (create, receipt, approve, reject) |
| Media | `app/Modules/Media/` | MediaAsset, MediaCaption, PlaybackGrant | Upload tickets + playback grants (issue/stream/renew) + captions |
| Notifications | `app/Modules/Notifications/` | Notification, NotificationDelivery, NotificationPreference, MessageTemplate, ContactVerification | Notification centre + preferences + contact verification |
| Analytics | `app/Modules/Analytics/` | (Filament widgets) | Admin dashboard |
| CMS | `app/Modules/CMS/` | Article, Category, Tag | Articles CRUD + publish |
| Marketplace | `app/Modules/Marketplace/` | TeacherProfile, TeacherApplication, Subject, GradeLevel, AvailabilitySlot, Review, Complaint | Public listings (no auth) + teacher application + academic review + reviews/complaints |
| LiveSessions | `app/Modules/LiveSessions/` | ClassSession, SessionBooking, Attendance, ClassSessionFeedback, FreezePeriod | Calendar + booking + broadcast room + register + freeze periods |

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


## Video Pipeline (spec 004)

Providers sit behind `Media\Contracts\VideoProviderInterface`. Adding one is a file in
`Modules/Media/Providers/` and a case in `MediaServiceProvider` — nothing in `Actions/`,
`Models/` or the frontend changes. `LocalVideoProvider` is the only implementation today
and needs no external account: the commercial choice is deliberately deferred, and what
that costs is listed in the feature plan rather than glossed over.

`ProviderContractTest` runs every registered implementation through the same set, including
the rule that a provider claiming adaptive bitrate must actually report more than one
rendition. `ProviderAgnosticTest` fails the build if any vendor name appears outside
`Modules/Media/Providers/`.

### Playback

| Endpoint | Auth | Notes |
|---|---|---|
| `POST /lessons/{lesson}/playback` | sanctum + `throttle:playback` | Issues a short-lived grant bound to the current session. 409 when the video is still processing — the viewer is entitled, it is simply not ready |
| `GET /playback/{grant}/stream` | **none** | A `<video>` element cannot send a bearer token. The guard is the grant row, re-checked on every range request |
| `POST /playback/{grant}/renew` | sanctum | Extends the grant and saves the viewing position. Also how the client discovers its session was ended elsewhere |
| `POST /lessons/{lesson}/assets` | `LESSONS_MANAGE` | Returns an upload ticket. The client PUTs to wherever it points |
| `POST /media/assets/{asset}/complete` | `LESSONS_MANAGE` | Settles the type from the file's own bytes, never from its name |
| `DELETE /media/assets/{asset}` | `LESSONS_MANAGE` + `2fa.required` | Destroys uploaded work irreversibly |
| `POST /media/assets/{asset}/captions` · `DELETE /media/captions/{caption}` | `LESSONS_MANAGE` | WebVTT, parsed before it is stored — a file that would render an empty track is refused here rather than discovered mid-lesson |
| `GET /playback/{grant}/captions/{caption}` | **none** | A `<track>` element sends no bearer token either. Same grant, same guard, same expiry as the video |

`MediaAssetReady` fires when an asset settles to `ready`. Nothing listens yet: it exists so
a provider webhook and the local `complete` call arrive at the same event, rather than the
webhook growing its own copy of the follow-up work.

**The transcript is derived, never stored.** `media_captions` holds a WebVTT path and
nothing else; `WebVtt::parse()` validates and `TranscriptPanel.tsx` re-derives in the
browser from the file the `<track>` already fetched. A transcript column would be a second
copy of the same words, and the two drift at the first typo fix.

**No new permissions.** Managing an asset is managing lesson content (`LESSONS_MANAGE`);
watching is entitlement, not permission, and lives in `IssuePlaybackGrant`. Devices,
sessions and two-factor are own-row ownership — a teacher never reads them, enrolment or
not.

### Sessions and devices

| Endpoint | Auth | Notes |
|---|---|---|
| `GET /auth/sessions` · `DELETE /auth/sessions/{uuid}` | sanctum | Own rows only |
| `GET /auth/sessions/{uuid}/end-reason` | **none** | Asked after the token is gone, so it cannot be authenticated. Two fields, no PII |

The device limit lives in `platform_settings` (`auth.device_limits`, default
`{"student": 1}`) so an operator can tune it without a deploy. It counts **distinct
devices** among live sessions, not sessions: two sign-ins on one laptop are one machine.

### Two-factor authentication

| Endpoint | Auth | Notes |
|---|---|---|
| `POST /auth/2fa/setup` · `DELETE /auth/2fa` | sanctum + `throttle:two-factor` | Both re-ask for the current password; the delete also needs a live code |
| `POST /auth/2fa/confirm` | sanctum + `throttle:two-factor` | Returns the recovery codes **once** — they are stored hashed, so no endpoint can show them again |
| `GET /auth/2fa` | sanctum | State only: never the secret, never the codes |
| `POST /auth/2fa/recovery-codes` | sanctum + `throttle:two-factor` | A new set; the old set stops working immediately |
| `POST /auth/2fa/challenge` | **none** | The second half of signing in — no token exists yet |

A correct password on an enrolled account returns `{two_factor: true, challenge}` and **no
token**. The challenge lives ten minutes in the cache, not a table. The exchange goes
through `StartAuthSession` like any other sign-in, so the device limit and its alert apply.

The secret sits on `user_security_settings` and `User` implements Filament's
`HasAppAuthentication` by hand — its traits assume columns on `users` — so one enrolment
serves both `/admin` and the API.

`2fa.required` is applied **route by route**, never to a group: order approve/reject,
workspace update, member removal and invitation, teacher approve/reject, asset delete.
Before `two_factor_required_at` it passes and the screen nags; after it, `403` with
`code: two_factor_required`.

### Breaking changes

- `LessonResource.media` → `LessonResource.asset` (uuid, status, duration — never the
  provider or its path). No frontend consumer existed, so the change lands before one does.
- `UserResource.grade_level_slug` / `registered_by_parent` → nested under `student_profile`.
- `POST /auth/login` now also returns `session_uuid`, which the client keeps to ask why it
  was later signed out — and returns `{two_factor: true, challenge}` **instead of** a token
  for an enrolled account. A client that assumed `token` is always present must narrow.
- `PlaybackGrantResource.captions[]` gained `url`, which points through the grant. There is
  no caption URL that outlives it.


## Live Sessions and Attendance (spec 005)

The model is `ClassSession`, never `Session`: `AuthSession` (004) already owns that word,
and two things called Session in one product is a line every reader misreads once. The
frontend follows — `lib/class-sessions.ts` next to `lib/sessions.ts`.

Broadcast providers sit behind `LiveSessions\Contracts\BroadcastProviderInterface` with an
explicit `BroadcastCapabilities` declaration, the same shape as `VideoProviderInterface`
(004) and `PaymentProviderInterface`. `NullBroadcastProvider` is the only implementation
today; `BroadcastProviderContractTest` holds each one to exactly what it claims, which is
what makes deferring the commercial choice safe rather than merely convenient.

**Attendance never passes through the provider.** The register is built from a heartbeat
hitting our own route, and the server does the arithmetic:

```
stay_seconds += min(now − last_ping_at, 2 × presence_interval)
```

Three properties fall out of that one line: two devices do not double the time, leaving and
returning aggregates into one stay, and a long silence is capped at two intervals rather
than credited as attendance. It is also why the whole of attendance was buildable and
provable before any broadcast contract existed.

### Endpoints

| Method | Path | Guard |
|---|---|---|
| GET | `/schedule` · `/schedule/next` | sanctum — the student's own timetable across **every** teacher they study with |
| GET | `/class-sessions` · `/class-sessions/{uuid}` | `sessions.view` |
| POST | `/class-sessions` · `/class-sessions/generate` | `sessions.manage`, `throttle:sessions` |
| PUT | `/class-sessions/{uuid}` | `sessions.manage` — refuses a `type` change once a seat is taken |
| POST | `/class-sessions/{uuid}/cancel` | `sessions.manage` |
| POST | `/class-sessions/{uuid}/book` · DELETE `/bookings/{uuid}` | Student's own seat |
| POST | `/class-sessions/{uuid}/join` | Seat or `sessions.host`. One 403 for every refusal — a refusal that distinguishes them tells the caller the session exists and when to come back |
| POST | `/class-sessions/{uuid}/leave` | Ticket holder |
| POST | `/class-sessions/{uuid}/presence` | Ticket holder, `throttle:presence` — its own limiter, since one participant sends two a minute |
| POST | `/class-sessions/{uuid}/host/{action}` | `sessions.host`. `501` when the provider cannot do it — the honest answer, not a 500 |
| GET | `/class-sessions/{uuid}/attendance` | `sessions.view`, workspace-scoped |
| POST | `/attendances/{uuid}/override` | `attendance.override`; past the edit window it takes `settings.update` |
| POST | `/class-sessions/{uuid}/feedback` | `sessions.manage` — writing on a student's record is not something a reader gains by being able to read |
| GET/POST/DELETE | `/freeze-periods` | `freeze.manage` |

### Permissions

`sessions.view` · `sessions.manage` · `sessions.host` · `attendance.view` ·
`attendance.override` · `freeze.manage`. Students hold `sessions.view`; assistants add
`attendance.view`; the other four are the teacher's.

### Events

`SessionScheduled` · `SessionCancelled` · `SessionCompleted` · `SessionDelivered` ·
`AttendanceConfirmed` · `AttendanceOverridden`.

**`SessionCompleted` is not `SessionDelivered`.** Two events rather than one with a flag,
because a flag makes the condition optional for the listener. Completion is "the session
ended"; delivery is "the teacher joined, stayed long enough, and it ended normally", and
only delivery carries the frozen seat count that spec 006 will bill against.

**`billable_seats` is written once**, at the cancellation deadline, and never recomputed
(FR-060): it answers a question about a moment that has passed. A consumer must read the
column, never derive it from live bookings.

### `attendance_rate` means the teacher's attendance

`teacher_profiles.attendance_rate` is the share of countable sessions the teacher actually
**delivered** — not their students' attendance. A student's absence never touches it. That
reading is not the obvious one from the column name, which is why it is written here, in
`SyncTeacherCountersJob`, and in a test that fails if anyone changes it: marking a teacher
down for an unreliable student would let one person lower a profile the marketplace ranks
on.

### Recordings

A finished session's recording is published as an ordinary `Lesson` carrying
`class_session_id`, so it inherits every protection built in 004 — the short-lived grant,
the watermark, the renewal loop, the refusal after expiry — without a line of new security
code. Who may watch is one branch in `IssuePlaybackGrant`, and it sits **above** the
workspace-membership shortcut: every enrolled student is a member of their teacher's
workspace, so membership alone would hand the whole cohort an hour only its seats paid
for. A late cancellation still entitles — the seat was counted.

Recordings land in a per-course «تسجيلات الحصص» section created on demand. A session with
no course is marked `no_course` rather than having a course invented for it.

### Freeze periods

A freeze writes nothing to attendance rows, counters or streaks. It is **read** by
scheduling, booking, `MarkAbsenteesJob` and `SyncTeacherCountersJob` — which is why
resuming afterwards cannot fail: there is nothing to undo. Sessions already booked inside
one are **suspended, not deleted**, their seats released, and every seat holder told.

### Rate limiters

`throttle:sessions` (writes) and `throttle:presence` (the heartbeat), both named in
`AppServiceProvider::registerRateLimiters()`. Inline limits stay banned.
