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
       │ │ id · workspace_id       │    │ id · workspace_id       │
       │ │ course_id (FK)          │───►│ course_id (FK)          │
       │ │ title · order           │    │ section_id (FK)         │
       │ │ is_published            │    │ title · order           │
       │ └─────────────────────────┘    └───────────┬─────────────┘
       │                                              │
       │                              ┌──────────────▼──────────────┐
       │                              │  lessons                     │
       │                              ├──────────────────────────────┤
       │                              │ id · uuid · workspace_id     │
       │                              │ course_id · section_id       │
       │                              │ chapter_id · title · type    │
       │                              │ content · order · duration   │
       │                              │ is_preview · is_free         │
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
       │ │ amount · currency · provider · provider_ref · status         │
       │ │ rejection_reason · approved_by · approved_at · metadata      │
       │ └──────────┬──────────────────────────────────────────────────┘
       │            │
       │ ┌──────────▼─────────────┐
       │ │ payment_transactions    │
       │ ├─────────────────────────┤
       │ │ id · workspace_id       │
       │ │ order_id (FK)           │
       │ │ provider · reference    │
       │ │ amount · status         │
       │ └─────────────────────────┘
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
