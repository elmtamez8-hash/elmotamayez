---
description: "Task list for 006-credit-billing-engine"
---

# Tasks: محرّك الأرصدة والتحصيل

**Input**: `specs/006-credit-billing-engine/` — [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/api.md](./contracts/api.md) · [contracts/events.md](./contracts/events.md) · [quickstart.md](./quickstart.md)

**Tests**: **مطلوبة**. `NFR-007` تفرضها نصّاً، وواحدٌ وعشرون معيار نجاح من أصل ستّة وعشرين يقول
«**مُثبَتة باختبار**». وهذه المرحلة **تنتج أول ريال محصَّل**: خطأ في الرصيد يظهر كخسارة نقدية أو
كطالب محجوب ظلماً، فلا مهمة تنفيذ بلا مهمة اختبار تسبقها.

**Format**: `[ID] [P?] [Story] Description`

- **[P]**: قابلة للتوازي — ملفات مختلفة، بلا اعتماد على مهمة غير مكتملة
- **[Story]**: `US1`…`US9` لمهام مراحل قصص المستخدم فقط

**Path Conventions**: `backend/` أحادية معيارية · `frontend/` Next.js. كل المسارات من جذر
المستودع. **الوحدة توسعة لـ`Payments` القائمة، لا وحدة جديدة** ([research.md › R1](./research.md))
— فلا سطر في `phpstan.neon` ولا مزوّد جديد.

---

## Phase 1: Setup (البنية المشتركة)

**Purpose**: قرارات حاجبة · صلاحيات · محدّدات · مفاتيح مضبوطة · تعدادات — بلا منطق.

- [X] T001 ✅ **محسوم 2026-08-08**: `credit_packages` **مملوكة للمنصة**، و`.specify/memory/constitution.md` عُدّل إلى **v1.2.0**. التعديل لم يكن نقل بندٍ بين قائمتين بل **تدوين ممارسة قائمة**: الطبقة المنصّية صارت صنفين — (أ) هوية الطالب وما يتبعه، (ب) **بيانات مرجعية تعرّفها المنصة وتستهلكها كل مساحات العمل**؛ والصنف (ب) كان موجوداً في الكود بلا نصّ يغطّيه (`Tenancy\Models\PlatformSetting` يحمل تعليق «deliberately no BelongsToWorkspace»). حارس (ب) **صلاحية كتابة منصّية** لا ملكية صفّ، وهو ما تنفّذه T004 و T091
- [X] T002 ✅ **محسوم 2026-08-08**: `courses.price` و`currency` **يبقيان مُجمَّدَين ومحصورَين**، ولا تكتب 006 سطراً يقرؤهما. **مُتحقَّق**: `Payments\Actions\CreateOrder.php:20-21` يقرأ `$course->price`/`currency` لبناء الطلب، و`Courses\Models\Course.php:153` (`isFree()`) يقارنه بصفر ليقرّر التسجيل المجاني — فالحذف يكسر مسارَي شراءٍ منشورَين. القيود الثلاثة تُنفَّذ في T084 و T087 و T089أ

---

> **حدود القرار الثاني، مكتوبةً كي لا تُقرأ بعد ستة أشهر كسابقة**: `courses.price` **يعرّفه
> المدرّس** اليوم (`Courses/Http/Requests/CreateCourseRequest.php:24` · `Filament/Resources/CourseResource.php:47`)،
> وهو حرفياً ما تمنعه `FR-021ب`. تجميدُه يجعله **استثناءً موروثاً محصوراً في `orders.kind = course`**،
> لا قاعدةً. وتقاعده قرارٌ منتَجي بأثر على الإيراد — لا ينتمي إلى 006، ولا يُشتقّ من `cost-plus`
> أصلاً لأن الأخير يسعّر **حصة** لا كورساً كاملاً. محلّه 011 حيث تُحسم الباقات.
- [X] T003 [P] أضف الصلاحيات الثماني `BILLING_BALANCE_VIEW` · `BILLING_SETTINGS_MANAGE` · `BILLING_PURCHASE_APPROVE` · `BILLING_CREDITS_ADJUST` · `BILLING_LIMIT_MANAGE` · `BILLING_EXAM_MODE_MANAGE` · `BILLING_PACKAGES_MANAGE` · `BILLING_PRICING_MANAGE` إلى `backend/app/Modules/Tenancy/Support/Permissions.php` **وإلى `Permissions::all()`** — الغياب عن `all()` صلاحية لا تُبذَر فلا تُمنح لأحد
- [X] T004 أسند الأدوار في `backend/app/Modules/Tenancy/Listeners/SeedDefaultRoles.php`: المدرّس يأخذ `BILLING_BALANCE_VIEW` و`BILLING_EXAM_MODE_MANAGE`، و`BILLING_SETTINGS_MANAGE` **لمالك مساحة العمل** (نفس مصطلح [api.md §١](./contracts/api.md)) — وهذه الثلاث **وحدها**؛ و`BILLING_PURCHASE_APPROVE` و`BILLING_CREDITS_ADJUST` و`BILLING_LIMIT_MANAGE` و`BILLING_PACKAGES_MANAGE` و`BILLING_PRICING_MANAGE` **للمنصة لا لأي دور مستأجر** ([api.md §١](./contracts/api.md)) — منح `bonus` أو رفع الحدّ يخلق طلباً على المال بلا ساق دفع
- [X] T005 [P] أضف المحدّد المسمّى `billing` (بمفتاح **المستخدم**) في `AppServiceProvider::registerRateLimiters()` بـ`backend/app/Providers/AppServiceProvider.php` على شكل `contact-verification` و`settlement` القائمين — **يُمنع** استعمال `throttle:auth` هنا: حدّه الثاني `by('email:'.$request->input('email'))` وكتابةُ فوترةٍ بلا حقل `email` تجعل المفتاح النصّ الثابت `'email:'` أي **دلواً واحداً لكل المنصة**، فمهاجمٌ يدور على `POST /billing/consents` يمنع كل طالب من الشراء ([api.md §٢ج](./contracts/api.md))
- [X] T006 [P] أضف مفاتيح `billing.*` الأحد عشر إلى `PlatformSettings::KEYS` في `backend/app/Modules/Tenancy/Support/PlatformSettings.php` وصفوفها إلى `backend/database/seeders/PlatformSettingsSeeder.php` بالقيم المحسومة في [quickstart.md › القيم الافتراضية](./quickstart.md): `operating_fee_minor.individual` · `.group` · `gateway_fee_bps` (**نقاط أساس صحيحة لا نسبة مئوية صحيحة**) · `gateway_fixed_fee_minor` · `currency` · `limit.initial_credits`=1 · `limit.increase_after_on_time`=3 · `limit.increase_by_credits`=1 · `limit.max_credits`=4 · `limit.decrease_after_late_days`=14 · `dormant_notice_months`=12
- [X] T007 [P] أنشئ التعدادات في `backend/app/Modules/Payments/Enums/`: `CreditTransactionType` (`purchase` · `consume` · `bonus` · `refund` · `adjustment` · `expire`) · `BillingMode` (`prepaid_credits` · `manual_collection` · `payment_gateway` · `hybrid`) · `ZeroBalanceBehavior` (`block` · `remind` · `both`) · `OrderKind` (`course` · `credits`) — كلٌّ بدالة `label()` عربية
- [X] T008 [P] أضف مدخلات `attributes` العربية لكل حقل `FormRequest` جديد في هذه المرحلة إلى `backend/lang/ar/validation.php` — الحقل بلا مدخل يُعرَض للمستخدم باسمه البرمجي (`credit_limit_credits`)
- [ ] T009 [P] أنشئ المجلدات الناقصة تحت `backend/app/Modules/Payments/`: `Enums` · `Events` · `Jobs` · `Listeners` · `Support` · `Http/Resources/Manage` — المجلد الأخير هو **نطاق مسح `StudentBalanceAllowlist`** ولا يجوز أن يمسح الوحدة كلها ([api.md §٣ب](./contracts/api.md))

**Checkpoint**: القراران الحاجبان محسومان · الصلاحيات مفصولة بين المنصة والمدرّس · المحدّد مسمّى · الأرقام قابلة للضبط.

---

## Phase 2: Foundational (متطلبات حاجبة لكل القصص)

**Purpose**: تعديلات المخطّط على كود منشور · جداول المرحلة · النماذج والعقود · الحُرّاس — لا قصة تبدأ قبلها.

**⚠️ حاجبة**: كل مهام Phase 3+ تعتمد على اكتمال هذه المرحلة.

### التعديلات على كود منشور (مرتّبة، لا [P])

- [X] T010 أنشئ هجرة `add_kind_to_orders` في `backend/app/Modules/Payments/Database/Migrations/` تضيف `kind` `string(16)` بافتراض `course` — بدونها يستقبل `CreateEnrollmentFromOrder` طلبَ الأرصدة **فيُسجّل الطالب في الكورس مجاناً**، لأنه يفحص `course_id === null` وحده وطلب الأرصدة يحمل كورساً بطبيعته ([events.md §١هـ](./contracts/events.md))
- [X] T011 فرّع `backend/app/Modules/Payments/Listeners/CreateEnrollmentFromOrder.php:31` على `kind === OrderKind::Course`، **وأضف إليه `ShouldHandleEventsAfterCommit`** — `ApproveOrder.php:44` يُطلق `PaymentApproved` **داخل** `DB::transaction` المفتوحة عند ٢٥ والمغلقة عند ٤٩، فمستمعٌ مطبور بلا الواسم يُلتقط والمعاملة مفتوحة فيقرأ الطلب `pending` أو لا يجده ⇒ الطالب دفع ولم يحصل على شيء، **بلا إعادة محاولة لأن الوظيفة «نجحت»**. السابقة `Learning/Listeners/CompleteExamLessonOnSubmission.php:49`
- [X] T012 عدّل `backend/app/Modules/Payments/Policies/OrderPolicy.php::approve` لترفض طلباً بـ`kind = credits` إلا بحيازة `BILLING_PURCHASE_APPROVE` — `RolePermissionMatrix.php:69` يضع `PAYMENTS_APPROVE` داخل مصفوفة `$teacher`، فبدون هذا يعلّم المدرّس حوالةً لم تقع بأنها معتمَدة ⇒ أرصدة تُسكّ ⇒ الطالب يحجز ⇒ الحصة تُنفَّذ ⇒ **014 تدفع للمدرّس عن حصص لم يدخل مقابلها ريال**، وعزل السياقين يجعل كشف ذلك من جهة التسوية مستحيلاً بالتصميم ([research.md › R16](./research.md))
- [X] T013 عدّل `backend/app/Modules/Tenancy/Support/RolePermissionMatrix.php` بحيث لا يمنح دور المدرّس أياً من صلاحيات المنصة الخمس من T004 — **و`PAYMENTS_APPROVE` تبقى له** لطلبات الكورسات؛ الفصل يقع على `kind` لا على نزع صلاحية قائمة
- [X] T014 أنشئ هجرة `add_pricing_keys_to_courses` تضيف إلى `courses`: `subject_id` (FK) · `grade_level` · **`teacher_profile_id`** (FK) — بدون الأخير `approvedRateMinorForCourse` **غير قابلة للتنفيذ**: `RateResolver::resolve()` يبدأ من `teacher_profile_id` بينما `courses` تحمل `workspace_id` و`created_by` **القابل للإفراغ** فقط، وتعليق النموذج يقول «الكورس قد يعيش بعد مؤلّفه»
- [X] T015 عبّئ الأعمدة الثلاثة على الكورسات القائمة داخل الهجرة نفسها بـ`chunkById` (لا `chunk`) — المُسنَد يتقلّص تحت ترقيمٍ بـOFFSET فتُتخطّى صفوف **ويُبلَّغ نجاح** (درس backfill الـuuid في 016). و`teacher_profile_id` يُشتقّ من مالك مساحة العمل لا من `created_by`
- [X] T016 [P] أنشئ هجرة `add_is_high_value_to_lessons` تضيف `is_high_value` `boolean` بافتراض `false` — التصنيف يبقى في `Courses`: المدرّس يصنّف محتواه والقرار المالي وحده في `Payments` (`FR-041` · [research.md › R11](./research.md))
- [X] T017 أنشئ هجرة `add_billing_columns_to_class_sessions` تضيف `subject_id` · `grade_level` · **`charged_at`** timestamp nullable — الأخير يجعل «سُلِّمت ولم تُشحَن» مجموعةً **قابلة للاستعلام** ([research.md › R17](./research.md))
- [X] T018 أنشئ هجرة تعبئة `class_sessions.course_id` بـ`chunkById` من الكورس المرتبط بالحجز أو الجدولة — **ولا `UPDATE … JOIN`**: MySQL وSQLite تختلفان في صياغته. سجّل عدد ما تعذّرت تعبئته في مخرجات الهجرة
- [X] T019 اعتمد الخيار المُوصى به في [quickstart.md §١٦](./quickstart.md): يبقى `class_sessions.course_id` **قابلاً للإفراغ إلى الأبد**، ويُفرَض الوجود في `backend/app/Modules/LiveSessions/Actions/ScheduleClassSession.php` و`Http/Requests/StoreClassSessionRequest.php` — أرخص وأصدق من اختراع كورسات لحصص تاريخية. *(البديل: هجرة قيد ثالثة بتأكيدٍ يرمي قبل التغيير برسالة تحمل العدد لا `errno` من MySQL، و`->nullable(false)->change()` يعيد تصريح العمود فكل مُعدِّل لا يُكرَّر يسقط بصمت.)*
- [X] T020 عدّل `ScheduleClassSession.php` لينسخ `subject_id` و`grade_level` **من الكورس** إلى الحصة عند الجدولة — وإلا حلّ الشراء والتسوية بمُدخَلات مختلفة وانهار التساوي الذي بُنيت عليه `Q-7`
- [X] T021 صحّح `backend/app/Modules/Settlement/Actions/AccrueTeachingUnits.php:48-53` ليمرّر `grade_level` إلى `RateResolver::resolve()` — يمرّر أربعة وسائط اليوم فيبقى الخامس `null` والشرط `orWhere('grade_level', null)` لا يصدق أبداً ⇒ **كل سعر مخصَّص بصفّ غير مرئي عند التسوية اليوم** ([research.md › R4](./research.md))
- [X] T022 عدّل تعليق `backend/app/Shared/Contracts/EnrollmentDirectory.php` ومُنادِيه في `LiveSessions/Support/BookingEligibility.php`: `Q-7` **تنسخ `FR-045` من 005** — الحجز يصير مقيَّداً بالكورس لأن السعر خاصية الكورس وحصةٌ بلا كورس حصةٌ بلا سعر. التعارض يُحسَم هنا، لا يُترك لمن يكتب مسار الشحن فيقرّر بالعملة

### جداول المرحلة

- [X] T023 أنشئ هجرة `create_credit_tables` في `backend/app/Modules/Payments/Database/Migrations/` (حرف **M** كبير) بالجداول الستّة من [data-model.md §٢](./data-model.md): `student_credit_accounts` · `credit_balances` · `credit_transactions` · `credit_lots` · `credit_allocations` · `credit_purchases`. **الأرصدة `integer` مُوقَّعة** — و`unsigned` يعمل على SQLite وينفجر على أول رصيد سالب في MySQL وحدها (`NFR-011`)؛ **والمبالغ `bigInteger`** بالوحدة الصغرى، وهجرة 014 تقول لماذا: «٢٫١ مليار وحدة صغرى ليست إلا ٢١ مليون ريالاً، وهو سقف تبلغه منصة». و`credit_limit_credits` **مُوقَّع** بفحص `>= 0` في الـAction، لا `unsigned`
- [X] T024 أنشئ هجرة `create_credit_packages` — **بعد حسم T001**. الأعمدة: `id` · `uuid` · `name` · `credits` · `session_type` · `validity_days` (nullable) · `is_active` · `sort_order`. **لا عمود سعر**: السعر يُحتسب لكل كورس لأن مُدخَله سعر مدرّس ذلك الكورس، وعمود سعر هنا يعني سعراً واحداً لكل المدرّسين
- [X] T025 [P] أنشئ هجرة `create_terms_consents_and_exam_windows` بجدولَي `terms_consents` (**مملوك للمنصة، بلا `workspace_id`**) و`exam_mode_windows` (`BelongsToWorkspace`)
- [X] T026 أضف في الهجرات نفسها الفهارس المُعلَنة: فريد `(user_id)` على الحسابات · فريد `(student_credit_account_id, course_id)` و`(workspace_id, course_id, student_user_id)` و`(workspace_id, negative_since)` على الأرصدة · **فريد `(credit_balance_id, type, source_type, source_id)`** و`(credit_balance_id, created_at)` على المعاملات · `(credit_balance_id, expires_at, id)` على الدفعات · فريد `(consumed_transaction_id, lot_transaction_id)` على التخصيصات · `(workspace_id, purchased_at)` و`(credit_balance_id)` على المشتريات · `(workspace_id, starts_on, ends_on)` على نوافذ الامتحانات
- [X] T027 [P] أنشئ النماذج التسعة في `backend/app/Modules/Payments/Models/` بـ`HasUuid` و`casts()` صريحة و`@property` للأعمدة. **الطبقات**: `StudentCreditAccount` و`TermsConsent` **بلا `BelongsToWorkspace`** (إضافتها تنتج شخصاً مكرّراً لكل مدرّس — مرآة العطل الذي يختبره `PlatformOwnershipTest`)؛ وجداول الجسر الخمسة **بالسمة**، بتجاوز صريح واحد مسموح: قراءة الطالب لحسابه بـ`withoutWorkspaceScope()` **مع ترشيح صريح بـ`student_credit_account_id`** وتعليق يذكر السبب — السابقة `EloquentEnrollmentDirectory`
- [X] T028 [P] أنشئ المصانع في `backend/database/factories/Modules/Payments/` — **يُمنع** تعريف `newFactory()` على النماذج (`AppServiceProvider::guessFactoryName()` يحلّها مركزياً)
- [X] T029 [P] أنشئ الأحداث الثمانية في `backend/app/Modules/Payments/Events/`: `CreditsPurchased` · `CreditConsumed` · `BalanceUpdated` · `CreditExpired` · `BalanceThresholdCrossed` · `AccessWithheld` · `AccessRestored` · `RefundIssued` — بخصائص `readonly` مُرقّاة. و`BalanceUpdated` **إلزامي** لأن `SC-006` تشترط رصد سلسلةٍ **رباعية** وكانت الحلقة الرابعة غير موجودة في أي قائمة إطلاق

### العقود المشتركة

- [X] T030 أنشئ `backend/app/Shared/Contracts/ApprovedRateDirectory.php` بتوقيعة `approvedRateMinorForCourse(int $courseId, ClassSessionType $type, DateTimeInterface $moment): ?int` — تعيد **عدداً** لا نموذجاً فيستحيل تسرّب حقل من `SettlementRate` إلى حمولة. `ClassSessionType` أوّل استيرادٍ لوحدة داخل هذه الطبقة، فيُقبَل صراحةً بتعليق أو يُمرَّر نصّاً
- [X] T031 نفّذ العقد في `backend/app/Modules/Settlement/Support/EloquentApprovedRateDirectory.php` وسجّله في `SettlementServiceProvider` — يقرأ الكورس بـ`forWorkspace()` **بتعليق يذكر السبب**: `Course` يستعمل `BelongsToWorkspace` وطلب الطالب محلولٌ على مساحة عمل واحدة، فكورسٌ عند مدرّس آخر يعود `null` فتُقرأ «لا سعر معتمَد» فتظهر قائمة فارغة بلا خطأ. واحفظ الجواب لكل طلب — الحزم الستّ تعطي جوابين لا ستّة لأن السعر يتغيّر بنوع الحصة وحده
- [X] T032 [P] أنشئ `backend/app/Shared/Contracts/AccountStanding.php` بدالتَي `isWithheld(User $student, int $courseId): bool` و`withheldCourseIdsFor(User $student): array` — **بالكورس لا بمساحة العمل**: بالثانية تُغلَق على من عليه مستحقّ في الفيزياء مذكّراتُ الرياضيات التي سدّدها. والدالة الجماعية إلزامية، والعقدان الشقيقان (`EnrollmentDirectory::activeCourseIdsFor` · `SessionAttendanceDirectory::bookedLessonIdsFor`) يحملانها بالتعليق نفسه
- [X] T033 [P] أضف `childrenOf(User $guardian, GuardianPermission $permission): Collection<User>` إلى `backend/app/Shared/Contracts/GuardianDirectory.php` وتنفيذه — بدونها **لا مسار لوليّ الأمر أصلاً**: العقد يحمل `authorisedGuardians()` و`isAuthorised()` فقط، والبديل استعلام `ParentStudentRelation` من `Payments` وهو خرقٌ للمبدأ الثالث على جدولٍ منصّي بلا نطاق
- [X] T034 [P] أنشئ `backend/app/Modules/Payments/Support/BillingSettings.php` — المصدر **الوحيد** لقرار النمط والعتبات وسلوك الصفر (`FR-013`): النمط من `workspaces.settings.billing` والتسعير من `PlatformSettings`. `PlatformSettings` منصّي بحكم بنيته (مفتاح واحد لصفّ واحد بلا `workspace_id`) فوضع نمط مساحة العمل فيه ينقض `FR-011` نفسها
- [X] T035 [P] أنشئ سياسات `backend/app/Modules/Payments/Policies/` للنماذج الجديدة وسجّلها في `PaymentsServiceProvider`؛ أسماء الصلاحيات من ثوابت `Permissions` حصراً — **يُمنع** نصّ حرفي

### الحُرّاس (قبل أي منطق)

- [X] T036 أضف حالات `credit_balances` و`credit_transactions` و`credit_purchases` و`credit_lots` و`credit_allocations` و`exam_mode_windows` إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — `SC-016` تَعِد بحالة لـ«الحساب والمعاملة والحد»، ونموذج بلا `BelongsToWorkspace` يعمل في كل اختبار قائم ويسرّب بصمت في الإنتاج ⚠️ **انحراف مسجَّل**: `credit_allocations` بلا `workspace_id` بقرار الهجرة (جدول وصل بين صفَّين مَنطوقَين أصلاً) — فحالة «أنشئ في أ فلا يراها ب» غير قابلة للكتابة له. غُطّي بتأكيد **غياب** السمة، وهو اتجاه `PlatformOwnershipTest` نفسه.
- [X] T037 اكتب `backend/tests/Feature/Payments/PlatformOwnershipTest.php` للطبقة المنصّية (`student_credit_accounts` · `terms_consents`) **بالاتجاهين** (`NFR-001ب`): مدرّس لا يقرأ بيانات طالب لا يملك تسجيلاً نشطاً في كورس داخل مساحة عمله، **والطالب يرى كيانه الواحد عبر كل مدرّسيه**. الاتجاه الثاني هو مرآة العطل: إضافة `BelongsToWorkspace` حيث لا تنتمي تُكرّر شخصاً واحداً بعدد مدرّسيه
- [X] T038 أضف الحالة العكسية إلى `backend/tests/Feature/Settlement/ContextIsolationTest.php`: `Payments` **يجوز** أن تذكر `Shared\Contracts\ApprovedRateDirectory` و**يُمنع** أن تذكر `App\Modules\Settlement` — المسح اليوم `moduleFiles('Settlement')` وحده، أي **أحادي الاتجاه**، فالقراءة الجديدة غير محروسة. وأضف اسم جدول أرصدة إلى تأكيد سلامة `tablesCreatedBy('Payments')` وإلا مرّ المسح بألا يجد شيئاً
- [X] T039 اكتب `backend/tests/Feature/Payments/JobIsolationTest.php` يمسح `Modules/Payments/{Jobs,Listeners}/` بحثاً عن `WorkspaceContext::set(` — **`Listeners` وليس `Jobs` وحدها**: سكّ الأرصدة يقع في مستمع مطبور، وهو سياق عاملٍ بقدر الوظيفة تماماً. السابقة `TrustScoreJobIsolationTest`

**Checkpoint**: `php artisan migrate` يمرّ (**لا `migrate:fresh`** — تُسأل قاعدة أحدٍ قبل هدمها) · PHPStan نظيف · العزل بالطبقات الثلاث مُختبَر · ثغرة سكّ الأرصدة مسدودة قبل كتابة أول قيد.

---

## Phase 3: US1 — حساب أرصدة بسجلّ لا يُعدَّل (Priority: P1) 🎯 MVP

**Goal**: حساب واحد لكل طالب على مستوى المنصة، وأرصدةٌ بحسب الكورس، وسجلّ مضاف لا يُعدَّل —
والرصيد الظاهر يساوي مجموع قيوده دائماً، تحت التزامن وإعادة التسليم.

**Independent Test**: على مستوى الـAction بطلبٍ معتمَد مُهيّأ — `RecordCreditPurchase` ثم
`AdjustCredits` ثم خصم مباشر — ومقارنة `remaining_credits` بمجموع `credits`. بلا محرّك تسعير
وبلا حصص.

### الاختبارات أولاً

- [X] T040 [P] [US1] اكتب `backend/tests/Feature/Payments/LedgerInvariantTest.php` — ١٠٬٠٠٠ معاملة متسلسلة تتخللها استردادات وتسويات، ثم `remaining_credits` مقابل `SUM(credits)` بفارق **صفر** (`SC-001` · `FR-004`). وأضف تأكيداً على الثابتة الثانية التي لا تراها `SC-001`: `remaining = purchased − consumed`، ⚠️ **تصحيح**: الاسترداد **يُنقص** `remaining` و`purchased` معاً ولا يمسّ `consumed` أبداً — الصيغة الأولى («يزيد `remaining` ويُنقص `purchased`») اتجاهان متعاكسان لا تصدق معهما `remaining = purchased − consumed`. الطالب يعيد الأرصدة ويأخذ نقداً (007 تحوّل الحدث إلى مال) فالأرصدة تخرج، والاسترداد عكس الشراء
- [X] T041 [P] [US1] اكتب `backend/tests/Feature/Payments/ImmutableLedgerTest.php`: `CreditTransaction::first()->update([...])` و`->delete()` **كلاهما يرمي** (`SC-002`). الحارس على النموذج فالمسار لا يهمّ؛ ووثّق الثقب المعلوم: `update()` جماعي لا يُحمّل نماذج فيتجاوزه
- [X] T042 [P] [US1] اكتب `backend/tests/Feature/Payments/ConcurrentConsumeTest.php` — خصمان متوازيان على رصيدٍ يكفي أحدهما: واحد يمرّ وواحد يُرفض، والرصيد لا يهبط تحت الأرضية (`SC-003` · `SC-004`). **يُمنع** أن يُبنى الاختبار حول `lockForUpdate()`: لا أثر له على SQLite فينجح محلياً ولا يقول شيئاً عن MySQL. السابقة `SeatConcurrencyTest`
- [X] T043 [P] [US1] اكتب `backend/tests/Feature/Payments/IdempotencyTest.php`: نفس المصدر مرتين ⇒ قيد واحد (`FR-007`)؛ **وحالة `uuid` صراحةً** — أدرج قيدين متتاليين وتحقّق أن لكلٍّ `uuid` غير فارغ، فـ`insertOrIgnore` مناداةٌ على `Query Builder` فلا يقع `creating` ولا تعمل `bootHasUuid()`، وعلى MySQL يُخفَّض الانتهاك إلى تحذير ويُكتب `''` **فكل قيد تالٍ في المنتج كلّه يصطدم بذلك الصفّ على `unique(uuid)` فيُقرأ «قُيِّد سابقاً» فيُتخطّى**
- [X] T044 [P] [US1] أضف إلى الملف نفسه حالة القيد اليدوي: ضغطتان على «امنح مكافأة» ⇒ **منحة واحدة** — القيم الفارغة في `source_id` متمايزة في الفهرس الفريد على MySQL وSQLite معاً، فالحارس هو مفتاح التعامُد الذي تسكّه الـAction لا القاعدة
- [X] T045 [P] [US1] اكتب `backend/tests/Feature/Payments/LotAllocationTest.php` ([quickstart §١٤](./quickstart.md)): حزمتان (٨ و١٦) واستهلاك ١٢ ⇒ `SUM(credit_lots.credits_remaining)` يساوي `remaining_credits` ومجموع التخصيصات لكل دفعة **لا يتجاوز** حجمها؛ ثم استهلاكان متوازيان على دفعة برصيد واحد متبقٍّ ⇒ واحد يمرّ وواحد ينتقل إلى التالية و**لا تُفرَط دفعة**
- [X] T046 [P] [US1] اكتب `backend/tests/Feature/Payments/AccountContextTest.php`: طالب في ثلاثة كورسات عند ثلاثة مدرّسين ⇒ **صفّ واحد** في `student_credit_accounts` وثلاثة أرصدة؛ ثم كورس **ثانٍ عند المدرّس نفسه** ⇒ رصيد رابع مستقلّ، و**يُمنع** استهلاك رصيد الكورس الأول في حصص الثاني (`SC-018` · `SC-019`)
- [X] T047 [P] [US1] اكتب `backend/tests/Unit/Payments/BalanceFloorTest.php` على المُسنَد الواحد — بما فيه الحالة الافتراضية `remaining = 0, limit = 0` ⇒ **محجوب**، وهي بالضبط الحالة التي كانت الصيغة القديمة تقول فيها «غير محجوب». ووثّق أن فخّ `1690 BIGINT UNSIGNED out of range` لا يظهر على SQLite: يُشغَّل على MySQL إن توفّر، وإلا فالحارس هو `CAST(... AS SIGNED)` المكتوب في T049

### التنفيذ

- [X] T048 [US1] أنشئ `backend/app/Modules/Payments/Support/CreditLedger.php` بالمُسنَد الواحد المُعرَّف مرة ([data-model.md §٥أ](./data-model.md)): `floor(balance) = mode == prepaid || insideExamWindow ? 0 : −credit_limit_credits` · `canAfford(balance, n) ⟺ remaining_credits − n ≥ floor` · `blocked ⟺ ¬canAfford(balance, 1)` — **نسخة ثانية من المُسنَد نسخة تتباعد**
- [X] T049 [US1] نفّذ الخصم الذرّي في `CreditLedger` بعبارة `UPDATE credit_balances SET remaining_credits = remaining_credits - :n, consumed_credits = consumed_credits + :n WHERE id = :id AND remaining_credits - :n >= GREATEST(:floorCap, -1 * CAST(credit_limit_credits AS SIGNED))` — **العمود في `WHERE` لا قيمةً مربوطة** وإلا فتغييرٌ متزامن للحدّ لا يراه الخصم وهي القراءة-ثم-الكتابة نفسها التي بُنيت العبارة لإلغائها؛ **و`CAST(... AS SIGNED)` إلزامي والترتيب الجبري ممنوع** لأن معاملاً واحداً بلا إشارة يجعل الناتج بلا إشارة فتصير `remaining + limit >= :n` خطأً `1690` عند `remaining = −3`؛ **و`lockForUpdate()` ممنوع**
- [X] T050 [US1] رتّب العمليات داخل `CreditLedger` بالترتيب المُلزِم ([data-model.md §٥ب](./data-model.md)): (١) `insertOrIgnore` للقيد **بـ`uuid` و`created_at` صراحةً في المصفوفة**؛ (٢) صفر صفوف ⇒ `SELECT` بالمفتاح: وُجد ⇒ تكرار فلا عمل، **لم يوجد ⇒ يُرمى** لأن الصفر كان خطأً (`NOT NULL`، مفتاح أجنبي، مدىً) لا مُعادَلة؛ (٣) سحب الدفعات ثم الخصم. **الترتيب هو القاعدة**: الخصم أولاً يعني أن إعادة تسليمٍ تخصم مرتين ويُتجاهَل الإدراج مرة ⇒ الرصيد لا يساوي مجموع قيوده **نهائياً وبصمت**
- [X] T051 [US1] نفّذ **معاملةً لكل مقعد** لا معاملة واحدة للحصة — معاملة لثلاثين مقعداً تجعل رفض طالبٍ واحد يُسقط التسعة والعشرين وتحمل ثلاثين قفلاً عبر المستمع كلّه. وأي عبارة تمسّ أرصدةً متعدّدة **تُرتَّب بـ`credit_balances.id`**: حصّتان متزامنتان تتقاسمان طالبين تُقفلان بترتيبين متعاكسين فتتجمّدان ⚠️ **نُفِّذ نصفه**: المعاملة لكل حركة قائمة في `CreditLedger::post()`، أما شرط ترتيب العبارات التي تمسّ أرصدةً متعدّدة بـ`credit_balances.id` فلا موضع نداءٍ له بعد — يلزم مع `ChargeSessionSeats` في `US5`، وهناك يُختبَر.
- [X] T052 [US1] أضف `CreditTransaction::booted()` ترمي على `updating` و`deleting` في `backend/app/Modules/Payments/Models/CreditTransaction.php` — نسخة `Settlement\Models\LedgerEntry`، والحارس على **النموذج** لا في الـAction وحده
- [X] T053 [US1] نفّذ سحب الدفعات في `CreditLedger` بـ`UPDATE credit_lots SET credits_remaining = credits_remaining - :take WHERE id = :lot AND credits_remaining >= :take` بترتيب `(expires_at IS NULL), expires_at, id`؛ صفر صفوف ⇒ استُنفدت ⇒ التالية. وأعلن **سقفاً لعدد الدفعات في السحب الواحد** لأن الحلقة استعلامٌ لكل دفعة، مقابل ميزانية `NFR-012`
- [X] T054 [US1] اكتب `credit_allocations` بعد كل سحب — سجلّ ما **ادّعاه** السحب لا مصدر الحقيقة. الجدول موجود لأن «أي دفعةٍ دفعت أي استهلاك» **لا يمكن اشتقاقه لاحقاً**: ترتيب «الأقرب انتهاءً أولاً» يعيد كتابة الجواب بأثر رجعي كلما دخلت دفعةٌ أقرب انتهاءً
- [X] T055 [US1] أنشئ إنشاء الحساب **الكسول** في `backend/app/Modules/Payments/Support/CreditAccounts.php`: القراءة تعيد صفراً لطالبٍ بلا حساب (`US1/1`)، والإنشاء المتزامن يُمتَصّ بالتقاط `UniqueConstraintViolationException` ثم إعادة القراءة — **صراحةً على شكل `BookSeat.php:76-83`**، لا اتّكالاً على سلوك نسخةٍ من الإطار
- [X] T056 [US1] أنشئ `backend/app/Modules/Payments/Actions/RecordCreditPurchase.php` — الموضع **الوحيد** الذي يضيف رصيداً بمقابل. يُسنِد `workspace_id` على الرصيد **صراحةً من الكورس**: المستمع المطبور بلا سياق مساحة عمل فـ`WorkspaceContext::id()` فارغ و`WorkspaceScope` عديم الأثر، والملء التلقائي يكتب أول قيد في الإنتاج بمفتاح مستأجر فارغ
- [X] T057 [US1] أنشئ `backend/app/Modules/Payments/Actions/AdjustCredits.php` لأنواع `bonus` · `adjustment` · `refund` — **بسبب إلزامي** يُفرَض داخل الـAction لا في التحقّق، وبمفتاح تعامُد من العميل يسكّ `source_id`. **والاسترداد يُفتَح بهويّته هو** (`source_type='credit_refund'`) لا بهوية الشراء الذي يردّه، وإلا مُنع الاسترداد الجزئي الثاني وأُبلغ نجاحاً
- [X] T058 [US1] أنشئ `backend/app/Modules/Payments/Listeners/CreditPurchaseOnApproval.php` وسجّله على `PaymentApproved` بـ`Event::listen()` في `PaymentsServiceProvider::boot()` — بـ`ShouldQueue, ShouldHandleEventsAfterCommit`، ويتجاهل الطلبات بـ`kind = course`
- [X] T059 [US1] أطلق `CreditsPurchased` و`CreditConsumed` و`BalanceUpdated` **بعد الالتزام** لا داخل المعاملة — إشعارٌ يخبر الطالب بحركةٍ ارتدّت أسوأ من صمت ⚠️ **نُفِّذ نصفه**: `CreditsPurchased` و`BalanceUpdated` يُطلَقان بـ`DB::afterCommit` من `RecordCreditPurchase` و`AdjustCredits`؛ أما `CreditConsumed` فلا موضع نداءٍ له بعد — يُطلَق مع `ChargeSessionSeats` في `US5`، وهناك يُختبَر.
- [X] T060 [US1] أنشئ `GET /api/v1/billing/balance` بمتحكّم ومورد في `Payments/Http/` — حسابه **الواحد** مقسّماً بالكورس (`FR-009ج`): العنوان واسم المدرّس والمشترى والمستهلَك والمتبقّي والحد والحجب، **بالأرصدة بلا مال** (`FR-021د`). القراءة عبر التجاوز الصريح الوحيد المسموح من T027
- [X] T061 [US1] أنشئ `GET /api/v1/billing/transactions?course={uuid}` مرقّماً، مرشَّحاً **بحسابه هو** لا بالمعرّف القادم في الطلب
- [X] T062 [P] [US1] أنشئ `frontend/src/lib/billing.ts` بأنواع الرصيد والمعاملات ودوالّ القراءة — **بلا أي مبلغ مُصاغ من الخادم**: الـAPI لا يرسل نصّاً مالياً
- [X] T063 [P] [US1] أنشئ `frontend/src/components/billing/BalanceSummary.tsx` و`TransactionList.tsx` بمكوّنات `components/ui/` وحدها — بلا `className` حرّ، وبألوان `@theme` فقط، وخصائص منطقية (`ms-*` · `text-start`)
- [X] T064 [US1] أنشئ `frontend/src/app/(app)/(shell)/billing/page.tsx` **وأضف رابطها إلى قائمة تنقّل اللوحة** في `frontend/src/app/(app)/(shell)/layout.tsx` — صفحة بلا رابط وارد صفحة غير مُسلَّمة. الأخطاء عبر `userMessage()`/`fieldErrors()`، **يُمنع** عرض خطأ خام

**Checkpoint**: US1 قابلة للتسليم وحدها — حساب دقيق قابل للتدقيق تحت التزامن، وكل ما بعدها سياسات فوقه.

---

## Phase 4: US2 — أنماط الفوترة قابلة للتبديل من الإعدادات (Priority: P2)

**Goal**: مسؤول مساحة العمل يبدّل النمط فيتغيّر سلوك الحجز والشراء **بلا نشر كود** وبصفر إعادة
حساب لمعاملة قائمة.

**Independent Test**: بدّل النمط على مساحة عمل وتحقّق من تغيّر قرار الحجز، ثم قارن قيود ما قبل
التبديل بما بعده — بفارق صفر.

### الاختبارات أولاً

- [X] T065 [P] [US2] اكتب `backend/tests/Feature/Payments/BillingModeTest.php`: في `prepaid_credits` الحجز برصيد صفر **مرفوض**، وفي `hybrid` مقبول حتى الحد؛ وصفر إعادة حساب لمعاملة قائمة وصفر إبطال لالتزام سارٍ (`SC-005` · `FR-012` · `FR-014`)
- [X] T066 [P] [US2] أضف حالة `FR-015`: حفظ `payment_gateway` قبل شحن 007 **يُرفض** — نمطٌ غير مهيّأ بالكامل لا يُحفَظ
- [X] T067 [P] [US2] اكتب `backend/tests/Feature/Payments/SingleSourceOfModeTest.php` — مسح نصّي يُفشِل أي ملف خارج `BillingSettings` يذكر `prepaid_credits` أو أخواتها نصّاً، على شكل `ProviderAgnosticTest` القائم (`FR-013`) ⚠️ **قيمة الدورة تحمل بادئة `per_`** لأن `'session'` كلمة شائعة في المنتج: بالصيغة المجرّدة أطلق المسح على ثلاثة ملفات لا صلة لها بالفوترة. الخيار كان إضعاف الحارس أو جعل القيمة لا لبس فيها — والقيمة أرخص. والمسح يقرأ **الكود** بعد تجريد التعليقات، فشرحُ القاعدة لا يُفشل البناء.

### التنفيذ

- [X] T068 [US2] أكمل `BillingSettings` بقراءة وكتابة `workspaces.settings.billing` (`mode` · `zero_behavior` · `thresholds`) — العمود `workspaces.settings` موجود منذ أول هجرة ولا يقرؤه ولا يكتبه شيء، وهذا **أول مستهلك له** ⚠️ **وُسِّع بـ`Q-10`**: أُضيف حقل `cadence` محوراً ثانياً مستقلاً عن النمط (`per_session` · `per_half_month` · `per_month`)، ومعه `defaultLimitCredits()` و`save()` الدامجة — `workspaces.settings` عمودٌ ستكتب فيه مراحل لاحقة، فإسنادُ مصفوفةٍ جديدة يُسقط ما بجانب `billing` عند أول تبديل نمط. وأسماء الحقول الفعلية `zero_balance_behavior` و`alert_thresholds` — وكانت `lang/ar` تحمل `zero_behavior` و`thresholds`، وهما لا يطابقان حقلاً فتُعرَض الرسالة بالاسم البرمجي؛ صُحّحت.
- [X] T069 [US2] أضف مُسنَد الجاهزية لكل نمط في `BillingSettings` — `payment_gateway` غير جاهز حتى تشحن 007، ويُفرَض في الـAction لا في `FormRequest` وحده
- [X] T070 [US2] أنشئ `backend/app/Modules/Payments/Actions/UpdateBillingSettings.php` وDTO يرث `DataTransferObject` وFormRequest — والتبديل **يسري على ما بعده** ولا يمسّ قيداً قائماً
- [X] T071 [US2] أنشئ `PATCH /api/v1/manage/billing/settings` بصلاحية `BILLING_SETTINGS_MANAGE` وبـ`throttle:billing` — بدون هذا المسار كانت `FR-011` و`US2` و`SC-005` بلا تنفيذ أصلاً
- [X] T072 [US2] اربط `CreditLedger::floor()` بـ`BillingSettings` — نقطة قرار واحدة، و`prepaid_credits` تُجبر الأرضية إلى صفر بصرف النظر عن `credit_limit_credits` (`FR-014`)
- [X] T073 [P] [US2] أنشئ شاشة إعدادات الفوترة في `frontend/src/app/(app)/(shell)/manage/billing/settings/page.tsx` — النمط والعتبات وسلوك الصفر، والنمط غير الجاهز مُعطَّل بسببه المكتوب لا بصمت ⚠️ **الرفض نصٌّ لا `bool`**: `refusalToAdopt()` تعيد **سبباً**، لأن `false` في الـAction يصير «تعذّر الحفظ» على شاشة لا يرى صاحبها خطأً فيما كتب.

> ⚠️ **ثلاثة تصحيحات وقعت أثناء `US3` تستحق التدوين**:
>
> 1. **سقف `FR-021ي` كان يحدّ النيّة لا التراكم**. أول نسخة قاست `remaining_credits` وحدها،
>    ولا شيء يُسكّ قبل الاعتماد (`FR-018`) — فطالبٌ عند صفرٍ وسقفٍ ٦ يرسل شراءَين بـ٦ متتاليَين،
>    فيمرّان معاً (كلٌّ يقيس رصيداً لم يتغيّر) ويُسكّان ١٢ حين يُعتمَد الدفعان بعد أيام. والسكّ
>    نفسه لا يفحص السقف عمداً: الرفض هناك يأخذ المال ويمنع الأرصدة. فصار العدّ يشمل
>    **الطلبات المعلّقة**.
> 2. **`T079` كشف أن قائمة السماح لم تكن تصف الحمولات أصلاً**: خمس أشكال متداخلة — إحصاءات
>    المدرّس، مكوّنات درجة الثقة، ملخّص التقييمات، شهادات الصفحة الرئيسة، الأسئلة الشائعة —
>    كانت تُنشر بلا ثابتٍ يذكرها، فإضافة حقلٍ إلى أيٍّ منها لم تكن تحتاج قراراً من أحد. أُضيفت
>    الثوابت الخمسة، وصار الفحص **قائمة سماح** لا قائمة منع.
> 3. **صياغة `T097` الأصلية غير قابلة للتنفيذ**: حقلٌ في حمولة اعتماد السعر يُحتسب من
>    `credit_purchases` هو حمولة تسوية تقرأ جداول فوترة، وهو ما يُفشل `ContextIsolationTest`.
>    صار مساراً مستقلاً في الفوترة يناديه المعتمِد.

**Checkpoint**: النمط سياسة قابلة للاستبدال خلف تجريد واحد؛ إضافة نمط لا تعدّل منطق الاستهلاك (`NFR-002`).

> ⚠️ **تعديل لاحق على T068/T071/T073 — رُفِع منع «مقدَّم + دورة غير الحصة» (قرار `Q-11`، 2026-08-09)**.
> صاحب المنتج نصّ على أن الدفع المقدَّم لشهر أو نصف شهر **صورةٌ مطلوبة**. والمنع كان يقرأ
> «الدورة تحدّد كم يُشترى» وليست تحدّده — تلك الحزمة (`FR-016`). فحُذف فرع الدورة من
> `refusalToAdopt()` **ومعه الوسيط نفسه**: وسيطٌ لا يقرؤه فرع يقرأ كفحصٍ ما زال قائماً.
> وحُذفت معه **تسوية الدورة** في `UpdateBillingSettings`: كانت تنقذ من ردٍّ لم يعد يقع، وصارت
> بعد الرفع **تُتلف حالةً مشروعة** — تعيد دورة شهرية إلى الحصة كلما حُفظ أي حقل آخر.
> والمحرَّم الذي كان المنع يحميه بالخطأ صار له اختباره: **الدورة لا تشتقّ عدد أرصدة ولا سقفاً
> ولا سعراً** — `cadenceAllowsCredits()` صفرٌ والأرضية صفرٌ تحت نمطٍ مقدَّم مهما كانت الدورة.

---

## Phase 5: US3 — حزم الأرصدة والتسعير (Priority: P3)

**Goal**: المنصة تعرّف الحزم ويُحتسب سعرها بـ`cost-plus` لكل كورس؛ الطالب يرى **إجمالياً واحداً**؛
والمدرّس لا يعرّف سعر بيع ولا يراه — **ولا يظهر سعره في أي سطح عام**.

**Independent Test**: عرّف ثلاث حزم واشترِ واحدة والتحقّق من الرصيد المضاف ومن اللقطة الرباعية؛
ثم امسح كل حمولة عامة بحثاً عن `hourly_rate`.

### الاختبارات أولاً

- [X] T074 [P] [US3] اكتب `backend/tests/Unit/Payments/CostPlusPricingTest.php` — المعادلة في موضع واحد، و**اتجاه رسوم البوابة مكتوب صراحةً**: إن كانت البوابة تقتطع نسبةً من المبلغ المحصَّل فالصيغة `المجموع ÷ (1 − نسبة)` لا `المجموع + نسبة×المجموع`، والثانية تُقصّر عن التغطية في **كل** عملية والفرق يقع على هامش المنصة بصمت. و`null` من العقد ⇒ **الحزم لا تُعرَض**، لا سعر افتراضي
- [X] T075 [P] [US3] اكتب `backend/tests/Feature/Payments/PriceSnapshotTest.php`: ١٠٠٪ من المشتريات تحمل المكوّنات الأربعة (`SC-015ب`)، واعتماد سعر مدرّس جديد **لا يغيّر** سعر شراء تمّ ولا يمسّ رصيداً قائماً (`SC-015ج` · `FR-021ز`)
- [X] T076 [P] [US3] اكتب `backend/tests/Feature/Payments/PackagePricingAccessTest.php`: غريبٌ عن الكورس يطلب `GET /billing/packages?course=` ⇒ **403 لا قائمة مسعَّرة** — `total = (rate + operating_fee + gateway) × credits` ومكوّناه الآخران ثابتان منصّيان، فمن يعرف سعره هو يحلّ المجهولين من زوجين من أرقامه ثم **يقلب `total` أي كورسٍ آخر إلى سعر مدرّسه بالضبط**، وأحجام الحزم المتعدّدة تجعل الجملة زائدة التحديد فلا يُخفي التقريبُ شيئاً ([api.md §٢ب](./contracts/api.md))
- [X] T077 [P] [US3] اكتب `backend/tests/Feature/Payments/CreditMintingTest.php` ([quickstart §١٥](./quickstart.md)): مدرّس يحمل `PAYMENTS_APPROVE` يعتمد طلباً بـ`kind = credits` ⇒ **403 وصفر قيد**؛ ثم يحاول منح `bonus` ورفع الحد ⇒ مرفوضان
- [X] T078 [P] [US3] اكتب حالة حمولة الحزم: صفر تفصيل لمكوّنات السعر في أي حمولة تصل طالباً أو وليّ أمر (`SC-015أ` · `FR-021ج`)

### إخراج سعر المدرّس من الأسطح العامة — **بهذا الترتيب**

- [X] T079 [US3] **أولاً**: حوّل `backend/tests/Feature/Marketplace/PublicExposureTest.php` إلى **قائمة سماح فعلية** تقابل الحمولة بثوابت `TEACHER_CARD`/`TEACHER_DETAIL`/… السبعة — هو اليوم قائمة **منع** (`expect(FORBIDDEN)->not->toContain($key)`) وثوابت السماح **غير مرجوعة من أي كود أو اختبار**، فحذف `hourly_rate` من المسموح لا يغيّر تأكيداً واحداً وتنفق المرحلة يوماً على تغييرٍ بلا حارس
- [X] T080 [US3] أضف `hourly_rate` إلى `PublicFieldAllowlist::FORBIDDEN` واحذفه من المسموح في `backend/app/Modules/Marketplace/Support/PublicFieldAllowlist.php:53`
- [X] T081 [US3] احذف الحقل من `backend/app/Modules/Marketplace/Http/Resources/PublicTeacherCardResource.php:35` ومن حمولة تفاصيل المدرّس
- [X] T082 [US3] احذف الفلترين وترتيب `SORT_PRICE` من `backend/app/Modules/Marketplace/Actions/Public/ListPublicTeachers.php:53,54,96`، و`price_min`/`price_max` من `Marketplace/DTOs/TeacherFilterDTO.php` و`toFilterMap():68,69` (**`DTOs/` لا `Data/`**) — وهي تردّهما اليوم إلى `meta.filters`
- [X] T083 [US3] اجعل `backend/app/Modules/Marketplace/Http/Requests/ListPublicTeachersRequest.php:32,33,39-43` **يردّ `price_min`/`price_max`/`sort=price_asc` بـ422** لا يتجاهلها بصمت — لا يزال يقبل الوسائط اليوم
- [X] T084 [US3] عالج التوأم الخلفي لـ`CourseFilters.tsx`: `Marketplace/DTOs/CourseFilterDTO.php:19,63-64` · `Actions/Public/ListPublicCourses.php:72,73,80` · `Http/Requests/ListPublicCoursesRequest.php:37` — **وهذا يشمل فلتر وترتيب `courses.price` أيضاً** (قرار T002: يخرج من أسطح التصفّح ويبقى على سطح الشراء)
- [X] T085 [US3] فرّغ **ثلاث ذاكرات مخبّأة لحمولات مُصاغة** عند النشر — `Marketplace/Actions/Public/GetMarketplaceHome.php:34-43` و`Http/Controllers/PublicMarketplaceController.php:56-99` — وإلا بقي السعر معروضاً بعد حذفه من الكود. واحذف الفهرس `['is_publicly_listed','hourly_rate']` الذي فقد مبرّره
- [X] T086 [US3] احذف الحقل من الواجهة: `frontend/src/lib/public-api.ts:34` · `components/marketplace/TeacherCard.tsx:88` · `TeacherFilters.tsx:17` · `CourseFilters.tsx:21` · `app/(public)/teachers/[uuid]/page.tsx:171,301`
- [X] T087 [US3] أعد بناء `frontend/src/app/(public)/pricing/page.tsx:43,45` على **أسعار الحزم** — صفحة كاملة مبنية اليوم على «أرخص مدرّس»، ولا تُترك تعرض رقماً لم يعد يخرج من الـAPI
- [X] T088 [US3] أصلح الاختبارين اللذين **يؤكّدان التسريب اليوم وسيفشلان**: `backend/tests/Feature/Marketplace/PublicTeacherListTest.php:90` و`frontend/e2e/discovery.spec.ts:68,74`
- [X] T089 [US3] وثّق ما **يبقى** عمداً: `hourly_rate` عموداً وحقلاً في معالج تسجيل المدرّس ومدخلاً لطلب تغيير السعر في 014 — الممنوع عرضه لا تخزينه
- [X] T089أ [US3] نفّذ قرار T002 على `courses.price`: احذفه من **بطاقة القائمة** (`Marketplace/Http/Resources/PublicCourseCardResource.php:36-40,99-100` و`Marketplace/Support/PublicFieldAllowlist.php:85`) وأبقِه على **سطح الشراء** — `FR-021هـ` تقول «يظهر عند اختيار الوحدة القابلة للشراء فقط»، والبطاقة في قائمة سطحُ تصفّح بينما صفحة الكورس هي الوحدة. **ولا يُضاف إلى `FORBIDDEN`** خلافاً لـ`hourly_rate`: مبلغٌ مقطوع لا تربطه بسعر التسوية معادلة، فلا يصلح عرّافاً على ما يتقاضاه مدرّس آخر ([api.md §٢ب](./contracts/api.md))

### محرّك التسعير والشراء

- [X] T090 [US3] أنشئ `backend/app/Modules/Payments/Support/CostPlusPricing.php` — المعادلة في موضع واحد: `approvedRateMinorForCourse(الكورس, النوع, الآن) + billing.operating_fee_minor[النوع] + gateway(المجموع)`. **رسم التشغيل مبلغ ثابت لكل نوع حصة لا نسبة** (`FR-021أ`): استضافة حصة جماعية تكلّف مرة واحدة لا بعدد طلابها
- [X] T091 [US3] أنشئ إدارة `credit_packages`: `GET · POST · PATCH /api/v1/admin/billing/packages` بصلاحية `BILLING_PACKAGES_MANAGE` — الأحجام **قيم قابلة للضبط** (`FR-017`)، وتعطيل حزمة لا يمسّ أرصدة اشتُريت منها (`FR-019`)
- [X] T092 [US3] أنشئ `GET · PUT /api/v1/admin/billing/pricing` بصلاحية `BILLING_PRICING_MANAGE` لرسم التشغيل ونسبة البوابة
- [X] T093 [US3] أنشئ `GET /api/v1/billing/packages?course={uuid}` — يثبت **طرفية الطالب في الكورس** قبل التسعير (تسجيل نشط أو عضوية في مساحة العمل)، ويعيد `total` واحداً بلا أي مكوّن؛ وكورسٌ بلا سعر معتمَد ⇒ **قائمة فارغة**
- [X] T093أ [US3] *(مضافة بـ`Q-11` · `FR-021ط`)* أوقف البيع تلقائياً لكورسٍ توقّف تسليمه: عمود `courses.last_delivered_at` **تملكه الفوترة** يختمه مستمعٌ على `SessionDelivered` — الإشارة الوحيدة المسموحة، فقراءة جداول التسوية تُفشل `ContextIsolationTest`. والقاعدة `last_delivered_at ?? created_at` أقدم من المدة المعلنة ⇒ **قائمة حزم فارغة**: كورسٌ لم يُسلَّم فيه شيء قط ليس متوقّفاً بل جديداً، وحجبه يحجب كل كورسٍ يُنشأ
- [X] T093ب [US3] *(مضافة بـ`Q-11` · `FR-021ي`)* سقفٌ لما هو **محتجَز غير مصروف** لكل طالب في كل كورس، من `platform_settings` — شراءٌ يرفع `remaining_credits` فوقه **يُردّ** برسالة تحمل السقف والمتبقّي. استرجاع المال من مدرّس **مفاوضةٌ بشرية** (`Q-11`)، فالحدّ يقع على ما يتراكم لا على ما يُسترجَع
- [X] T094 [US3] أنشئ `POST /api/v1/billing/purchases` ينشئ صفّ `credit_purchases` وطلباً بـ`kind = credits` — **لا رصيد حتى الاعتماد** (`FR-018`)، وبـ`throttle:billing`
- [X] T095 [US3] اكتب لقطة السعر الرباعية على `credit_purchases` وقت الشراء (`teacher_rate_minor` · `operating_fee_minor` · `gateway_fee_minor` · `total_minor` + العملة) — تُكتب **مرة** ولا يُعاد حسابها عند أي اعتماد سعر لاحق. دفاتر 015 تُولَّد منها بأثر رجعي، وما لم يُلتقط لا يُسترجَع (`Q-2`)
- [X] T096 [P] [US3] أنشئ شاشة شراء الأرصدة في `frontend/src/app/(app)/(shell)/billing/purchase/page.tsx` — إجمالي واحد، ورفع الإيصال بالمسار اليدوي القائم
- [~] T097 [P] [US3] ⚠️ **نصفها الخلفي مُنجَز، وواجهتها لا مكان لها بعد**: شاشة *اعتماد* السعر غير موجودة في الواجهة أصلاً — `manage/settlement/page.tsx` شاشة المدرّس التي *يطلب* منها، والاعتماد مسار إداري بلا واجهة. والأهم أن الصياغة الأصلية غير قابلة للتنفيذ كما كُتبت: حقلٌ في حمولة اعتماد السعر يُحتسب من `credit_purchases` **حمولة تسوية تُقرأ من جداول الفوترة**، وهو بالضبط ما يُفشل `ContextIsolationTest`. فالعدّاد صار **مسار فوترة مستقلاً** يناديه المعتمِد: `GET /admin/billing/outstanding?workspace=` بصلاحية `SETTLEMENT_RATE_APPROVE` — سياقان، طلبان، بلا مفتاح بينهما. يبقى ربطه بالشاشة حين تُبنى. النصّ الأصلي: أضف إلى شاشة اعتماد السعر في `frontend/src/app/(app)/(shell)/manage/settlement/` عدّاد **«كم رصيداً قائماً بيع دون السعر الجديد»** — مقروءاً من `credit_purchases`. فرق السعر بين الشراء والتنفيذ **بنيوياً غير قابل للإزالة**، ومخرجه أن يصير خسارةً تُقرَّر لا خسارةً تُكتشَف عند الإقفال

**Checkpoint**: السعر تملكه المنصة في موضع واحد، والمدرّس لا يظهر سعره في أي سطح عام — ومُثبَت بقائمة سماح تفشل عند أول تسريب.

---

## Phase 6: US4 — الاستهلاك عند تحقّق تنفيذ الحصة (Priority: P4)

**Goal**: `SessionDelivered` ⇐ قيد `consume` عن **كل مقعد مُجمَّد**، أياً كانت حالة الحضور — وحصةٌ
سُلِّمت ولم تُشحَن مجموعةٌ **قابلة للاستعلام** لا خسارة صامتة.

**Independent Test**: أغلِق حصةً نفّذها المدرّس وارصد السلسلة الرباعية، ثم أعد التسليم وتحقّق من
قيد واحد.

### الاختبارات أولاً

- [ ] T098 [P] [US4] اكتب `backend/tests/Feature/Payments/ConsumptionChainTest.php` — السلسلة `SessionDelivered → ChargeSessionSeats → CreditConsumed → BalanceUpdated` بترتيبها برصد الأحداث (`SC-006`). **و`Queue::fake()` جزئي إلزامي**: `Queue::fake([CloseClassSessionJob::class, SendSessionReportsJob::class])` — بلا وسائط يسافر المستمعُ المطبور على الطابور نفسه فيصير التأكيد على **صفر صفوف**؛ وبلا `fake` أصلاً يعمل `->delay()` فوراً على اتصال `sync` فيغلق `CloseClassSessionJob` الحصة قبل أن يدخل المدرّس
- [ ] T099 [P] [US4] اكتب `backend/tests/Feature/Payments/SeatNotAttendanceTest.php`: أربع حالات حضور على حصة **نُفِّذت** (`Present` · `Late` · `Absent` · `Excused`) ⇒ **أربعة قيود** (`SC-008` · `FR-025د`)؛ ثم حصة لم ينفّذها المدرّس ⇒ صفر قيد أياً كانت الحالات. و`Excused` تربوية لا إعفاء مالي (`FR-025ب`)
- [ ] T100 [P] [US4] اكتب `backend/tests/Feature/Payments/NoChargeTest.php` على ملغاة ومتعذّرة وواقعة في تجميد ⇒ صفر قيد في الثلاث، وتحقّق بـ`Event::fake()` أن **`SessionDelivered` لم يُطلَق أصلاً** — كي يثبت الاختبار أن `FR-024` و`FR-025أ` و`FR-025ج` شروط إطلاق الحدث **في 005** لا فحوصاً هنا (`SC-007`)
- [ ] T101 [P] [US4] أضف «الحدث نفسه وصل مرتين ⇒ استهلاك واحد» بإطلاق `SessionDelivered` عشر مرات (`SC-003` · `US4/5`)
- [ ] T102 [P] [US4] اكتب `backend/tests/Feature/Payments/UnbilledDeliveryTest.php` ([quickstart §١٣](./quickstart.md)): أغلِق حصةً منفَّذة مع تعطيل الطابور، ثم شغّل `ChargeUnbilledDeliveriesJob` ⇒ تُشحَن **مرة واحدة**؛ أعد تشغيلها ⇒ صفر قيد جديد
- [ ] T103 [P] [US4] أضف حالة **الأرضية لا تحرس التسليم**: طالب عند أرضيته تماماً وحصةٌ نُفِّذت ⇒ **يُسجَّل الدَّين** وينزل الرصيد تحت الأرضية؛ رفضُ التسجيل يعني أن المنصة مدينة للمدرّس بلا مطالبة على أحد، ووضع الامتحانات يجعلها منهجية لأنه يُجبر الأرضية إلى صفر ([research.md › R17](./research.md))

### التنفيذ

- [ ] T104 [US4] أنشئ `backend/app/Modules/Payments/Actions/ChargeSessionSeats.php` — يقرأ **العدد** `billableSeats` من الحدث و**الهوية** من الحجوزات مباشرة، بنفس قسمة `Settlement/Actions/AccrueTeachingUnits.php:41`: «العدد سلطته الحدث، والحجوزات تُقرأ للهوية فقط، واختلافهما تباينٌ يستحقّ نظر إنسان لا رقماً يُفضَّل بصمت». **والمجموعة** هي التي يعدّها `FreezeBillableSeatsJob` — `Booked` أو `CancelledLate`
- [ ] T105 [US4] **يُمنع** أي تعديل على `SessionDelivered` أو مصنعه أو اختباره في 005 — كان التصميم يضيف `billableSeatHolders` ونُقض: القيمة الافتراضية `= []` تجعل منادياً نسي التمرير **يشحن صفر طالب بلا خطأ ولا اختبار أحمر**، من عائلة «`->delay()` على `sync`» ([events.md §١ج](./contracts/events.md))
- [ ] T106 [US4] أنشئ `backend/app/Modules/Payments/Listeners/ChargeSeatsOnDelivery.php` بـ`ShouldQueue, ShouldHandleEventsAfterCommit` وسجّله على `SessionDelivered` في `PaymentsServiceProvider::boot()`، **مع تعليق يسمّي `AttendanceConfirmed` و`AttendanceOverridden` متروكَين عمداً** — الأول موجود ويُطلَق من `CloseClassSession.php:66` **بلا شرط** حتى لحصةٍ لم تُدرَّس ولا يحمل عدد المقاعد، والثاني لا أثر مالي له لأن الاستهلاك بالمقعد لا بالحالة. السابقة `SettlementServiceProvider.php:54`؛ والاشتراك الذي لم يُذكر يُقترَح ثانيةً بعد ستة أشهر
- [ ] T107 [US4] اجعل خصم التسليم يمرّ **بلا أرضية** في `CreditLedger` (وسيط صريح، لا فرع مخفي) — الأرضية تحرس **الحجز** لا تسجيل دَينٍ وقع
- [ ] T108 [US4] اكتب `class_sessions.charged_at` عند نجاح الشحن، واستعمله مُسنَداً لمجموعة «سُلِّمت ولم تُشحَن»
- [ ] T109 [US4] أنشئ `backend/app/Modules/Payments/Jobs/ChargeUnbilledDeliveriesJob.php` مجدولة في `backend/routes/console.php` بـ`forWorkspace()` — **يُمنع** `WorkspaceContext::set()`. آمنةٌ بالتكرار بفضل المفتاح الفريد من T050
- [ ] T110 [US4] ارفع `SessionDelivered::dispatch` فوق بقيّة الإطلاقات في `backend/app/Modules/LiveSessions/Actions/CloseClassSession.php` — الفعل يعود مبكراً على حالة نهائية، فرميةُ مستمعٍ سابق تبتلع الحدث و**لا يُطلَق ثانيةً أبداً**، والحجب مشتقٌّ من الرصيد فيبقى الطالب نظيف السجلّ ويواصل الحجز وفتح الأصول
- [ ] T111 [US4] طبّق سلوك بلوغ الصفر المضبوط في `CreditLedger` عند نزول الرصيد إلى الصفر: منع الحجز الجديد أو توليد تذكير دفع أو كلاهما، مقروءاً من `BillingSettings` (`FR-027`)

**Checkpoint**: التشغيل صار مالاً، وحصةٌ لم تُشحَن صارت صفّاً يُستعلَم عنه ووظيفةً تُعيد المحاولة — لا خسارة صامتة.

---

## Phase 7: US5 — تذكيرات الرصيد وجدار الحجب المتدرّج (Priority: P5)

**Goal**: تنبيه هادئ ثم تذكير لوليّ الأمر ثم حجب — والحجب **مشتقّ** فيُرفع فوراً باعتماد الشراء
بلا وظيفة ولا تدخّل يدوي.

**Independent Test**: أنزل رصيداً عبر العتبتين وتحقّق من التنبيه المناسب وعدم تكراره، ثم تجاوز
الحد وتحقّق من الرفض ثم من الرفع الفوري بعد الاعتماد.

### الاختبارات أولاً

- [ ] T112 [P] [US5] اكتب `backend/tests/Feature/Payments/ThresholdNotificationTest.php` ([quickstart §٧](./quickstart.md)): تنبيه داخل المنصة عند الأولى، وتذكير لوليّ الأمر وعدّاد صريح عند الثانية؛ ثم أعد تشغيل الاحتساب ⇒ **صفر** تنبيه جديد؛ ثم ارفع الرصيد وأنزله ⇒ تنبيه واحد جديد (`SC-009` · `FR-034`)
- [ ] T113 [P] [US5] اكتب `backend/tests/Feature/Payments/WithholdingTest.php`: طالب تجاوز حدّه في كورس ⇒ الحجز فيه مرفوض **برسالة تبيّن الرصيد المطلوب ومسار الشراء** لا خطأ عام (`FR-032`)، و**كورسه الآخر المسدَّد لا يتأثّر**؛ ثم اعتمد شراءه ⇒ المحاولة **التالية مباشرةً** تمرّ بلا وظيفة ترفع الحجب (`SC-010` · `FR-033`)
- [ ] T114 [P] [US5] اكتب `backend/tests/Feature/Payments/TeacherPanelRowTest.php` **على مستوى الصفّ**: طالب مسجَّل عند مدرّس واحد ⇒ لوحة الآخر خالية؛ ثم يُلغى التسجيل ⇒ يختفي الصفّ في الطلب **التالي** (`SC-020` · `FR-055`). قائمة الحقول حارس حقول و`FR-054`/`FR-055` قاعدتا **صفوف**، ومسحُ Resource لا يرى أي صفوف اختيرت. السابقة `PlatformOwnershipTest:99-115`: «الوصول يتبع التسجيل، لا لقطةً أُخذت عند بدايته»
- [ ] T115 [P] [US5] اكتب `backend/tests/Feature/Payments/QueryBudgetTest.php` — يقارن **٥٠ صفّ رصيد بـ٥٠٠** ويؤكّد **التساوي** لا سقفاً ثابتاً: تأكيدُ «≤١٥» يمرّ فوق N+1 ما دامت العيّنة صغيرة وهي في الاختبار صغيرة دائماً. و`NFR-012` تُقاس بعدد **صفوف الرصيد** لا الطلاب، وإلا زُرع الاختبار بغير ما ينتجه الإنتاج

### التنفيذ

- [ ] T116 [US5] احسب الرتبة من `remaining_credits` وقارنها بـ`notified_tier` **في نفس المعاملة** في `CreditLedger`؛ التنبيه على الانتقال **هبوطاً** وحده، والصعود يصفّر الرتبة
- [ ] T117 [US5] أطلق `BalanceThresholdCrossed` و`AccessWithheld`/`AccessRestored` — **ومن المواضع الثلاثة التي تقلب المُسنَد بلا قيد**: `PATCH .../limit` (الحدّ) · `POST /manage/billing/exam-mode` (الأرضية إلى صفر) · `DELETE`/انتهاء النافذة (عودتها). كشفُ التغيّر داخل فعل الرصيد وحده يفوّتها كلها فلا يعلم المحجوب بحجبه حتى الحركة المالية التالية ([events.md §٢أ](./contracts/events.md))
- [ ] T118 [US5] أنشئ `backend/app/Modules/Payments/Support/EloquentAccountStanding.php` وسجّله على العقد — `withheldCourseIdsFor` تقرأ الاستحقاق **مرة واحدة لقائمة كاملة**، و**يُمنع** نداء العقد من داخل أي Resource: نداءٌ لكل صفّ هو N+1 بالبناء
- [ ] T119 [US5] اربط `backend/app/Modules/LiveSessions/Support/BookingEligibility.php` بـ`AccountStanding` — هو الموضع الذي يُنفَّذ فيه المنع؛ بدونه `SC-010` و`SC-013` بلا تنفيذ لأن العقد كان مُسنَداً إلى `Media` وحدها و`Media` لا تحجز
- [ ] T120 [P] [US5] أضف أنواع `NotificationType` الجديدة (عتبة أولى · عتبة ثانية لوليّ الأمر · حجب · رفع حجب) — **والمالي الحرج مصنَّف إلزامياً** (`FR-035`)
- [ ] T121 [P] [US5] أضف قوالب الأنواع الجديدة إلى `backend/database/seeders/NotificationTemplateSeeder.php` — **قالب مفقود يعني إشعاراً يُسقَط بصمت** (`TemplateRenderer` يرفض الرندر و`DispatchNotification` يسجّل ولا يفشل)، فكل تأكيد في T112 يمرّ على صفر
- [ ] T122 [US5] أنشئ المستمعين للإشعارات — عبر `DispatchNotification` وحدها، و**يُمنع** تسمية قناة في أي ملف تحت `Actions/` (`ProviderAgnosticTest` يُفشل البناء)
- [ ] T123 [US5] أنشئ `GET /api/v1/manage/billing/students` — صفٌّ لكل **(طالب × كورس)** ومصدره `enrollments` بضمّ خارجي لا `credit_balances`: الحساب كسول فطالبٌ لم يشترِ بعد بلا صفّ رصيد ويجب أن يظهر بأصفار. والجمع عبر الكورسات **جوابٌ خاطئ لا مضغوط**: `+10` رياضيات و`−6` فيزياء يظهر `+4` وغير محجوب بينما الحجب بالكورس تحديداً كي يبقى المسدَّد مفتوحاً ([research.md › R18](./research.md))
- [ ] T124 [US5] أنشئ `backend/app/Modules/Payments/Support/StudentBalanceAllowlist.php` بالحقول التسعة المسموحة، ومسحاً يقتصر على **`Payments/Http/Resources/Manage/`** — `OrderResource` القائم يُصدِّر `amount` و`currency` و`receipt_url` **إلى الطالب المشتري عن حقّ**، فمسحٌ على الوحدة كلها يفشل يوم كتابته؛ حارس 014 نجح لأن كل موارد وحدته للمدرّس. ووثّق الباقي الذي لا تُزيله قائمة حقول: المدرّس يضرب `purchased_credits` في سعره فيعرف حدّاً أدنى لما دفعه الطالب — لصيقٌ بـ`cost-plus`
- [ ] T125 [P] [US5] أنشئ `frontend/src/app/(app)/(shell)/manage/billing/page.tsx` — لوحة المدرّس بالأرصدة وحالة الحجب، **بلا مال**، وأضف رابطها إلى تنقّل اللوحة
- [ ] T126 [P] [US5] أنشئ شاشة الطالب المحجوب: السبب والمطلوب ومسار السداد لا رسالة خطأ عامة (`US5/7`)
- [ ] T127 [US5] أنشئ `GET /api/v1/billing/balance?student={uuid}` لوليّ الأمر عبر `GuardianDirectory::childrenOf(..., GuardianPermission::Payments)` — **الصلاحية تحديداً**: «وليّ أمر لا يجوز له رؤية السجلّ المالي لا شأن له بتذكير دفع عنه»

**Checkpoint**: الرصيد صار تحصيلاً — تنبيهٌ لا يتكرّر، وحجبٌ يُرفع بالبناء لا بوظيفة.

---

## Phase 8: US6 — الحد الائتماني يضبط نفسه (Priority: P6)

**Goal**: يرتفع بالالتزام وينخفض بالتعثّر بقرار **نظام لا مدرّس** — ومطفأ عند الإطلاق لأن النمط
الافتراضي `PREPAID_CREDITS`.

**Independent Test**: طالب يسدّد ثلاث مرات في موعدها ثم طالب يتأخّر، ورصد تغيّر السقف وسببه.

### الاختبارات أولاً

- [ ] T128 [P] [US6] اكتب `backend/tests/Feature/Payments/CreditLimitTest.php`: الارتفاع بعد ثلاث عمليات في موعدها بـ+١ حتى سقف ٤، والانخفاض إلى صفر مع التحويل إلى الدفع المسبق بعد ١٤ يوماً برصيد سالب — والقيم مقروءة من `platform_settings` لا مثبَّتة (`SC-012` · `FR-037`)
- [ ] T129 [P] [US6] أضف حالة `FR-014`: في `prepaid_credits` **يُمنع** أن يهبط الرصيد تحت الصفر إطلاقاً بصرف النظر عن أي حد
- [ ] T130 [P] [US6] أضف حالة `FR-038`: مدرّس يحاول تعديل الحد ⇒ **403**؛ والاستثناء المسجَّل بمن نفّذه ومتى وسببه يمرّ بصلاحية المنصة وحدها

### التنفيذ

- [ ] T131 [US6] أنشئ `backend/app/Modules/Payments/Actions/EvaluateCreditLimit.php` — القواعد من `BillingSettings`/`platform_settings` بمفاتيح `billing.limit.*`، والسقف الأقصى يُفرَض **داخل الـAction**
- [ ] T132 [US6] اكتب `negative_since` عند النزول تحت الصفر وامحُه عند العودة — بدونه يشتقّ كنسُ الحدّ «كم يوماً سالباً» بمسح سجلٍّ لكل رصيد
- [ ] T133 [US6] أنشئ `backend/app/Modules/Payments/Jobs/EvaluateCreditLimitsJob.php` مجدولة بـ`forWorkspace()`، تقود من الفهرس `(workspace_id, negative_since)`
- [ ] T134 [US6] أنشئ `PATCH /api/v1/manage/billing/students/{student}/limit` بصلاحية `BILLING_LIMIT_MANAGE` (**منصّية**) وبـ`throttle:billing` — و**يثبت التسجيل النشط قبل الكتابة** بـ`EnrollmentDirectory::hasActiveEnrollmentInWorkspace()`، **و403 لا 404**: الـ404 عرّافٌ بذاته. `exists:users,uuid` يجيب سؤالاً آخر — بارامتر uuid عارٍ هو تحقيقٌ في الهوية
- [ ] T135 [US6] سجّل تاريخ الحد في `activity_log` بـ`spatie/activitylog` — القيمة السابقة والجديدة والسبب، وهو نصّ `FR-039`. **لا جدول `credit_limit_changes`**: نسخة ثالثة من الفكرة نفسها
- [ ] T136 [US6] نفّذ التحويل التلقائي إلى الدفع المسبق للمتعثّر (`FR-040`) وأطلق منه `AccessWithheld` عند انقلاب المُسنَد

**Checkpoint**: الدين المعدوم محدود بقاعدة معلنة قابلة للضبط، ومسجَّل كل تغيّر فيها بسببه.

---

## Phase 9: US7 — حجز الأصول عالية القيمة (Priority: P7)

**Goal**: من رصيده سالب في كورس لا تُفتح له مذكّراته ونماذجه — **ولو كانت حصصه مفتوحة**، وكورسه
المسدَّد لا يتأثّر.

**Independent Test**: طالب رصيده سالب في كورس يطلب أصلاً مصنَّفاً وأصلاً عادياً وأصلاً في كورس آخر.

### الاختبارات أولاً

- [ ] T137 [P] [US7] اكتب `backend/tests/Feature/Payments/HighValueAssetTest.php` (`SC-011`): الأصل المصنَّف مرفوض بسببه والمطلوب، والعادي يُفتح، و**أصل الكورس المسدَّد يُفتح** — الحجب بالكورس لا بالشخص
- [ ] T138 [P] [US7] أضف حالة `FR-043`: الفحص يقع عند **كل إصدار وصول** لا مرة واحدة — أصدر منحة ثم أنزل الرصيد ثم اطلب منحة ثانية ⇒ مرفوضة
- [ ] T139 [P] [US7] أضف حالة `FR-044`: عودة الرصيد موجباً تفتح الوصول **في المحاولة التالية مباشرةً** بلا وظيفة

### التنفيذ

- [ ] T140 [US7] أضف `is_high_value` إلى تحرير الدرس وموارده في `backend/app/Modules/Courses/` — المدرّس يصنّف محتواه (`FR-041`)
- [ ] T141 [US7] اربط إصدار منحة التشغيل في `backend/app/Modules/Media/` بـ`AccountStanding::isWithheld($student, $courseId)` — الفحص عند **الإصدار** حيث بُني الحارس في 004
- [ ] T142 [US7] استعمل `withheldCourseIdsFor()` في أي قائمة أصول — **قراءة واحدة ثم ترشيح في الذاكرة**، وأضف حالة إلى مسح `Manage/` تمنع نداء العقد من داخل Resource

**Checkpoint**: الضابط المستقل الذي تصفه الوثيقة يعمل: «لا تُفتح إطلاقاً وعلى الحساب رصيد مستحق، حتى لو كانت الحصص نفسها مفتوحة».

---

## Phase 10: US8 — وضع الامتحانات (Priority: P8)

**Goal**: نافذة تُجبَر فيها الأرضية إلى صفر — بلا إبطال بأثر رجعي لحجزٍ قائم، وبعودة تلقائية
للنمط عند انتهائها.

**Independent Test**: فعّل الوضع على فترة وحاول الحجز برصيد سالب ثم برصيد موجب.

### الاختبارات أولاً

- [ ] T143 [P] [US8] اكتب `backend/tests/Feature/Payments/ExamModeTest.php` (`SC-013`): صفر حجز برصيد غير كافٍ **مهما كان الحد**، وحجز قائم قبل التفعيل **لا يبطل** (`FR-047`)، وانتهاء الفترة يعيد النمط تلقائياً
- [ ] T144 [P] [US8] أضف حالة الحدود الزمنية: نافذة تنتهي اليوم ⇒ الحجز بعد منتصف الليل **غير مقيَّد** — الحدّ الأعلى لمقارنة timestamp بتاريخ هو **بداية اليوم التالي**، و`<= ends_on` يُسقط كل ما بعد منتصف ليل اليوم الأخير

### التنفيذ

- [ ] T145 [US8] أنشئ `backend/app/Modules/Payments/Actions/ManageExamModeWindow.php` ونموذج `ExamModeWindow` بـ`BelongsToWorkspace`
- [ ] T146 [US8] اقرأ التغطية بمقارنة **نصّ تاريخ** لا بـ`whereDate()` — الدالة حول العمود تُلغي الفهرس `(workspace_id, starts_on, ends_on)`، وهو الفهرس نفسه الذي كلّف `FreezePeriod::covering()` إصلاحاً في 005
- [ ] T147 [US8] أجبر `CreditLedger::floor()` إلى صفر داخل النافذة — من `BillingSettings`، بلا فرع ثانٍ للقرار
- [ ] T148 [US8] أنشئ `POST · DELETE /api/v1/manage/billing/exam-mode` بصلاحية `BILLING_EXAM_MODE_MANAGE` وبـ`throttle:billing`، وأطلق منهما `AccessWithheld`/`AccessRestored` (T117)
- [ ] T149 [P] [US8] أنشئ شاشة نافذة الامتحانات في لوحة المدرّس

**Checkpoint**: ذروة الخطر الموسمية محكومة، بلا أثر رجعي على ما حُجز قبلها.

---

## Phase 11: US9 — موافقة موثّقة على شروط التأجيل (Priority: P9)

**Goal**: لا تأجيل بلا موافقة صريحة مسجَّلة بوقتها وعنوان شبكتها ونسخة شروطها — ولا خلط بينها
وبين موافقة معالجة البيانات.

**Independent Test**: حاول تفعيل التأجيل بلا موافقة ثم بموافقة.

### الاختبارات أولاً

- [ ] T150 [P] [US9] اكتب `backend/tests/Feature/Payments/TermsConsentTest.php` (`SC-014`): صفر تفعيل للتأجيل بلا موافقة مسجَّلة؛ ونسخة شروط جديدة **يُمنع** نسبة الموافقة السابقة إليها (`FR-049`)؛ وموافقة التأجيل **لا تُغني** عن موافقة معالجة البيانات ولا العكس (`FR-050`)
- [ ] T151 [P] [US9] أضف حالة التفويض: مستخدم يوقّع باسم طالب ليس ابنه ⇒ **مرفوض** — وإلا وقّع أي مستخدم وثيقةً قانونية باسم غيره، **وأكّدت الاستجابة أن المعرّف لشخص حقيقي**

### التنفيذ

- [ ] T152 [US9] هيّئ `TrustProxies` في `backend/bootstrap/app.php` **قبل** الاعتماد على `$request->ip()` — غير مهيّأ اليوم، فالعنوان المسجَّل في الإنتاج هو عنوان موازِن الحمل أي **العنوان نفسه للجميع**، في السجلّ الذي وُجد للاحتجاج به
- [ ] T153 [US9] أنشئ `backend/app/Modules/Payments/Actions/RecordTermsConsent.php` — يفحص أن الموقِّع هو الطالب نفسه أو `GuardianDirectory::isAuthorised(..., GuardianPermission::Payments)` **قبل** الكتابة، ويحفظ الوقت والـIP و`user_agent` ومعرّف نسخة الشروط
- [ ] T154 [US9] أنشئ `POST /api/v1/billing/consents` بـ`throttle:billing` (**لا `throttle:auth`** — T005) وشاشة القبول
- [ ] T155 [US9] امنع تفعيل نمط يسمح بالتأجيل لطالب بلا موافقة سارية في `CreditLedger`/`EvaluateCreditLimit` (`FR-048`) — والسقف الابتدائي **صفر** بلا موافقة موثّقة و**١** بعدها (`Q-9`)
- [ ] T156 [US9] استثنِ `terms_consents` من محو 013 بوصفه سجلّ التزام قانوني، واكتب مدّة حفظه في `docs/README.md`

**Checkpoint**: الوثيقة عند أي نزاع موجودة وموثّقة ومنسوبة إلى نسختها.

---

## Phase 12: Polish & Cross-Cutting

- [ ] T157 أنشئ `backend/app/Modules/Payments/Jobs/ReconcileCreditBalancesJob.php` مجدولة تكتب الانحرافات في جدول نتائج — **المطابقة وظيفةٌ لا صفحة**: كما وُصفت أولاً هي `GROUP BY` على أسرع جداول المرحلة نمواً بلا مرشّح مستأجر ولا ترقيم على GET
- [ ] T158 أضف إلى الوظيفة الثابتتين اللتين تكشفان ما تعمى عنه المطابقة البسيطة — طرفاها يكتبهما نفس المسار في نفس المعاملة فحصةٌ لم تُشحَن أصلاً تتركهما متطابقين: `الحصص المُسلَّمة × مقاعدها == قيود الاستهلاك` و`SUM(credit_lots.credits_remaining) == remaining_credits` حين يكون موجباً
- [ ] T159 أنشئ `GET /api/v1/admin/billing/reconciliation` يقرأ جدول النتائج **مع وقت آخر تشغيل** بصلاحية `BILLING_PRICING_MANAGE`
- [ ] T160 [P] أنشئ `ExpireCreditLotsJob` **مطفأةً** واربطها بحدث `CreditExpired` القائم من T029 (هذه المهمة الوظيفة والتوصيل وحدهما، لا الحدث): `expires_at` افتراضه `null` أي «لا تنتهي»، وترتيب «الأقرب انتهاءً أولاً» قائم من اليوم الأول — تفعيله لاحقاً هجرةٌ على أرصدة اشتراها الناس على أنها دائمة (`Q-5`)
- [ ] T161 [P] أنشئ كنس الخمول (`Q-8`): بعد `billing.dormant_notice_months` بلا نشاط على `last_transaction_at` يصل الطالب **إشعار** بما لديه ومسار الاسترداد — تذكيرٌ لا انتهاء، ولا مصادرة ولا نقل إلى مدرّس آخر (النقل إعادة تسعير)
- [ ] T162 [P] أضف بذرة أرصدة إلى `backend/database/seeders/ScenarioSeeder.php`: طالب برصيد موجب وآخر سالب محجوب وثالث عند عتبة تنبيه، وحزم مفعَّلة، ونافذة امتحانات — كي لا تكون الشاشة فارغة عند أول فتح
- [ ] T163 [P] حدّث `docs/README.md` بجداول المرحلة ونقاط النهاية والصلاحيات الثماني الجديدة، وبمدّة حفظ `terms_consents`
- [ ] T164 [P] حدّث `docs/erd.md` بالجداول التسعة وطبقات ملكيتها الثلاث، **وارسم غياب** أي مفتاح بينها وبين جداول التسوية صراحةً
- [ ] T165 [P] حدّث `CLAUDE.md` و`AGENTS.md` معاً بمزالق هذه المرحلة: `insertOrIgnore` تكسر `HasUuid` · الحساب الموقَّع وفخّ `1690` · الترتيب إدراجاً قبل خصماً · الأرضية تحرس الحجز لا التسليم · اعتماد شراء الأرصدة صلاحية منصة · `Queue::fake()` الجزئي
- [ ] T166 [P] حدّث `docs/roadmap.md` بحالة 006 وأثرها على 007 و009 و011 و015
- [ ] T167 راجع كل مسار كتابة: محدود المعدّل بمحدِّد **مسمّى** لا سطري · وكل مسار عرض مالي محروس بصلاحية صريحة (`NFR-013`)
- [ ] T168 شغّل البوابات الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` (Larastan L8 **بلا baseline جديد**) · `npx tsc --noEmit` (`SC-021`)
- [ ] T169 شغّل `npx playwright test` على **بناء إنتاج** مع `PHP_CLI_SERVER_WORKERS=8 php artisan serve` — الخادم أحادي الخيط يرفض طلبات ما قبل التصيير المتوازية فيسقط البناء قبل أول اختبار. وأوقف خادم التطوير أولاً (**بإذن المستخدم**): كلاهما يكتب `.next/`
- [ ] T170 امشِ سيناريوهات [quickstart.md](./quickstart.md) الستة عشر يدوياً وسجّل أي فارق

---

## Dependencies & Execution Order

```
                                          ┌──→ US2 (P2) ──┐
Phase 1 ──→ Phase 2 ──→ US1 (P1) 🎯 ──────┼──→ US3 (P3) ──┼──→ US5 (P5) ──┬──→ US6 (P6)
                                          └──→ US4 (P4) ──┘               ├──→ US7 (P7)
                                                                          └──→ US8 (P8)
            US9 (P9) — مستقلة تقنياً بعد Phase 2، وتُفرَض FR-048 مع US6
```

- **T001 حاجبة لـT024** (جدول الحزم) و**T002 حاجبة لـT087** (صفحة الأسعار) — قراران للمستخدم لا للمنفّذ.
- **US2 و US3 و US4 تعتمد على US1 وحدها** ولا تعتمد على بعضها: لا نمط ولا سعر ولا خصم بلا سجلّ يكتبون فيه.
- **US4 لا تعتمد على US3**: اختباراتها (T098–T103) تحتاج حصةً منفَّذة والسجلّ فقط. وربطها بأكبر مرحلة — ٢٤ مهمة فيها كنس `hourly_rate` من ١٩ موضعاً — يؤخّر **أخطر مسار مالي** بلا سبب تقني. الترتيب في «ترتيب التسليم» أدناه سردي لا حاجب.
- **US5 تعتمد على US1 و US4**: العتبة تُقاس على رصيد يتحرّك.
- **US6 · US7 · US8 تعتمد على US5** (`AccountStanding` والمواضع الثلاثة لانقلاب الحجب) وهي **مستقلة عن بعضها**.
- **US9 مستقلة تقنياً** ويمكن بدؤها بعد Phase 2، لكن `FR-048` لا تُفرَض قبل US6.

## Parallel Opportunities

| المرحلة | قابل للتوازي |
|---|---|
| Phase 1 | T003 · T005 · T006 · T007 · T008 · T009 |
| Phase 2 | T016 · T025 بعد الهجرات المرتّبة؛ ثم T027 · T028 · T029 · T032 · T033 · T034 · T035 |
| US1 | كل اختبارات T040–T047 معاً · ثم T062 · T063 |
| US2 | T065–T067 معاً · ثم T073 |
| US3 | T074–T078 معاً · ثم T096 · T097 (وسلسلة T079–T088 **مرتّبة لا متوازية**) |
| US4 | T098–T103 معاً |
| US5 | T112–T115 معاً · ثم T120 · T121 · T125 · T126 |
| US6 | T128–T130 معاً |
| US7 | T137–T139 معاً |
| US8 | T143 · T144 معاً · ثم T149 |
| US9 | T150 · T151 معاً |
| Phase 12 | T160–T166 معاً |

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + US1**. عند اكتمالها يوجد حساب أرصدة واحد لكل طالب، مقسّم بالكورس،
بسجلّ مضاف لا يُعدَّل، صامد أمام التزامن وإعادة تسليم الأحداث — وهو المحرّك الذي تصير كل بقيّة
المرحلة سياساتٍ فوقه.

**ترتيب التسليم**: US1 (المحرّك) ← US2 (النمط، قبل أن تتراكم قيود على سلوك مثبَّت) ←
US3 (المال يدخل + إخراج سعر المدرّس من الأسطح العامة) ← US4 (المال يخرج) ← US5 (التحصيل) ←
US6/US7/US8 (الضوابط) ← US9 (التوثيق القانوني).

**تحذير مالي**: هذه **أخطر مرحلة مالياً في المشروع كله** — تنتج أول ريال محصَّل. خطأ في الرصيد
يظهر كخسارة نقدية أو كطالب محجوب ظلماً، وثلاثة من أعطالها المعروفة (كسر `HasUuid` · فخّ الحساب
غير المُوقَّع · الدفعة المُفرَطة) **لا تظهر على SQLite إطلاقاً** وتمرّ الحزمة كلها خضراء فوقها.
اختبارات التزامن والتكرار والمطابقة فيها أولى من أي شاشة.
