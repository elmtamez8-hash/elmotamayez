# Phase 1 — Data Model: سوق المدرّسين العام

**Feature**: `001-public-marketplace-pages` · **Date**: 2026-08-01

كل جدول تابع لمستأجر يحمل `workspace_id` وسمة `BelongsToWorkspace` وحالة في
`WorkspaceIsolationTest` (الدستور، المبدأ I). كل نموذج يستخدم `HasUuid`
ويكشف `uuid` لا `id` (المبدأ VI).

---

## نماذج جديدة — وحدة `Marketplace`

### `teacher_profiles`

| الحقل | النوع | ملاحظات |
|---|---|---|
| `id` | PK | داخلي فقط، لا يُكشف |
| `uuid` | uuid, unique | مفتاح المسار |
| `workspace_id` | FK → workspaces | `BelongsToWorkspace` |
| `user_id` | FK → users, unique | واحد-لواحد |
| `headline` | string(160) | التخصص المعروض |
| `bio` | text | النبذة |
| `qualifications` | json | المؤهلات والشهادات (نصوص) |
| `years_experience` | unsignedTinyInteger | 0–70 |
| `teaching_languages` | json | رموز لغات |
| `hourly_rate` | decimal(10,2) | السعر لكل حصة |
| `currency` | char(3) | افتراضي `QAR` |
| `photo_path` | string, nullable | مستضافة ذاتياً |
| `is_verified` | boolean | شارة "موثّق" |
| `approval_status` | enum | `pending` / `approved` / `rejected` / `suspended` |
| `is_publicly_listed` | boolean, default false | علم النشر (FR-002) |
| `trust_score` | unsignedTinyInteger, nullable | `null` = قيد التكوين |
| `trust_score_factors` | json, nullable | قيم العوامل وقت الاحتساب |
| `trust_score_calculated_at` | timestamp, nullable | |
| `completed_sessions_count` | unsignedInteger, default 0 | عدّاد مادّي للأداء |
| `reviews_count` | unsignedInteger, default 0 | عدّاد مادّي |
| `average_rating` | decimal(3,2), nullable | عدّاد مادّي |
| `response_rate` | unsignedTinyInteger, nullable | نسبة مئوية |
| `attendance_rate` | unsignedTinyInteger, nullable | نسبة مئوية |
| `first_session_at` | timestamp, nullable | أساس حساب الأقدمية |

**فهارس**: `(is_publicly_listed, approval_status, trust_score)` ·
`(is_publicly_listed, hourly_rate)` · `(is_publicly_listed, average_rating)` ·
`workspace_id` · `user_id` unique

**قواعد التحقق**: `hourly_rate ≥ 0` · `years_experience ≤ 70` ·
`approval_status` من قائمة مغلقة · `user_id` موجود عبر قاعدة عامة (المستخدم ليس تابعاً لمستأجر)

**انتقالات الحالة** (FR-015 — إجراء مُدار لا تعديل حقل مباشر):

```
pending ──approve──▶ approved ──suspend──▶ suspended ──reinstate──▶ approved
   │                     │
   └──reject──▶ rejected └──(revoke verification)──▶ approved (is_verified=false)
        │
        └──resubmit──▶ pending
```

`is_publicly_listed` **يُشتق** ولا يُضبط يدوياً: يصبح `true` فقط عند
`approval_status = approved` **و** اشتراك مساحة العمل في السوق.

> **تنبيه عرض عمود (الدستور، قيود البيئة)**: `unsignedTinyInteger` يقبل 0–255 في MySQL
> الصارم ويقبل أي عدد في SQLite. درجة الثقة والنسب المئوية 0–100 فتناسبه —
> لكن أي عدّاد يتجاوز 255 **يجب** أن يكون `unsignedInteger`.

---

### `teacher_applications`

| الحقل | النوع | ملاحظات |
|---|---|---|
| `uuid`, `workspace_id`, `user_id` | | |
| `step_data` | json | بيانات الخطوات الأربع للاستئناف (FR-070) |
| `current_step` | unsignedTinyInteger | 1–4 |
| `status` | enum | `draft` / `submitted` / `approved` / `rejected` / `changes_requested` |
| `submitted_at` | timestamp, nullable | |
| `reviewed_by` | FK → users, nullable | من قرّر (FR-016) |
| `reviewed_at` | timestamp, nullable | |
| `rejection_reason` | text, nullable | |

**ملاحظة أمنية**: `step_data` **لا يخزّن أي مستند هوية** — خطوة المستندات واجهة فقط
(FR-071). أي محتوى ملف يُرفض على مستوى التحقق لا على مستوى العرض.

---

### `reviews`

| الحقل | النوع | ملاحظات |
|---|---|---|
| `uuid`, `workspace_id` | | |
| `teacher_profile_id` | FK | |
| `student_id` | FK → users | |
| `rating` | unsignedTinyInteger | 1–5 |
| `comment` | text, nullable | |
| `is_visible` | boolean, default true | إخفاء إداري |

**قيد فريد**: `(teacher_profile_id, student_id)` — سجل فعّال واحد (FR-019).
إعادة التقييم `update` لا `insert`.

**قاعدة عمل مفروضة في الـ Action** (المبدأ II، لا في التحقق فقط):
`SubmitReview` يرفض إن لم يكن للطالب حصة مكتملة واحدة مع المدرّس (FR-018).

**فهرس**: `(teacher_profile_id, is_visible, created_at)`

---

### `availability_slots`

| الحقل | النوع | ملاحظات |
|---|---|---|
| `uuid`, `workspace_id`, `teacher_profile_id` | | |
| `day_of_week` | unsignedTinyInteger | 0–6 |
| `start_time` | time | مخزّن بـ UTC |
| `end_time` | time | مخزّن بـ UTC |

**قاعدة**: رفض التداخل لنفس المدرّس واليوم (FR-028) — مفروضة في `SetAvailability`.
**التخزين UTC والعرض بتوقيت الزائر** (FR-029) — التحويل في الواجهة لا في قاعدة البيانات.

---

### `complaints`

| الحقل | النوع | ملاحظات |
|---|---|---|
| `uuid`, `workspace_id`, `teacher_profile_id` | | |
| `reported_by` | FK → users | |
| `reason` | text | |
| `status` | enum | `open` / `confirmed` / `dismissed` |
| `confirmed_at` | timestamp, nullable | الشكوى المؤكدة فقط تخصم |

فقط `status = confirmed` تدخل في خصم درجة الثقة (نموذج درجة الثقة، المواصفة).

---

### `subjects` و `grade_levels`

تصنيفان مشتركان: `uuid`, `workspace_id`, `name_ar`, `slug`, `icon`, `sort_order`,
`is_active`. جدولا ربط `teacher_profile_subject` و`teacher_profile_grade_level`.

**قرار**: تابعان لمستأجر (`BelongsToWorkspace`) اتساقاً مع بقية النماذج، مع بذرة موحّدة
تُنشئ نفس القائمة لكل مساحة عمل. الفلترة العامة تتم بـ `slug` لا بالمعرّف، لأن المدرّسين
عبر مساحات عمل مختلفة يشتركون في الـ slug لا في الصف.

---

## تعديلات على نماذج قائمة

### `users` (وحدة `Identity`)

| الحقل الجديد | النوع | ملاحظات |
|---|---|---|
| `platform_role` | enum, nullable | `student` / `teacher` / `parent` (R3, FR-009) |
| `phone` | string, nullable | مع رمز الدولة |
| `country` | char(2), nullable | |
| `grade_level_slug` | string, nullable | للطالب |
| `registered_by_parent` | boolean, default false | FR-064 |

`platform_role` **يُضاف إلى `$guarded` و`$hidden`** أسوةً بـ `is_super_admin`
(`User.php:34,40`) ولا يُضبط إلا داخل Actions التسجيل.

### `workspaces` (وحدة `Tenancy`)

| الحقل الجديد | النوع | ملاحظات |
|---|---|---|
| `participates_in_marketplace` | boolean, **default false** | اشتراك صريح (FR-001) |

القيمة الافتراضية `false` غير قابلة للتفاوض: مساحة عمل قائمة **يجب ألا** تُنشر بأثر رجعي.

### `courses` (وحدة `Courses`)

**بلا أعمدة جديدة.** يُعاد استخدام `status` و`visibility` القائمين (`Course.php:36-37`)
وسمة `IsPublishable` (R9). النشر العام للكورس = `isPublished()` **و**
`visibility = 'public'` **و** اشتراك مساحة العمل.

`toSearchableArray()` (`Course.php:88`) **يُعدَّل** لإضافة علم النشر العام حتى تُستبعد
الكورسات غير المنشورة من الفهرس لا بعد استرجاعها (R2).

### `parent_child_links` (جدول جديد، وحدة `Identity`)

`uuid`, `parent_id` FK → users, `child_id` FK → users nullable, `child_name`,
`child_age`, `child_grade_level_slug`.
**قيد**: وليّ أمر لا يصل إلى ابن غير مرتبط بحسابه (FR-075) — سياسة، لا عمود.

### `notification_preferences` (جدول جديد)

`user_id`, `weekly_reports` boolean, `session_alerts` boolean (FR-076).

---

## سمة مشتركة جديدة — `IsPubliclyListed`

`backend/app/Shared/Traits/IsPubliclyListed.php`

توفّر نطاقاً محلياً `publiclyListed()` يشترط الثلاثة معاً:

1. علم النشر على العنصر نفسه
2. `participates_in_marketplace` على مساحة العمل المالكة
3. حالة الاعتماد صالحة (للمدرّس)

**سبب وجودها كسمة لا كشرط مكرر**: هي **الحارس البديل** الذي يقوم مقام `WorkspaceScope`
المعطّل أمام الزوار (R1). تكرارها يدوياً في كل استعلام يعني أن نسيانها مرة واحدة = تسريب.

---

## الأحداث (المبدأ III — لا استدعاء مباشر بين الوحدات)

| الحدث | المُصدِر | المستمع | الأثر |
|---|---|---|---|
| `TeacherApplicationSubmitted` | Marketplace | Notifications | إخطار الفريق الأكاديمي |
| `TeacherApproved` | Marketplace | Notifications | إخطار المدرّس + نشره في السوق |
| `TeacherRejected` | Marketplace | Notifications | إخطار بالسبب |
| `ReviewSubmitted` | Marketplace | Marketplace | وظيفة إعادة احتساب درجة الثقة |
| `ComplaintConfirmed` | Marketplace | Marketplace | وظيفة إعادة الاحتساب |
| `SessionCompleted` | Learning (لاحقاً) | Marketplace | تحديث العدّادات + إعادة الاحتساب |

**قاعدة إلزامية**: وظيفة إعادة الاحتساب تستخدم
`WorkspaceContext::forWorkspace($workspace, fn () => ...)` — **ممنوع** `set()` في الطابور،
لأنها مفردة على مستوى التطبيق تسرّب مساحة العمل إلى المهمة التالية على نفس العامل
(الدستور، المبدأ I).

---

## الأذونات الجديدة (ثوابت `Tenancy\Support\Permissions` — لا نصوص حرفية)

`marketplace.teachers.review` · `marketplace.teachers.approve` ·
`marketplace.teachers.suspend` · `marketplace.reviews.moderate` ·
`marketplace.complaints.manage` · `marketplace.participation.manage`

---

## مخطّط العلاقات

```
workspaces ─1:N─ teacher_profiles ─1:1─ users
                      │
                      ├─1:N─ reviews ──N:1── users (student)
                      ├─1:N─ availability_slots
                      ├─1:N─ complaints
                      ├─N:M─ subjects
                      └─N:M─ grade_levels

users ─1:N─ parent_child_links ─N:1─ users (child)
users ─1:1─ notification_preferences
users ─1:N─ courses (created_by)   ← علاقة المدرّس بكورساته (R9، بلا عمود مكرر)
```
