---
description: "Task list for 014-teacher-settlement"
---

# Tasks: تسوية المدرّس ومستحقاته

**Input**: `specs/014-teacher-settlement/` — [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md)

**Tests**: **مطلوبة**. الدستور IV يجعل اختبارات الميزة شبكة الأمان الأساسية، والمواصفة تربط كل
`SC-` من الثمانية عشر باختبار مسمّى. وهذه المرحلة **مالية**: خطأ في الدفتر يظهر كمال يصل مدرّساً
لا يستحقه أو لا يصله وهو يستحقه، فلا مهمة تنفيذ بلا مهمة اختبار تسبقها أو ترافقها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: قابلة للتوازي — ملفات مختلفة، بلا اعتماد على مهمة غير مكتملة
- **[Story]**: `US1`…`US5` لمهام مراحل قصص المستخدم فقط

## Path Conventions

`backend/` أحادية معيارية · `frontend/` Next.js. كل المسارات من جذر المستودع.

---

## Phase 1: Setup (البنية المشتركة)

**Purpose**: هيكل الوحدة والإعدادات والصلاحيات والمحدّدات — بلا منطق.

- [X] T001 أنشئ هيكل وحدة `Settlement` في `backend/app/Modules/Settlement/` بمجلدات `Data` · `Enums` · `Models` · `Actions` · `Jobs` · `Listeners` · `Events` · `Http/{Controllers,Requests,Resources}` · `Policies` · `Support` · `Database/Migrations` (حرف M كبير — خطأ الحالة يحمّل صفر هجرات على Linux بصمت) · `routes`
- [X] T002 أنشئ `backend/app/Modules/Settlement/SettlementServiceProvider.php` يمتدّ `App\Shared\Modules\Module` بـ `protected string $name = 'Settlement'` — **يُمنع** تسجيله في `bootstrap/providers.php`
- [X] T003 أضف `app/Modules/Settlement/Database/Migrations` إلى `databaseMigrationsPath` في `backend/phpstan.neon` — بدونها لا يستنتج Larastan أنواع خصائص النماذج وتسقط بوابة المستوى ٨
- [X] T004 [P] أنشئ `backend/config/settlement.php` بمفاتيح `period_days` · `required_package_components` · `zero_attendance_compensation_enabled` · `zero_attendance_compensation_percent` · `rate_requests_per_window` · `rate_request_window_days` · `currency` — كلها **افتراضيات** يعلوها `platform_settings` (data-model § SettlementPolicy)
- [X] T005 [P] أضف المحدّد المسمّى `settlement-write` (٢٠/دقيقة بالمستخدم) في `AppServiceProvider::registerRateLimiters()` بـ `backend/app/Providers/AppServiceProvider.php` — **يُمنع** أي `throttle:N,M` سطري: `ThrottleRequests` يفهرس بلا مسار فتتشارك كل الحدود السطرية عدّاداً واحداً
- [X] T006 [P] أنشئ `backend/app/Modules/Settlement/routes/api.php` فارغاً بترويسة توضّح أن `Module` يضيف البادئة `/api/v1` ومجموعة `api` تلقائياً
- [X] T007 [P] أضف مفاتيح `settlement.*` السبعة إلى `PlatformSettings::KEYS` في `backend/app/Modules/Tenancy/Support/PlatformSettings.php` وصفوفها إلى `backend/database/seeders/PlatformSettingsSeeder.php`
- [X] T008 [P] أضف الثوابت الستّ `SETTLEMENT_RATE_REQUEST` · `SETTLEMENT_RATE_APPROVE` · `SETTLEMENT_STATEMENT_VIEW` · `SETTLEMENT_PERIOD_MANAGE` · `SETTLEMENT_PAYOUT_EXECUTE` · `SETTLEMENT_AUDIT_VIEW` إلى `backend/app/Modules/Tenancy/Support/Permissions.php` **وإلى `Permissions::all()`** — الغياب عن `all()` صلاحية لا تُبذَر فلا تُمنح لأحد
- [X] T009 أسند الصلاحيات في `backend/app/Modules/Tenancy/Listeners/SeedDefaultRoles.php`: المدرّس يأخذ `SETTLEMENT_RATE_REQUEST` و`SETTLEMENT_STATEMENT_VIEW` **وحدهما**؛ الإدارة تأخذ الاعتماد والفترة والصرف؛ **ولا أحد** يأخذ `SETTLEMENT_AUDIT_VIEW` تلقائياً (تُمنح يدوياً على مستوى المنصة — FR-034). **يُمنع** منح أيٍّ منها لدور مساعد المدرّس (FR-020)

**Checkpoint**: الوحدة مكتشَفة · PHPStan يقرأ هجراتها · المحدّد مسمّى · الصلاحيات مبذورة ومفصولة عن التشغيل.

---

## Phase 2: Foundational (متطلبات حاجبة لكل القصص)

**Purpose**: المخطّط والنماذج والتعدادات — لا قصة تبدأ قبلها.

**⚠️ حاجبة**: كل مهام Phase 3+ تعتمد على اكتمال هذه المرحلة.

- [X] T010 [P] أنشئ التعدادات في `backend/app/Modules/Settlement/Enums/`: `TeachingUnitStatus` (`pending_package` · `accrued` · `disputed` · `settled` · `reversed`) · `SettlementBasis` (`frozen_seat` · `zero_attendance_compensation`) · `LedgerEntryType` (`unit` · `reversal` · `deduction` · `bonus` · `payout` · `carry_over`) · `SettlementPeriodStatus` (`open` · `closed` · `paid`) · `RateRequestStatus` (`pending` · `approved` · `rejected`) — كلٌّ بدالة `label()` عربية
- [X] T011 أنشئ هجرة `create_settlement_tables` في `backend/app/Modules/Settlement/Database/Migrations/` بالجداول الستّة من [data-model.md](./data-model.md): `settlement_rates` · `rate_change_requests` · `teaching_units` · `ledger_entries` · `settlement_periods` · `teacher_payouts`. المبالغ `bigInteger` بالوحدة الصغرى — **مُوقَّعة في `ledger_entries` وغير مُوقَّعة في الباقي** (research §R3). **يُمنع** أي مفتاح خارجي إلى `orders` · `payments` أو أي جدول فوترة (FR-030)
- [X] T012 أضف في الهجرة نفسها الفهارس المُعلَنة: `(workspace_id, teacher_profile_id, session_type, effective_from)` على الأسعار · `(workspace_id, teacher_profile_id, settlement_period_id, status)` على الوحدات · فريد `(class_session_id, student_user_id)` على الوحدات غير العكسية · فريد `(settlement_period_id)` على الصرف
- [X] T013 [P] أنشئ النماذج الستّة في `backend/app/Modules/Settlement/Models/` بـ`HasUuid` و`BelongsToWorkspace` و`casts()` صريحة و`@property` للأعمدة التي يقرأ Larastan نوعها من الهجرة نصاً
- [X] T014 [P] أنشئ المصانع في `backend/database/factories/Modules/Settlement/` — **يُمنع** تعريف `newFactory()` على النماذج (`guessFactoryName()` يحلّها مركزياً)
- [X] T015 [P] أنشئ `backend/app/Modules/Settlement/Support/SettlementSettings.php` يقرأ `settlement.*` من `PlatformSettings` — المصدر **الوحيد** لأرقام هذه المرحلة؛ **يُمنع** `config()` مباشرةً من أي Action
- [X] T016 [P] أنشئ الأحداث الأربعة في `backend/app/Modules/Settlement/Events/`: `TeachingUnitAccrued` · `SettlementRateApproved` · `SettlementPeriodClosed` · `TeacherPayoutIssued` — بخصائص `readonly` مُرقّاة، وبتعليق على `SettlementRateApproved` يسمّي **006 مستهلكاً مؤجَّلاً** ([contracts/events.md](./contracts/events.md))
- [X] T017 [P] أنشئ سياسات `backend/app/Modules/Settlement/Policies/` وسجّلها في المزوّد؛ أسماء الصلاحيات من ثوابت `Permissions` حصراً — **يُمنع** نصّ حرفي
- [X] T018 أضف حالات الجداول الستّة إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — نموذج بلا `BelongsToWorkspace` يعمل في كل اختبار قائم ويسرّب بصمت في الإنتاج؛ الاختبار المضاف هو البوابة الوحيدة
- [X] T019 اكتب `backend/tests/Feature/Settlement/OwnershipLayerTest.php`: `TeachingUnit` جسر (يحمل `workspace_id` **ويشير** إلى الطالب المملوك للمنصة) والباقي مملوك لمساحة العمل — والحالة المقابلة: مدرّس لا يقرأ وحدة طالب غير مسجَّل عنده

**Checkpoint**: `migrate:fresh --seed` يمرّ · PHPStan نظيف · العزل مُختبَر قبل أي منطق.

---

## Phase 3: US1 — وحدة تدريس عن كل حصة مُنفَّذة (Priority: P1) 🎯 MVP

**Goal**: كل حصة مُنفَّذة تولّد وحدة عن **كل مقعد مُجمَّد**، بقيمتها من سعر التسوية الساري — بلا
أي علاقة بما دفعه الطالب أو بما إذا دفع أصلاً.

**Independent Test**: أكِّد تنفيذ حصص معروفة العدد وقارن الدفتر بسعر التسوية × العدد — بلا أي
بيانات دفع في الاختبار كلّه.

### الاختبارات أولاً

- [X] T020 [P] [US1] اكتب `backend/tests/Feature/Settlement/AccrualTest.php` — «تولّد وحدة عن كل مقعد مُجمَّد» بحصة جماعية **عشرة مقاعد وستة حاضرين ← عشر وحدات** (SC-005ب). قارن بـ`billable_seats` لا بعدد صفوف الحضور: الاختبار الذي يعدّ الحضور يمرّ اليوم ويخفي العطل غداً
- [X] T021 [P] [US1] أضف إلى الملف نفسه حالتَي «لم يُنفّذ المدرّس»: لم يدخل · غادر قبل المدة. توقّع **صفر وحدة**، وتحقّق بـ`Event::fake()` أن `SessionDelivered` **لم يُطلَق أصلاً** — كي يثبت الاختبار أن الشرط في 005 حيث وُضع لا في مستمع هنا (SC-005د)
- [X] T022 [P] [US1] أضف «الحدث نفسه وصل مرتين ← وحدة واحدة»: أطلق `SessionDelivered` عشر مرات وتوقّع عدداً واحداً (SC-002)
- [X] T023 [P] [US1] اكتب `backend/tests/Feature/Settlement/PackageCompletionTest.php` بثلاث حالات على `recording_status`: `pending` ← معلَّقة · `published` ← مُفرَج عنها **تلقائياً بلا تدخّل** · `failed` ← **مُفرَج عنها** و`recording_fault = true` (SC-005هـ · FR-008ج)
- [X] T024 [P] [US1] اكتب `backend/tests/Feature/Settlement/RefundDoesNotTouchLedgerTest.php`: نفّذ حصة، ولّد وحداتها، ثم نفّذ استرداداً وخصماً وكوبوناً على جانب الطالب — الدفتر **بفارق صفر** قبل وبعد في الحالات الثلاث (SC-003 · SC-004)
- [X] T025 [P] [US1] اكتب `backend/tests/Feature/Settlement/ZeroBookingTest.php`: صفر مقعد ← صفر استحقاق افتراضاً · وبتفعيل المفتاح ← التعويض المضبوط · وفي الحالتين **تُعلَّم للمراجعة** (SC-005و)
- [X] T026 [P] [US1] اكتب `backend/tests/Feature/Settlement/UnitReversalTest.php`: عدّل الحضور بعد التوليد وتوقّع صفّاً جديداً بـ`reversal_of_id` بمنفّذه وسببه، و**بقاء** الأصل (FR-006 · SC-013)

### التنفيذ

- [X] T027 [US1] أنشئ `backend/app/Modules/Settlement/Support/RateResolver.php`: الأخصّ (مادة+مرحلة ← مادة ← عام) ثم أحدث `effective_from` لا يتجاوز بدء الحصة (research §R4 · FR-014ب)
- [X] T028 [US1] أنشئ `backend/app/Modules/Settlement/Actions/AccrueTeachingUnits.php` — يقرأ `billableSeats` **من الحدث** ولا يعيد احتسابه من الحجوزات (FR-007أ)، ويخزّن `settlement_rate_id` **و**`amount_minor` **و**`frozen_seats` **و**`basis` مع الوحدة (FR-007ب)
- [X] T029 [US1] اجعل التوليد عديم الأثر بالفهرس الفريد `(class_session_id, student_user_id)` + `firstOrCreate` — لا بفحص `count()` قبل الإدراج، فذاك تعريف السباق
- [X] T030 [US1] أنشئ `backend/app/Modules/Settlement/Listeners/AccrueUnitsOnDelivery.php` وسجّله على `SessionDelivered` بـ`Event::listen()` في `SettlementServiceProvider::boot()` — **وليس** على `AttendanceConfirmed` (research §R1؛ ذاك يُطلَق عن حصة غير مُنفَّذة ولا يحمل عدد المقاعد)
- [X] T031 [US1] أنشئ `backend/app/Modules/Settlement/Support/PackageCompletion.php` بجدول قيم `recording_status` من [research §R6](./research.md): `published`/`failed`/`no_course`/`null` ← مكتملة · `pending`/`ingesting` ← ناقصة
- [X] T032 [US1] أنشئ `backend/app/Modules/Settlement/Actions/ReleasePendingUnits.php` يُفرِج تلقائياً بلا مراجعة بشرية (FR-008ب) ويكتب `pending_reason` بنصّ ما ينقص بالضبط (FR-008د)
- [X] T033 [US1] أنشئ `backend/app/Modules/Settlement/Jobs/ReleasePendingUnitsJob.php` مجدولة في `backend/routes/console.php` بـ`forWorkspace()` — **يُمنع** `WorkspaceContext::set()`: المفردة تُخزّن نتيجتها فتسرّب مساحة العمل إلى المهمة التالية على العامل نفسه
- [X] T034 [US1] أنشئ `backend/app/Modules/Settlement/Actions/ReverseTeachingUnit.php` **بمنفّذ وسبب إلزاميين**، ومساره `POST /admin/settlement/units/{unit}/reverse` — **بلا مستمع على `AttendanceOverridden`**: الوحدة بالمقعد لا بالحضور، و005 تشحن `AttendanceHasNoFinancialEffectTest` الذي يُفشِل البناء على ذلك (`Q7`)
- [X] T035 [US1] أنشئ `backend/app/Modules/Settlement/Actions/WriteLedgerEntry.php` — المدخل **الوحيد** للدفتر، ويرفض أي `UPDATE`/`DELETE` صراحةً (FR-016)
- [X] T036 [US1] أطلق `TeachingUnitAccrued` عند خروج الوحدة إلى `accrued`، واربطه بقيد الدفتر داخل الوحدة نفسها
- [X] T037 [US1] عالج التعويض عن الحصة بلا حجوزات في `AccrueTeachingUnits`: مطفأ افتراضاً · مشروط بتحقّق التنفيذ (FR-008ز) · والعلامة تُكتب في الحالتين (FR-008ح)

**Checkpoint**: US1 قابلة للتسليم وحدها — دفتر دقيق قابل للتدقيق، وكل ما بعدها سياسات فوقه.

---

## Phase 4: US2 — المدرّس يرفع سعره والإدارة تعتمده (Priority: P2)

**Goal**: السعر مُصدَّر بنسخ، يسري على ما بعده فقط، ولا يعيد تسعير الماضي أبداً.

**Independent Test**: نفّذ حصصاً · غيّر السعر · نفّذ حصصاً أخرى · تحقّق أن المجموعة الأولى لم تتغيّر.

### الاختبارات أولاً

- [X] T038 [P] [US2] اكتب `backend/tests/Feature/Settlement/RateVersioningTest.php` — «تغيير السعر لا يعيد تسعير وحدة سابقة» بمقارنة قبل/بعد على المبلغ المخزَّن لا على الصف المرجعي (SC-005)
- [X] T039 [P] [US2] أضف «صفر سعر يسري بلا اعتماد»: ارفع سعراً ونفّذ حصة وتوقّع السعر **السابق** (SC-005أ)
- [X] T040 [P] [US2] اكتب `backend/tests/Feature/Settlement/RateResolutionTest.php` — أولوية النطاقات الثلاثة (عام · مادة · مادة+مرحلة) بحالة لكل ترتيب (SC-005ج)
- [X] T041 [P] [US2] اكتب `backend/tests/Feature/Settlement/RateRequestTest.php`: الرفض يُبقي السابق ويوجب سبباً · طلبان معلّقان على نطاق واحد مرفوضان · تجاوز الحدّ الترددي **٤٢٢ بموعد الإتاحة التالي** (FR-013أ · FR-013ب)
- [X] T042 [P] [US2] أضف اختباراً يفحص المخطّط والكود: **صفر** عمود أو حقل نسبة مئوية في التسعير (SC-006 · FR-009)

### التنفيذ

- [X] T043 [P] [US2] أنشئ `backend/app/Modules/Settlement/Data/RateChangeData.php` يرث `DataTransferObject` بخصائص `readonly` ومُنشئ `fromArray()`
- [X] T044 [US2] أنشئ `backend/app/Modules/Settlement/Actions/RequestRateChange.php` — يفرض الحدّ الترددي من `SettlementSettings` **داخل الـAction** لا في `FormRequest` وحده (الدستور II: اللوحة تلتفّ على تحقّق الطلب)
- [X] T045 [US2] أنشئ `backend/app/Modules/Settlement/Actions/DecideRateChange.php` — الاعتماد **وحده** يُنشئ صفّ `settlement_rates`؛ لا مسار ثانٍ ينشئه، وهو ما يجعل SC-005أ خاصيةً بنيوية لا قاعدة يُذكَّر بها المراجع
- [X] T046 [US2] أطلق `SettlementRateApproved` من `DecideRateChange` مع تعليق يسمّي **006** مستهلكاً مؤجَّلاً — ويمنع أن يمسّ شراءً تمّ أو رصيداً قائماً (FR-013ج)
- [X] T047 [P] [US2] أنشئ `SettlementRateResource` و`RateChangeRequestResource` في `Http/Resources/` — تكشف `uuid` فقط
- [X] T048 [US2] أنشئ `RateChangeController` و`StoreRateChangeRequest` ومساري الاعتماد والرفض الإداريَّين بـ`throttle:settlement-write` ([contracts/api.md](./contracts/api.md))
- [X] T049 [P] [US2] أضف قوالب إشعارات الاعتماد والرفض إلى `backend/database/seeders/NotificationTemplateSeeder.php` — قالب مفقود يعني إشعاراً يُسقَط بصمت وتأكيداً يمرّ على صفر

**Checkpoint**: السعر مُؤرَّخ ومحروس، وUS1 ما زالت خضراء.

---

## Phase 5: US3 — كشف المدرّس: عقده هو لا محفظة طالبه (Priority: P3)

**Goal**: المدرّس يرى وحداته وأسعاره وصافيه — ولا يرى **ولا مرة واحدة** ما دفعه أي طالب.

**Independent Test**: مدرّس بوحدات معروفة، وفحص كل حمولة تصله بحثاً عن أي رقم يخصّ دفع الطالب.

### الاختبارات أولاً

- [X] T050 [P] [US3] اكتب `backend/tests/Feature/Settlement/StatementPayloadTest.php` — قائمة حقول مصرّح بها مغلقة، وأي حقل خارجها يُفشِل الاختبار. تُطبَّق على الكشف **والتصدير معاً** (SC-007 · FR-018)
- [X] T051 [P] [US3] أضف «إجماليات الكشف تطابق الدفتر بفارق صفر» بعد ١٠٬٠٠٠ قيد (SC-012 · FR-022)
- [X] T052 [P] [US3] اكتب `backend/tests/Feature/Settlement/StatementAccessTest.php`: مساعد المدرّس ← **٤٠٣** · مدرّس آخر ← لا يرى شيئاً · لا معامل `teacher` يقبل غير صاحب الرمز (SC-010 · SC-011)
- [X] T053 [P] [US3] اكتب `backend/tests/Feature/Settlement/QueryBudgetTest.php` بـ`DB::enableQueryLog()` — تُقاس بمقارنة **حجمين** (١٠٠ وحدة مقابل ١٠٬٠٠٠) لا برقم ثابت: الرقم الثابت يسمح لـN+1 بالاختباء داخل السماحية (SC-016)

### التنفيذ

- [X] T054 [P] [US3] أنشئ `backend/app/Modules/Settlement/Support/TeacherFieldAllowlist.php` — قائمة الحقول **مشتركة** بين الكشف والتصدير؛ نسخة ثانية منها نسخة تتباعد، والتصدير أكثر سطح يُنسى عند إضافة حقل
- [X] T055 [US3] أنشئ `backend/app/Modules/Settlement/Actions/BuildTeacherStatement.php` — يقرأ الإجماليات **المُجمَّدة** من الفترة المغلقة و`SUM` على الفهرس للفترة الجارية وحدها (research §R8)
- [X] T056 [P] [US3] أنشئ `TeacherStatementResource` و`TeachingUnitResource` — **يُمنع** استعلام داخل أيٍّ منهما: الـResource يعمل مرة لكل صف فأي استعلام فيه N+1 بالبناء (درس 005)
- [X] T057 [US3] أنشئ `StatementController` بمساري الكشف والوحدات، محروسَين بـ`SETTLEMENT_STATEMENT_VIEW`، ويعيدان بيانات صاحب الرمز وحده
- [X] T058 [US3] أنشئ `backend/app/Modules/Settlement/Actions/ExportTeacherStatement.php` يمرّ بـ`TeacherFieldAllowlist` نفسها (FR-021)
- [X] T059 [P] [US3] أنشئ `frontend/src/lib/settlement.ts` بأنواع الكشف ودوالّ القراءة
- [X] T060 [P] [US3] أنشئ `frontend/src/components/settlement/StatementSummary.tsx` بمكوّنات `components/ui/` وحدها — بلا `className` حرّ، وبألوان `@theme` فقط، وخصائص منطقية (`ms-*` · `text-start`)
- [X] T061 [US3] أنشئ `frontend/src/app/(app)/(shell)/manage/settlement/page.tsx` — الأخطاء عبر `userMessage()`/`fieldErrors()`، **يُمنع** عرض خطأ خام
- [X] T062 [US3] أضف رابط «كشف التسوية» إلى قائمة تنقّل اللوحة في `frontend/src/app/(app)/(shell)/layout.tsx` — صفحة بلا رابط وارد صفحة غير مُسلَّمة
- [X] T063 [P] [US3] أضف `frontend/e2e/settlement.spec.ts` يمشي المسار كاملاً ويؤكّد **غياب** أي مبلغ يخصّ دفع طالب من الصفحة — **مكتوب ولم يُشغَّل بعد**: الإعداد المعتمَد يبني نسخة إنتاج في `.next/` وخادم التطوير قائم عند المستخدم، فالتشغيل يقتله (راجع CLAUDE.md). يُشغَّل بإذنه أو بـ`--config e2e/playwright.local.config.ts` على منفذ آخر

**Checkpoint**: المدرّس يرى عقده هو، ومُثبَت آلياً أنه لا يرى غيره.

---

## Phase 6: US4 — التسوية الدورية والصرف (Priority: P4)

**Goal**: فترة تُغلَق بإجماليات مُجمَّدة، وصافٍ يُحتسب، وصرف يُسجَّل — بلا تكرار وبلا سالب.

**Independent Test**: أغلِق فترة وقارن صافيها بمجموع وحداتها، ثم سجّل صرفاً وتحقّق من خصمه.

### الاختبارات أولاً

- [X] T064 [P] [US4] اكتب `backend/tests/Feature/Settlement/PeriodCloseTest.php`: أغلِق مرتين ← إغلاق واحد · صرف مرتين ← صرف واحد (SC-014). **`Queue::fake()` إلزامي**: `->delay()` يُنفَّذ فوراً على اتّصال `sync` فتعمل الوظيفة داخل الفعل الذي جدولها
- [X] T065 [P] [US4] أضف «الوحدة المتأخرة تُرحَّل ولا تُعيد فتح المغلقة» (FR-024)
- [X] T066 [P] [US4] أضف «الصافي السالب يُرحَّل ولا يُصرَف» — صفر صرف بمبلغ سالب (SC-015 · FR-026)
- [X] T067 [P] [US4] أضف «الوحدة المتنازع عليها لا تدخل التسوية» (FR-008)

### التنفيذ

- [X] T068 [US4] أنشئ `backend/app/Modules/Settlement/Actions/CloseSettlementPeriod.php` بـ`UPDATE … WHERE status = 'open'` **ذرّي** وفحص عدد الصفوف المتأثّرة — **يُمنع** `count()` ثم `insert()` و**يُمنع** `lockForUpdate()`: الأخير عديم الأثر على SQLite فاختبارٌ مبني عليه ينجح محلياً ولا يثبت شيئاً عن MySQL
- [X] T069 [US4] احسب الصافي داخل معاملة واحدة، وجمّد `units_count` و`gross_minor` و`deductions_minor` و`net_minor` على صفّ الفترة (NFR-010)
- [X] T070 [US4] أنشئ `backend/app/Modules/Settlement/Actions/RecordTeacherPayout.php` — يرفض الصافي ≤ صفر ويرفض فترة لها صرف سابق (الفريد على `settlement_period_id` هو الحارس الحقيقي)
- [X] T071 [US4] أنشئ `backend/app/Modules/Settlement/Actions/RecordDeduction.php` — الخصم بسببه، ويظهر في الكشف (FR-025)
- [X] T072 [US4] أنشئ `backend/app/Modules/Settlement/Jobs/CloseDueSettlementPeriodsJob.php` مجدولة بـ`forWorkspace()`، في ساعة **بعيدة** عن مسحات 005 الليلية
- [X] T073 [US4] أطلق `SettlementPeriodClosed` و`TeacherPayoutIssued`، وأنشئ مستمعيهما للإشعار عبر `DispatchNotification` — **يُمنع** تسمية قناة في أي ملف تحت `Actions/`
- [X] T074 [P] [US4] أضف قوالب الإشعارين إلى `NotificationTemplateSeeder.php` (FR-029)
- [X] T075 [US4] أنشئ `SettlementPeriodController` بمساري الإغلاق والصرف الإداريَّين بـ`throttle:settlement-write` وصلاحيتيهما

**Checkpoint**: الدفتر صار مالاً يصل المدرّس، بلا تكرار وبلا سالب.

---

## Phase 7: US5 — عزل السياقين مُختبَراً لا موعوداً (Priority: P5)

**Goal**: مطوّر يحاول — عن غير قصد — جمع دفعة الطالب بوحدة المدرّس، فيسقط البناء.

**Independent Test**: اختبار معماري يمرّ على الجدولين والحمولات والصادرات.

- [X] T076 [US5] اكتب `backend/tests/Feature/Settlement/ContextIsolationTest.php` — الحالة الأولى: **صفر مفتاح خارجي** بين جداول `Settlement` وجداول الفوترة في الاتجاهين، يُقرأ من ملفات الهجرة (SC-009 · FR-030)
- [X] T077 [US5] أضف الحالة الثانية: **صفر ذكر** لأسماء نماذج أو جداول سياق الفوترة داخل `app/Modules/Settlement/` — بالنمط نفسه الذي يستعمله `ProviderAgnosticTest` و`TrustScoreJobIsolationTest` القائمان (FR-031)
- [X] T078 [US5] أضف الحالة الثالثة: كل مورد يصل مدرّساً يمرّ بـ`TeacherFieldAllowlist`، وكل حمولة تصل طالباً أو وليّ أمر **خالية** من سعر تسوية أو نصيب مدرّس (SC-008 · FR-033)
- [X] T079 [US5] **تحقّق من أن الحارس يحرس**: أضف مؤقتاً حقلاً من سياق الفوترة إلى مورد الكشف وشغّل الاختبار — إن مرّ فالحارس زينة. أعِد الحقل بعدها وسجّل النتيجة في وصف الإيداع
- [X] T080 [US5] فعّل `Shared\Traits\LogsActivity` على كيانات هذا السياق، ورشّح سجلّ التدقيق عند العرض بحيث لا يرى أيٌّ من الطرفين الجانب الآخر؛ الرؤية الكاملة لـ`SETTLEMENT_AUDIT_VIEW` وحدها (FR-034)

**Checkpoint**: الفصل صار خاصية يحرسها البناء، لا نيّة في وثيقة.

---

## Phase 8: Polish & Cross-Cutting

- [X] T081 [P] أضف `sessions()`-style بذرة تسوية إلى `backend/database/seeders/ScenarioSeeder.php`: مدرّس بوحدات مُستحقّة ومعلَّقة ومتنازع عليها، وفترة مغلقة بصرف — كي لا تكون الشاشة فارغة عند أول فتح
- [X] T082 [P] حدّث `docs/README.md` بجدول الوحدة ونقاط النهاية والصلاحيات الستّ الجديدة
- [X] T083 [P] حدّث `docs/erd.md` بالجداول الستّة، **وارسم غياب** الرابط بينها وبين جداول الفوترة صراحةً — الغياب هنا قرار معماري لا نقص في الرسم
- [X] T084 [P] حدّث `CLAUDE.md` و`AGENTS.md` معاً بمزالق هذه المرحلة: الحدث الجسر ولماذا هو `SessionDelivered` · المبالغ بالوحدة الصغرى ولماذا تخالف `decimal` القائم · الدفتر لا يُعدَّل
- [X] T085 [P] حدّث `docs/roadmap.md` بحالة 014 وأثرها على 006
- [X] T085أ [P] أظهر «مستحقّ من فترات مغلقة لم تُصرَف» في الكشف — بعد إغلاق بصافٍ موجب ينتظر الصرف، يهبط عنوان الكشف إلى صفر تقريباً بينما المال مستحقّ فعلاً: المبلغ صار مُجمَّداً على صفّ الفترة والنافذة الجارية فارغة. مصدره `/settlement/periods` القائم؛ لا استعلام جديد
- [X] T086 راجع كل مسار كتابة: محدود المعدّل بمحدِّد **مسمّى** · وكل مسار عرض مالي محروس بصلاحية صريحة (NFR-012)
- [X] T087 شغّل البوابات الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit` (SC-018)
- [X] T088 شغّل `npx playwright test` على **بناء إنتاج** مع `PHP_CLI_SERVER_WORKERS=8 php artisan serve` — الخادم أحادي الخيط يرفض طلبات ما قبل التصيير المتوازية فيسقط البناء قبل أول اختبار
- [X] T089 امشِ سيناريوهات [quickstart.md](./quickstart.md) التسعة يدوياً وسجّل أي فارق

---

## Dependencies & Execution Order

```
Phase 1 (Setup) ──→ Phase 2 (Foundational) ──┬──→ US1 (P1) ──→ US2 (P2) ──→ US3 (P3) ──→ US4 (P4)
                                              └──→ US5 (P5) ─────────────────────────────────┘
                                                    ▲ يُكتب مبكراً ويُشدَّد مع كل قصة
```

- **US1 لا تعتمد على US2**: السعر موجود من Phase 2؛ US2 تضيف مسار طلبه واعتماده فوقه.
- **US3 تعتمد على US1** (لا كشف بلا دفتر) و**US4 تعتمد على US3** (الإجماليات المُجمَّدة هي ما يقرأه الكشف).
- **US5 مستقلة تقنياً** ويُنصح ببدء `ContextIsolationTest` مع Phase 2: اختبار معماري يُكتب بعد الكود يوثّق ما بُني، ويُكتب قبله يمنع ما لا يُبنى.

## Parallel Opportunities

| المرحلة | قابل للتوازي |
|---|---|
| Phase 1 | T004 · T005 · T006 · T007 · T008 (ملفات مختلفة) |
| Phase 2 | T010 · T013 · T014 · T015 · T016 · T017 بعد T011–T012 |
| US1 | كل اختبارات T020–T026 معاً |
| US2 | T038–T042 معاً · ثم T043 · T047 · T049 |
| US3 | T050–T053 معاً · ثم T054 · T056 · T059 · T060 · T063 |
| US4 | T064–T067 معاً · ثم T074 |
| Phase 8 | T081–T085 معاً |

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + US1**. عند اكتمالها يوجد دفتر دقيق قابل للتدقيق لمستحق كل مدرّس،
مبنيّ على المقعد المُجمَّد، معزول عن أي بيانات دفع — وهو **المُدخَل الذي تنتظره 006**. كل ما
بعده سياسات وعرض فوق دفتر يعمل.

**ترتيب التسليم**: US1 (المحرّك) ← US2 (تأريخ السعر، قبل أن يتراكم ما يُعاد تسعيره) ←
US3 (وجه المدرّس) ← US4 (المال يصل) ← US5 (تثبيت الفصل).

**تحذير مالي**: هذه أخطر مرحلة مالياً بعد 006. خطأ في الدفتر يظهر كمال يصل مدرّساً لا يستحقه
أو لا يصله وهو يستحقه — واختبارات التزامن والتكرار والمطابقة فيها أولى من أي شاشة.
