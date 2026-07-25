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

## Key Relationships

- **Workspace → Users:** Many-to-many via `workspace_members` (pivot with role)
- **Course → Sections → Chapters → Lessons:** Hierarchical content structure
- **Enrollment → LessonProgress:** One progress record per lesson per enrollment
- **Exam → Questions → QuestionOptions:** Assessment content with correct answer tracking
- **Attempt → Answers:** Student responses with auto-grading
- **Certificate:** Unique per (workspace, enrollment, course) — idempotent issuance
- **Order → PaymentTransaction:** Manual payment flow with receipt upload + approval
