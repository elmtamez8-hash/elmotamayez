# Implementation Plan: بنية الإشعارات القابلة للتوسيع وأساس التواصل مع وليّ الأمر

**Branch**: `003-notification-architecture` | **Date**: 2026-08-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/003-notification-architecture/spec.md`

## Summary

بناء طبقة إشعارات لا تعرف مزوّدها: عقد قناة واحد (`NotificationChannelInterface`) وسجلّ قنوات
موسوم في الحاوية، وفعل إرسال واحد (`DispatchNotification`) يحلّ المستلمين من علاقات وليّ الأمر
والأوصياء، ويستشير التفضيلات، ويكتب **سجلّ إشعار واحداً** ثم يوزّع محاولة تسليم مستقلة لكل قناة
في وظيفة مطبورة منفصلة. القناة الوحيدة المُنفَّذة في هذه المرحلة هي القناة داخل المنصة؛ القنوات
الخمس الأخرى تُعرَّف كقيم معروفة معلَّمة **غير مُنفَّذة** فلا تُختار ولا تُرسَل.

بالتوازي: ترقية `parent_child_links` إلى `parent_student_relations` — كيان أصيل مملوك للمنصة
بنوعه (وليّ أمر / وصيّ) وصلاحياته وحالته، ليتابع وليّ الأمر ابنه عبر كل مدرّسيه من مكان واحد.

الستة إشعارات القائمة تُرحَّل من `Illuminate\Notifications\Notification` إلى هذه البنية،
فتصبح البنية هي المسار الوحيد ولا يبقى مسار موازٍ.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript 5 (Next.js 15، App Router)

**Primary Dependencies**: قائمة سلفاً — `laravel/horizon`، `spatie/laravel-permission` (وضع الفرق)،
`filament/filament` (لوحة القوالب وسجلّ التسليم). **لا تبعية جديدة**: عقد القناة والسجلّ يقومان
على وسم الحاوية (`$this->app->tag()`) وهو من صميم الإطار.

**Storage**: SQLite محلياً · MySQL 8 في الإنتاج (الوضع الصارم) · Redis للطابور والعدّادات الساخنة

**Testing**: Pest — `RefreshDatabase` + `WithWorkspace`؛ القنوات تُستبدَل بمزيّفة (`FakeChannel`)
ولا نداء شبكي حقيقي (NFR-010)

**Target Platform**: خادم Linux (Docker) — الواجهة على Node 20

**Project Type**: تطبيق ويب — أحادية معيارية (`backend/app/Modules/`) + واجهة Next.js

**Performance Goals**: صفحة الإشعارات الأولى وعدّاد غير المقروء ≤ ٣٠٠ مللي ثانية (p95) لمستخدم
لديه ١٠٬٠٠٠ إشعار (SC-008) · إطلاق الإشعار لا يضيف زمناً يُقاس إلى العملية المُطلِقة (SC-005)

**Constraints**: **يُمنع** أن يذكر أي كود في `Actions/` قناةً أو مزوّداً بعينه — شرط قبول
معماري يُفحص آلياً (SC-002) · Redis طابوراً افتراضياً بطوابير منفصلة (NFR-009) · بيانات
الاعتماد من البيئة حصراً (FR-011)

**Scale/Scope**: ٦ إشعارات قائمة تُرحَّل + ١٢ نوعاً معرَّفاً · ٦ قنوات معرَّفة (١ مُنفَّذة) ·
٦ جداول · ~١٤ نقطة نهاية · ٣ شاشات

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

الدستور v1.1.0 — البوابات الستّ:

### I. عزل المستأجرين — **تصنيف الملكية (قرار مُلزِم)**

| الكيان | الطبقة | الحارس |
|---|---|---|
| `notifications` | **جسر** | `recipient_user_id` يملك الصفّ؛ `workspace_id` **لاغٍ مسموح** للسياق لا للعزل |
| `notification_deliveries` | **جسر** | يتبع إشعاره؛ لا وصول مباشر |
| `notification_preferences` (موسَّع) | **مملوك للمنصة** | ملكية الصفّ للمستخدم — تفضيل واحد عبر كل مدرّسيه |
| `parent_student_relations` | **مملوك للمنصة** | ملكية الصفّ للطرفين + حارس التسجيل للمدرّس |
| `contact_verifications` | **مملوك للمنصة** | ملكية الصفّ للمستخدم |
| `message_templates` | **مملوك للمنصة** | صلاحية `notifications.templates.manage` (سوبر أدمن) |

**يُمنع** استخدام `BelongsToWorkspace` على أيٍّ من الستّة. تفصيل الحراسة في
[data-model.md](./data-model.md) §الحُرّاس.

**حارس رؤية المدرّس (NFR-001أ)**: `parent_student_relations` مكشوف بلا نطاق عالمي، فالمدرّس
**يُمنع** أن يقرأ علاقات طالب لا يملك تسجيلاً نشطاً في كورس داخل مساحة عمله — مفروض في
`ParentStudentRelationPolicy` ومُختبَر في `tests/Feature/Notifications/PlatformOwnershipTest.php`.

**الاختبار الإلزامي (NFR-001ب)**: لكل كيان مملوك للمنصة حالتان — مدرّس لا يرى بيانات طالب غير
مسجَّل عنده، والطالب يرى كيانه **الواحد** عبر كل مدرّسيه بلا تكرار.

✅ **يمرّ** — التصنيف معلَن ومبرَّر، والحارس مُصمَّم ومُختبَر.

### II. المنطق في الـ Actions

كل مسار: `FormRequest → DTO → Action → API Resource`. الفعل المركزي `DispatchNotification`
هو **المدخل الوحيد** — المستمعون يستدعونه، وقواعد الإلزامية وأوقات الهدوء والتفضيلات مفروضة
بداخله لا في التحقّق فقط. ✅ **يمرّ**

### III. استقلال الوحدات والتكامل بالأحداث

الوحدات الأخرى **لا** تستدعي `DispatchNotification` مباشرةً — تُطلق حدث نطاقها، ومستمع في
`NotificationsServiceProvider::boot()` يترجمه. أربعة أحداث جديدة تُعلَن للمراقبة.
`app/Modules/Notifications/Database/Migrations` بحرف M كبير + إضافة الوحدة إلى `phpstan.neon`
(هي غير مدرجة اليوم لأنها بلا هجرات). ✅ **يمرّ**

### IV. البوابات الآلية خضراء

`pest` · `pint --test` · `phpstan analyse` (L8، بلا baseline جديد) · `npx tsc --noEmit`.
المسارات الحرجة الثمانية تبقى خضراء. الملفّان المتأثّران **حصراً** (مُحصيان بالفحص لا بالتقدير):

| الملف | ما يعتمد عليه | التحديث |
|---|---|---|
| `tests/Feature/Marketplace/TeacherApplicationTest.php` (٣ مواضع) | `Notification::assertSentTo(…Notification::class)` | تأكيد على صفوف `notifications` بنوعها |
| `tests/Feature/Payments/PaymentTest.php:127` | `DatabaseNotification::where('notifiable_id', …)` | نموذجنا `Notification` — **مسار حرج**، يُحدَّث لا يُحذف |

`Learning` و`Assessments` **بلا أي تأكيد إشعار**. ✅ **يمرّ**

### V. التفويض بالسياسات والثوابت

ثوابت جديدة في `Tenancy\Support\Permissions` — **يُمنع** أي اسم صلاحية نصّي. سياسات لكل من
`Notification` و`ParentStudentRelation` و`MessageTemplate`. ✅ **يمرّ**

### VI. العقود الظاهرة مقصودة

`HasUuid` وكشف الـ uuid فقط · `declare(strict_types=1);` · DTOs ترث `DataTransferObject` ·
كل استجابة عبر API Resource. عقد القناة يقتدي حرفياً بـ `PaymentProviderInterface`:
إضافة قناة **يجب ألا** تتطلّب تعديل منطق الإشعارات. ✅ **يمرّ**

**النتيجة**: صفر مخالفات — `## Complexity Tracking` يبقى فارغاً.

### إعادة الفحص بعد تصميم المرحلة ١

| المبدأ | بعد التصميم | ما تغيّر |
|---|---|---|
| I | ✅ | الطبقات مُثبَتة في [data-model.md](./data-model.md) §الحُرّاس، وحارس التسجيل مُصمَّم في `ParentStudentRelationPolicy` ومُختبَر. **تعارض ظاهري** بين FR-016 وتصنيف الملكية حُسم في [research.md](./research.md) §R5 لصالح FR-025ب — ويُعرَض على مالك المستودع |
| II | ✅ | `DispatchNotification` المدخل الوحيد؛ قاعدتان تُفرضان فيه لا في التحقّق: وليّ أمر واحد نشط (FR-019)، والنوع الإلزامي (FR-029) |
| III | ✅ | لا وحدة تستدعي `DispatchNotification`؛ أربعة أحداث معلنة. `Notifications` تُضاف إلى `phpstan.neon` مع أول مجلد هجرات لها |
| IV | ✅ | ملفّان متأثّران حصراً — `TeacherApplicationTest` و`PaymentTest:127` (الثاني مسار حرج) — تُحدَّث تأكيداتهما لا تُحذف |
| V | ✅ | ثلاثة ثوابت جديدة؛ `RELATIONS_VIEW_STUDENT` **لا تكفي وحدها** — حارس التسجيل شرط ثانٍ مستقلّ |
| VI | ✅ | `NotificationChannelInterface` يقتدي بـ `PaymentProviderInterface` حرفياً؛ الظرف DTO ولا يحمل نماذج |

**قراران يغيّران سلوكاً قائماً ويجب أن يُقرّا صراحةً**:

1. **بريد الإشعارات يتوقّف.** الإشعارات الستّة ترسل اليوم `['database','mail']`؛ الملحق يجعل
   القناة داخل المنصة قناة MVP الوحيدة، فتصبح `email` معرَّفة **غير مُنفَّذة**. مقصود لا سهو.
   **استثناء**: استعادة كلمة المرور وتوثيق البريد تبقيان على بريد الإطار (research §R14) —
   رابط استعادة يُسلَّم داخل المنصة عديم الفائدة، فمن نسي كلمة مروره لا يستطيع الدخول ليقرأه.
2. **جدول `notifications` الخاص بـ Laravel يُستبدَل** بجدولنا (research §R4). الجدول فارغ —
   لا إنتاج بعد — فلا ترحيل بيانات. مستهلكاه القائمان: `PaymentTest:127`
   و`User::notifications()` الموروثة — كلاهما يُعاد توجيهه.

## Project Structure

### Documentation (this feature)

```text
specs/003-notification-architecture/
├── plan.md              # هذا الملف
├── research.md          # Phase 0 — القرارات التقنية
├── data-model.md        # Phase 1 — الكيانات والحُرّاس والفهارس
├── quickstart.md        # Phase 1 — دليل التحقّق القابل للتشغيل
├── contracts/
│   ├── notification-channel.md   # عقد القناة (داخلي — نقطة الانعكاس)
│   └── api.md                    # نقاط النهاية العامة
├── checklists/requirements.md    # ٢٢/٢٢ ✓
└── tasks.md             # Phase 2 — يولّده /speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/Notifications/
├── Actions/                    # DispatchNotification · MarkRead · MarkAllRead
│   │                           # UpdatePreferences · RequestContactVerification
│   │                           # ConfirmContactVerification
├── Channels/                   # InAppChannel · ChannelRegistry
├── Contracts/                  # NotificationChannelInterface
├── Data/                       # NotificationEnvelope · DeliveryResult · DTOs
├── Database/Migrations/        # M كبيرة — الجداول الخمسة
├── Events/                     # NotificationRequested/Queued/Delivered/Failed
├── Exceptions/                 # PermanentDeliveryException
├── Http/{Controllers,Requests,Resources}/
├── Jobs/                       # DeliverNotification · ReleaseDeferred · PruneOld
├── Listeners/                  # الستّة القائمة — تُعاد كتابتها فوق DispatchNotification
├── Models/                     # Notification · NotificationDelivery
│                               # MessageTemplate · ContactVerification
├── Policies/
├── Support/                    # NotificationType · NotificationChannel (enums)
│                               # RecipientResolver · PreferenceResolver
│                               # QuietHours · TemplateRenderer
├── routes/api.php
└── NotificationsServiceProvider.php

backend/app/Modules/Identity/
├── Models/ParentStudentRelation.php        # يحلّ محلّ ParentChildLink
├── Actions/{LinkGuardian,RevokeRelation,UpdateRelationPermissions}.php
├── Database/Migrations/…_create_parent_student_relations_table.php
├── Policies/ParentStudentRelationPolicy.php
└── Support/{RelationType,RelationPermission}.php

frontend/src/
├── app/(app)/notifications/page.tsx         # المركز
├── app/(app)/settings/notifications/page.tsx # التفضيلات
├── app/(app)/family/page.tsx                # الأوصياء
├── components/app/NotificationBell.tsx      # العدّاد في الترويسة
└── lib/notifications.ts                     # الأنواع + تسميات عربية

backend/tests/Feature/Notifications/
├── ChannelContractTest.php          # SC-001 · SC-003 — قناة وهمية
├── ProviderAgnosticTest.php         # SC-002 — الفحص الآلي
├── NotificationCenterTest.php       # SC-004 · SC-008 · SC-009
├── PlatformOwnershipTest.php        # NFR-001ب — الحارسان
├── GuardianDeliveryTest.php         # SC-010 · SC-011
├── PreferencesTest.php              # SC-012 · SC-013
├── QuietHoursTest.php               # SC-014
└── MigrationBackfillTest.php        # SC-016
```

**Structure Decision**: توسيع وحدتين قائمتين لا إنشاء وحدة ثالثة. `Notifications` تملك البنية
والقنوات والتفضيلات والقوالب والسجلّ؛ `Identity` تملك علاقة وليّ الأمر بالطالب لأنها علاقة هوية
لا رسالة. `Notifications` تحصل على أول مجلد هجرات لها — فتُضاف إلى `phpstan.neon`.

## Complexity Tracking

> لا مخالفات دستورية. القسم فارغ عمداً.

التجريد الوحيد المُضاف (`NotificationChannelInterface`) مبرَّر بمشكلة **قائمة الآن** لا متوقّعة:
`via()` في كل إشعار من الستّة يذكر `['database','mail']` نصّاً، فإضافة قناة سابعة اليوم تعني
تعديل ستّة ملفات ثم عشرين — وهو حرفياً ما يمنعه قيد الملحق. والنمط نفسه مطبَّق ومُثبَت في هذا
المستودع عبر `PaymentProviderInterface`.
