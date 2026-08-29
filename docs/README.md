# Elmotamayez (المتميز) — Educational Multi-Tenant SaaS Platform

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
| Courses | `app/Modules/Courses/` | Course, Section, Chapter, Lesson | Courses + the authoring tree: nodes, ordering, publish batches, impact preview |
| Learning | `app/Modules/Learning/` | Enrollment, LessonProgress, ProgressHistory, Cohort, CohortMembership, CohortMembershipEvent, CohortTransferRequest | Enrollments (enroll, lesson access, complete) + the curriculum tree with a reason on every row + groups (pick, join, transfer, roster) and the teacher's half (create, archive, members, decide) |
| Assessments | `app/Modules/Assessments/` | Exam, Question, QuestionOption, Attempt, Answer, Concept, ExamItem, AttemptItem, RubricCriterion, GradingRecord, Assignment, Submission, Accommodation, QuestionImport, UnlockRule, UnlockExemption | Exams + the question bank, imports, item analysis, the mistake notebook, self-generated papers, the essay grading board, homework and the unlock condition |
| Certificates | `app/Modules/Certificates/` | Certificate, CertificateTemplate | Certificates (list, verify, regenerate) + templates CRUD |
| Payments | `app/Modules/Payments/` | Order, Product, PaymentTransaction | Orders (create, receipt, approve, reject) |
| Media | `app/Modules/Media/` | MediaAsset, MediaCaption, PlaybackGrant | Upload tickets + playback grants (issue/stream/renew) + captions |
| Notifications | `app/Modules/Notifications/` | Notification, NotificationDelivery, NotificationPreference, MessageTemplate, ContactVerification | Notification centre + preferences + contact verification |
| Analytics | `app/Modules/Analytics/` | (Filament widgets) | Admin dashboard |
| CMS | `app/Modules/CMS/` | Article, Category, Tag | Articles CRUD + publish |
| Marketplace | `app/Modules/Marketplace/` | TeacherProfile, TeacherApplication, Subject, GradeLevel, AvailabilitySlot, Review, Complaint | Public listings (no auth) + teacher application + academic review + reviews/complaints |
| LiveSessions | `app/Modules/LiveSessions/` | ClassSession, SessionBooking, Attendance, ClassSessionFeedback, FreezePeriod | Calendar + booking + broadcast room + register + freeze periods |
| Settlement | `app/Modules/Settlement/` | SettlementRate, RateChangeRequest, TeachingUnit, SettlementPeriod, LedgerEntry, TeacherPayout | Teacher statement + export + units + rate requests + period close/payout + financial audit |
| Compliance | `app/Modules/Compliance/` | DataCategory, DataProcessor, DataRequest, LegalHold, RetentionSweepRun, BreachReport, TeacherOffboarding | The privacy catalogue and policy (public) + data-rights requests + the officer's queue + legal holds + breach reports + a teacher's exit |
| Community | `app/Modules/Community/` | Conversation, ConversationParticipant, Message, ModerationAction, BlockedTerm, AssistantAssignment, AssistantScope, PeriodicReview, GradingScheme, ReportCard, ReportCardSegment, Announcement, ConversationWriteBan | Private and public chat (private · session · lesson · **cohort**) + moderation, the per-thread write ban + assistants and their scopes + periodic reviews + the weighted report card + announcements |
| Store | `app/Modules/Store/` | StoreItem, StoreOrder, Shipment | The teacher's store — books and notes, digital or printed: stock, fulfilment and shipment states (spec 011 · US1) |
| Gamification | `app/Modules/Gamification/` | AwardEntry, AwardDailyCounter, StudentProgress, CoinBalance, GamificationAction, Level, Badge, BadgeAward, Reward, Redemption, FocusSession, LeaderboardEntry | The student's profile + leaderboards + the reward shop + the focus timer |

### Gamification (spec 009)

`award_entries` is the truth and everything else is derived from it: `student_progress`
is a running aggregate kept so no request has to sum the ledger, and
`leaderboard_entries` is an indexed table one command rebuilds. There is no number in
this module that cannot be proved by reading the ledger line by line.

**Three ownership layers in one module**, and the split is the phase's central decision:

| Layer | Tables | Guard |
|---|---|---|
| Platform (أ) — the student | `student_progress`, `badge_awards`, `focus_sessions` | Row ownership + an active enrolment for a teacher to read one (NFR-001أ) |
| Platform (ب) — reference data | `gamification_actions`, `levels`, `badges`, `leaderboard_entries`, and **`subjects`/`grade_levels` since 009** | The platform permissions `gamification.catalog.manage` / `taxonomy.manage`, held by no tenant role |
| Bridge / workspace | `award_entries`, `award_daily_counters`, `coin_balances`, `rewards`, `redemptions` | `workspace_id` for context; `BelongsToWorkspace` on the last three |

⚠️ **Experience is one file per person; coins are one purse per teacher.** A student with
three teachers has ONE level, streak and badge set and THREE purses — and no payload sums
them, because no sum is correct: the shop refuses coins earned elsewhere.

| Method | Path | Guard |
|---|---|---|
| GET | `/gamification/me` | The caller's own profile. Five queries whatever the number of badges and purses |
| GET | `/gamification/students/{user}` | `progress.view.student` **and** an active enrolment in the reader's workspace. A missing account answers 403, identically to one that is not theirs |
| GET | `/gamification/leaderboard?scope=&period=` | `throttle:gamification-board`. Scopes: `platform`, `grade:{slug}`, `subject:{uuid}`, `teacher:{uuid}`, `course:{uuid}`, `lesson:{uuid}` |
| GET | `/gamification/leaderboard/scopes` | Which boards the caller may open. No limiter — no parameter to enumerate, and it answers only about the caller's own active enrolments. A non-student gets `[]` |
| GET | `/gamification/shop?workspace={uuid}` | An active enrolment in that workspace |
| POST | `/gamification/rewards/{reward}/redeem` | `throttle:gamification-write`. The uuid is resolved INSIDE the Action, after the enrolment check |
| GET | `/gamification/redemptions` | The caller's own, filtered by `user_id` explicitly |
| POST | `/gamification/focus` · `/gamification/focus/{session}/end` | `throttle:gamification-write`. Own session only |
| GET/POST/PUT | `/manage/gamification/rewards…` | `rewards.manage` |
| GET/POST | `/manage/gamification/redemptions…/fulfill` · `/reject` | `redemptions.fulfill` |

⚠️ **The first three cross-workspace scopes are for STUDENTS ONLY; a teacher gets 403.** A
teacher holds no level band, so there is no natural bound on what they would see — and
opening them would hand every teacher a weekly roster of their competitors' students.

⚠️ **Two of the six scopes only mean the same thing for every teacher because 009 promoted
the taxonomy.** `subjects` and `grade_levels` carried a `workspace_id` until then, so
"الرياضيات" was a different row per teacher and the "cross-workspace" subject board was
one board per teacher wearing a platform board's name.

⚠️ **The picker is DERIVED FROM THE AUTHORISER'S OWN PREDICATE, not assembled beside it.**
The nearest data the profile screen already holds is the student's coin purses, and they
answer a different question: a teacher board is authorised on an **active enrolment**, while
a purse outlives the enrolment (a departed teacher's coins still show, deliberately) and
lags it (a fresh enrolment has earned nothing yet). Built on purses, the picker offers
boards the API answers 403 and hides boards it allows. `LeaderboardScopesTest` walks every
returned option through the real endpoint, which is what fails if the two spellings drift
apart again. The grade comes from `courses.grade_level` — the column the rollup groups on —
and not from `student_profiles.grade_level_slug`, which is what the student would call their
year and can differ. `lesson:` is deliberately not listed: it is opened from beside its
lesson, and a global picker naming every lesson of every course is a list nobody reads.

### Marketplace endpoints

Public — **no authentication**, throttled 60/min per IP. `WorkspaceScope` contributes
nothing on these requests; the guard is `publiclyListed()` inside each Action.

| Method | Path | Notes |
|---|---|---|
| GET | `/marketplace/home` | Stats + featured teachers/courses + subjects + testimonials |
| GET | `/marketplace/stats` | Platform counters |
| GET | `/marketplace/subjects` · `/marketplace/grade-levels` | Taxonomy, matched by slug across workspaces |
| GET | `/marketplace/teachers` | Filters: subject, grade_level, price, min_rating, min_trust_score, language, available_now, q; sorts: rating_desc, price_asc, trust_desc |
| GET | `/marketplace/teachers/{slug}` | Resolves a slug **or** a uuid — links shared before slugs existed are uuids; the page 308s to the canonical slug. One 404 for missing / unapproved / unlisted / withdrawn |
| GET | `/marketplace/courses` | Filters: subject, grade_level, type, price; sorts: popular, price_asc, newest |

Authenticated:

| Method | Path | Guard |
|---|---|---|
| POST | `/auth/register/student` · `/auth/register/parent` · `/auth/register/teacher/step-1` | Public, `throttle:10,1` + `idempotent` |
| GET/PUT | `/teacher/application`, `/teacher/application/step-2..4` | Applicant's own token |
| POST | `/teacher/application/submit` | Applicant, `idempotent` |
| GET | `/teacher/profile` | The signed-in teacher's own slug; `slug: null` when no listing exists yet |
| PUT | `/teacher/profile/slug` | The teacher renames their public URL. No route parameter — the profile comes from the token. `throttle:profile-slug`. Rejects a uuid-shaped value: the public lookup accepts both, so that value would shadow another teacher's URL |
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

### Commerce permissions (spec 011)

| Permission | Held by | Grants |
|---|---|---|
| `store.items.manage` | teacher | Create and price the books and notes in their own store |
| `store.shipments.manage` | teacher | Advance a printed order through its fulfilment states |
| `plans.manage` | teacher | Set which of their courses a subscription plan covers, and for how long |
| `billing.coupons.manage` | **platform only** | Mint a discount code |
| `flags.manage` | **platform only** | Turn a feature on or off, per workspace or platform-wide |

⚠️ **The last two are platform permissions, and that is declared by ABSENCE.**
`RolePermissionMatrix::platformPermissions()` is `Permissions::all()` minus
everything any tenant role holds — so a coupon permission dropped into the
teacher's array is a teacher minting a discount spent out of the platform's own
commission, and nothing in the existing panel test would say so
(`PermissionLabels::tenantMap()` is BUILT by looping `tenantPermissions()`, so it
is a derivation compared with itself). `CommercePermissionNamesTest` pins all
five literally, in both directions.

### Order kinds and who signs for the money (spec 011)

`OrderKind` has four cases: `course`, `credits`, `store`, `subscription`.

⚠️ **Only `course` is approved by the teacher.** Approving a manual transfer is
witnessing that the money arrived, and the seller must not sign for their own
receipt — `PAYMENTS_APPROVE` sits in the teacher's array and the workspace check
is a bar the seller clears by definition. So a store sale (the teacher's own
goods) is approved by the platform for exactly the reason a credit purchase is.
The condition lives on the enum (`requiresPlatformApproval()`), read by all three
of `OrderPolicy`'s `view`/`approve`/`reject`.

⚠️ **And the two list cuts are allowlists now.** `OrderController::index()` and
`OrderResource::getEloquentQuery()` were both `where('kind', '!=', Credits)` —
true of an enum with two cases and a silent widening at four. They read
`OrderKind::teacherListedValues()`, which names what belongs on a teacher's order
table rather than what does not.

### Feature flags (spec 011)

`feature_flags` carries one row per `(key, workspace_id)`, with `workspace_id = 0`
meaning the platform default. `Tenancy\Support\Flags` reads both scopes in one
query and is bound `scoped()`.

⚠️ **`0`, never `NULL`** — `NULL != NULL` in a unique index, so a nullable column
would let two platform defaults for one key coexist and the answer would be
whichever row came back first. Third time in this tree (`concept_stats.lesson_id`,
`unlock_rules.course_id`). The price is that `(int) null === 0` addresses the
default row, so any WRITE guards with `abort_if` before it runs.

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

Two are implemented: `InAppChannel` and, since spec 020, `WhatsAppChannel`. Email,
Telegram, SMS and push remain known values with no class — they are what a
preference row, a template and a delivery log may legally hold, so the shape of
the data does not change when one lands.

**Seventeen types default to WhatsApp**, derived from `NotificationType::targetsGuardians()`
rather than from a second list: these are the messages addressed to the person who
is not sitting on our site. `security_alert` is deliberately not among them — it is
mandatory and the student's own. Users add or remove the channel per type from
`/settings/notifications`, where they also prove their number; nothing reaches a
phone that has not been verified, so the tick box and the verification card are on
one screen on purpose.

**A WhatsApp message is a provider-approved template, never text we compose.** The
row in `message_templates` supplies the template NAME (`type`), the ORDER of its
parameters (`variables`) and the approval state; `body_ar` documents what was
approved and is not what the phone displays. Rows ship `pending` — see
`docs/deployment.md` for the approval checklist, and for why `contact_verification`
must be approved before any of the others.

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

### Deploying a release that adds a `NotificationType`

**Nothing extra to run — but only because a migration does it.** `plain migrate`
is the whole deployment step; `2026_08_25_000100_backfill_missing_notification_templates`
calls `NotificationTemplateSeeder::seedMissing()`, which writes the rows a live
database is missing with `firstOrCreate` and touches nothing that exists.

⚠️ **Never put `db:seed --class=NotificationTemplateSeeder` in a deployment.**
`run()` writes with `updateOrCreate`, which is correct for `migrate:fresh --seed`
and would replace every wording an admin has edited from the panel with the
shipped default, on every release, silently.

⚠️ **And a type added after that migration needs its own backfill migration** —
one line, copying that file. Without it the notification is dropped in *silence*:
`TemplateRenderer` refuses a missing template and `DispatchNotification` logs
rather than failing the operation that triggered it. Measured, not assumed —
spec 010's first live announcement reached **zero** of three students on a
database whose migrations were fully up to date, with nothing reporting a fault.
The suite cannot see it either: `tests/Pest.php` seeds the templates before every
Feature test. `tests/Feature/Notifications/TemplateBackfillTest.php` is the guard.

### `FRONTEND_URL` is load-bearing for payments

`payments.return_url_base` defaults to it, and `PaymentReturnUrl` builds the
address a gateway returns the payer to. In development one host serves both
through Next's rewrite; deployed, the API and the app are two origins and a
return URL built from `APP_URL` lands the payer on a JSON endpoint.

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
explicit `BroadcastCapabilities` declaration, the same shape as `MediaProviderInterface`
(004 — it was `VideoProviderInterface` until commit `5df9ffb`; the old name resolves to
nothing) and `PaymentProviderInterface`. There are two: `NullBroadcastProvider`, which needs
no account and no network and is what the test suite runs on, and `LiveKitBroadcastProvider`
(017), selected with `BROADCAST_PROVIDER=livekit`. `BroadcastProviderContractTest` holds
each one to exactly what it claims — which is what let the commercial choice be deferred
safely rather than merely conveniently.

`LiveKitBroadcastProvider` is the only file **under `app/`** that names the provider or
imports `Agence104\LiveKit\*` — which is exactly the scope `ProviderNameContainmentTest`
enforces, and the honest statement of the rule. Outside `app/` the name appears in
`config/sessions.php`, **`config/filesystems.php`** (the `r2` disk reuses the
`LIVEKIT_EGRESS_*` credentials), `.env`, `LiveSessionsServiceProvider` (allowlisted in
full, not merely its `match` arm), `LiveKitAdapterTest` and the contract test's dataset,
and the frontend's `BroadcastStage.tsx` plus two `package.json` dependencies. Recording is attached to
the room itself — `RoomEgress` on `createRoom`, stopped by `deleteRoom` — so there is no
`egress_id` column, no manual start/stop, and no webhook. It writes to an S3-compatible
bucket **we** own, and the file enters 004's asset pipeline from there.

⚠️ **`BROADCAST_PROVIDER=livekit` is not set in production before `MEDIA_PROVIDER=bunny`.**
The launch rule still stands, and spec 019 is what makes it satisfiable: until a media
provider can fetch a recording for itself, the ingest path downloads it onto the
application server — a gigabyte per session through one worker.

### Media providers (spec 019)

Two implementations behind `MediaProviderInterface`: `LocalMediaProvider`, which needs no
account and is what the whole test suite runs on, and `BunnyMediaProvider`, selected with
`MEDIA_PROVIDER=bunny`. `ProviderContractTest` walks both by what each one *declares*, and
`tests/Feature/Media/ProviderNameContainmentTest.php` allows the vendor's name in the adapter and at the
binding and nowhere else in `app/`.

Four things here are not obvious and each one is a defect that would ship green:

- **`ingestFromUrl()` is the only change to 004's contract.** The download did not
  disappear — it MOVED from `IngestSessionRecordingJob` into `LocalMediaProvider`, which
  is what a provider with no remote fetch has to do. Both providers declare
  `remoteFetch: true`, because the flag answers "can you take a file over from a URL", not
  "did the bytes avoid our network". That second question is `SC-001`'s, measured by
  `ZeroVideoBandwidthTest` as a **negation over the whole request list** — asserting that
  `videos/fetch` was called passes on an implementation that downloads, uploads, then calls
  it.
- **`videos/fetch` returns the video's id, and the title is the recovery key.** ⚠️ Verified
  against a live account on 2026-08-17: the 200 carries `id`, and `GET /videos/{id}` returns
  it as the `guid`. The OpenAPI schema types the response as `StatusModel` and research §R3
  built the design on that; the narrative page showed an id and was right. The id is saved
  inside `ingestFromUrl`, before anything else can fail. The title `{prefix}:{asset_uuid}`
  remains the way an asset and a video find each other when the response never came back,
  so changing `BUNNY_TITLE_PREFIX` still orphans every asset not yet recovered. It also means
  `fetch` creates a new video on *every* call, so the job refuses to deliver twice for one
  session — a retry would be a second video, billed monthly, referenced by nothing.
- **`token_path` is the whole of the playback protection.** The manifest is HLS, so a token
  on the playlist alone leaves every segment open — whoever holds one URL holds the video.
  The signature is the advanced scheme (`HS256-` + Base64URL(HMAC-SHA256)) over the video's
  **directory**, and `token_path` is part of the hashed message as well as of the URL —
  `signature_path` + `expires` + `token_path=`+`signature_path`, since only `token` and
  `expires` are excluded from `signing_data`. The token travels in the **path**
  (`/bcdn_token=…`), not the query string: a relative segment URI inherits the base query
  only when its own path is empty, which an HLS master playlist's references never are.
  Both halves are pinned to the vendor's published test vector in
  `tests/Feature/Media/BunnyTokenVectorTest.php`, because a signature has one correct value
  and no observable structure — a shape assertion is a test of the code against itself.
  The viewer's IP is deliberately not in it: a phone moving from wifi to
  mobile data would cut out mid-lesson, and the device limit answers the same question with
  a fingerprint that survives a network change.
- **An asset is resolved from its own `provider` column, not from the config.**
  `MediaProviderResolver` does that, *beside* the binding rather than replacing it — an
  upload ticket is asked for before a row exists, so there is nothing to read. Without the
  resolver, the day production flips every earlier recording is handed to the new provider
  and answered "file not found". `SC-011` therefore uses **two assets for two providers in
  one database**; one asset cannot see the bug, because a test's config always agrees with
  its own fixture.

The credentials live in the environment and never in `platform_settings` — that table is
readable by anyone who can open the admin panel, and the signing key mints valid playback
tokens. The size and duration ceilings do come from `platform_settings`, through
`MediaLimits`, which is also where the adapter's `capabilities()` reads them, so the
announced limit and the enforced one cannot drift.

⚠️ **`ReconcileAssetStatus` is scheduled as of 019, and had never run before.** Nothing
reached `Processing` and stayed there while the only provider settled an asset inside the
upload request. A provider that transcodes on its own clock makes `Processing` a state
something has to leave — same family as the retry loop 017 shipped. It now asks about
`Processing` only, never `Uploading`: bytes still arriving *here* are not a question for a
provider, and a 2 GiB PUT outlives the sweep's one-minute age check.

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
| POST | `/class-sessions/{uuid}/host/{action}` | `sessions.host`. `mute` · `remove` · `readmit` · `end` name a participant or the session; `mute-all` · `remove-all` · `lower-hands` act on the room and name nobody (`lower-hands` clears BOTH student signals — the raised hand and «لم أفهم» — which is why its button reads «امسح الإشارات»). **A removal is RECORDED** on `attendances.removed_at` and refused at the door — without it the student was back one refresh later — and `readmit` clears it, never reaching the provider. **The host is always excluded** — removing yourself leaves the room open, the recording running and nobody inside who can close it — and so is the recorder, which is a participant of its own (`kind = EGRESS`). `501` when the provider cannot do it — the honest answer, not a 500 |
| GET | `/class-sessions/{uuid}/participants` | Seat or `sessions.host`. Names, faces and badges for the uuids the provider echoes into the room — the ticket carries a uuid and never a name (`FR-006`). **Not the register**: no status, no stay, no note, because every seat holder holds this while `attendance.view` guards those |
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

## Teacher Settlement (spec 014)

What the **teacher is owed**. Deliberately not the same context as what a **student pays**:
the two share no foreign key and no query, and the only bridge is the `SessionDelivered`
event coming out of 005. `tests/Feature/Settlement/ContextIsolationTest.php` fails the build
over a violation in either direction, including a billing event consumed here.

### The one rule that decides every other

**Counts come from `teaching_units`; money comes from `ledger_entries`.** `LedgerEntryType`
carries `Deduction` and `Bonus`, which have no teaching unit behind them — so a total derived
from units omits the first manual adjustment anyone writes, and the statement stops matching
the close the same day. The ledger is the balance.

The ledger is **append-only**, enforced on the model (`LedgerEntry::booted()` throws on
`updating`/`deleting`), not only in the Action. A bulk `update()` retrieves no models and so
bypasses that guard — which is exactly why period stamping is the one sanctioned post-insert
write and nothing else does it.

### Endpoints

| Method | Path | Permission |
|---|---|---|
| GET | `/settlement/statement` | `settlement.statement.view` |
| GET | `/settlement/statement/export` | `settlement.statement.view` (CSV, UTF-8 BOM) |
| GET | `/settlement/units` | `settlement.statement.view` |
| GET | `/settlement/periods` | `settlement.statement.view` |
| GET | `/settlement/rates` | `settlement.rate.request` |
| GET | `/settlement/rate-requests` | `settlement.rate.request` |
| POST | `/settlement/rate-requests` | `settlement.rate.request` |
| POST | `/admin/settlement/rate-requests/{r}/approve` | `settlement.rate.approve` |
| POST | `/admin/settlement/rate-requests/{r}/reject` | `settlement.rate.approve` |
| POST | `/admin/settlement/units/{unit}/reverse` | `settlement.period.manage` |
| POST | `/admin/settlement/periods/{period}/close` | `settlement.period.manage` |
| POST | `/admin/settlement/periods/{period}/payouts` | `settlement.payout.execute` |
| GET | `/admin/settlement/audit` | `settlement.audit.view` |

**No endpoint takes a `teacher` parameter.** The profile comes from the bearer token via
`ResolvesOwnTeacher`, so "may I read this other teacher?" is not a question the code has to
keep answering correctly. The workspace scope alone would not do it — a workspace can hold
more than one teacher profile, and the seeded academy holds six.

### Permissions

`settlement.rate.request` and `settlement.statement.view` sit on the **teacher** role and
deliberately not on assistant-teacher: an assistant runs the classroom, they do not read the
teacher's money. The other four (`rate.approve`, `period.manage`, `payout.execute`,
`audit.view`) are platform decisions and reach super-admin alone.

### Closing and paying

Both are one atomic conditional `UPDATE` plus an affected-rows check — never `count()` then
write, and never `lockForUpdate()`, which is a **no-op on SQLite**. The loser of the race
gets `null`, not an error: re-running the cycle is the expected thing, not a failure.

Totals are **frozen** on the period row rather than derived on read, because a total that
recomputes gives a different answer after any later correction — including to a teacher
already paid against the old one. A negative net is **carried** to the next window; the
payout column is unsigned, so "carried, never paid" is a fact of the schema.

Unit claiming uses `< ends_on + 1 day`, not `<= ends_on`: `ends_on` is a DATE and
`delivered_at` a timestamp, so the second form binds midnight and silently drops every unit
taught on the closing day. Standalone ledger lines (a deduction, a bonus) carry **no** date
filter at all — an adjustment is typed after the window ended, and a date filter there would
push every deduction into the following period.

### The financial audit

`GET /admin/settlement/audit` reads `activity_log` filtered to six subject types
(`SettlementAuditSubjects`). The filter is the **shape of the query**, not a pass over its
results: `activity_log` is one shared table that billing also writes to, and asking for the
table and then removing rows is one forgotten branch away from showing the wrong context.
Platform-wide by design — the table carries no `workspace_id` column and the permission is a
platform one.

### Rate limiters

`throttle:settlement-write` on every write, named in `AppServiceProvider::registerRateLimiters()`.
Inline limits stay banned.

## Course Authoring (spec 016)

Ten structure endpoints existed from the first migration and nothing in the product called
one of them — a teacher could not create a section, a chapter or an item from any screen.
016 is that surface, and its risk is in the EDITING rather than the creating: a course tree
is written by three parties at once (the teacher, the 005 listener that publishes
recordings, and students standing inside it right now).

### The two rules everything else follows from

**Reordering is a write to ACCESS RIGHTS.** `Enrollment::accessTo()` derives what a student
may open from the triplet of section, chapter and item positions, so moving an item changes
who can reach what, immediately. Every reorder therefore sends the COMPLETE sibling list
plus `structure_version`, and `StructureVersion::claim()` — a conditional UPDATE, the seat
idiom — makes a second writer 409 instead of silently overwriting the first.

**A new node is a draft, and that is a precondition rather than a preference.** The progress
denominator counts published items, so a half-written lesson saved into a running course
would otherwise drop every enrolled student's percentage the moment it is created. Required
fields are enforced at PUBLISH (`PublishReadiness`), never at save — a draft that refuses to
save is not a draft.

### Endpoints

| Endpoint | Auth | Notes |
|---|---|---|
| `GET /courses/{course}/tree` | `LESSONS_MANAGE` | The author's tree, drafts included, with the reason each node is hidden. Separate route from the student's — a shared one with `?include_drafts=1` makes leaking an unfinished lesson a matter of forgetting a query string |
| `GET /courses/{course}/tree/publish-preview` | `LESSONS_MANAGE` | What a publish does to the students already enrolled (`FR-049`). `items` optional; without it, every draft — and the list it costed comes back in the response, which is what the client then publishes |
| `POST /courses/{course}/tree/publish` | `LESSONS_MANAGE` + `throttle:authoring` | Publish, unpublish or archive a BATCH. One request, because a section and its items become visible together and eleven calls show a student eleven half-built trees |
| `PUT /courses/{course}/sections/order` · `…/{section}/chapters/order` · `…/{chapter}/lessons/order` | `LESSONS_MANAGE` + `throttle:authoring` | The complete sibling list, in its new order, with `structure_version` |
| `POST · PUT · DELETE /courses/{course}/sections\|chapters\|lessons/…` | `LESSONS_MANAGE` (`LESSONS_DELETE` to remove) | Node CRUD. Every node is addressed by uuid — sections and chapters had no public identifier at all until 016 |
| `GET /courses/{course}/lessons/{lesson}` | `LESSONS_MANAGE` | One item in full. The tree carries no bodies, only the outline |
| `GET · PUT /courses/{course}/lessons/{lesson}/type` | `LESSONS_MANAGE` | Read what a type change costs, then do it. Two routes, because a "preview" flag on the write is one forgotten parameter away from doing the thing |
| `GET /courses/{course}/reference-targets` | `LESSONS_MANAGE` | This course's published exams and its sessions, for the two pickers |

### The impact preview computes nothing of its own

`SC-018` promises that what the teacher is shown is what happens, to the percentage point.
That only holds while there is one of each calculation, so `PreviewPublishImpact` borrows
every part of its answer: `PublishTreeNodes::resolve()` for the item list, its
`assertReady()` for the refusal, `Lesson::progressEligible()` for the denominator's fixed
half, `CourseProgress::percentage()` for the arithmetic and `ExamGateSatisfaction` for the
students an exam item is about to credit. Exactly one thing is simulated — the three status
conditions, which is what a publish changes.

Courses does not import Learning. The enrolment half goes through
`Shared\Contracts\ProgressImpact`, the same shape as `EnrollmentDirectory`.

### Item types

Ten declared in `LessonTypeRegistry`, in four families — `inline` (article, note),
`uploaded` (video, audio, pdf, file), `reference` (exam, live_session, assignment) and
`external` (link). The registry is the single source of truth for what each type IS, and
the payload carries `family` and `asset_kind` so the editor branches on the registry's
answer rather than a second copy of it in TypeScript.

`assignment` is declared and **not implemented** — spec 008 owns the entity. It is refused
by name in the Action and shown disabled with its reason in the editor: hiding it would be
silent about the plan, offering it would be a choice that saves and then does nothing.

### Progress, and the ways it used to break forever

An item that enters the denominator but can never be completed caps every enrolled student
below 100%, so `CourseCompleted` never fires and no certificate ever issues. Not eventually
— never. Six roads led there, and `Lesson::countableForProgress()` plus
`CourseStructureChanged` close them:

1. **Session recordings** — entitled by a SEAT, not by enrolment (005 `FR-030`), so a
   student without one can never open it. Excluded from the denominator and from the
   sequential prerequisite chain
2. **Exam items with no writer of their progress row** — the exam is sat from its own page,
   so `CompleteExamLessonOnSubmission` listens to `ExamSubmitted`
3. **Students who sat the exam BEFORE the teacher placed it** — no event will ever fire for
   them again, so `ExamItemOpened` triggers `CompleteExamLessonsAlreadyAnswered`
4. **Items whose referenced exam was deleted** — `ReferenceIntegrity`, applied at the READ
   rather than in a deletion listener that must be registered, must fire, and misses bulk
   deletes
5. **Archiving the last item a student had left** — remaining work reaches zero with no
   lesson left to complete, and completion is only ever decided when one is
6. **Deleting it** — the same thing through a door no publish event reaches

`progress_pct` is written when a lesson is completed and at no other moment, so anything
that moves the countable set fires `CourseStructureChanged` → `ResyncCourseProgress`.
Completion is granted there, never withdrawn (`FR-050`): `CourseProgress::sync()` writes
`status` in one direction only, so content added after a student finished lowers their
percentage and leaves their certificate alone.

### Permissions

**No new permissions.** Authoring the tree is `LESSONS_MANAGE`; removing a node is
`LESSONS_DELETE`. Destroying an uploaded file stays behind `LESSONS_DELETE` + `2fa.required`
from 004, and a type change or a re-upload that would drop one is refused rather than
allowed to become a back door onto that decision.

### Audit

Every authoring verb writes to `activity_log` through `LogsActivity` — created, renamed,
updated, deleted, published, reordered, type_changed — with the causer and the node it was
done to (`FR-056`). Deletes log BEFORE the row goes, or the subject no longer resolves. The
settlement audit filters `activity_log` by a closed six-type allowlist, so these subjects
are excluded by construction rather than by anyone remembering.

### Rate limiters

`throttle:authoring` on every write, named in `AppServiceProvider::registerRateLimiters()`.
Reads — the tree, one item, the impact preview, the reference targets — sit outside it.
Inline limits stay banned.

## Credit Billing (spec 006)

A credit is one session at one teacher's approved rate. Ten tables, three ownership layers,
and one rule that decides most of the rest: **the ledger is append-only, and every number a
student sees is either an entry in it or derived from one.**

### Endpoints

| Method | Path | Who |
|---|---|---|
| GET | `/api/v1/billing/balance` | the student's own balances, per course |
| GET | `/api/v1/billing/transactions` | their ledger, paginated |
| GET | `/api/v1/billing/children/balance` | a guardian, with `GuardianPermission::Payments` |
| GET · POST | `/api/v1/billing/consents` | what is outstanding, and accepting it |
| GET | `/api/v1/billing/packages` | the catalogue, priced for one course |
| POST | `/api/v1/billing/purchases` | starts an order; the receipt flow is unchanged |
| GET | `/api/v1/manage/billing/students` | the teacher's panel — credits, no money |
| PATCH | `/api/v1/manage/billing/students/{student}/limit` | the platform's manual exception |
| GET · PATCH | `/api/v1/manage/billing/settings` | mode, cadence, thresholds, zero-balance |
| GET · POST · DELETE | `/api/v1/manage/billing/exam-mode` | the window in which nothing defers |
| GET · POST · PATCH | `/api/v1/admin/billing/packages` | the platform catalogue (no DELETE) |
| GET · PUT | `/api/v1/admin/billing/pricing` | the platform's half of the price |
| GET | `/api/v1/admin/billing/outstanding` | read by the rate-approval screen (Q-7) |
| GET | `/api/v1/admin/billing/reconciliation` | what the nightly sweep found, and when |

Every write here carries `throttle:billing`, keyed by USER. Never `throttle:auth`: that
limiter's second bucket keys on `'email:'.$request->input('email')`, and a billing request
carries no email — so the key collapses to the constant `'email:'` and the whole platform
shares one counter.

**`credits_needed` is sent by the server, and the browser must never compute it.** The
deficit is `floor + 1 − remaining`, and the FLOOR is derived from five inputs — the balance,
the ceiling, the billing mode, an open exam window and a current terms consent. A client
holding only `remaining` and `credit_limit` can reproduce it on an ordinary day and gets it
wrong on every interesting one: inside an exam window the floor is forced to zero, and the
day new terms are published every debtor's ceiling drops. Both quote a number smaller than
the booking gate will actually demand, so the student buys what they were told and is
refused again.

It is stamped in ONE place — `WithholdingReader::stamp()`, which already resolves all five
inputs in bulk for the withheld flag itself — and read from the stamp by
`EloquentAccountStanding::creditsNeededFor()` and by `CreditBalanceResource`. The refusal
message at the booking gate, the playback refusal and the withholding notification all print
that same number, which is the point: three surfaces quoting a figure each derived
separately is three chances to disagree.

### Permissions (eight, and six of them are PLATFORM-level)

| Constant | Reaches |
|---|---|
| `billing.balance.view` | the teacher's panel of their own students |
| `billing.settings.manage` | the workspace's billing mode and thresholds — **platform** |
| `billing.exam_mode.manage` | opening and closing the exam window |
| `billing.purchase.approve` | approving a credit purchase — **platform**, not the teacher |
| `billing.credits.adjust` | a bonus, a correction, a refund — **platform** |
| `billing.limit.manage` | moving a credit ceiling by hand — **platform** |
| `billing.packages.manage` | the credit catalogue — **platform** |
| `billing.pricing.manage` | the platform's fees, and the reconciliation report — **platform** |

The six platform permissions are held by no tenant role. A package a teacher could define
is a sale price a teacher sets, which FR-021ب forbids; a ceiling a teacher could raise is a
teacher deciding how much the platform may be owed.

⚠️ **`billing.settings.manage` is platform-level too, and that is a change.** It was on the
workspace owner until the post-006 review: since 014 the teacher is paid from DELIVERY, so
the credit a student owes is owed to the PLATFORM — and the collection mode, the reminder
thresholds and the withholding ceiling decide how much the platform may be owed and when it
stops lending. A teacher switching their own workspace to `remind` was a teacher granting
credit against someone else's balance sheet.

**`finance-admin` is the one delegated platform role** (`Roles::FINANCE_ADMIN`), carrying
`billing.purchase.approve` and `orders.view_all` — receipt approval and nothing else. It is
seeded from `RolePermissionMatrix::map()` alongside `super-admin` by
`Roles::platformRoles()`, and like `super-admin` it is **not assignable yet**: spatie's
`model_has_roles.team_id` is NOT NULL and part of the composite primary key, so a role with
no team cannot be attached to a user. Two tests in `PlatformBillingRolesTest` are skipped
with that reason written on them. Choosing the mechanism (a column beside `is_super_admin`,
a `platform_staff` table, or altering spatie's key) is an open decision, not an oversight.

⚠️ **`billing.credits.adjust` has no HTTP surface yet.** `AdjustCredits` is reachable from
tests and from code, and the permission exists, but no route or panel page calls it — a
correction today is a tinker-level operation. Recorded here rather than left for someone to
discover: the gap is in the SURFACE, not in the rule, and the Action already enforces the
mandatory reason and the idempotency key that a future screen would need.

### The catalogue is seeded, and edited from `/admin`

**`credit_packages` shipped empty, and an empty catalogue is a closed loop.** No package
means no purchase; no purchase means no credits; and the default mode is prepaid, so on a
fresh install every booking is refused with the remedy unreachable. Two things close it:

- **`CreditPackageSeeder`** — six sizes, in the unconditional reference block of
  `DatabaseSeeder` beside the roles and the notification templates. `firstOrCreate` **on the
  name alone**: these rows are reference data at birth and operator data ever after, so a
  re-seed must not undo a size or a label an operator has since edited. `validity_days` is
  null — expiry is off at launch (Q-5). `ScenarioSeeder` calls it rather than creating its
  own, so the demo data and a fresh install show the same catalogue.
- **`Payments/Filament/Resources/CreditPackageResource`** — the platform's screen for it,
  gated by `billing.packages.manage`. **No price field anywhere on the form**: the price is
  derived (`teacher rate + platform constants`), and a typed total is a second answer that
  drifts from the formula. **And no delete, refused at the Resource as well as the policy** —
  `BasePolicy::before()` waves super-admin past every policy, so a refusal written only in
  `CreditPackagePolicy::delete()` still renders a working button. Retirement is
  `is_active = false` (FR-019); purchases and credits still being consumed point at the row.

**`taxonomy.manage` was declared in 009 and checked in no file for the whole phase.** The
promotion migration named it, `CatalogPermissionTest` proved it was classified
platform-level, and both stayed green while `subjects` and `grade_levels` had no policy and
no screen — because `RolePermissionMatrix::platformPermissions()` derives the platform set
by **absence**, which is a property that can be perfectly true of a permission nobody calls.
`Marketplace/Policies/TaxonomyPolicy` and the two `Marketplace/Filament/Resources` screens
close it, and `TaxonomyPermissionTest` asserts **both directions** — a workspace owner fails
every write, a super admin passes — because a deny-only test passes just as well against a
`Gate::policy()` line that was never added. It has to be registered explicitly: one policy
serves two models, so Laravel's guesser looks for `SubjectPolicy`/`GradeLevelPolicy`, finds
neither, and fails **open** into "no policy applies".

⚠️ **And the slug is immutable on the form.** It is a denormalised join key in three places
and nothing in the database defends any of them: `courses.grade_level`,
`student_profiles.grade_level_slug`, and every stored `grade:{slug}` leaderboard key.
Editing it splits one board into two and files a course under a stage the marketplace can no
longer name — silently. Saving flushes `MarketplaceCache`, on the **model** rather than in
the Filament page, so a seeder or a later endpoint gets it too; without it a subject retired
because it is wrong keeps being offered for up to a minute and a corrected name reads as a
save that did not take.

A module's Filament resources need their own `discoverResources()` line in
`AdminPanelProvider` — there is no scan across `app/Modules/*/Filament`, so a new module's
screens are invisible until that line exists. Discovery **skips abstract classes**, which is
what lets `TaxonomyResource` hold the form and the table for the two concrete screens.

### Scheduled sweeps

| When | Job | Why a sweep rather than a listener |
|---|---|---|
| `:05,:20,:35,:50` | `ChargeUnbilledDeliveriesJob` | a delivery that was never charged fires no event |
| 04:25 daily | `EvaluateCreditLimitsJob` | nothing fires on the fourteenth day of owing |
| 04:35 daily | `ExpireCreditLotsJob` | finds nothing until an operator sets a validity (Q-5) |
| 04:45 daily | `ReconcileCreditBalancesJob` | after every sweep that moves a balance |
| Sunday 05:00 | `NotifyDormantBalancesJob` | the boundary is months; nightly would be nagging |
| every 15 min | `RetryPendingRecordingsJob` (017) | **nothing re-sent the ingest at all** — see below |
| every 5 min | `ReconcileAssetStatus` (004, scheduled in 019) | a provider transcodes on its own clock; it had never run |

⚠️ **`RetryPendingRecordingsJob` closes a hole that was silent since 005.**
`IngestSessionRecordingJob::giveUpOrRetry()` increments the counter, writes
`recording_status = 'pending'` and returns — no `release()`, no second dispatch — and the
only sender was `SessionCompleted`, which fires once. So the first "not finished yet" was
also the last attempt. It stayed invisible because `NullBroadcastProvider` declares
`recording: false`, so the job returned before reaching that branch; 017 is what switches
it on. The cost was never a stuck badge: `Settlement\Support\PackageCompletion` reads the
same column and withholds a teacher's fee for any session whose recording is not one of
`published`, `failed`, `no_course` — `no_course` releases it too, since a session with no
course has no lesson to publish into. ⚠️ And `null` and `ingesting` are both in the
**withholding** set while the sweep re-sends for `'pending'` alone, so a session whose
ingest job died before writing anything holds a wage with nothing left to move it.

**All of them land on the `maintenance` queue with `withoutOverlapping()`, and both halves
matter.** (The queue is the second argument to `Schedule::job()`, not a `->onQueue()` call —
`->onQueue()` on a scheduled job object is a different thing and is not what is written.)
⚠️ **And `withoutOverlapping()` on `Schedule::job()` locks the DISPATCH, not the queued
run** — so it does not stop two dispatches of the same work from being processed
concurrently, which is why `IngestSessionRecordingJob`'s own guard cannot rely on it.
`ChargeUnbilledDeliveriesJob` runs every fifteen minutes: one run overrunning its own window
starts a second over the same rows, and while the charge itself is safe (the unique index
refuses the duplicate), the SCAN doubles — so the lateness that caused the overlap feeds
itself. Two `ReconcileCreditBalancesJob` runs in one night write two "run" rows and make
"the last run" ambiguous, which is the one thing the reconciliation endpoint reports.

The queue is separate because these are long scans and the charge listener is queued too:
on `default` a nightly reconciliation walking every balance sits in front of a student's
withholding notification. **`maintenance` needs a Horizon supervisor to exist in every
environment it runs in** — `environments` decides which supervisors are STARTED, and
`defaults` only supplies shared values, so a queue named there but absent from
`environments.production` is a queue whose jobs enqueue and are never drained, silently.
`supervisor-maintenance` is declared with `maxProcesses: 1` (the overlap guard again, one
worker deep) and `nice: 10`, so a scan yields to request-path work.

### Reconciliation, and why the naive check is blind

`ReconcileCreditBalancesJob` asks three questions, and the first one alone would prove almost
nothing: the balance and its entries are written by the SAME path inside the SAME
transaction, so a session that was never charged at all leaves them in perfect agreement.
The two that see it come from outside the ledger — a charged session must carry one
consumption entry per seat it was taught to, and a positive balance must equal what its lots
still hold. Nothing is repaired automatically: a sweep that silently corrected a balance
would destroy the evidence, and the append-only ledger has no shape for an undo.

The read endpoint reports the LAST RUN TIME alongside the findings, because "no findings"
and "the sweep stopped on Tuesday" are otherwise the same empty list.

### Recorded consents, and how long they are kept

`terms_consents` is the record of somebody agreeing to owe. It stores the SIGNER, the
STUDENT they signed for, the document, the version in force at the time, the timestamp, the
IP address and the user agent. FR-048 makes it the gate on every credit limit above zero,
and FR-049 makes the version part of the question rather than a note beside it.

**Retention: kept for the life of the account, and excluded from spec 013's erasure.** It is
the evidence of a legal commitment, not a record of behaviour — a deleted consent turns
every session taken on credit into a debt nobody can show was agreed to. When 013 lands, its
erasure must skip this table and say so in its own spec; it is called out here because a
sweep written from a list of tables is a sweep that will otherwise include it. The
data-processing consent 013 introduces is stored in the SAME table, under a different
`document`, and never substitutes for this one in either direction (FR-050).

**Publishing new terms is a settings change, not a migration.** The version in force is
`consents.versions.<document>` in `platform_settings` (`config/consents.php` is the
fallback). Bumping it makes every acceptance of the old text outstanding immediately: the
reader asks for the current version, so nothing walks the table and no consent row is
touched. The ceiling a student earned is NOT zeroed either — `CreditLedger::floorForBalance()`
simply ignores it until the new text is signed, and it starts counting again on the next
booking after that.

**The opening ceiling is granted once**, on a person's FIRST acceptance of the
deferred-payment terms (`RecordTermsConsent`) and at the birth of any balance opened
afterwards (`CreditAccounts::balanceFor()`). Never on a re-acceptance: a grant that ran on
every signature would hand back the ceiling FR-040's demotion took away, the next time the
terms were republished.

**`TRUSTED_PROXIES` must be set in production or the recorded IP is the load balancer's** —
the same address for every person, in the column that exists to be relied on in a dispute.
It is read in `AppServiceProvider::trustConfiguredProxies()` and defaults to trusting
nothing; `'*'` is worse than the default unless the proxy always overwrites
`X-Forwarded-For`, because it lets any client choose what that column says.

---

## Qatar Payments (spec 007)

The gateway itself is **not chosen**, and everything around it is built: the provider
contract, the callback path, the sweep that finds money nobody told us about, the audit
trail, and the platform's collection report. A real gateway is one class implementing
`PaymentProviderInterface` — `ProviderExtensibilityTest` fails the build if an Action names
the registry, because resolving a provider by string is the coupling the interface removes.

**Refunds are credits, always.** `supportsRefund()` returns `false` and no money leaves
through code: a refund posts a negative ledger entry with `enforceFloor: true`, and the
transfer out of the system is a human decision taken elsewhere.

**`createCharge()` takes a `$returnUrl`, and the parameter is required on purpose.** It is
where the gateway sends the payer BACK — the opposite direction from
`ChargeIntent::redirectUrl`, which sends them to the gateway. ⚠️ **The return screen
`/billing/pay/return` shipped with 007 and nothing built its URL, on either side**: no
field on the interface, no config key, no builder, and no link in the frontend. It reads
`?transaction=`, polls for the settled answer and renders three real states — a finished
page with no inbound path, which the day a gateway is integrated would have been reached
by a URL somebody invented. `PaymentReturnUrl` builds it from `payments.return_url_base`
(← `FRONTEND_URL`), and `InitiatePayment` mints the transaction's uuid one line BEFORE the
provider call so the URL can name the attempt. A parameter with a default would have been
the polite change and would have kept exactly that silence.

**The screen displays; it never decides.** A return URL is a redirect in the payer's own
browser, so anyone can type it with any query string. What settled the payment is the
signed callback the server verified — a page that read `?status=success` would be granting
credits on the strength of a URL.

⚠️ **`InitiatePayment`'s docblock used to claim the transaction row is written before the
provider is called; it never was.** The claim was corrected rather than the order: a
callback is matched on the PROVIDER'S reference, which does not exist until `createCharge()`
returns, so writing our row first produces a row the arriving callback still cannot find.
The residual race — a gateway calling back faster than we commit — is open and belongs
here, not to a guess in that file.

### Endpoints

| Method | Path | Who |
|---|---|---|
| POST | `/api/v1/payments/{order}/charge` | the payer, starting a gateway payment |
| GET | `/api/v1/payments/{transaction}` | the payer, reading their own attempt |
| POST | `/api/v1/webhooks/payments/{provider}` | the provider — **no `auth:sanctum`** |
| GET | `/api/v1/admin/payments/reconciliation` | the last sweep and what it could not settle |
| GET | `/api/v1/admin/payments/audit` | every financial decision, newest first |
| GET | `/api/v1/admin/payments/audit/{transaction}` | the whole chain behind one payment |
| GET | `/api/v1/admin/payments/collection` | what the platform collected in a period |
| GET | `/api/v1/admin/payments/collection/export` | the same rows, as a file |

The webhook carries no token because a gateway holds none of ours: **the signature is the
authentication**, and it is checked before the body is read. `VerifyWebhookSource` runs
BEFORE `throttle:webhook` so that junk from a refused address never consumes the provider's
bucket — which is what a real resend burst needs to find free. The answer is `202` whether
the signature verified or not: a distinct reply for a bad one is an oracle telling an
attacker when they are getting warm.

### Permissions (two, both PLATFORM)

| Constant | Reaches |
|---|---|
| `billing.collection.view` | the collection report, its export, and the payment sweep's findings |
| `billing.audit.view` | the financial audit list and the chain behind one payment |

Both are refused to the workspace owner, who holds every tenant permission there is. FR-033
is the reason: a teacher reading a collection total reads what other teachers' students paid,
and `activity_log` has no `workspace_id` column at all, so the audit is platform-wide by
construction rather than by choice.

### The sweep replays the callback

`ReconcilePaymentsJob` runs hourly on the `payments` queue. It does **not** capture payments
by hand — it records the notification the provider should have sent and hands it to
`HandleProviderCallback`, so a sweep and a late notification for the same payment collapse on
`unique(provider, external_id)`. A second code path that wrote `captured` would be a second
answer to "who gets recorded, and what happens to a payment on a cancelled order".

The window is **half-open**, `[from, to)`, and the next run starts where the last one ended —
never at `now() − 1 hour`, which loses every payment taken while a run was late.

**The direction is asymmetric.** `pending → captured` is automatic; `captured → failed` is
never automatic, only recorded, so a reversal is a decision a person takes and can see.

**And the check that matters comes from outside both parties.** Comparing our status to the
provider's is a comparison between two sides that agree: when the queued credit-minting dies,
both say "captured" and the student is still blocked. `captured_without_credits` is the
finding that sees it.

`FR-015`'s timeout closes `pending` payments older than `payments.pending_timeout_minutes`
with `Expired` — one conditional UPDATE per row, after the provider pass.

### The audit cannot be rewritten, and the guard is ours

spatie enforces nothing of this: `activity_log` is an ordinary table with `$guarded = []`.
`App\Shared\Models\ActivityEntry` throws on `updating` and `deleting`, and it is registered as
`activity_model` in `config/activitylog.php` — **so the whole product's entries are covered**,
not billing's alone. Three doors past it are named in `ImmutableAuditTest` as passing tests
rather than comments: `Model::query()->update()`, `DB::table()->update()`, and a row loaded
through spatie's own `Activity` class. A "tamper-proof" claim with an unwritten exception is
how that claim ends up in a document shown to an auditor.

`BillingAuditSubjects` and `SettlementAuditSubjects` name their own model types and neither
ever asks for the table. The two lists are asserted to share **no** entry: one in both would
pass every per-direction test and still join the two contexts.

### The collection report is one grouped statement

Method, status and source are the **marginals of a single joint grouping**, and a marginal
summed from a joint distribution is exact — three `GROUP BY`s over the fastest-growing table
in the product would be three scans to learn what one already said. Currency is part of every
key, because a total that adds QAR to anything else matches no source.

⚠️ **The reader declares `withoutWorkspaceScope()` and the index starts at `created_at`.**
The permission is platform-level but `payment_transactions` carries `BelongsToWorkspace`, and
`WorkspaceContext::id()` falls back to `users.last_workspace_id` for **every** user including
a super admin. Left scoped, the report shows one teacher's money as the platform's total —
and agrees with its own source perfectly while doing it, on any single-workspace fixture.

⚠️ **The bypass is per model, and an eager load is its own query.** A bare `->with('order')`
runs Order's global scope inside the relation and returns null for every row outside the
reader's fallback workspace. This shipped in the audit chain and was fixed here; the test
that catches it builds a second workspace and audits from the first.

The export shares the Request, the filter and the query — never a second one written beside
it. `teacher_rate_minor` is **not** in either payload (FR-035), though the number is derivable
from the credits and the two platform fees; `PaymentFieldAllowlist` says so out loud rather
than implying the omission closes the inference.

### The queue, and who is told when it stops

The charge listener, the callback processing and the hourly sweep all run on the queue, so a
stuck worker now means money that settled and was never credited. `viewHorizon` compared
against an **empty array** until this phase — the dashboard was unreachable in every
environment but local — and is now the `is_super_admin` flag. `HORIZON_NOTIFICATION_EMAIL`
routes the long-wait notice; unset, Horizon tells nobody, which is fine locally and is a
payments outage nobody hears about in production. A queue with no entry in `waits` is not
watched at a default — it is not watched.

---

## Question Bank, Grading, Homework and the Unlock Gate (spec 008)

A question used to belong to one exam. It now belongs to the **bank**, and an exam merely
**includes** it — which is the whole spec, and the reason `exam_items` exists.

### Endpoints

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/manage/bank/questions` | `bank.view` | Five filters and a text box; free text goes through Scout, which is outside every global scope and is given `workspace_id` by hand |
| GET | `/manage/bank/questions/{uuid}` | `bank.view` | |
| POST · PATCH · DELETE | `/manage/bank/questions[/{uuid}]` | `questions.manage` | `throttle:authoring`. DELETE **disables**; a question with recorded attempts is never removed (FR-005) |
| GET · POST · PATCH | `/manage/bank/concepts[/{uuid}]` | `bank.view` / `questions.manage` | |
| GET · POST | `/manage/bank/imports[/{uuid}]` | `questions.manage` | `throttle:upload`. Answers 202 and hands back an id; the report is polled |
| GET · PUT | `/manage/exams/{uuid}/items` | `questions.manage` | The COMPLETE list every time — see `SyncExamItems` |
| GET | `/manage/analytics/questions` · `/concepts` | `analytics.view` | `?scope=platform` needs `analytics.cross_teacher.view`, which no tenant role holds |
| GET | `/mistakes` | authenticated | The student's own notebook |
| POST | `/practice/from-mistakes` · `/practice/exams` | authenticated | `throttle:practice`, keyed by **user**: students sit in classrooms behind one address |
| GET | `/practice/attempts/{uuid}/result` | authenticated | |
| GET | `/manage/grading/queue` · `/attempts/{uuid}` | `grading.perform` | |
| POST · PATCH | `/manage/grading/answers/{uuid}` | `grading.perform` / `grading.revise` | `throttle:authoring` |
| PUT | `/manage/bank/questions/{uuid}/rubric` | `questions.manage` | The rubric hangs off the QUESTION |
| GET | `/assignments[/{uuid}]` | authenticated | One list endpoint with two branches; a second route is a second place to forget the draft filter |
| POST | `/assignments/{uuid}/submissions` | authenticated | `throttle:upload` |
| POST · PATCH | `/manage/assignments[...]` | `assignments.manage` | `throttle:authoring` |
| POST | `/manage/submissions/{uuid}/grade` | `submissions.grade` | |
| GET · POST · DELETE | `/manage/accommodations[/{uuid}]` | `accommodations.manage` | |
| GET | `/submissions/{uuid}/file` | owner or `submissions.grade` | `signed` **and** `auth:sanctum`, five-minute TTL, policy re-run at open |
| GET · POST | `/manage/unlock-rules` | `unlock_rules.manage` | |
| POST | `/manage/class-sessions/{uuid}/unlock-exemptions` | `unlock_rules.manage` | |

The **student's** side of the unlock gate is not here: it is
`/class-sessions/{uuid}/eligibility`, owned by LiveSessions because that module binds the
session. Both answers come from one resolver.

### Permissions (nine)

`bank.view` · `questions.manage` · `grading.perform` · `grading.revise` ·
`assignments.manage` · `submissions.grade` · `accommodations.manage` ·
`unlock_rules.manage` · `analytics.view`, plus the platform-only
`analytics.cross_teacher.view`.

⚠️ **`questions.manage` was REVOKED from the assistant-teacher role** by migration
`..._002000`. Under the old nested routes it meant "edit this exam's questions"; against the
bank it means the whole shared bank of the workspace. Leaving it is not "no change" — it is
one teacher's assistant silently holding every teacher's questions. Restoring it is one tick
box in `/admin`; the leak has no equivalent single step.

### The snapshot is the denominator

`attempt_items` is written when an attempt STARTS, one row per question with a json snapshot
of it. Grading reads that snapshot and never the live question, so an exam edited after a
student sat it cannot change what they were marked on — and the migration chain backfilled
these rows for every pre-existing attempt, without which each one's denominator would be
zero and `SC-015` would have passed while measuring `score` alone.

### Two things the analytics rollup must not count

- **Practice attempts.** `is_practice` is on `exam_attempts` while the answers are on
  `exam_answers`, so that join exists for this reason alone
- **Ungraded essays.** `GradeAttempt` writes `is_correct = false` for every essay because no
  machine can judge one; counting those reports every essay in the bank as 100% wrong,
  permanently

And a sample below `assessments.min_sample_size` stores `wrong_pct = NULL`, never zero: "nobody
got this wrong" is what makes a teacher delete a good question two students happened to sit.

### The unlock gate guards two doors and not the third

`BookingEligibility::openingRefusal()` is asked when a seat is booked and when a join ticket
is issued. The nightly `ReleaseIneligibleBookings` sweep still calls the older
`refusalReason()`, and the split is deliberate: the sweep **cancels** seats, so folding the
unlock condition into it would repossess a paid seat over unfinished homework.

Excused counts as attended — only `absent` fails, because an excusal is the teacher's
decision that the absence is not held against the student. "Previous" is the latest countable
earlier session, skipping cancelled and suspended ones. And a submitted-but-unmarked homework
satisfies a score threshold: the late party there is the teacher.

### Rate limiters

`throttle:authoring` (teacher writes) · `throttle:upload` (imports and hand-ins) ·
`throttle:practice` (paper generation, keyed by **user** rather than ip).

## Data Protection and Minors (spec 013)

One module, `Compliance`, plus a contract every other module implements. Six new
tables, three altered, five permissions — **all five platform-level**, held by
super-admin and the seeded `compliance-officer` and by no workspace role. A
teacher deciding who may read a child's record would be the tenant deciding the
platform's obligations.

### The contract, and why it is a contract

`App\Shared\Contracts\PersonalDataOwner` has five functions and thirteen
implementors, each registered with one `->tag('compliance.personal_data')` line in
its own module's service provider. `Compliance` therefore names **no other
module's table**: it asks each owner to describe its categories, export them,
erase them, and expire them.

`PersonalDataContractCoverageTest` fails the build when a module holding personal
data has no implementor, and `CategoryRegistryCoverageTest` when a category is
described by nobody. A registry checked against itself would be green for ever.

### Endpoints

| Method | Path | Who |
|---|---|---|
| `GET` | `/privacy/categories` | public — the policy in structured form |
| `GET` | `/privacy/policy` | public |
| `POST` | `/privacy/breach-reports` | **public, unauthenticated** (FR-040) |
| `PUT` | `/privacy/consents/categories` | the subject or their guardian |
| `POST` · `GET` | `/privacy/requests` | the subject or their guardian |
| `GET` | `/privacy/requests/{request}/download` | the subject — a `302` to a signature |
| `GET` | `/privacy/exports/{request}` | `signed`, no bearer token |
| `GET` · `POST` | `/teaching/offboarding` | the workspace **owner**, never a member |
| `GET` | `/teaching/offboarding/content` | the workspace owner |
| `GET` | `/manage/compliance/requests` | `compliance.requests.execute` |
| `POST` | `/manage/compliance/requests/{request}/execute` · `/refuse` | ⤴ |
| `POST` · `DELETE` | `/manage/compliance/holds` · `/holds/{hold}` | `compliance.holds.manage` |
| `GET` · `PATCH` | `/manage/compliance/breach-reports` | `compliance.breaches.manage` |
| `GET` | `/manage/compliance/offboardings` | `compliance.offboarding.execute` |
| `POST` | `/manage/compliance/offboardings/{exit}/execute` | ⤴ |

### Permissions (five, every one PLATFORM)

| Permission | What it authorises |
|---|---|
| `compliance.requests.execute` | run an export or an erasure somebody asked for, and read the queue |
| `compliance.registry.manage` | edit the category catalogue and the processor register |
| `compliance.holds.manage` | suspend an erasure, and release the suspension |
| `compliance.offboarding.execute` | finalise a teacher's exit once the books are square |
| `compliance.breaches.manage` | read and triage reported breaches |

`RolePermissionMatrix::platformPermissions()` derives the platform set as `all()`
minus everything any tenant role holds, so a permission added tomorrow is
platform-level until somebody deliberately puts it in a tenant role.

### The breach route is published, and that is the requirement

`POST /privacy/breach-reports` carries **no** `auth:sanctum`. The best-known leaks
are reported by outside researchers who hold no account, so a login requirement
restricts the report to the population least likely to be making it. Three things
make it safe to publish, and none of them is authentication:

- `throttle:public`, the named guest limiter keyed by address.
- **The response is a constant.** No uuid, no id, no count, no echo — an
  unauthenticated endpoint that varied its answer by what it found would tell an
  attacker whether an address holds an account, or whether a report already
  exists. The same uniform-`202` rule the payment webhook is built on.
- **The scope fields are not in the request.** Which categories and how many
  people are triage's answer; accepted from the reporter, anyone could assert the
  size of an incident into our own record of it.

`AdvanceBreachReport` moves a report forward only, and refuses `notified` until
**both** `authority_notified_at` and `subjects_notified_at` exist — the authority
and the people whose data leaked are two obligations on two clocks, which is why
the table carries two columns rather than a flag. A notification timestamp is
never re-stamped: it is the record of when an obligation was discharged, measured
against a deadline, and a later edit would move it inside the window. Both
deadlines are **derived** in `BreachReportResource` from `created_at` plus
`ComplianceSettings`, never stored.

### Retention runs itself, and three behaviours are not interchangeable

`RunRetentionSweepJob` (nightly, 03:30, queue `compliance`) walks every category
with a retention and calls `expire()` on the owner. `ExpiryBehaviour` is `Delete`,
`Anonymise` or `Archive`:

- **`Anonymise` is unavailable wherever the identifying column is `NOT NULL`.**
  `exam_attempts.student_user_id` and `attendances.student_user_id` both are, and
  `->change()` on either rebuilds the table on SQLite. So `exam_attempt` expires as
  `Delete` (its answers are already gone at 1095 days; at five years the row is a
  bare score naming a person for no reader), while attendance anonymises by
  clearing the free text and keeping the row — deleting a seat would break
  `ReconcileCreditBalancesJob`'s "one consumption entry per seat" invariant for
  ever, with no cause anybody could find.
- **`Archive` needs a mark or it never converges.** "Older than N days" is true
  again tomorrow, so `media_assets.archived_at` is what stops the same recording
  being re-archived — and the same file re-deleted at the provider, billed each
  time — every night. `Delete` cannot show that defect in a test, because a
  deleted row does not come back; the idempotency fixture must include an archive
  category.
- **A class recording is a write to the COURSE TREE.** A recording IS a lesson, so
  expiring the asset without archiving the lesson leaves an item pointing at an id
  nothing resolves. `MediaAssetsExpired` announces a BATCH and
  `ArchiveExpiredRecordingLessons` fires `CourseStructureChanged` **once per
  course** — fifty recordings ageing out together would otherwise run fifty full
  resyncs over one identical set of enrolments.

**A legal hold has a fourth door.** Retention needs nobody to ask, so a hold that
only touched `data_requests` would let the nightly sweep delete the exact rows a
court ordered kept — on a schedule, with the hold row green beside it.
`RunRetentionSweepJob` resolves the held subject ids once and threads them through
`PersonalDataOwner::expire()`.

`withoutOverlapping()` on `Schedule::job()` guards the DISPATCH, not the run — for
a queued job that is milliseconds around the push. The sweep uses
`WithoutOverlapping` as **job middleware** instead, and `expireAfter()` is the
load-bearing half: without it a killed worker holds the lock for ever and
retention silently never runs again.

### Coming of age

`TransferDataOwnershipJob` (daily, 06:25 Doha) hands a student their own data at
eighteen. The predicate is `date_of_birth < (threshold + 1 day)`, never
`<= threshold`: the column is a `date`, the model writes `00:00:00`, and the `<=`
form string-compares FALSE for the person born exactly that day — telling them
tomorrow, on a date the law attaches meaning to. On MySQL the `<=` form works, so
no local test would ever have disagreed with production.

### A teacher's exit

`RequestTeacherOffboarding` opens a notice period, notifies every student, and
pulls the public listing **at request** rather than at completion — a month-long
notice with a live marketplace page enrols new students with a departing teacher.
`ExecuteTeacherOffboarding` completes it with a conditional UPDATE carrying all
three conditions (`status`, `settlement_cleared_at`, `notice_ends_at`), never a
read followed by a write.

- **The books are asked, never queried.** `App\Shared\Contracts\SettlementClearance`
  answers two integers in minor units and a boolean; `ContextIsolationTest` fails
  the build on a `Compliance` query against the settlement or billing schema, and
  on settlement *vocabulary* in any payload outside that module — which is why the
  Resource sends `dues_cleared` and the model carries `duesCleared()`.
- `SUM(ledger_entries.amount_minor)` **is** the balance, payouts included: a payout
  is written as a negative entry, so subtracting `teacher_payouts` on top would
  report a fully-paid teacher as owing the platform their salary. What the sum
  misses is a `PendingPackage` unit, which has no ledger entry at all — a lesson
  taught whose recording has not landed.
- **FR-037 ends the teacher's access and their assistants', never their
  students'.** The obvious implementation deletes every `workspace_members` row,
  which takes away the course the student bought one requirement after the platform
  promised it stays.
- **Never `WorkspaceContext::forget()` inside a listener.** It is an
  application-wide singleton that caches its resolution; dropping it leaves the
  next request resolving from scratch, and a student — a member of no workspace —
  then has a null team id and therefore **no roles at all**. `forWorkspace()` sets
  both and puts both back.
- **`media_assets.retain_until` is an OR against the age rule, never an extra
  AND.** Written as a further condition it could only ever shorten, so a student
  two months into a paid year would lose the lesson on its second birthday. A null
  `expires_at` means access that does not expire — the default shape of an
  enrolment here — so `EnrollmentDirectory::accessHorizonFor()` answers a date
  **and a boolean**, and the boolean is the load-bearing half.

### Logs and processors

`FR-041` forbids personal data in application logs. Every job in this phase takes
an **id** and re-reads, which is what keeps `failed_jobs.payload` clean — that
table outlives the erasure it failed to perform, and nothing sweeps it.

`FulfilDataRequestJob` logs the exception **class**, never `getMessage()`: Laravel
interpolates query BINDINGS into a `QueryException` message, so any failing
statement in the walk would carry whatever it was searching for into a line that
ships to a monitoring vendor. The full trace still reaches
`failed_jobs.exception`, in our own database.

`data_processors` names everyone who receives personal data outside our servers,
with an honest `erasure_capability` per row — `partial` for a CDN whose edge copies
lapse on their own schedule, `none` for a message already delivered to a phone.
`ProcessorAllowlistTest` checks the register **against the code**: every external
channel the container carries must have an active row, and an outbound delivery is
measured with `Http::preventStrayRequests()` so a second endpoint nobody declared
shows up.

### Rate limiters

`throttle:data-rights` keys on the **account**, never the address — a family behind
one router shares an address and a guardian may hold several children. It is
deliberately **not** used on `/privacy/exports/{request}`, which carries no
`auth:sanctum`: `user()` is null there, every anonymous hit would share the key
`'user:'`, and the second person to download their own archive in the same minute
would be refused. That route uses `throttle:public`, which is guest-keyed by
address.

## Chat, Assistants, Reviews and Announcements (spec 010)

Twelve tables: eleven workspace-owned (layer 2) and **one platform-owned**.
`report_cards` carries no `workspace_id` at all — a student has ONE cumulative
record across every teacher they study with, and scoping it would silently
duplicate one person per teacher. Its segments DO carry one, because a segment is
precisely «this teacher's contribution»; the guard is therefore the Action and the
policy, not a global scope.

### The socket is an accelerator, and the database is the source

A message is written to `messages` and read from `messages`. `MessagePosted` is a
queued `ShouldBroadcast` carrying an IDENTIFIER and no payload; Reverb going down
turns live delivery into "one refresh behind", never into a failed send — which is
what `SC-015` says out loud and `BroadcastOutageTest` measures with the server
killed mid-session. Broadcasting gets NO abstraction of ours, unlike Bunny or
LiveKit: Laravel already owns a driver layer for it, and `reverb` is a driver in
it, so moving to a managed provider is a line of configuration.

### Endpoints

| Method | Path | Who |
|---|---|---|
| `GET` | `/api/v1/conversations` | either party |
| `POST` | `/api/v1/conversations` | the student — opens or returns the one private thread |
| `GET` | `/api/v1/conversations/{conversation}/messages` | either party |
| `POST` | `/api/v1/conversations/{conversation}/messages` | either party, while the relationship lasts |
| `POST` | `/api/v1/conversations/{conversation}/attachments` | either party |
| `DELETE` | `/api/v1/messages/{message}` | the author, or `chat.moderate` |
| `POST` | `/api/v1/messages/{message}/helpful` | the teacher's side, in a room |
| `POST` | `/api/v1/conversations/{conversation}/lock` | `chat.moderate`. Closes a ROOM's discussion and opens it again; whoever holds the permission keeps writing, or the teacher cannot answer the last question on screen. Refused on a private thread — silencing one person there is a **ban**, which is declared, recorded and appealable |
| `POST` | `/api/v1/messages/{message}/report` | anyone who can read it |
| `POST` | `/api/v1/reviews/{review}/report` | anyone — the SAME moderation path |
| `GET` | `/api/v1/class-sessions/{session}/chat` · `/lessons/{lesson}/chat` | seat holders / enrolled |
| `POST` | `/api/v1/moderation/actions` | `chat.moderate` |
| `GET` | `/api/v1/manage/assistants` · `PUT .../{assignment}/scope` · `DELETE .../{assignment}` | the owner |
| `GET` | `/api/v1/assistants/me` | the assistant |
| `GET` | `/api/v1/manage/students/{student}/reviews` · `POST /manage/periodic-reviews` · `POST .../{review}/publish` | `reviews.periodic.manage` |
| `GET` | `/api/v1/students/me/reviews` | the student — PUBLISHED only |
| `GET` | `/api/v1/manage/report-card-segments` · `/manage/grading-schemes` · `POST /manage/grading-schemes` | the teacher |
| `GET` | `/api/v1/report-cards` · `/report-cards/{uuid}` · `/report-cards/{uuid}/download` | the student, their guardian |
| `GET` | `/api/v1/manage/announcements` · `POST` · `POST .../{announcement}/publish` · `PATCH` · `DELETE` | `announcements.manage` |
| `GET` | `/api/v1/chat-media/{message}` · `/report-card-files/{uuid}` | signed, short-lived |

### Permissions (four, every one a TENANT permission)

| Name | What it really grants |
|---|---|
| `chat.reply` | answering as the teacher's side — this, not membership, is what separates the two sides of a room |
| `chat.moderate` | hiding a message, banning a writer, deciding a report |
| `reviews.periodic.manage` | writing and publishing a periodic review of a named student |
| `announcements.manage` | publishing to every one of the teacher's students, **in the teacher's name** |

An assistant holds what the owner ticks on the roles screen and nothing by
default. **`billing.balance.view` is the one financial item that may be delegated**
— a count of remaining sessions with no amount anywhere near it — and everything
else financial is refused on the API, on the panel, and now vocabularly:
`ContextIsolationTest` fails the build over a `use App\Modules\Payments` written
under `Modules/Community/`.

### The wall is at the CHECK, never on the role name

`Gate::before` asks `AssistantScopeDirectory::isAssistantIn()` and refuses the
financial permissions there — bound `scoped()`, never `singleton()`, so a
revocation is instant inside a queue worker as well as over HTTP. A rule written
against the role NAME would be one custom role away from nothing.

### `hidden_at`, never `deleted_at`

A moderated message stays readable to the moderator and to the audit; a soft
delete would put it behind Laravel's global scope where the moderation screen
cannot see the thing it just acted on. The same column, and the same reason, on
`announcements`.

### A departed teacher's chat closes for writing and stays open for reading

`ConversationPolicy::post()` asks `TeacherOffboardingDirectory::hasDeparted()`
(spec 013 · FR-037). The enrolment cannot say it — FR-035 keeps the course a
student PAID for until their term ends — so without this the student writes into
a workspace with nobody left to answer, for ever. It is asked in the POLICY and
not stamped on the row because `StartConversation` authorises an UNSAVED
`Conversation` against the same ability: one question, one place.

### Rate limiters

`chat-write` · `chat-report` · `moderation-write` · `announcement-publish` ·
`report-card-render`, all named in `AppServiceProvider::registerRateLimiters()`.
`chat-report` is a SEPARATE bucket from `chat-write` on purpose: sharing one means
a burst of messages spends the budget a person needs to report abuse.

### Announcements

One row, one fan-out job, `notifications` rows per recipient keyed by
`(source_type, source_id)` — which is what makes «كم قرأه» a single grouped query
rather than a column that drifts. `published_at` is claimed by a conditional
UPDATE (the seat idiom), so a re-published draft fans out exactly once. Groups and
attachments are OUT of scope and recorded as such.

## Roles, permissions, and who may grant them

Roles became editable from `/admin` (Filament Shield), and two facts make that
safe rather than reckless.

### Shield is the screen; it is not the source

The package's usual job is to DERIVE permission names from your Filament
resources. This product already has seventy-three of them as constants in
`Tenancy\Support\Permissions`, read by every policy, route and test — so both
generators are off in `config/filament-shield.php`:

| Key | Value | Why |
|---|---|---|
| `permissions.generate` | `false` | Shield invents no names |
| `policies.generate` | `false` | the policies here are hand-written and carry their reasoning |
| `permissions.format_custom_permission_keys` | `false` | our names are dotted and lower-case; the formatter would pascal-case `billing.audit.view` into a string no policy has heard of |
| `super_admin.enabled` · `panel_user.enabled` | `false` | super-admin here is the `users.is_super_admin` column; a second one wearing a role is two answers to one question |
| `custom_permissions` | `PermissionLabels::tenantMap()` | the picker's whole vocabulary, in Arabic |

`PermissionLabels` **composes** the Arabic instead of listing it: names are
`{subject}.{action}`, so twenty nouns and forty actions cover every permission
including the ones nobody has written yet. `PermissionPanelTest` fails the build
if any offered permission falls back to its raw name.

### A workspace role can never hold a platform permission

`RolePermissionMatrix::platformPermissions()` is **derived** — `Permissions::all()`
minus everything any workspace role holds — so a permission added tomorrow is
platform-level **until somebody puts it in a tenant role on purpose**. The screen
offers the complement, and `Tenancy\Models\Role` (registered as
`permission.models.role`) throws on `givePermissionTo()`/`syncPermissions()` if a
platform permission reaches a role with a `team_id`.

⚠️ **The picker is not the guard.** A filtered form shapes the request; it does
not constrain the next one. The model is where the refusal lives, which is why
both seeders now import `Tenancy\Models\Role` rather than spatie's — a class
named directly is the class that runs, guard and all.

⚠️ **And `roles` is scoped now.** `TeamRoleScope` filters by spatie's team id,
because `/admin` is reachable by every teacher and an unscoped list hands one
teacher another teacher's roles with an edit button beside each. `RolePolicy` is
the row-level half: a list filtered by a query and a record fetched by id are two
different questions, and the second is the one an address bar asks. Default roles
cannot be deleted — `SeedDefaultRoles` runs once, at workspace creation, so a
deleted `teacher` never comes back.

### Platform standing is a table, not a role assignment

`model_has_roles` puts `team_id` inside its primary key and forbids NULL, so a
role belonging to no workspace can be seeded and given to nobody. That is why
`finance-admin` was correct and unassignable from 006 until 2026-08-14.

`platform_staff` carries the standing — **who, which role, granted by whom, and
why** — and a `Gate::before` in `TenancyServiceProvider` turns it into the
permissions of the teamless spatie role, in whichever workspace the officer is
looking at. The alternatives were a boolean column beside `is_super_admin` (the
constitution refuses it: a column true of one role gets its own table, and the
third platform role would want a third column) and altering a primary key on an
auth table (a NULL inside a composite PK behaves differently on MySQL and
SQLite — the failing environment being the one nobody runs the suite in).

- The `Gate::before` returns `null`, never `false`: a hook that answers false
  short-circuits every policy behind it.
- It answers only for names in `Permissions::all()`, because Filament checks
  `view`/`update` against models constantly.
- `PlatformStaffDirectory` memoises per request — a `Gate::before` runs on every
  ability check, and a query inside one is a query per checkbox. It declares
  `withoutTeamScope()`, the one sanctioned bypass: a workspace team id is set on
  almost every request an officer makes, and the scope would otherwise hide the
  teamless role that carries their permissions.
- The screen (`PlatformStaffResource`) grants a ROLE, never a permission, and is
  super-admin only. A finance officer who could appoint a finance officer can
  grant themselves a colleague.

---

## Course Groups and the Course Page (spec 021)

A course that runs in **cohorts** — the Saturday four o'clock and the Sunday six —
and one page that carries the whole of it: the curriculum with a state and a reason
on every row, the sessions, the papers, the homework, the announcements, the
certificate, the group's thread and the classmates.

### The two rules everything else follows from

- **A student belongs to exactly one group of a course at a time, and the guard is
  a UNIQUE INDEX.** `unique(student_user_id, course_id, closed_slot)` with
  `closed_slot = 0` while open and the row's own id afterwards — never a partial
  index, which MySQL does not have, and never `count()` then `insert()`, which is
  the definition of the race. `closed_slot` is deliberately **not** `$fillable`:
  it is written inside the statement that owns the closing.
- **Membership can be REQUIRED to reach the content, and the requirement has a
  safety valve.** `LessonGate` refuses the tree while the course has a joinable
  group and the reader is in none — and opens it **completely** the moment every
  group is full, closed or archived (`joinableCohortsExist()` is false). Without
  that valve a student who paid for a course is held behind a condition no action
  of theirs can satisfy, which is the family of the worst defect this repository
  records.

### Endpoints

| Method · path | Who | Note |
|---|---|---|
| `GET /courses/{course}/cohorts` | the enrolled student | the picker: schedule preview, seats left, the reader's own membership, their pending request, **and the groups they have left** |
| `POST /cohorts/{cohort}/join` | the enrolled student | first join is free (FR-028د) · `throttle:cohort-write` |
| `POST /cohorts/{cohort}/transfer-requests` | the enrolled student | `{ reason? }` · `throttle:cohort-write` |
| `DELETE /transfer-requests/{request}` | the requester | withdraw · `throttle:cohort-write` |
| `GET /cohorts/{cohort}/roster` | a CURRENT member | name, face, level, rank, badges — and nothing else |
| `GET /cohorts/{cohort}/chat` | whoever was **ever** a member | resolve-or-open the group's thread |
| `GET · POST /manage/courses/{course}/cohorts` | `courses.update` | creating is for `group` courses only (FR-037) |
| `PATCH /manage/cohorts/{cohort}` · `POST /manage/cohorts/{cohort}/archive` | `courses.update` | ⚠️ **there is no `DELETE`** — see below |
| `GET · POST /manage/cohorts/{cohort}/members` · `DELETE …/members/{user}` | `courses.update` | direct add and remove, no request and no approval |
| `GET /manage/cohorts/{cohort}/history` · `GET /manage/courses/{course}/students/{student}/cohort-history` | `courses.update` | the audit trail |
| `POST /manage/transfer-requests/{request}/approve` · `/reject` | `courses.update` | ⚠️ `reason` is **required** on a rejection — the student reads it |
| `POST /manage/courses/{course}/assign-sessions` | `courses.update` | ONE request for the whole batch, never a loop at the caller |
| `POST · DELETE /conversations/{conversation}/write-bans` | `chat.moderate` | one person, one thread, with an end |

### Permissions: none new

Groups run on **`courses.update`** — a group is a run of a course, and whoever may
edit the course may schedule its runs. Moderation of the group's thread is
`chat.moderate`, and `attendance.view` is untouched. A new permission would mean a
seeder row **and** a backfill migration for every workspace that already exists
(`SeedDefaultRoles` runs once at creation and never comes back) in exchange for no
delegation anybody asked for.

### The two doors of a group's thread are different questions

Reading is `wasEverMember`; writing is `isCurrentMember`. FR-046 keeps the old
group's conversation readable **for ever** after a transfer — reading the answers
you were given is the point — while answering back into a group you are no longer
in is not. `chat.moderate` is exempt from the write door for the reason the lock
exempts it: the teacher holds no membership in their own cohort.

⚠️ **And the roster's door is the narrow one.** `GET /cohorts/{cohort}/roster`
asks `isCurrentMember`, deliberately unlike the thread beside it: the archive is a
record of what was said while you were there, and no requirement gives somebody
who left continuing sight of **who is in the group today**.

### The roster carries five fields and no sixth

`uuid`, `name`, `avatar_url`, `badges`, and — **as absent keys, never zeros** —
`level` and `rank`. Boards roll up nightly, so a student who joined this morning is
on none of them; «المركز ٠» printed beside a name in front of their class is the
reading of a zero. `CohortRosterExposureTest` walks every key in the payload
against an allowlist, because a deny-list only ever sees the field somebody
thought to name.

⚠️ **Zero attendance, stay, mark or teacher's note** (FR-052). Those are
`attendance.view`'s questions and this route opens to every member; one of them
here hands each student their classmates' record. And the rank scope is the
**teacher's**, keyed by the workspace — the same key `ChatRankStamper` uses,
because the thread is one tab away and two scopes would show one student rank ٧
beside their message and rank ٣ beside their name.

### Archiving, never deleting

There is no `DELETE /manage/cohorts/{cohort}` and its absence **is** the
requirement (FR-035). Archiving keeps every closed membership pointing at something
that resolves and keeps the history readable; a delete turns each of them into a
row naming a group that no longer exists.

### Rate limiters

`throttle:cohort-write` (20/minute, keyed on the user) on every group write.
Inline `throttle:N,M` is banned: `ThrottleRequests` keys guests on `domain|ip` with
no route in the hash, so every inline limit shares one counter and the strictest in
the application wins.
