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
       │                              │ is_preview · is_free · media │
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

Platform-level (deliberately NOT workspace-scoped — like `users`):

┌──────────────────────────┐      ┌──────────────────────────┐
│ parent_child_links       │      │ notification_preferences │
├──────────────────────────┤      ├──────────────────────────┤
│ id · uuid                │      │ id · uuid                │
│ parent_id (FK → users)   │      │ user_id (unique)         │
│ child_id (FK, nullable)  │      │ weekly_reports           │
│ child_name · child_age   │      │ session_alerts           │
│ child_grade_level_slug   │      └──────────────────────────┘
└──────────────────────────┘
   UNIQUE (parent_id, child_id)

workspaces gains: participates_in_marketplace (bool, default false)
                  owner_user_id is now NULLABLE (the platform workspace has no owner)
users gains:      platform_role · phone · country · grade_level_slug · registered_by_parent
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
