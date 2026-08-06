# Data Model — الحصص المباشرة والحضور والتجميد

**Feature**: `005-live-sessions-attendance` · **Date**: 2026-08-06

خمسة جداول، وكل واحد مصنَّف في طبقة ملكية قبل هجرته (المبدأ I — التصنيف قرار مُلزِم لا
اجتهاد وقت الكتابة).

---

## 1. `class_sessions` — الحصة

**الطبقة**: مملوك لمساحة العمل · `BelongsToWorkspace` + `HasUuid`

**تنبيه تسمية**: `ClassSession` لا `Session` — الاسم محجوز لـ`AuthSession` (004) ولمساعد
الإطار المدمج.

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | المسارات تكشف `uuid` فقط |
| `workspace_id` | FK | |
| `teacher_profile_id` | FK | صاحب الحصة |
| `course_id` | FK · nullable | حصة قد ترتبط بكورس أو بمادة وحدها |
| `subject_id` | FK · nullable | |
| `title` | string | |
| `type` | enum | `individual` · `group` — **معلن لا مستنتَج** (FR-001أ) |
| `status` | enum | أدناه |
| `starts_at` · `ends_at` | timestamp UTC | التخزين UTC، والعرض بـ`sessions.timezone` (R9) |
| `duration_minutes` | unsignedSmallInteger | مشتقّ ومخزَّن — كل الحسابات الزمنية تقرأه |
| `seats_total` | unsignedSmallInteger | |
| `seats_taken` | unsignedSmallInteger | **يُحدَّث بتحديث شرطي ذرّي وحده** (R5) |
| `billable_seats` | unsignedSmallInteger · nullable | يُكتب مرة عند مهلة الإلغاء، ولا يُعاد احتسابه (FR-060) |
| `seats_frozen_at` | timestamp · nullable | لحظة التجميد — ما يجعل «مرة واحدة» قابلاً للفحص |
| `broadcast_provider` | string · nullable | **يُمنع** ظهوره في أي مورد (FR-019) |
| `broadcast_room_id` | string · nullable | كذلك |
| `room_opened_at` · `room_closed_at` | timestamp · nullable | |
| `recording_status` | enum · nullable | `pending` · `ingesting` · `published` · `failed` |
| `recording_attempts` | unsignedTinyInteger | حدّ المحاولات (FR-031) |
| `media_asset_id` | FK · nullable | التسجيل بعد نشره |
| `delivered_at` | timestamp · nullable | لحظة `SessionDelivered` (FR-057) |
| `interruption_note` | string · nullable | انقطاع من طرف المزوّد (حالة حافّة) |
| `cancelled_at` · `cancellation_reason` | | |

**الفهارس** (NFR-009): `(workspace_id, teacher_profile_id, starts_at)` للتداخل وجدول
المدرّس · `(workspace_id, status, starts_at)` للجدولة · `(starts_at)` للوظائف المؤجّلة.

### حالات الحصة (FR-005)

```
scheduled ──▶ live ──▶ completed
    │           │
    │           └────▶ interrupted ──▶ completed
    ├──▶ cancelled          (انقطاع المزوّد؛ المدة المحتسَبة تبقى سليمة)
    └──▶ suspended          (وقعت داخل تجميد — FR-040)
```

`completed` **لا** تعني «نُفِّذت». التنفيذ حقل مستقل `delivered_at` يُكتب فقط عند تحقّق
شروط FR-056 الثلاثة — حصة لم يحضرها المدرّس تنتهي ولا تُنفَّذ (R7).

---

## 2. `session_bookings` — الحجز

**الطبقة**: **جسر** — `workspace_id` للسياق و`student_user_id` يشير إلى الطالب العام.

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` · `workspace_id` | | |
| `class_session_id` | FK | |
| `student_user_id` | FK → `users` | الطالب كيان منصّة واحد عبر كل مدرّسيه |
| `status` | enum | `booked` · `cancelled_in_window` · `cancelled_late` · `released` |
| `booked_at` · `cancelled_at` · `cancellation_reason` | | |
| `is_billable` | boolean | `booked` أو `cancelled_late` (FR-010) |

**فهرس فريد**: `(class_session_id, student_user_id)` — يمنع الحجز المزدوج من الطالب نفسه.
الحارس ضد تجاوز المقاعد **ليس** هنا: هو التحديث الشرطي على `seats_taken` (R5).

**`released`**: انتهت أهلية الطالب قبل الموعد فتحرّر مقعده تلقائياً (FR-012) — حالة مستقلة
عن الإلغاء لأن الطالب لم يفعل شيئاً.

---

## 3. `attendances` — الحضور

**الطبقة**: **جسر**. القراءة محروسة: الطالب يقرأ صفّه، ووليّ أمره يقرأ صفّ ابنه وحده
(FR-023ب)، والمدرّس يقرأ صفوف حصصه هو (NFR-001أ).

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` · `workspace_id` | | |
| `class_session_id` · `student_user_id` | FK | |
| `status` | enum | `present` · `absent` · `late` · `excused` — **قائمة مغلقة** (FR-048) |
| `source` | enum | `automatic` · `manual` (FR-023) |
| `first_joined_at` | timestamp · nullable | أول نبضة |
| `last_ping_at` | timestamp · nullable | مرجع حساب الفارق (R3) |
| `stay_seconds` | unsignedInteger | المدة المُجمَّعة |
| `auto_status` | enum · nullable | **المصدر الآلي يبقى ظاهراً بعد التعديل** (FR-025) |
| `overridden_by` · `overridden_at` · `override_reason` | | من ومتى ولماذا |
| `confirmed_at` | timestamp · nullable | لحظة `AttendanceConfirmed` (FR-050) |
| `recording_watched_at` | timestamp · nullable | **واقعة مستقلة** — لا تغيّر `status` (FR-021د) |

**فهرس فريد**: `(class_session_id, student_user_id)`. **فهرس**: `(student_user_id, created_at)`
لجدول الطالب وتقاريره.

### السُّلَّم الزمني (FR-021 · SC-020)

مع `T` = لحظة البدء، و`G` = `grace_minutes`، و`H` = `absence_threshold_ratio × duration`:

| أول نبضة | البقاء ≥ المطلوب | الحالة |
|---|---|---|
| ضمن `T+G` | نعم | `present` |
| ضمن `T+G` | لا | `late` |
| بين `T+G` و`T+H` | — | `late` |
| لا نبضة حتى `T+H` | — | `absent` — **تُكتب عند `T+H` لا عند النهاية** (FR-021ب · SC-021) |
| بعد `T+H` | — | تبقى `absent`؛ يُسمح بالدخول ولا تنقلب آلياً (FR-021ج) |

`excused` **لا تُمنح آلياً أبداً** (FR-049 · SC-014) — بقرار مسجَّل من المدرّس أو الإدارة.

**احتساب المدة**: كل نبضة تضيف `min(now − last_ping_at, 2 × presence_interval)`. من هذا
السطر تأتي: جهازان لا يضاعفان (FR-024)، والعودة بعد انقطاع تُجمَّع لا تُسجَّل من جديد،
وفجوة الانقطاع لا تُحتسب حضوراً.

---

## 4. `class_session_feedback` — تقييم الحصة

**الطبقة**: **جسر**. صفّ لكل (حصة، طالب): `rating` صغير و`note` نصّي و`created_by`.

**فهرس فريد**: `(class_session_id, student_user_id)`. التقرير لا ينتظره (FR-035): يُرسَل
بحالة الحضور وحدها إن لم يُدخَل.

---

## 5. `freeze_periods` — فترة التجميد

**الطبقة**: مملوك لمساحة العمل · `BelongsToWorkspace`.

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` · `workspace_id` | | |
| `student_user_id` | FK · **nullable** | `null` = كل طلاب المدرّس · قيمة = تجميد فردي (FR-039) |
| `starts_on` · `ends_on` | date | |
| `reason` · `created_by` | | مقروءة لمن يملك صلاحية الاطلاع (FR-044) |

**فهرس**: `(workspace_id, starts_on, ends_on)`.

**الأثر بالاستعلام لا بالكتابة** (R11): التجميد يُقرأ عند الجدولة والحجز والاحتساب، ولا يعدّل
صفّ حضور ولا عدّاداً. لهذا «الاستئناف بلا فقدان» (FR-043) ليس عملية يمكن أن تفشل — هو غياب
عملية.

---

## تعديلات على جداول قائمة

| الجدول | التعديل | السبب |
|---|---|---|
| `lessons` | `+ class_session_id` FK nullable | التسجيل المنشور يعرف حصته — مسار الأهلية الثالث (R8) |
| `teacher_profiles` | لا تعديل | الحقول الأربعة موجودة منذ 001 بلا مُنتِج؛ هذه المرحلة تملؤها (R12) |
| `platform_settings` | `+ ١٠ صفوف` | القيم القابلة للضبط (R9) — **يُمنع** تثبيتها في الكود (FR-021أ) |

---

## الأحداث (NFR-003)

| الحدث | متى | من يستهلكه |
|---|---|---|
| `SessionScheduled` | إنشاء حصة | الإشعارات (تذكير موعد) |
| `SessionCompleted` | إغلاق طبيعي | عدّادات المدرّس · التقارير |
| `SessionDelivered` | إغلاق + شروط FR-056 | **006 الاستهلاك · 014 التسوية** |
| `AttendanceConfirmed` | تثبيت الكشف الكامل | **006** · 009 السلاسل |

`SessionCompleted` و`SessionDelivered` **حدثان مختلفان عمداً** — دمجهما بعَلَم منطقي يجعل
شرط التنفيذ اختيارياً للمستمع (R7).

---

## العقد المشترك

```php
namespace App\Shared\Contracts;

interface SessionAttendanceDirectory
{
    /** هل حجز هذا المستخدم مقعداً في الحصة التي أنتجت هذا الدرس؟ (FR-030) */
    public function hasBookingForLesson(User $user, int $lessonId): bool;

    /** @return list<int> معرّفات الدروس المسموح بها — قراءة واحدة لقائمة كاملة */
    public function bookedLessonIdsFor(User $user): array;
}
```

`LiveSessions` تنفّذه وتربطه، و`Media` تستهلكه في `IssuePlaybackGrant::mayWatch()` كمسار
ثالث بجانب `EnrollmentDirectory` — بنفس الشكل بالضبط، لأن المبدأ III يمنع `Media` من لمس
نماذج `LiveSessions`.
