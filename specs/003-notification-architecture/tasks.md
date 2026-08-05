---

description: "Task list for 003-notification-architecture"
---

# Tasks: بنية الإشعارات القابلة للتوسيع وأساس التواصل مع وليّ الأمر

**Input**: Design documents from `/specs/003-notification-architecture/`

**Prerequisites**: [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) ·
[data-model.md](./data-model.md) · [contracts/](./contracts/)

**Tests**: **مطلوبة** — NFR-007 ينصّ على اختبارات وحدة وتكامل، و١٨ معياراً من `SC-` يُثبَت
باختبار، والدستور IV يجعل `pest` بوابة دمج. مهامّ الاختبار **تُكتب قبل** تنفيذها وتفشل أولاً.

**Organization**: مجمَّعة بقصّة المستخدم — كل قصّة قابلة للتنفيذ والاختبار والتسليم وحدها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يجوز التوازي (ملفات مختلفة، بلا تبعية)
- **[Story]**: القصّة التي تخدمها (US1…US6)
- كل مهمّة تحمل مسار ملفها بالضبط

## Path Conventions

- الخلفية: `backend/app/Modules/{Notifications,Identity}/` · الاختبارات `backend/tests/Feature/`
- الواجهة: `frontend/src/`
- الهجرات **يجب** أن تكون في `Database/Migrations` بحرف **M كبير** (الدستور III — الحالة
  الخاطئة تعمل على Windows وتحمّل **صفر** هجرات على Linux)

---

## Phase 1: Setup (البنية المشتركة)

**Purpose**: تهيئة الوحدة والتهيئة والثوابت قبل أي كود

- [X] T001 أنشئ هيكل وحدة الإشعارات: `backend/app/Modules/Notifications/{Actions,Channels,Contracts,Data,Database/Migrations,Events,Exceptions,Http/Controllers,Http/Requests,Http/Resources,Jobs,Models,Policies,Support}/` — الوحدة قائمة بلا مجلد هجرات، وهذه أول هجرات لها
- [X] T002 أضف `app/Modules/Notifications/Database/Migrations` إلى `scanDirectories` في `backend/phpstan.neon` — بدونها لا يستنتج Larastan أنواع خصائص النماذج وتسقط بوابة L8
- [X] T003 [P] أضف الثوابت الثلاثة `NOTIFICATIONS_LOGS_VIEW` · `NOTIFICATIONS_TEMPLATES_MANAGE` · `RELATIONS_VIEW_STUDENT` إلى `backend/app/Modules/Tenancy/Support/Permissions.php` وابذرها في `backend/database/seeders/RolesAndPermissionsSeeder.php`
- [X] T004 [P] أنشئ `backend/config/notifications.php`: مدة الاحتفاظ (٩٠ يوماً للمقروء)، تراجع إعادة المحاولة `[30,120,300,900]`، الحد الأقصى ٥ محاولات، المنطقة الزمنية الافتراضية `Asia/Qatar`
- [X] T005 [P] حوّل الطابور إلى Redis: `QUEUE_CONNECTION=redis` في `backend/.env.example` و`docker/`، وأضف طابورَي `notifications-high` و`notifications` إلى `backend/config/horizon.php` (research §R11)
- [X] T006 [P] أضف محدّد المعدّل المسمّى `contact-verification` إلى `registerRateLimiters()` في `backend/app/Providers/AppServiceProvider.php` — **يُمنع** الحدّ السطري `throttle:5,1` (research §R12)

---

## Phase 2: Foundational (متطلبات حاجبة)

**Purpose**: التعدادات والمخطّط والنماذج التي تعتمد عليها **كل** القصص

**⚠️ CRITICAL**: لا تبدأ أي قصّة قبل اكتمال هذه المرحلة

### التعدادات

- [X] T007 [P] أنشئ `backend/app/Modules/Notifications/Support/NotificationChannel.php` — ٦ حالات مع `label()` و`isExternal()`؛ `isImplemented()` يسأل `ChannelRegistry` لا ثابتاً (data-model §التعدادات)
- [X] T008 [P] أنشئ `backend/app/Modules/Notifications/Support/NotificationType.php` — ١٢ حالة مع `label()` و`defaultChannels()` و`isMandatory()` و`targetsGuardians()` وفق جدول data-model
- [X] T009 [P] أنشئ `backend/app/Modules/Notifications/Support/DeliveryStatus.php` — `pending·queued·delivered·failed·skipped`
- [X] T010 [P] أنشئ `backend/app/Modules/Identity/Support/{RelationType,RelationStatus,RelationPermission}.php`

### الهجرات

- [X] T011 احذف `backend/database/migrations/2026_07_21_162842_create_notifications_table.php` وأنشئ `backend/app/Modules/Notifications/Database/Migrations/2026_08_05_000100_create_notifications_table.php` بمخطّطنا وفهرس `(recipient_user_id, read_at, id)` (research §R4 · data-model §1)
- [X] T012 أنشئ هجرة `…_000200_create_notification_deliveries_table.php` — بلا عمود هاتف أو بريد (FR-040)
- [X] T013 أنشئ هجرة `…_000300_rebuild_notification_preferences_table.php`: تُسقط الجدول القديم بعد **نقل** `session_alerts=false` إلى تفضيلات فارغة على `attendance_alert` و`appointment_reminder` (FR-031)
- [X] T014 [P] أنشئ هجرة `…_000400_create_message_templates_table.php` بفهرس فريد `(type, channel)`
- [X] T015 [P] أنشئ هجرة `…_000500_create_contact_verifications_table.php` — `code_hash` لا `code`
- [X] T016 [P] أنشئ هجرة `…_000600_add_quiet_hours_to_users.php`: `quiet_hours_start` · `quiet_hours_end` · `timezone`
- [X] T017 أنشئ `backend/app/Modules/Identity/Database/Migrations/2026_08_05_000700_create_parent_student_relations_table.php` — تنقل كل صفوف `parent_child_links` بـ `relation_type=parent` والصلاحيات الخمس و`status=active`، ثم تُسقط الجدول القديم (research §R9 · FR-024)

### النماذج والمصانع

- [X] T018 [P] أنشئ `backend/app/Modules/Notifications/Models/Notification.php` — `HasUuid`، **بلا** `BelongsToWorkspace` (كيان جسر — plan §I)
- [X] T019 [P] أنشئ `backend/app/Modules/Notifications/Models/NotificationDelivery.php`
- [X] T020 [P] أنشئ `backend/app/Modules/Notifications/Models/MessageTemplate.php`
- [X] T021 [P] أنشئ `backend/app/Modules/Notifications/Models/ContactVerification.php`
- [X] T022 [P] أنشئ `backend/app/Modules/Identity/Models/ParentStudentRelation.php` واحذف `ParentChildLink.php`
- [X] T023 [P] أنشئ المصانع في `backend/database/factories/Modules/{Notifications,Identity}/` — **يُمنع** `newFactory()` على النماذج (الدستور)
- [X] T024 أعد تعريف `notifications()` على `backend/app/Models/User.php` لتشير إلى نموذجنا، و**أبقِ `Notifiable`** — استعادة كلمة المرور وتوثيق البريد تعتمدان عليه (research §R14)
- [X] T025 [P] أنشئ `backend/database/seeders/NotificationTemplateSeeder.php` — ١٢ قالباً على `in_app` بحالة `not_required`، واستدعِه من `DatabaseSeeder`
- [X] T026 [P] أضف تسميات الحقول العربية الجديدة إلى `attributes` في `backend/lang/ar/validation.php` — الحقل بلا مدخل يظهر باسمه البرمجي للمستخدم

**Checkpoint**: `php artisan migrate:fresh --seed` يمرّ · `phpstan analyse` أخضر · القصص تبدأ

---

## Phase 3: User Story 1 — بنية قنوات لا تعرف مزوّدها (Priority: P1) 🎯 MVP

**Goal**: عقد قناة واحد وسجلّ ومسار إرسال مطبور — إضافة قناة تصبح ملفاً وسطراً

**Independent Test**: تسجيل `FakeChannel` في بيئة الاختبار والتحقق من وصول كل أنواع الإشعارات
إليها بلا تعديل سطر واحد من الكود المُطلِق

### Tests for User Story 1 ⚠️ تُكتب أولاً وتفشل

- [X] T027 [P] [US1] أنشئ `backend/tests/Support/FakeChannel.php` و`FakeExternalChannel.php` — بديلان يسجّلان ما استلماه (NFR-010 — **يُمنع** نداء شبكي حقيقي)
- [X] T028 [P] [US1] اكتب `backend/tests/Feature/Notifications/ChannelContractTest.php` — SC-001 (قناة تُسجَّل فتستقبل كل الأنواع) · SC-003 (قناة تفشل ولا تمنع الأخرى) · SC-004 (سجلّ واحد مهما تعدّدت القنوات)
- [X] T029 [P] [US1] اكتب `backend/tests/Feature/Notifications/ProviderAgnosticTest.php` — SC-002: يمسح `app/Modules/*/Actions/**/*.php` بحثاً عن اسم قناة أو مزوّد ويفشل عند أي تطابق، مستثنياً `Modules/Notifications/Channels/` و`sendPasswordResetNotification`/`sendEmailVerificationNotification` (research §R14)
- [X] T030 [P] [US1] اكتب `backend/tests/Feature/Notifications/DeliveryLifecycleTest.php` — SC-006 (عابر يُعاد ٥ مرات · دائم صفر إعادة) · SC-007 (سلسلة الأحداث الأربعة بترتيبها) · SC-005 (قناة بطيئة لا تزيد زمن العملية المُطلِقة)

### Implementation for User Story 1

- [X] T031 [US1] أنشئ `backend/app/Modules/Notifications/Contracts/NotificationChannelInterface.php` — `channel()` · `isEnabled()` · `canReach()` · `send(): void` (contracts/notification-channel.md)
- [X] T032 [P] [US1] أنشئ `backend/app/Modules/Notifications/Data/NotificationEnvelope.php` يرث `DataTransferObject` — **يُمنع** أن يحمل نموذج `Notification` أو `NotificationDelivery`
- [X] T033 [P] [US1] أنشئ `backend/app/Modules/Notifications/Exceptions/{PermanentDeliveryException,ChannelNotImplementedException}.php`
- [X] T034 [P] [US1] أنشئ الأحداث الأربعة في `backend/app/Modules/Notifications/Events/`: `NotificationRequested` · `NotificationQueued` · `NotificationDelivered` · `NotificationFailed` (FR-010)
- [X] T035 [US1] أنشئ `backend/app/Modules/Notifications/Channels/ChannelRegistry.php` — `has()` · `get()` · `available()` (يعتمد على T031)
- [X] T036 [US1] أنشئ `backend/app/Modules/Notifications/Channels/InAppChannel.php` — القناة الوحيدة المُنفَّذة (FR-004)
- [X] T037 [US1] سجّل الوسم في `backend/app/Modules/Notifications/NotificationsServiceProvider.php` ← `register()`: `$this->app->tag([InAppChannel::class], 'notification.channels')` + ربط `ChannelRegistry` مفردةً — **هذا هو السطر الوحيد الذي تضيفه قناة جديدة** (SC-001)
- [X] T038 [P] [US1] أنشئ `backend/app/Modules/Notifications/Support/TemplateRenderer.php` — يرمي عند نقص متغيّر أو قالب غير معتمد (FR-037)
- [X] T039 [P] [US1] أنشئ `backend/app/Modules/Notifications/Support/PreferenceResolver.php` بصورته الأساسية: الافتراضي عند غياب التفضيل · الإلزامي يتجاوز التعطيل · القناة غير المُنفَّذة تُسقَط (FR-028 · FR-029 · FR-030)
- [X] T040 [P] [US1] أنشئ `backend/app/Modules/Notifications/Support/RecipientResolver.php` بصورته الأساسية: المستلم المباشر وحده (يوسَّع لأولياء الأمور في US3)
- [X] T041 [US1] أنشئ `backend/app/Modules/Notifications/Data/NotificationRequest.php` و`backend/app/Modules/Notifications/Actions/DispatchNotification.php` — **المدخل الوحيد**: يحلّ المستلمين، يستشير التفضيلات، يُصدر القالب، يكتب **سجلّ إشعار واحداً** + تسليماً لكل قناة، ويعود فوراً (FR-007 · FR-008)
- [X] T042 [US1] أنشئ `backend/app/Modules/Notifications/Jobs/DeliverNotificationJob.php` — وظيفة مستقلة لكل قناة (عزل الفشل FR-006)، `$tries=5`، `backoff()` تصاعدي، تلتقط `PermanentDeliveryException` فتسجّل الفشل بلا إعادة (FR-009)
- [X] T043 [US1] أعد كتابة المستمعين الستّة في `backend/app/Modules/Notifications/Listeners/` فوق `DispatchNotification`، واحذف `backend/app/Modules/Notifications/Notifications/` كاملاً (٦ ملفات) — **يُمنع** بقاء مسار إرسال موازٍ
- [X] T044 [US1] أضف نوع `security_alert` ومُطلِقه عند تغيير كلمة المرور في `backend/app/Modules/Identity/Http/Controllers/AuthController.php` — يعطي FR-029 و SC-013 موضوعاً حقيقياً
- [X] T045 [US1] حدّث `backend/tests/Feature/Marketplace/TeacherApplicationTest.php` (٣ مواضع `Notification::assertSentTo`) إلى تأكيد على صفوف `notifications` بنوعها
- [X] T046 [US1] حدّث `backend/tests/Feature/Payments/PaymentTest.php:127` من `DatabaseNotification::where('notifiable_id', …)` إلى نموذجنا — **مسار حرج** (اعتماد الدفع ← إنشاء التسجيل)، يُحدَّث لا يُحذف
- [X] T047 [US1] تحقّق أن استعادة كلمة المرور وتوثيق البريد ما زالتا عاملتين: `php vendor/bin/pest --filter="password|verif"` (research §R14)

**Checkpoint**: البنية تعمل من طرف إلى طرف · SC-001…SC-007 خضراء · إضافة قناة = ملف + سطر

---

## Phase 4: User Story 2 — مركز الإشعارات داخل المنصة (Priority: P2)

**Goal**: المستخدم يرى إشعاراته ويعلّمها مقروءة — أول ظهور مرئي للبنية

**Independent Test**: إنشاء إشعارات لمستخدم وقراءتها وتعليمها مقروءة عبر واجهة البرمجة وحدها

### Tests for User Story 2 ⚠️

- [X] T048 [P] [US2] اكتب `backend/tests/Feature/Notifications/NotificationCenterTest.php` — الترتيب والترقيم · العدّاد · التعليم عديم الأثر عند التكرار (FR-014) · SC-009 (مستخدم آخر يحصل على `404`) · SC-008 (١٠٬٠٠٠ إشعار، الصفحة الأولى والعدّاد ≤ ٣٠٠ مللي ثانية)

### Implementation for User Story 2

- [X] T049 [P] [US2] أنشئ `backend/app/Modules/Notifications/Policies/NotificationPolicy.php` — الحارس `recipient_user_id === $user->id`، و`404` لا `403` (وجود الصفّ نفسه معلومة)
- [X] T050 [P] [US2] أنشئ أفعال `backend/app/Modules/Notifications/Actions/{MarkNotificationRead,MarkAllNotificationsRead}.php`
- [X] T051 [P] [US2] أنشئ `backend/app/Modules/Notifications/Http/Resources/NotificationResource.php` — يكشف `uuid` فقط (الدستور VI)
- [X] T052 [US2] أنشئ `backend/app/Modules/Notifications/Http/Controllers/NotificationController.php`: `index` · `unreadCount` · `markRead` · `markAllRead` — `workspace` **مُرشِّح اختياري لا نطاق** (research §R5)
- [X] T053 [US2] أنشئ `backend/app/Modules/Notifications/routes/api.php` خلف `auth:sanctum` — يُحمَّل تلقائياً بـ `/api/v1` من `Module` الأساس
- [X] T054 [P] [US2] أضف أنواع الإشعارات وتسمياتها العربية إلى `frontend/src/lib/notifications.ts` ودوالّ الاستدعاء إلى `frontend/src/lib/api.ts`
- [X] T055 [P] [US2] أنشئ `frontend/src/components/app/NotificationBell.tsx` — عدّاد غير المقروء في الترويسة، بألوان `@theme` وخصائص منطقية (`ms-*`) لا `ml-*`
- [X] T056 [US2] أنشئ `frontend/src/app/(app)/notifications/page.tsx` — القائمة والترقيم وتعليم الكل، بمكوّنات `components/ui/` (**لا** `className` حرّ)
- [X] T057 [US2] اربط الجرس في `frontend/src/app/(app)/layout.tsx` — الأخطاء عبر `userMessage()`، **يُمنع** عرض خطأ خام

**Checkpoint**: `/notifications` تعمل عربية RTL · SC-008 · SC-009 خضراء

---

## Phase 5: User Story 3 — وليّ الأمر والأوصياء كيانات أصيلة (Priority: P3)

**Goal**: علاقة أصيلة بنوعها وصلاحياتها، ووصول التنبيه إلى المخوَّلين وحدهم

**Independent Test**: ربط طالب بوليّ أمر ووصيَّين والتحقق من وصول التنبيه إلى المخوَّلين وحدهم

### Tests for User Story 3 ⚠️

- [X] T058 [P] [US3] اكتب `backend/tests/Feature/Notifications/PlatformOwnershipTest.php` — **إلزامي دستورياً** (NFR-001ب): لكل كيان مملوك للمنصة حالتان — مدرّس **لا** يرى صفوف طالب غير مسجَّل عنده، والطالب يرى كيانه **الواحد** عبر ثلاثة مدرّسين لا ثلاث نسخ
- [X] T059 [P] [US3] اكتب `backend/tests/Feature/Notifications/GuardianDeliveryTest.php` — SC-010 (وليّ أمر ووصيَّان ⇒ ثلاثة تسليمات وسجلّ واحد لكل مستلم) · SC-011 (غير المخوَّل لا يُبلَّغ · صفر تسليم بعد الإلغاء حتى لوظيفة موزَّعة سلفاً)
- [X] T060 [P] [US3] اكتب `backend/tests/Feature/Notifications/MigrationBackfillTest.php` — SC-016: كل صفّ في `parent_child_links` انتقل بلا فقد (FR-024)

### Implementation for User Story 3

- [X] T061 [US3] أنشئ `backend/app/Modules/Identity/Policies/ParentStudentRelationPolicy.php` — **حارس رؤية المدرّس** (NFR-001أ): المدرّس أو مساعده يقرأ **فقط** إن كان الطالب يملك تسجيلاً نشطاً في كورس داخل مساحة عمله. `RELATIONS_VIEW_STUDENT` **لا تكفي وحدها**
- [X] T062 [P] [US3] أنشئ `backend/app/Modules/Identity/Data/{LinkGuardianData,UpdateRelationPermissionsData}.php`
- [X] T063 [US3] أنشئ `backend/app/Modules/Identity/Actions/LinkGuardian.php` — يفرض **وليّ أمر واحد نشط لكل طالب** (FR-019) في الـ Action لا في المخطّط (SQLite لا يدعم الفهرس الفريد الجزئي بصيغة MySQL — data-model §4)
- [X] T064 [P] [US3] أنشئ `backend/app/Modules/Identity/Actions/{RevokeRelation,UpdateRelationPermissions}.php` — الإلغاء `status=revoked` + `revoked_at`، **لا حذف** (FR-023)
- [X] T065 [US3] وسّع `backend/app/Modules/Notifications/Support/RecipientResolver.php` إلى أولياء الأمور والأوصياء: النوع الموسوم `targetsGuardians()` يصل كل مرتبط **نشط ومخوَّل** بذلك النوع (FR-021 · FR-022)
- [X] T066 [US3] أضف إلى `backend/app/Modules/Notifications/Jobs/DeliverNotificationJob.php` إعادة فحص العلاقة قبل التسليم — علاقة أُلغيت أثناء وجود الوظيفة في الطابور ⇒ `skipped` (FR-023 · Edge Case)
- [X] T067 [P] [US3] أنشئ `backend/app/Modules/Identity/Http/{Requests/LinkGuardianRequest.php,Resources/ParentStudentRelationResource.php}`
- [X] T068 [US3] أنشئ نقاط `/family/relations` في `backend/app/Modules/Identity/Http/Controllers/` وسجّلها في `routes/api.php` (contracts/api.md)
- [X] T069 [US3] حوّل `backend/app/Modules/Identity/Actions/AddChild.php` و`ParentController.php` إلى النموذج الجديد واحذف `ParentChildLinkPolicy.php`
- [X] T070 [US3] أنشئ `frontend/src/app/(app)/family/page.tsx` — إضافة وصيّ، ضبط صلاحياته، إلغاء العلاقة

**Checkpoint**: SC-010 · SC-011 · SC-016 خضراء · حارس رؤية المدرّس مُختبَر

---

## Phase 6: User Story 4 — تفضيلات القنوات لكل نوع (Priority: P4)

**Goal**: المستخدم يختار قنوات كل نوع، ويسري اختياره فوراً

**Independent Test**: تعطيل نوع على قناة ثم إطلاق حدث منه والتحقق من عدم وصوله عليها ووصوله على غيرها

### Tests for User Story 4 ⚠️

- [X] T071 [P] [US4] اكتب `backend/tests/Feature/Notifications/PreferencesTest.php` — SC-012 (التعطيل يمنع قناةً ولا يمنع غيرها) · SC-013 (**صفر** تعطيل ناجح لنوع إلزامي) · FR-030 (قناة غير مُنفَّذة ⇒ `422` ولا تظهر في `types`)

### Implementation for User Story 4

- [X] T072 [P] [US4] أنشئ `backend/app/Modules/Notifications/Data/UpdatePreferencesData.php` و`Http/Requests/UpdatePreferencesRequest.php`
- [X] T073 [US4] أنشئ `backend/app/Modules/Notifications/Actions/UpdateNotificationPreferences.php` — يرفض النوع الإلزامي (FR-029) والقناة غير المُنفَّذة (FR-030) برسالة عربية تبيّن السبب
- [X] T074 [P] [US4] أنشئ `backend/app/Modules/Notifications/Http/Resources/NotificationTypeResource.php` — يعرض القنوات **المُنفَّذة وحدها**
- [X] T075 [US4] أضف `GET /notifications/types` · `GET|PUT /notifications/preferences` إلى المتحكّم والمسارات
- [X] T076 [P] [US4] أنشئ `frontend/src/app/(app)/settings/notifications/page.tsx` — مصفوفة نوع × قناة؛ الإلزامي معطَّل التحرير مع بيان السبب، وغير المُنفَّذ لا يظهر أصلاً
- [X] T077 [US4] اربط `422` بحقولها عبر `fieldErrors()` في `frontend/src/app/(app)/settings/notifications/page.tsx` — **يُمنع** عرض رسالة خام

**Checkpoint**: SC-012 · SC-013 خضراء

---

## Phase 7: User Story 5 — أوقات الهدوء والتجميع (Priority: P5)

**Goal**: لا تنبيه في الثالثة فجراً، ولا عشرون إشعاراً متتالياً

**Independent Test**: إطلاق إشعار داخل نافذة الهدوء وآخر إلزامي، والتحقق من تأجيل الأول ووصول الثاني

> **بلا أثر ملحوظ عند الإطلاق**: أوقات الهدوء تسري على القنوات **الخارجية** وحدها، وكلها غير
> مُنفَّذة — فتُثبَت بـ `FakeExternalChannel` (research §R8). تُبنى الآن لأن إضافتها بعد أول
> قناة حقيقية تعني مراجعة كل مسار إرسال قائم.

### Tests for User Story 5 ⚠️

- [X] T078 [P] [US5] اكتب `backend/tests/Feature/Notifications/QuietHoursTest.php` — SC-014: غير الإلزامي داخل النافذة يُؤجَّل ثم **يُسلَّم** بعدها (لا يُسقَط — FR-033) · الإلزامي يُسلَّم فوراً بلا تأجيل ولا تجميع (FR-035) · النافذة العابرة لمنتصف الليل

### Implementation for User Story 5

- [X] T079 [P] [US5] أنشئ `backend/app/Modules/Notifications/Support/QuietHours.php` — `deferUntil()` يحترم منطقة المستخدم الزمنية والنافذة العابرة لمنتصف الليل
- [X] T080 [US5] أضف التأجيل إلى `backend/app/Modules/Notifications/Actions/DispatchNotification.php`: قناة خارجية + نوع غير إلزامي + داخل النافذة ⇒ `delay()` على الوظيفة و`deferred_until` على التسليم (FR-032)
- [X] T081 [US5] نفّذ التجميع: `digest_window_minutes` على التفضيل — التسليمات المؤجَّلة من النوع نفسه تُدمج في رسالة واحدة عند الإصدار (FR-034)
- [X] T082 [P] [US5] أضف `PUT /notifications/quiet-hours` وطلبه ومورده
- [X] T083 [P] [US5] أضف ضبط نافذة الهدوء والتجميع إلى `frontend/src/app/(app)/settings/notifications/page.tsx`

**Checkpoint**: SC-014 خضراء

---

## Phase 8: User Story 6 — القوالب ومراقبة التسليم (Priority: P6)

**Goal**: تعديل نصّ رسالة من اللوحة بلا نشر كود، ورؤية ما أُرسل وما فشل ولماذا

**Independent Test**: تعديل قالب من اللوحة والتحقق من انعكاسه على الرسالة التالية، وقراءة سجلّ محاولات فاشلة

### Tests for User Story 6 ⚠️

- [X] T084 [P] [US6] اكتب `backend/tests/Feature/Notifications/TemplateAndLogTest.php` — SC-015 (تعديل النصّ ينعكس على التالية والقديمة تبقى بنصّها) · FR-037 (متغيّر ناقص ⇒ منع + تسجيل السبب) · FR-039 (بلا صلاحية ⇒ لا اطّلاع)

### Implementation for User Story 6

- [X] T085 [P] [US6] أنشئ `backend/app/Modules/Notifications/Policies/{MessageTemplatePolicy,NotificationDeliveryPolicy}.php` بثوابت `Permissions` — **يُمنع** اسم صلاحية نصّي
- [X] T086 [P] [US6] أنشئ مورد Filament `backend/app/Filament/Resources/MessageTemplateResource.php` — إنشاء وتعديل بلا نشر كود (FR-036)
- [X] T087 [P] [US6] أنشئ `backend/app/Filament/Resources/NotificationDeliveryResource.php` **قراءة فقط**: المستلم · النوع · القناة · القالب · الحالة · المحاولات · سبب الفشل — **بلا** هاتف أو بريد (FR-040، وهي غير مخزَّنة أصلاً)
- [X] T088 [US6] افرض في `TemplateRenderer` منع الإرسال بقالب غير معتمد على قناة تشترط الاعتماد، وسجّل السبب في التسليم (FR-037)

**Checkpoint**: SC-015 خضراء · اللوحة تعرض السجلّ للمخوَّلين وحدهم

---

## Phase 9: التحقّق من وسيلة التواصل (FR-041 … FR-043)

**Purpose**: بوّابة تفعيل القنوات الخارجية — تُبنى وتُختبَر و**تبقى بلا مستهلك** حتى أول قناة
خارجية، فالقناة داخل المنصة لا تحتاج وسيلة مُتحقَّقاً منها (research §R12)

- [X] T089 [P] اكتب `backend/tests/Feature/Notifications/ContactVerificationTest.php` — الصلاحية ١٠ دقائق · ٥ محاولات · تغيير الوسيلة يُلغي التحقّق السابق (FR-042) · الرمز **لا** يظهر في أي استجابة أو سجلّ
- [X] T090 أنشئ `backend/app/Modules/Notifications/Actions/{RequestContactVerification,ConfirmContactVerification}.php` — الرمز **مُجزَّأ** بـ `Hash::make`
- [X] T091 أضف مساري `POST /contact-verifications` و`/{uuid}/confirm` خلف `throttle:contact-verification` (T006) — **يُمنع** `throttle:5,1`
- [X] T092 اربط `canReach()` في عقد القناة بحالة التحقّق — قناة خارجية بلا وسيلة مُتحقَّقة تُسقَط قبل الطابور

---

## Phase 10: Polish & Cross-Cutting Concerns

- [X] T093 [P] أنشئ `backend/app/Modules/Notifications/Jobs/PruneOldNotificationsJob.php` وجدولته في `routes/console.php` — ٩٠ يوماً للمقروء (FR-017)
- [X] T094 [P] تحقّق من `WorkspaceContext` في كل الوظائف: **يُمنع** `set()`، و`forWorkspace()` وحدها — الوظيفة تعالج مستخدمين من مساحات مختلفة على العامل نفسه (NFR-008)
- [X] T095 [P] حدّث `docs/erd.md` بالجداول الستّة وحذف `parent_child_links`
- [X] T096 [P] حدّث `docs/README.md` — وحدة Notifications: نقاط النهاية والأذونات والأحداث الأربعة
- [X] T097 [P] حدّث `CLAUDE.md` و`AGENTS.md` معاً — قاعدة «القنوات خلف العقد» والطوابير الجديدة و`Notifiable` الباقي للمصادقة
- [X] T098 شغّل [quickstart.md](./quickstart.md) بسيناريوهاته الثمانية يدوياً
- [X] T099 البوابات الأربع: `pest` · `pint --test` · `phpstan analyse` · `npx tsc --noEmit` — **يُمنع** baseline جديد أو `@phpstan-ignore` لتمريرها (SC-018)
- [X] T100 تحقّق من المسارات الحرجة الثمانية في `AGENTS.md` — خضراء بعد الترحيل

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (1)**: بلا تبعية
- **Foundational (2)**: بعد 1 — **يحجب كل القصص**
- **US1 (3)**: بعد 2 — **يحجب US2…US6** (كلها تستهلك البنية)
- **US2 (4)** · **US3 (5)** · **US4 (6)**: بعد US1 — تتوازى فيما بينها
- **US5 (7)**: بعد US4 (التجميع يعيش على التفضيل)
- **US6 (8)**: بعد US1
- **Phase 9**: بعد US1
- **Polish (10)**: بعد كل ما يُراد تسليمه

### تبعيات القصص

| القصّة | تعتمد على | السبب |
|---|---|---|
| US1 | Foundational | هي البنية نفسها |
| US2 | US1 | تعرض ما تنتجه البنية |
| US3 | US1 | توسّع `RecipientResolver` |
| US4 | US1 | توسّع `PreferenceResolver` |
| US5 | US1 · US4 | التجميع حقل على التفضيل |
| US6 | US1 | القوالب يستهلكها `TemplateRenderer` |

**US1 ليست مستقلّة عن البقية — البقية غير مستقلّة عنها.** هذه المرحلة بنيةٌ أولاً، فتسلسلها
حقيقي لا تنظيمي.

### داخل كل قصّة

الاختبارات تُكتب وتفشل أولاً ← التعدادات والنماذج ← الدعم والأفعال ← نقاط النهاية ← الواجهة

### Parallel Opportunities

- T003…T006 معاً · T007…T010 معاً · T014…T016 معاً · T018…T023 معاً
- اختبارات كل قصّة الموسومة [P] معاً
- بعد US1: **US2 و US3 و US4 بالتوازي** بمطوّرين مختلفين
- الواجهة والخلفية داخل القصّة الواحدة بعد استقرار عقد الـ API

---

## Parallel Example: User Story 1

```bash
# الاختبارات أولاً — معاً:
Task: "FakeChannel + FakeExternalChannel in backend/tests/Support/"
Task: "ChannelContractTest in backend/tests/Feature/Notifications/"
Task: "ProviderAgnosticTest in backend/tests/Feature/Notifications/"
Task: "DeliveryLifecycleTest in backend/tests/Feature/Notifications/"

# ثم الأجزاء المستقلة — معاً:
Task: "NotificationEnvelope DTO in Data/"
Task: "PermanentDeliveryException in Exceptions/"
Task: "الأحداث الأربعة in Events/"
```

---

## Implementation Strategy

### MVP (US1 + US2)

1. Phase 1 Setup
2. Phase 2 Foundational — **حاجب**
3. Phase 3 US1 — البنية
4. Phase 4 US2 — أول ظهور مرئي
5. **قف وتحقّق**: `/notifications` تعمل، وإضافة قناة ملف وسطر
6. سلّم

US1 وحدها بلا US2 بنيةٌ لا يراها أحد — فالـ MVP القابل للعرض هو الاثنتان معاً.

### التسليم التدريجي

1. Setup + Foundational → الأساس
2. US1 + US2 → **MVP**
3. US3 → وليّ الأمر والأوصياء (يفتح 005 و006 و008)
4. US4 → التفضيلات
5. US5 → أوقات الهدوء (تسري عند أول قناة خارجية)
6. US6 → القوالب والمراقبة
7. Phase 9 → التحقّق من الوسيلة (جاهز لواتساب)

### فريق متعدّد

بعد US1: مطوّر على US2 (مركز + واجهة) · مطوّر على US3 (وليّ الأمر) · مطوّر على US4+US5
(التفضيلات والهدوء). لا تعارض في الملفات بين الثلاثة عدا `DispatchNotification`
(T065 · T080) — تُسلسل هاتان.

---

## Notes

- [P] = ملفات مختلفة بلا تبعية
- الاختبارات تفشل **قبل** التنفيذ — وإلا فهي لا تختبر شيئاً
- التزم بعد كل مهمّة أو مجموعة منطقية
- **يُمنع** تشغيل `npm run build` وخادم `npm run dev` معاً
- ثلاث مهامّ إلزامية دستورياً لا تُسقَط تحت أي ضغط: **T002** (phpstan)، **T058** (حارس رؤية
  المدرّس)، **T046** (المسار الحرج)
