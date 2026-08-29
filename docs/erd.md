# Entity Relationship Diagram

## Core Tables

```
┌──────────────┐       ┌──────────────────────┐       ┌──────────────────┐
│   users      │       │   workspaces          │       │ workspace_members│
├──────────────┤       ├──────────────────────┤       ├──────────────────┤
│ id (PK)      │◄──────│ id (PK)              │◄──────│ workspace_id (FK)│
│ uuid         │       │ uuid                  │       │ user_id (FK)      │
│ first_name   │       │ name                  │       │ role              │
│ last_name    │       │ slug                  │       │ joined_at         │
│ email        │       │ type                  │       └──────────────────┘
│ password     │       │ owner_user_id (FK)───►│
│ is_super_admin│      │ settings (JSON)      │       ┌──────────────────┐
│ last_workspace_id│   └──────────────────────┘       │   invitations    │
└──────┬───────┘                │                     ├──────────────────┤
       │                        │                     │ id (PK)          │
       │                        └────────────────────►│ workspace_id (FK)│
       │                                              │ email            │
       │                                              │ role             │
       │                                              │ token            │
       │                                              │ expires_at       │
       │                                              └──────────────────┘
       │
       │ ┌─────────────────────────────────────────────────────────────┐
       │ │                    courses                                    │
       │ ├─────────────────────────────────────────────────────────────┤
       │ │ id (PK) · uuid · workspace_id · title · slug · description   │
       │ │ price · currency · status · visibility · is_sequential      │
       │ │ language · duration_seconds · created_by (FK→users)          │
       │ └──────────────┬──────────────────────────────────────────────┘
       │                │
       │ ┌──────────────▼──────────┐    ┌─────────────────────────┐
       │ │  course_sections         │    │  course_chapters        │
       │ ├─────────────────────────┤    ├─────────────────────────┤
       │ │ id · uuid · workspace_id│    │ id · uuid · workspace_id│
       │ │ course_id (FK)          │───►│ course_id (FK)          │
       │ │ title · order · status  │    │ section_id (FK)         │
       │ │                         │    │ title · order · status  │
       │ └─────────────────────────┘    └───────────┬─────────────┘
       │                                              │
       │                              ┌──────────────▼──────────────┐
       │                              │  lessons                     │
       │                              ├──────────────────────────────┤
       │                              │ id · uuid · workspace_id     │
       │                              │ course_id · section_id       │
       │                              │ chapter_id · title · type    │
       │                              │ content · order · duration   │
       │                              │ is_preview · is_free · status│
       │                              │ reference_id · exam_gate     │
       │                              │ class_session_id             │
       │                              └──────────────────────────────┘
       │                                        │ morphOne
       │                                        ▼
       │                              ┌──────────────────────────────┐
       │                              │  media_assets  (workspace)   │
       │                              ├──────────────────────────────┤
       │                              │ id · uuid · workspace_id     │
       │                              │ owner_type · owner_id        │
       │                              │ provider · provider_asset_id │
       │                              │ status · mime_type · size    │
       │                              │ duration · renditions        │
       │                              └──────────────────────────────┘
       │
       │ ┌─────────────────────────────────────────────────────────────┐
       │ │                    enrollments                                │
       │ ├─────────────────────────────────────────────────────────────┤
       │ │ id · uuid · workspace_id · course_id · student_user_id (FK) │
       │ │ source · order_id · status · progress_pct                    │
       │ │ enrolled_at · completed_at · expires_at                       │
       │ └──────────┬──────────────────────────────────────────────────┘
       │            │
       │ ┌──────────▼─────────────┐    ┌─────────────────────────┐
       │ │  lesson_progress        │    │  progress_history       │
       │ ├─────────────────────────┤    ├─────────────────────────┤
       │ │ id · workspace_id       │    │ id · workspace_id       │
       │ │ enrollment_id (FK)      │    │ enrollment_id (FK)      │
       │ │ lesson_id (FK)          │    │ lesson_id (FK)          │
       │ │ status · completed_at   │    │ from_status · to_status │
       │ └─────────────────────────┘    └─────────────────────────┘
       │
       │ ┌─────────────────────────────────────────────────────────────┐
       │ │  exams                                                        │
       │ ├─────────────────────────────────────────────────────────────┤
       │ │ id · uuid · workspace_id · course_id · title · description   │
       │ │ duration_minutes · passing_score · max_attempts             │
       │ │ shuffle_questions · shuffle_answers · status                 │
       │ └──────────┬──────────────────────────────────────────────────┘
       │            │
       │ ┌──────────▼─────────────┐    ┌─────────────────────────┐
       │ │  questions              │    │  question_options       │
       │ ├─────────────────────────┤    ├─────────────────────────┤
       │ │ id · workspace_id       │    │ id · workspace_id       │
       │ │ exam_id (FK)            │───►│ question_id (FK)        │
       │ │ type · difficulty       │    │ content · is_correct    │
       │ │ content · points        │    │ order                   │
       │ │ explanation             │    └─────────────────────────┘
       │ └─────────────────────────┘
       │
       │ ┌─────────────────────────────────────────────────────────────┐
       │ │  exam_attempts                                               │
       │ ├─────────────────────────────────────────────────────────────┤
       │ │ id · uuid · workspace_id · exam_id · enrollment_id          │
       │ │ student_user_id · status · score · max_score · passed      │
       │ │ random_seed · started_at · submitted_at                     │
       │ └──────────┬──────────────────────────────────────────────────┘
       │            │
       │ ┌──────────▼─────────────┐
       │ │  exam_answers           │
       │ ├─────────────────────────┤
       │ │ id · workspace_id       │
       │ │ attempt_id (FK)         │
       │ │ question_id (FK)        │
       │ │ selected_option_ids(JSON)│
       │ │ is_correct · points     │
       │ └─────────────────────────┘
       │
       │ ┌─────────────────────────────────────────────────────────────┐
       │ │  certificates                                                 │
       │ ├─────────────────────────────────────────────────────────────┤
       │ │ id · uuid · workspace_id · certificate_number (UNIQUE)      │
       │ │ verification_code (UNIQUE) · enrollment_id · course_id       │
       │ │ student_user_id · exam_attempt_id · issue_reason             │
       │ │ issued_at · template_id · metadata (JSON)                    │
       │ │ UNIQUE(workspace_id, enrollment_id, course_id)              │
       │ └─────────────────────────────────────────────────────────────┘
       │
       │ ┌─────────────────────────┐
       │ │ certificate_templates   │
       │ ├─────────────────────────┤
       │ │ id · workspace_id       │
       │ │ name · html_template    │
       │ │ defaults (JSON)         │
       │ └─────────────────────────┘
       │
       │ ┌─────────────────────────────────────────────────────────────┐
       │ │  orders                                                       │
       │ ├─────────────────────────────────────────────────────────────┤
       │ │ id · uuid · workspace_id · user_id · product_id · course_id  │
       │ │ amount_minor · currency · provider · provider_ref · status   │
       │ │ kind · rejection_reason · approved_by · approved_at·metadata │
       │ └──────────┬──────────────────────────────────────────────────┘
       │            │
       │ ┌──────────▼───────────────────────┐
       │ │ payment_transactions              │
       │ ├───────────────────────────────────┤
       │ │ id · uuid · workspace_id          │
       │ │ order_id (FK)                     │
       │ │ captured_order_id  ← unique, null │
       │ │ provider · reference · method     │
       │ │ amount_minor · status · payload   │
       │ │ failure_reason · settled_at       │
       │ └──────────┬────────────────────────┘
       │            │
       │ ┌──────────▼─────────────────────────┐
       │ │ provider_callbacks         (007)   │
       │ ├────────────────────────────────────┤
       │ │ id · uuid · workspace_id           │
       │ │ payment_transaction_id (nullable)  │
       │ │ provider · external_id  ← unique   │
       │ │ payload (scrubbed) · result        │
       │ │ received_at · processed_at         │
       │ └────────────────────────────────────┘
       │
       │ ┌────────────────────────────────────┐
       │ │ payment_reconciliation_runs (007)  │
       │ ├────────────────────────────────────┤
       │ │ id · uuid · ran_at                 │
       │ │ window_from · window_to  ← [ , )   │
       │ │ checked · corrected · unresolved   │
       │ │ findings (JSON sample)             │
       │ └────────────────────────────────────┘
       │
       │ ┌─────────────────────────────────────────────┐
       │ │  cms_articles                                │
       │ ├─────────────────────────────────────────────┤
       │ │ id · uuid · workspace_id · title · slug      │
       │ │ body · excerpt · status · published_at      │
       │ │ author_id · category_id · seo_*             │
       │ └─────────────────────────────────────────────┘
       │
       └─── (users also relate to: media, activity_log, notifications, roles)
```

## Marketplace Tables

```
┌──────────────────────────────┐        ┌──────────────────────┐
│ teacher_profiles             │        │ teacher_applications │
├──────────────────────────────┤        ├──────────────────────┤
│ id · uuid · workspace_id     │        │ id · uuid            │
│ user_id (FK → users)         │        │ user_id (unique)     │
│ headline · bio · quals(JSON) │        │ step_data (JSON)     │
│ years_experience · languages │        │ current_step · status│
│ hourly_rate · currency       │        │ reviewed_by/at       │
│ is_verified                  │        │ rejection_reason     │
│ approval_status              │        └──────────────────────┘
│ is_publicly_listed  ◄── derived from (approval × workspace participation)
│ trust_score (null = building)│        ┌──────────────────────┐
│ trust_score_factors (JSON)   │───────►│ availability_slots   │
│ trust_score_calculated_at    │        │ day_of_week · UTC    │
│ reviews_count · average_rating         │ start_time/end_time │
│ completed/cancelled_sessions │        └──────────────────────┘
│ students_taught · rates      │
│ first_session_at             │        ┌──────────────────────┐
└───────┬──────────────┬───────┘───────►│ complaints           │
        │              │                │ reported_by · reason │
        │              │                │ status · confirmed_at│
        │              │                └──────────────────────┘
        │              │
        │              └──► teacher_profile_subject ──► subjects (per workspace, matched by slug)
        │                   teacher_profile_grade_level ──► grade_levels
        ▼
┌──────────────────────────────┐
│ reviews                      │
├──────────────────────────────┤
│ id · uuid · workspace_id     │
│ teacher_profile_id           │
│ student_id (FK → users)      │  UNIQUE (teacher_profile_id, student_id)
│ rating 1–5 · comment         │  INDEX (teacher_profile_id, is_visible, created_at)
│ is_visible (moderation)      │
└──────────────────────────────┘

Platform-level (deliberately NOT workspace-scoped — like `users`).
Classification is a constitutional requirement (Principle I, three ownership
layers); these carry no workspace_id and therefore no global scope, so each one's
guard is written explicitly in its Action or Policy.

┌──────────────────────────────┐    ┌──────────────────────────────┐
│ parent_student_relations     │    │ notification_preferences     │
├──────────────────────────────┤    ├──────────────────────────────┤
│ id · uuid                    │    │ id · uuid                    │
│ guardian_user_id (FK → users)│    │ user_id (FK → users)         │
│ student_user_id (FK, null)   │    │ type (NotificationType)      │
│ student_name · student_age   │    │ channels (json)              │
│ student_grade_level_slug     │    │ digest_window_minutes (null) │
│ relation_type parent|guardian│    └──────────────────────────────┘
│ permissions (json, 5 keys)   │       UNIQUE (user_id, type)
│ status pending|active|revoked│
│ revoked_at                   │    Replaces parent_child_links (spec 003), whose
└──────────────────────────────┘    rows migrated in as full parent relations.
   UNIQUE (guardian_user_id, student_user_id)
   INDEX  (student_user_id, status)          ← recipient resolution

┌──────────────────────────────┐    ┌──────────────────────────────┐
│ message_templates            │    │ contact_verifications        │
├──────────────────────────────┤    ├──────────────────────────────┤
│ id · uuid · key              │    │ id · uuid                    │
│ type · channel               │    │ user_id (FK → users)         │
│ title_ar · body_ar           │    │ channel · contact_value      │
│ variables (json)             │    │ code_hash (HASHED, never raw)│
│ provider_approval_status     │    │ attempts · expires_at        │
│ is_active                    │    │ verified_at                  │
└──────────────────────────────┘    └──────────────────────────────┘
   UNIQUE (type, channel)              INDEX (user_id, channel)

Bridge layer — owned by a person, carrying workspace context that filters but
never scopes:

┌──────────────────────────────┐    ┌──────────────────────────────┐
│ notifications                │    │ notification_deliveries      │
├──────────────────────────────┤    ├──────────────────────────────┤
│ id · uuid                    │◄───│ id · uuid                    │
│ recipient_user_id  ← THE GUARD│   │ notification_id (cascade)    │
│ workspace_id (NULLABLE, ctx) │    │ channel · template_id        │
│ type · subject_user_id       │    │ status · attempts            │
│ payload (json)               │    │ failure_reason               │
│ title_ar · body_ar (rendered)│    │ deferred_until               │
│ action_url · read_at         │    │ last_attempted_at            │
└──────────────────────────────┘    │ delivered_at                 │
   INDEX (recipient_user_id,        └──────────────────────────────┘
          read_at, id)  ← unread count + first page in one index
   INDEX (recipient_user_id, created_at) · (workspace_id) · (created_at)

   One notification row per (recipient, event) however many channels carry it;
   one delivery row per channel attempt. Replaces Laravel's own `notifications`
   table, which could represent only the first of those two.

   No phone or email column on deliveries, by design: the channel reads the
   destination off the user at send time and never copies it into the log.

workspaces gains: participates_in_marketplace (bool, default false)
                  owner_user_id is now NULLABLE (the platform workspace has no owner)
users gains:      platform_role · phone · country
                  quiet_hours_start · quiet_hours_end · timezone  (spec 003)
users loses:      grade_level_slug · registered_by_parent  → student_profiles  (spec 004)
lessons loses:    media (free-form JSON)                    → media_assets     (spec 004)
lesson_progress:  last_position (JSON, unused) → last_position_seconds        (spec 004)
courses gains:    course_type · cover_path · price_before_discount
```

## Key Relationships

- **Workspace → Users:** Many-to-many via `workspace_members` (pivot with role)
- **Course → Sections → Chapters → Lessons:** Hierarchical content structure
- **Enrollment → LessonProgress:** One progress record per lesson per enrollment
- **Exam → Questions → QuestionOptions:** Assessment content with correct answer tracking
- **Attempt → Answers:** Student responses with auto-grading
- **Certificate:** Unique per (workspace, enrollment, course) — idempotent issuance
- **Order → PaymentTransaction:** Manual payment flow with receipt upload + approval
- **TeacherProfile → Reviews:** One live row per (teacher, student) — re-reviewing updates, so the average cannot be inflated; hidden reviews stay in the table so the pair stays taken
- **TeacherProfile → Complaints:** Only `status = confirmed` deducts from the trust score (5 each, capped at 20)
- **TeacherProfile.is_publicly_listed:** Derived from approval status × the workspace's `participates_in_marketplace`, never assigned. Withdrawal hides a whole workspace without changing anyone's `approval_status`
- **ParentChildLink:** `child_id` is nullable — a parent can name a child who has no account yet. Authorization is ownership of the link row, so two parents can both link the same child


## Video Pipeline & Sessions (spec 004)

Ownership layer is stated for each table, because that classification is a
binding decision and not an implementation detail.

```
media_assets        (workspace)  morphs to a Lesson today, a ClassSession next phase
media_captions      (workspace)  WebVTT per language; the transcript is derived, not stored
playback_grants     (bridge)     carries workspace_id for context, no global scope:
                                 a grant is issued and consumed with no workspace current

devices             (PLATFORM)   NO BelongsToWorkspace. A copy per workspace would give a
                                 student a fresh device allowance per teacher — no limit at all
auth_sessions       (PLATFORM)   one sign-in on one device; the row survives being ended,
                                 because it IS the audit trail
user_security_settings (PLATFORM) two-factor secret and recovery codes, off `users` so the
                                 four nulls are not read on every authenticated request
student_profiles    (PLATFORM)   grade_level_slug · registered_by_parent, moved off `users`
platform_settings   (PLATFORM)   key/value an operator edits without a deploy
```

- **PlaybackGrant → AuthSession:** the binding that makes a copied link useless. When the
  session ends, every grant it minted dies with it — checked on each range request, so
  playback stops mid-file rather than at the next page load
- **Device limit:** counted in DISTINCT DEVICES among live sessions, not sessions. Two
  sessions on one laptop are one machine and never evict each other
- **`users` rule from here on:** it carries what every account has. Anything true of one
  role only gets its own table. No `guardian_profiles` or `admin_profiles` exist yet —
  no field is theirs, and an empty table is not a design


## Live Sessions & Attendance (spec 005)

```
class_sessions      (workspace)  the taught hour. Named ClassSession, not Session:
                                 auth_sessions already owns that word
session_bookings    (bridge)     workspace_id for context, student_user_id pointing at the
                                 one platform-wide student
attendances         (bridge)     same shape; one row per student per session
class_session_feedback (workspace) the teacher's remark, one per (session, student)
freeze_periods      (workspace)  student_user_id NULL = every student of this teacher

lessons gains:      class_session_id  → the recording, published as an ordinary lesson
attendances gains:  report_sent_at    → when the guardian was told what this row says
```

- **ClassSession → SessionBookings:** `unique(class_session_id, student_user_id)` stops one
  student holding two seats. It is **not** the guard against overbooking — that is the
  conditional `UPDATE … WHERE seats_taken < seats_total`, which is atomic where
  `lockForUpdate()` is a no-op on SQLite and would prove nothing about MySQL
- **ClassSession.billable_seats:** written once at the cancellation deadline, never
  recomputed. It answers a question about a moment that has passed
- **ClassSession.delivered_at:** a column, not a status, because completion and delivery are
  different facts — spec 006 bills against this one
- **Attendance.auto_status:** survives every override, so the register always shows what the
  system concluded next to what a person decided. A record that hides having been edited is
  trusted more than it has earned
- **Attendance.recording_watched_at:** an independent fact that never moves `status`. The
  teacher may mark someone present on the strength of it; the system never does
- **Lesson.class_session_id:** the recording's entitlement hangs off this one column, checked
  in `IssuePlaybackGrant` **above** the workspace-membership shortcut — every enrolled
  student is a member of their teacher's workspace, so membership alone would hand the
  cohort an hour only its seats paid for
- **FreezePeriod:** read, never written to by anything. Scheduling, booking and the counting
  jobs consult it; no counter moves, so nothing has to be restored when it ends
- **`broadcast_provider` / `broadcast_room_id`:** on the row, never in a payload and never
  in the frontend bundle (FR-019)

## Teacher Settlement (spec 014)

```
settlement_rates      (workspace)  teacher_profile_id, session_type, subject_id, grade_level,
                                   amount_minor, effective_from — INSERT-only, never updated
rate_change_requests  (workspace)  the ask and the decision, with the reason for a "no"
teaching_units        (workspace)  one per (class_session, student). class_session_id NOT NULL:
                                   a unit is payment for a specific hour
settlement_periods    (workspace)  the window, plus the SIX frozen totals written at close
ledger_entries        (workspace)  append-only. THE balance — units are only the count
teacher_payouts       (workspace)  money that left. unique(settlement_period_id)
```

### The absence is the design

**Zero foreign keys between these six tables and `orders` / `payment_transactions` /
`products`, in either direction.** Not an omission from the diagram — the omission *is* the
architecture (FR-030). A teacher's pay and a student's payment are two answers to two
questions, and a key between them is the first line of the join that eventually makes a
refund reduce someone's salary.

The only bridge is the `SessionDelivered` event from 005. Nothing here consumes a billing
event either, and `ContextIsolationTest` fails the build over any of it — including a
derived check that reads both modules' `Schema::create` calls, so spec 006's credit tables
are covered the day they land.

- **`amount_minor` is an integer**, departing from Payments' `decimal(12,2)`. Laravel's
  `decimal:2` cast returns a **string**, so every sum goes through a float — which is fine
  until the day it is not, on the one table where it would be a wrong salary
- **`teaching_units` unique `(class_session_id, student_user_id, reversal_of_id)`** with
  `reversal_of_id` defaulting to **0, not NULL**: NULLs in a unique index are distinct in
  both MySQL and SQLite, so a nullable column there enforces nothing
- **`settlement_periods` unique `(teacher_profile_id, starts_on)`** — what the loser of two
  concurrent sweeps hits. Both racers derive the same window start from the previous close,
  so they collide by construction rather than by luck
- **`teacher_payouts.amount_minor` is unsigned** — a shortfall is carried into the next
  window (`carried_out_minor`), and the column is what makes that a fact rather than a rule
- **`ledger_entries` has no update path.** The model throws on `updating` and `deleting`.
  Period stamping is a BULK update, which retrieves no models and so bypasses the guard —
  the one sanctioned post-insert write, and it stays the only one
- **`teaching_units.settlement_period_id` NULL means "the open window"** — the statement is
  defined as "every unit no close has claimed", which is true whether or not a period row
  names those days yet

## Course Authoring (spec 016)

```
course_sections gains:  uuid    → sections and chapters had no public identifier at all;
                        status    the endpoints took serial ids, which nothing ever called
course_chapters gains:  uuid    → same pair
                        status
lessons gains:          status  → draft · published · archived, replacing nothing —
                        reference_id     items had no lifecycle of their own before
                        exam_gate
courses gains:          structure_version → the concurrency token
```

- **`status` is three states, not a boolean.** A lesson any student has progress on may never
  be hard-deleted (`FR-007`), so "gone from the course" has to be a state the row can hold.
  `is_published` plus an `archived_at` would encode one lifecycle in two places and every
  query would have to read both — one forgotten branch shows an archived lesson
- **`unique(chapter_id, order)`**, and the two siblings likewise. It makes a duplicate
  position unrepresentable rather than merely unlikely, which is why reorder parks every row
  above the live range first: assigning final positions directly collides halfway through any
  swap. The parking offset is `max(order) + 1`, never a constant — `max(order)` tracks the
  group's LIFETIME create count, so a long-lived chapter holds rows past any fixed number
- **`reference_id` is ONE column whose meaning comes from `type`** (R9). No `reference_type`
  beside it: two columns recording one fact can disagree, and an exam id read against
  `class_sessions` is not a link preserved. No foreign key either — the target lives in
  another module, and `ReferenceIntegrity` resolves it at the READ instead, where every
  reader passes through it by construction
- **`exam_gate` is two values and no third.** A percentage field would be a second passing
  mark beside the exam's own, free to disagree with it. `attempt` is the default because it
  is the weaker gate, and a column default would not have applied: a model built with `new`
  carries none
- **`structure_version` is claimed, not compared.** One conditional
  `UPDATE … WHERE structure_version = ?` is both the check and the bump, so two editors who
  loaded the same tree cannot both write. Never `lockForUpdate()`, which is a no-op on SQLite
  and would prove nothing about the MySQL it runs on
- **The uuid backfill walks with `chunkById`, never `chunk`.** `chunk` paginates by OFFSET
  while the predicate (`uuid IS NULL`) shrinks under it — every page after the first skipped
  as many rows as the previous page fixed, and reported success

## Credit Billing (spec 006)

```
student_credit_accounts (PLATFORM)  one per person, across every teacher. No workspace_id
credit_balances         (workspace) one per (account, course). purchased/consumed/remaining,
                                    credit_limit_credits, notified_tier, negative_since,
                                    on_time_payments, last_transaction_at, notified_dormant_at
credit_transactions     (workspace) APPEND-ONLY. Signed `credits`. unique(balance, type,
                                    source_type, source_id) — the idempotency key itself
credit_lots             (workspace) a batch with its own remaining counter. THE one mutable
                                    row beside the ledger, and mutable so the draw can claim
credit_allocations      (NO KEY)    which lot paid for which consumption. What the draw
                                    CLAIMED, never re-derivable afterwards. The one table here
                                    with NEITHER a tenant key NOR platform ownership — a pure
                                    join between two rows that are both already scoped,
                                    reached only through transaction ids the caller has
                                    resolved. No route reads it and no payload carries it
credit_purchases        (workspace) what was bought, at the price frozen when it was bought
credit_packages         (PLATFORM)  the catalogue. No workspace_id: a package a teacher could
                                    define is a sale price a teacher sets (FR-021ب)
terms_consents          (PLATFORM)  who agreed, for whom, when, from where, to which version
exam_mode_windows       (workspace) a period on the teacher's calendar. Dates, not timestamps
credit_reconciliation_runs (PLATFORM) what the nightly sweep found, and that it ran at all
```

### Three ownership layers, in one diagram

The account and the catalogue and the consent are **platform-owned** — no `workspace_id`, no
global scope, and therefore no scope to fall back on: the guard is written explicitly in the
Action, the same rule spec 003's notification tables live under. A student has ONE credit
account across every teacher they study with, and giving it a `workspace_id` would silently
duplicate one person per teacher. The **balance** underneath it is workspace-owned, because
a credit is worth one session at one teacher's approved rate and is not portable.

### The absence is the design, restated from the other side

**Zero foreign keys between any of these ten tables and `teaching_units` / `ledger_entries` /
`settlement_periods`.** The student's payment and the teacher's pay share no key and no
query; the only bridge is 005's `SessionDelivered`, which both sides consume without knowing
about each other. `ContextIsolationTest` derives its table lists from each side's
`Schema::create` calls, so every table above was covered the day it landed.

- **`credit_transactions` has no update path.** The model throws on `updating` and
  `deleting`; a correction is a NEW entry of type `adjustment`, with a mandatory reason
- **The floor is `−credit_limit_credits`, and a ceiling of zero IS prepaid** for that
  student. There is no per-student `mode` column, because it would be a second answer to a
  question the floor already answers — and the two would disagree the first time either moved
- **`is_withheld` is not a column.** It is derived from the balance, the ceiling, the mode,
  the exam window and a CURRENT terms consent, so lifting it is instantaneous by
  construction and no `access_holds` table can drift from it
- **`credit_lots.expires_at` defaults to NULL — "never expires", the launch policy (Q-5).**
  The soonest-expiring-first draw order is live from day one anyway: switching expiry on
  later must not rewrite which lot paid for which past session
- **`terms_consents` stores the version it was signed against** and the readers ask for the
  one in force, so publishing new terms invalidates every old acceptance with no migration
  and no sweep — and touches not a single stored row
- **Money on `credit_purchases` is an integer in MINOR UNITS** (`bigInteger`, plus a
  `char(3)` currency), following Settlement rather than Payments' `decimal(12,2)`. NFR-009
  requires it: Laravel's `decimal:2` cast returns a **string**, so every sum goes through a
  float — tolerable on an order total, not on the snapshot spec 015's books are generated
  from. The API never sends a formatted amount either way.

  ⚠️ **«`orders` keeps its decimal columns» was true when it was written and is not now.**
  Spec 007 converted `orders.amount` and `payment_transactions.amount` to `amount_minor`
  (`bigInteger`), so minor units are the platform's ONE convention and there is no longer a
  boundary at `order_id` where two meet. Corrected here rather than deleted, because the
  sentence describes a real decision that was later reversed, and a reader who remembers the
  old rule needs to see it retired

## Question Bank, Grading, Homework and the Unlock Gate (spec 008)

```
concepts            (workspace) the tag a question is filed under. unique(workspace_id, name).
                                «غير مصنّف» is seeded per workspace, so a question always has one
exam_items          (workspace) THE JOIN THAT REPLACED `questions.exam_id`. An exam INCLUDES a
                                bank question at an order, with an optional points_override.
                                unique(exam_id, question_id)
attempt_items       (workspace) the paper as it was SAT: one row per question with a json
                                snapshot, its points and its order. unique(attempt_id, question_id)
rubric_criteria     (workspace) the mark scheme, hung off the QUESTION and never off the exam
grading_records     (workspace) APPEND-ONLY, no updated_at. One row per criterion per version;
                                a revision is a new version, never an edit
assignments         (workspace) homework. course_id, lesson_id, class_session_id all nullable;
                                the last is what US7's gate reads
submissions         (workspace) unique(assignment_id, student_user_id) — the contention point.
                                state/late_by_minutes/late_penalty_applied_pct are STAMPED
accommodations      (workspace) unique(workspace_id, student_user_id). extra_time_pct for exams,
                                extended_days for deadlines. Revoked, never deleted
question_imports    (workspace) one upload, its report and its duplicate policy
question_stats      (workspace) the nightly rollup, one row per question. unique(question_id)
concept_stats       (workspace) unique(workspace_id, concept_id, lesson_id) — lesson_id is
                                NOT NULL and ZERO means "the concept overall"
unlock_rules        (workspace) unique(workspace_id, course_id) — course_id is NOT NULL and
                                ZERO is the workspace default
unlock_exemptions   (workspace) unique(class_session_id, student_user_id)
```

Columns added to tables that already existed: `questions` grew `uuid`, `concept_id`,
`lesson_id`, `bloom_level`, `content_hash`, `is_active`; `exam_answers` grew `uuid`,
`student_user_id`, `answer_text`, `requires_grading`, `graded_at`, `graded_by`,
`grading_version`; `exam_attempts` grew `finalized_at`, `is_practice`, `duration_minutes`, a
`pending_grading` status and a **nullable** `exam_id`.

### Two zero sentinels, and they exist for the same reason

`concept_stats.lesson_id` and `unlock_rules.course_id` are both `NOT NULL` with **0** meaning
"no narrower scope". The obvious design — a nullable column inside the unique index — does
not work, because **NULL never equals NULL**: `upsert()` matches nothing on the one row every
workspace reads, INSERTs a new one every night, and after a month the screen shows whichever
row came back first. A number thirty days old sitting beside the correct one, with not one
error logged. `RollupIdempotencyTest` runs the job twice for exactly that reason.

The cost is that `(int) null === 0` in PHP, so a failed uuid resolve silently addresses the
default row. `UnlockRuleController` guards it with `abort_if` before writing.

### `questions.exam_id` is gone, and it took two releases to get there

The expand/contract was spread over two deploys on purpose. `_000550` relaxed the column to
nullable — the code in 008 stopped writing it, and a NOT NULL column nobody writes rejects
every insert. `_000600` (2026-08-26) dropped it, nine specs later, because `Exam::questions()`
was a `hasMany` on it and an un-restarted worker running `load('exam.questions.options')`
would have hit "Unknown column" on every grading for the length of the rollout.

⚠️ **The index is dropped first, in its own statement.** MySQL discards a single-column index
with its column; SQLite's native `ALTER TABLE … DROP COLUMN` **refuses an indexed column**, and
the whole suite runs on in-memory SQLite. The `rollback` is empty and cannot be otherwise: a
question now in two exams does not fold back into one column, a bank question in zero exams has
nothing to restore, and the column would not return `NOT NULL` — so the schema after a rollback
is not the one that preceded it, and the migration says so where somebody will read it.

- **A question is INCLUDED by an exam, not owned by one.** That is the whole spec in one
  line, and it is why `Exam::questions()` is now a `belongsToMany` through `exam_items`
- **`attempt_items` is the denominator.** Grading reads the snapshot, never the live
  question — an exam edited after a student sat it must not change what they were marked on.
  A backfill built these rows for every pre-existing attempt, without which each one's
  denominator would be zero and its score a silent lie
- **`grading_records` is append-only and `submissions` is not.** The grade of an essay is a
  judgement with a history; a submission is a document with a state
- **An accommodation is exposed by its EFFECT, not its name** (FR-056). `state`,
  `submitted_at`, `extension_until` and an attempt's expiry are owner-private fields
- **The rollup excludes two invisible families**: `is_practice` attempts (the flag is on
  `exam_attempts` while the answers are on `exam_answers`, so the join exists only for it),
  and essays with `requires_grading` and no `graded_at` — those carry `is_correct = false`
  because no machine can judge them, and counting them reports every essay as 100% wrong
- **A sample below `min_sample_size` stores `wrong_pct = NULL`, never zero.** "Nobody got
  this wrong" is what makes a teacher delete a good question two students happened to sit

## Data Protection and Minors (spec 013)

Seven new tables, and **six of them are platform-owned (layer ب)** with no
`workspace_id` at all. That is the design rather than an omission: one person has
one date of birth, one set of consents, one erasure request and one answer to it,
however many teachers they study with. `BelongsToWorkspace` on any of them would
silently duplicate one person per teacher, the mirror-image bug
`PlatformOwnershipTest` catches in both directions.

`teacher_offboardings` is the exception and the only model in the module that uses
the trait: an exit is one workspace winding down, so the tenant column is the
subject of the row rather than a partition of somebody's personal data.

```
data_categories     (platform)  the catalogue: key, purpose_ar, lawful basis, retain_days,
                                erasure_mode, is_optional. Reference data, edited from /admin
data_processors     (platform)  who receives data outside our servers. categories[] and an
                                honest erasure_capability per row (full · partial · none)
data_requests       (platform)  one right exercised. type, status, due_at, requested_by_user_id,
                                executed_by_user_id, export_path, export_expires_at,
                                granted_scope, refusal_reason.
                                unique(open_key) — NULL never collides, so one OPEN request
                                per subject and any number of closed ones
legal_holds         (platform)  subject_user_id, reason, placed_by, placed_at, released_at.
                                Released, never deleted: the row IS the record that an
                                erasure was suspended
retention_sweep_runs (platform) one row per NIGHT, including nights that find nothing —
                                an absent row is the only evidence the sweep did not run
breach_reports      (platform)  reported_by_user_id NULLABLE (an outside researcher holds no
                                account), reporter_contact, description, affected_categories,
                                affected_subject_count, authority_notified_at,
                                subjects_notified_at, closed_at. index(status, created_at)
teacher_offboardings (workspace) the module's one scoped model. status
                                (requested · settlement_pending · notice_period · completed),
                                settlement_cleared_at, students_notified_at, notice_ends_at,
                                content_export_path, completed_by_user_id, completed_at.
                                index(status, created_at)
```

Columns added to tables that already existed:

- `student_profiles` grew `date_of_birth`, `dob_is_estimated`, `guardian_contact` and
  `ownership_transferred_at`.
- `terms_consents` grew `categories` and `decision`, so one signature records which
  optional categories were accepted rather than a single yes.
- `media_assets` grew `archived_at` (the mark that makes `Archive` converge) and
  `retain_until` (the departed teacher's floor, an **OR** against the age rule).
- `certificates` grew `student_display_name`, frozen at issue — a certificate must
  still name its holder after the account behind it is anonymised.
- Six retention indexes on `created_at`, because the sweep's predicate is an age:
  `attendances`, `exam_attempts`, `exam_answers`, `attempt_items`, `lesson_progress`
  and `invitations`. `attendances` already had `(student_user_id, created_at)` and it
  is **unusable** for an age predicate — a composite index is only usable from its
  leading column. `attempt_items` holds no personal column of its own, only a
  snapshot of what a named person was asked, so it hides from a search for one.

### The absence is the design, a third time

- **No `access_holds`, no `is_withheld`, no breach-deadline column.** Every deadline
  in this phase is derived: the request's `due_at` is the one stored date, because
  it is the promise made to the person when they asked. A breach's two notice
  deadlines are `created_at` plus a `platform_settings` row, computed in the
  Resource — a column would stop agreeing with the setting the first time a
  regulator shortens it, which is exactly the number an operator changes.
- **`ownership_transferred_at` is deliberately NOT `$fillable`.** It is claimed by a
  conditional UPDATE, the `captured_order_id` rule. Its three neighbours ARE
  fillable — and were not when they shipped, so every self-registered student's date
  of birth was discarded in silence, the guardian-consent gate never fired, and the
  coming-of-age sweep walked an empty set. Mass assignment drops a non-fillable key
  with no exception and a `201`, and every US1 assertion was made against the
  response body, which echoes what was submitted rather than what was stored.
- **`data_requests.open_key` is a nullable unique column, not a partial index.**
  MySQL has no partial indexes; `WHERE status = 'pending'` on an index is a Postgres
  feature. The column is written at creation and NULLed the moment the request
  closes, so NULL-never-equals-NULL is the whole guard.

## Chat, Assistants, Reviews and Announcements (spec 010)

Twelve tables, **eleven of them layer 2 (workspace-owned) and exactly one layer 3**
— a conversation belongs to one teacher's practice, and so does a ban, a review and
an announcement. `report_cards` is the exception and carries **no `workspace_id` at
all**: a student has ONE cumulative record across every teacher they study with, and
scoping it would duplicate one person per teacher — the mirror-image bug
`PlatformOwnershipTest` exists to catch in both directions. Its `report_card_segments`
DO carry one, because a segment is exactly «this teacher's contribution».

```
workspaces ─┬─< conversations ──< messages ──< (moderation_actions)
            │        │  kind: private | session | lesson
            │        └─< conversation_participants
            ├─< blocked_terms
            ├─< assistant_assignments ──< assistant_scopes   (course_id)
            ├─< periodic_reviews          (student_user_id · teacher_user_id)
            ├─< grading_schemes           (the weights a report card is built from)
            ├─< announcements             (scope: all | course | session)
            └─< report_card_segments      (this teacher's contribution)
                        │
                        ˅  belongs to
users ──────────────< report_cards        ⚠️ LAYER 3 — no workspace_id at all
                                             one cumulative record per person
```

### Three extensions to tables this phase did not create

| Table | Column(s) | Why here and not in a new table |
|---|---|---|
| `marketplace reviews` | the axes, plus a repointed unique | 001 already owned "a student's opinion of a teacher"; a second review table would be two answers to one question, and the trust score reads the first one |
| `notifications` | `source_type` · `source_id` · `index(source_type, source_id, read_at)` | an announcement's «كم قرأه» is a grouped COUNT over the rows the fan-out already wrote — a `read_count` column on `announcements` is a second copy that drifts |
| `messages` | the attachment columns | an attachment IS a message here; a separate table would make ordering and moderation two joins for one thread |

### The two claimed columns, and one that is deliberately not

- **`announcements.published_at` is claimed by a conditional UPDATE** and is NOT
  `$fillable`. `UPDATE … WHERE published_at IS NULL AND hidden_at IS NULL` is both
  the check and the claim, so two runners publishing one draft fan out exactly
  once. The seat idiom, shared with `captured_order_id` and `StructureVersion`.
- **`conversations.last_message_id` is claimed the same way** and for the same
  reason — mass-assignable it becomes a second way to move the pointer from
  outside the statement that owns the comparison.
- **`assistant_assignments.revoked_at` is not fillable either**, and revocation is
  instant because `AssistantScopeDirectory` is bound `scoped()`: a `singleton()`
  would keep answering `true` inside a worker until it restarted.

### `hidden_at`, never `deleted_at`

On `messages` and on `announcements` both. A soft delete puts the row behind
Laravel's global scope, where the moderation screen and the audit cannot see the
thing they just acted on — and a moderator who cannot read what they hid cannot
undo a mistake.

### The absence is the design, a fourth time

- **No `closed_at` on `conversations`.** When a teacher's exit completes, writing
  stops because `ConversationPolicy::post()` asks
  `TeacherOffboardingDirectory::hasDeparted()` — and it has to be a question rather
  than a stamp, because `StartConversation` authorises an UNSAVED `Conversation`
  against that same ability. A column could not answer for a row that does not
  exist yet, and a second guard beside the other door is the two-spellings defect.
- **No `read_count`, no `notified_count` on `announcements`.** Both are grouped
  counts over `notifications`, which is the table the fan-out actually wrote.
- **No teacher column on `conversations`.** A private thread names its student and
  its workspace; the teacher's side is DERIVED from membership, so it stays true
  as assistants come and go — and «who am I talking to» has two right answers
  depending on who is asking, only one of which is a user row.

---

## Course Groups (spec 021)

Five tables, **every one layer 2 (workspace-owned)** — a group is a run of one
teacher's course, and so is a membership, its history and a transfer request. The
fifth lives in Community because a write ban is a fact about a thread.

```
workspaces ─┬─< cohorts                       (course_id · capacity · members_count · status)
            │       │  status: open | closed | archived   ⚠️ no delete (FR-035)
            │       ├─< cohort_memberships    (student_user_id · course_id · joined_at · closed_at)
            │       └─< class_sessions.cohort_id          ⚠️ nullable — see below
            ├─< cohort_membership_events      (joined | transferred | left | removed | requested …)
            ├─< cohort_transfer_requests      (to_cohort_id · from_cohort_id · status · decision_reason)
            └─< conversations.cohort_id ──< conversation_write_bans
                        ⚠️ unique(cohort_id), nullable          (user_id · expires_at · lifted_at)
```

### Four claimed columns, and every one of them is a unique index

| Table | Column | What it guards |
|---|---|---|
| `cohort_memberships` | `closed_slot` — `0` while open, the row's own id afterwards | `unique(student_user_id, course_id, closed_slot)` — ONE open membership per course, enforced by the database. MySQL has no partial index, so «unique WHERE closed_at IS NULL» does not exist on the engine this ships to |
| `cohort_transfer_requests` | `pending_slot`, the same idiom | one pending request per course. A second one is `request_pending`, not a queue |
| `cohorts` | `members_count` | claimed by the atomic conditional UPDATE that takes the seat — never `count()` then `insert()`, and never `lockForUpdate()`, a no-op on SQLite |
| `conversations` | `cohort_id`, **nullable** with `unique()` | one thread per group. NULL never equals NULL, so every private, session and lesson row coexists freely — the `captured_order_id` idiom |

Neither slot column is `$fillable`: each is written inside the statement that owns
its transition, and mass-assignable it becomes a second door to the thing the
claim is the only correct writer of.

### `class_sessions.cohort_id` is nullable, and that is not laziness

Every session in this database predates the group and carries `null`. A bare
`whereNotNull` in the student's discovery list would empty the timetable of every
course that has no groups (FR-036) — so `CohortSessionVisibility` reads
«my groups' sessions, **plus** the unassigned ones, **plus** anything I already
hold a seat in». That last clause is the one that matters: hiding an unassigned
session from discovery must never swallow a seat the student has paid for.

### `cohort_membership_events` is append-only history, not a state

Rejoining a group writes a NEW `cohort_memberships` row rather than reopening the
old one — «left on the 3rd» and «joined on the 9th» are two facts, and one row
edited twice is neither of them. The events table is what the teacher's history
screen reads, and it is why the roster filters on `closed_at IS NULL` rather than
deleting anything.

## Commerce Tables (spec 011)

```
┌──────────────────────────────────────────────┐
│ feature_flags                                │
├──────────────────────────────────────────────┤
│ id · uuid · key(64) · enabled · description  │
│ workspace_id  ← 0 = the platform default     │
│ unique(key, workspace_id)                    │
└──────────────────────────────────────────────┘
```

```
┌─────────────────────────────────┐   ┌───────────────────────────────────┐
│ store_items                   │   │ store_orders                     │
├─────────────────────────────────┤   ├───────────────────────────────────┤
│ id · uuid · workspace_id      │   │ id · uuid · workspace_id         │
│ course_id (nullable)          │───│ order_id (unique → orders)      │
│ kind · title · excerpt         │   │ store_item_id · buyer_user_id   │
│ price_minor · currency         │   │ quantity · unit_price_minor     │
│ stock  ← SIGNED, nullable      │   │ discount_minor · shipping_minor │
│ shipping_fee_minor            │   │ commission_minor               │
│ media_asset_id · is_active     │   │ teacher_net_minor  ← never sent │
└─────────────────────────────────┘   │ fulfilled_at ← the claim        │
                                      │ first_accessed_at · refunded_at │
┌─────────────────────────────────┐   └───────────────────────────────────┘
│ shipments                     │            │
├─────────────────────────────────┤            │
│ id · uuid · workspace_id      │────────────┘
│ store_order_id (unique)       │
│ recipient_name · phone        │  ← a child's home address:
│ address_line · notes          │    `shipping_address`, 730 days,
│ status · tracking_ref         │    anonymised in place
│ status_changed_at · created_at│
└─────────────────────────────────┘
```

The plan, coupon, referral and region tables land with the waves after US1.

### `store_orders.shipping_minor` is frozen, like everything else on the line

`total_minor` is what a buyer transfers, and it omitted the postage until this
column existed: a printed purchase showed 50 while `orders.amount_minor` — the
sum the transfer must match — was 65, so the buyer sent what the screen told
them and `HandleProviderCallback` answered `mismatch`. No delivery, no refund,
and a reconciliation case opened over our own arithmetic.

It is frozen rather than read back off `store_items` for the reason
`unit_price_minor` is: the teacher may raise the postage tomorrow, and a Resource
that derived it from an eager-loaded item would additionally answer a DIFFERENT
number whenever the relation was not loaded.

### `feature_flags.workspace_id` is `0`, never `NULL`

`NULL` never equals `NULL`, so a unique index carrying a nullable column does not
bite on the one row every request reads: two platform defaults for the same key
coexist, an `updateOrCreate` matches neither and inserts a third, and the answer
becomes whichever row the engine returns first — with nothing logged. The third
time this tree has reached for the sentinel (`concept_stats.lesson_id`,
`unlock_rules.course_id`).

The price is that `(int) null === 0` addresses the DEFAULT row, which for a
feature switch means one teacher's unresolved uuid turning a feature off for the
whole platform. Reads are safe (a null context is a guest, and the default is the
right answer for one); every write guards with `abort_if` first, as
`UnlockRuleController` does over the same sentinel.

The table deliberately carries **no** `BelongsToWorkspace` — recorded as a
violation in `specs/011-commerce-growth/plan.md § Complexity Tracking`. The trait
would fill the column from the current context, and a scope keyed on it would
hide the platform row from every reader that needs it as a fallback.

### `cms_articles.slug` is unique platform-wide from spec 011

It was `unique(workspace_id, slug)`, which makes the public `/blog/{slug}`
ambiguous by definition: two teachers may both publish «خطة-المراجعة», and which
one a reader gets can change between two requests. Every other index on the table
also starts with `workspace_id`, and a public reader has no workspace — so each
article opened and each sitemap built was a full table scan.

⚠️ A soft-deleted article now holds its slug against the whole platform: a unique
index does not know about `deleted_at`. `SaveArticle` must answer «هذا الرابط
مستخدم» from the trashed set too, or a teacher gets a raw integrity error.
