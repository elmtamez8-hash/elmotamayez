# Phase 1 — Data Model: بنية الإشعارات

**Feature**: `003-notification-architecture` | **Date**: 2026-08-05

القرارات التقنية خلف هذه البنية في [research.md](./research.md). عقد القناة في
[contracts/notification-channel.md](./contracts/notification-channel.md).

---

## التعدادات (Enums)

### `Support\NotificationChannel`

| القيمة | التسمية | مُنفَّذة؟ | خارجية؟ |
|---|---|---|---|
| `in_app` | داخل المنصة | ✅ | لا |
| `whatsapp` | واتساب | ❌ | نعم |
| `email` | البريد الإلكتروني | ❌ | نعم |
| `telegram` | تيليجرام | ❌ | نعم |
| `sms` | رسالة نصّية | ❌ | نعم |
| `push` | إشعار فوري | ❌ | نعم |

`isImplemented()` يقرأ من `ChannelRegistry` لا من ثابت — القناة مُنفَّذة إذا كان لها صنف موسوم
(FR-005 · FR-030). `isExternal()` ثابت على التعداد ويحكم أوقات الهدوء (FR-032).

> **أثر مقصود**: `email` تصبح **غير مُنفَّذة**، فتتوقّف رسائل البريد التي ترسلها الإشعارات
> الستّة اليوم. هذا نصّ الملحق (Q1: القناة داخل المنصة وحدها في MVP) لا سهو.

### `Support\NotificationType`

لكل حالة: التسمية العربية · القنوات الافتراضية · إلزامي؟ · هل يستهدف وليّ الأمر؟

| المفتاح | التسمية | افتراضي | إلزامي | لوليّ الأمر | المُطلِق |
|---|---|---|---|---|---|
| `enrollment_created` | تسجيل في كورس | `in_app` | — | — | قائم |
| `certificate_issued` | إصدار شهادة | `in_app` | — | — | قائم |
| `certificate_regenerated` | إعادة إصدار شهادة | `in_app` | — | — | قائم |
| `teacher_application_approved` | اعتماد طلب التدريس | `in_app` | — | — | قائم |
| `teacher_application_rejected` | رفض طلب التدريس | `in_app` | — | — | قائم |
| `teacher_application_changes_requested` | طلب تعديلات على الطلب | `in_app` | — | — | قائم |
| `security_alert` | تنبيه أمني | `in_app` | ✅ | — | تغيير كلمة المرور |
| `attendance_alert` | تنبيه حضور | `in_app` | — | ✅ | **005** |
| `payment_reminder` | تذكير دفع | `in_app` | ✅ | ✅ | **006** |
| `appointment_reminder` | تذكير موعد | `in_app` | — | ✅ | **005** |
| `exam_result` | نتيجة اختبار | `in_app` | — | ✅ | **008** |
| `academic_warning` | إنذار أكاديمي | `in_app` | — | ✅ | **008** |

الخمسة الموسومة «لوليّ الأمر» هي التي ينصّ عليها الملحق (FR-021). تُعرَّف الآن **بلا مُطلِق** —
مُطلِقها يأتي مع مرحلته، وهو ما يجعل تلك المراحل إضافةَ مستمع لا إضافةَ بنية.

### `Identity\Support\RelationType` · `RelationStatus` · `RelationPermission`

- **RelationType**: `parent` (واحد كحدّ أقصى للطالب) · `guardian` (متعدّد)
- **RelationStatus**: `pending` · `active` · `revoked`
- **RelationPermission**: `attendance` · `payments` · `schedule` · `results` · `academic_warnings`
  — الخمس تحكم **الاستقبال والاطّلاع معاً**؛ من لا يملك `payments` لا يستقبل تذكيراً بالدفع
  ولا يقرأ سجلّ الطالب المالي.

### `Support\DeliveryStatus`

`pending` · `queued` · `delivered` · `failed` · `skipped`
(`skipped` = القناة معطَّلة أو غير مُنفَّذة أو مستبعَدة بالتفضيلات — ليست فشلاً)

---

## الجداول

### 1. `notifications` — **جسر**

سجلّ الإشعار الواحد. صفّ واحد لكل (مستلم، حدث) مهما تعدّدت القنوات (FR-007).

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid unique | `HasUuid` — المكشوف في الـ API |
| `recipient_user_id` | FK users, cascade | **الحارس** — مالك الصفّ |
| `workspace_id` | FK workspaces **nullable**, null on delete | سياق لا نطاق (R5) |
| `type` | string(64) | `NotificationType` |
| `subject_user_id` | FK users nullable | الطالب الذي يخصّه الحدث — لتمييز الابن عند وليّ أمر لعدّة أبناء |
| `payload` | json | متغيّرات القالب — **يُمنع** أن يحوي نصّاً معروضاً جاهزاً |
| `title_ar` · `body_ar` | string(200) · text | النصّ المُصدَر وقت الإنشاء — يبقى ثابتاً بعد تعديل القالب |
| `action_url` | string(500) nullable | موضع الانتقال داخل المنتج (FR-015) |
| `read_at` | timestamp nullable | |
| `created_at` · `updated_at` | | |

**فهارس**: `(recipient_user_id, read_at, id)` — يخدم العدّاد والصفحة الأولى معاً (SC-008) ·
`(recipient_user_id, created_at)` للترتيب الزمني · `(workspace_id)` للترشيح · `(created_at)`
للتنظيف الدوري (FR-017).

**لماذا نصّ مُصدَر مخزَّن؟** إشعار مقروء قبل ستّة أشهر يجب أن يبقى بنصّه وقتها. الإصدار عند
القراءة يعني أن تعديل قالب اليوم يعيد كتابة الأرشيف.

### 2. `notification_deliveries` — **جسر**

محاولة تسليم لقناة واحدة (FR-038). صفوف عدّتها بعدد قنوات الإشعار.

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | |
| `notification_id` | FK notifications, cascade | |
| `channel` | string(32) | `NotificationChannel` |
| `template_id` | FK message_templates nullable, null on delete | |
| `status` | string(16) | `DeliveryStatus` |
| `attempts` | unsignedTinyInteger default 0 | |
| `failure_reason` | string(500) nullable | |
| `deferred_until` | timestamp nullable | أوقات الهدوء (FR-032) |
| `delivered_at` · `last_attempted_at` | timestamp nullable | |
| `created_at` · `updated_at` | | |

**فهارس**: `(notification_id)` · `(status, deferred_until)` للمؤجَّلات · `(channel, status)`
للوحة المراقبة.

**يُمنع** أن يحوي هذا الجدول رقم هاتف أو بريداً (FR-040) — الوسيلة تُقرأ من المستخدم وقت
الإرسال ولا تُنسَخ هنا.

### 3. `notification_preferences` — **مملوك للمنصة** (يُعاد بناؤه)

الجدول القائم بعمودين منطقيين (`weekly_reports` · `session_alerts`) لا يمثّل «تعيين نوع إلى
قائمة قنوات» (FR-027). يُستبدَل بصفّ لكل (مستخدم، نوع).

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | |
| `user_id` | FK users, cascade | **الحارس** |
| `type` | string(64) | `NotificationType` |
| `channels` | json | مصفوفة قنوات مختارة |
| `digest_window_minutes` | unsignedSmallInteger nullable | `null` = بلا تجميع (FR-034) |
| `created_at` · `updated_at` | | |

**فهرس فريد**: `(user_id, type)`.

**أعمدة الهدوء** تعيش على `users` لا هنا — نافذة واحدة للمستخدم لا لكل نوع:
`quiet_hours_start` · `quiet_hours_end` (time، nullable) · `timezone` (string، افتراضي
`Asia/Qatar`).

**الترحيل (FR-031 · SC-016)**: `session_alerts = false` ⇒ `attendance_alert` و
`appointment_reminder` بقنوات فارغة. `weekly_reports = false` ⇒ لا مقابل اليوم (تقرير أسبوعي
غير موجود) فيُهمَل بلا فقدان اختيار فعلي. الغياب يعني الافتراضي (FR-028) — فلا تُنشأ صفوف لمن
لم يغيّر شيئاً.

### 4. `parent_student_relations` — **مملوك للمنصة**

يحلّ محلّ `parent_child_links` (R9).

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | |
| `guardian_user_id` | FK users, cascade | وليّ الأمر أو الوصيّ |
| `student_user_id` | FK users **nullable**, cascade | لاغٍ حتى ينشئ الابن حسابه |
| `student_name` | string(150) | |
| `student_age` | unsignedTinyInteger nullable | |
| `student_grade_level_slug` | string(100) nullable | |
| `relation_type` | string(16) | `RelationType` |
| `permissions` | json | مصفوفة `RelationPermission` |
| `status` | string(16) | `RelationStatus` |
| `revoked_at` | timestamp nullable | الأرشيف يبقى (FR-023) |
| `created_at` · `updated_at` | | |

**فهارس**: فريد `(guardian_user_id, student_user_id)` · `(student_user_id, status)` لحلّ
المستلمين · فريد جزئي **مفروض في الـ Action** لا في المخطّط: وليّ أمر واحد نشط لكل طالب
(FR-019) — SQLite لا يدعم الفهرس الفريد الجزئي بنفس صيغة MySQL، والقاعدة قاعدة عمل فتُفرَض في
`LinkGuardian` (الدستور II).

**بلا `workspace_id`** — العلاقة بين شخصين على المنصة، لا داخل أكاديمية (FR-025أ · FR-025ب).

### 5. `contact_verifications` — **مملوك للمنصة**

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | |
| `user_id` | FK users, cascade | **الحارس** |
| `channel` | string(32) | القناة المستهدفة |
| `contact_value` | string(190) | الرقم أو البريد |
| `code_hash` | string(255) | **مُجزَّأ** — `Hash::make` (R12) |
| `attempts` | unsignedTinyInteger default 0 | حدّ ٥ |
| `expires_at` · `verified_at` | timestamp nullable | صلاحية ١٠ دقائق |
| `created_at` · `updated_at` | | |

**فهارس**: `(user_id, channel)` · `(expires_at)` للتنظيف.

**FR-042**: تغيير `contact_value` يُلغي التحقّق السابق — `verified_at = null` على الصفوف
السابقة لنفس (مستخدم، قناة).

### 6. `message_templates` — **مملوك للمنصة**

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | |
| `key` | string(64) | `{type}.{channel}` |
| `type` · `channel` | string(64) · string(32) | |
| `title_ar` | string(200) | |
| `body_ar` | text | متغيّرات `{{ name }}` |
| `variables` | json | أسماء المتغيّرات المطلوبة (FR-037) |
| `provider_approval_status` | string(16) | `not_required` · `pending` · `approved` · `rejected` |
| `is_active` | boolean default true | |
| `created_at` · `updated_at` | | |

**فهرس فريد**: `(type, channel)`.

**بذرة**: قالب واحد لكل نوع على `in_app` — اثنا عشر صفّاً في `NotificationTemplateSeeder`،
حالتها `not_required` (القناة داخل المنصة لا تشترط اعتماداً).

---

## الحُرّاس (Guards) — تفصيل NFR-001 و NFR-001أ

الستّة كيانات **مملوكة للمنصة أو جسر**، فلا واحد منها يستخدم `BelongsToWorkspace`. وبما أن
`WorkspaceScope` هو النطاق العالمي الوحيد في هذا المستودع، فكلّها **بلا نطاق عالمي** — أي
مكشوفة تماماً ما لم يحرسها الـ Action صراحةً، تماماً كما أن `WorkspaceScope` عديم الأثر للزائر
غير المصادَق عليه.

| الكيان | الحارس المفروض في الـ Action / السياسة |
|---|---|
| `notifications` | `recipient_user_id === $user->id`. `workspace_id` مُرشِّح اختياري لا شرط |
| `notification_deliveries` | لا مسار قراءة للمستخدم؛ اللوحة فقط بـ `NOTIFICATIONS_LOGS_VIEW` |
| `notification_preferences` | `user_id === $user->id` |
| `parent_student_relations` | الوصيّ يرى صفوفه · الطالب يرى من يرتبط به · **المدرّس**: أدناه |
| `contact_verifications` | `user_id === $user->id` — بلا مسار قراءة أصلاً |
| `message_templates` | `NOTIFICATIONS_TEMPLATES_MANAGE` (سوبر أدمن) |

### حارس رؤية المدرّس (NFR-001أ — إلزامي دستورياً)

`ParentStudentRelationPolicy::view()` للمدرّس أو مساعده:

```
الطالب لديه Enrollment نشط في كورس داخل مساحة عمل المدرّس؟
    نعم → يُسمح، وبالحقول المسموحة فقط
    لا  → يُمنع (403)
```

**بلا هذا الحارس**، `parent_student_relations` مقروء بالكامل لأي مدرّس — فيقرأ مدرّس الرياضيات
أسماء أولياء أمور طلاب مدرّس الفيزياء وأرقامهم. الجدول عام، فالحماية من الـ Action لا من نطاق.

### الاختبار الإلزامي (NFR-001ب)

`tests/Feature/Notifications/PlatformOwnershipTest.php` — لكل كيان من الستّة حالتان:

1. **لا تسريب أفقي**: مدرّس في مساحة عمل A **لا** يرى صفوف طالب مسجَّل عند B فقط.
2. **كيان واحد لا نسخة لكل مدرّس**: طالب مسجَّل عند ثلاثة مدرّسين له **تفضيل واحد** و**علاقة
   وليّ أمر واحدة** و**تدفّق إشعارات واحد** — لا ثلاثة.

الحالة الثانية هي التي تكشف الخطأ المعاكس: كيان مملوك للمنصة أُعطي `BelongsToWorkspace` سهواً.

---

## دورة حياة الإشعار

```
حدث نطاق (EnrollmentCreated …)
   └─ مستمع في NotificationsServiceProvider
        └─ DispatchNotification::handle(NotificationRequest)
             ├─ RecipientResolver  → المستلمون المخوَّلون (الطالب + أولياء الأمر المخوَّلون)
             │                       FR-021 · FR-022 · علاقة revoked ⇒ يُستبعَد
             ├─ لكل مستلم:
             │    ├─ PreferenceResolver → قنوات هذا النوع لهذا المستخدم
             │    │     · إلزامي ⇒ الافتراضي دائماً، التعطيل يُتجاهَل (FR-029)
             │    │     · غير مُنفَّذة ⇒ تُسقَط (FR-030)
             │    ├─ TemplateRenderer  → title_ar · body_ar (يرمي عند نقص متغيّر — FR-037)
             │    ├─ إنشاء صفّ notifications واحد            ← FR-007
             │    ├─ إنشاء صفّ notification_deliveries لكل قناة
             │    ├─ إطلاق NotificationRequested
             │    └─ لكل تسليم: توزيع DeliverNotificationJob  ← وظيفة مستقلة = عزل الفشل (FR-006)
             │         ├─ QuietHours::deferUntil() للقنوات الخارجية غير الإلزامية (FR-032)
             │         └─ إطلاق NotificationQueued
             └─ العودة فوراً — بلا انتظار تسليم (FR-008 · SC-005)

DeliverNotificationJob (طابور notifications | notifications-high)
   ├─ العلاقة أُلغيت بعد التوزيع؟ ⇒ skipped بلا تسليم   ← Edge Case
   ├─ ChannelRegistry::get(channel)->send(envelope)
   ├─ نجاح          → delivered + NotificationDelivered
   ├─ عابر          → إعادة بتراجع تصاعدي (٥ محاولات)   ← FR-009
   └─ PermanentDeliveryException → failed + NotificationFailed، بلا إعادة
```

**سلسلة الأحداث الأربعة (FR-010 · SC-007)**: `NotificationRequested` ← `NotificationQueued`
← `NotificationDelivered` أو `NotificationFailed`. تُعلَن في `Events/` وتوثَّق في
`docs/README.md`.

---

## أثر على الوثائق

| الملف | التحديث |
|---|---|
| `docs/erd.md` | الجداول الستّة + حذف `parent_child_links` |
| `docs/README.md` | وحدة Notifications: نقاط النهاية والأذونات والأحداث الأربعة |
| `backend/phpstan.neon` | `app/Modules/Notifications/Database/Migrations` |
| `CLAUDE.md` · `AGENTS.md` | قاعدة «القنوات خلف العقد» + الطوابير الجديدة |
| `backend/lang/ar/validation.php` | `attributes` لكل حقل `FormRequest` جديد |
