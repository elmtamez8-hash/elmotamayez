# Tasks: الاشتراكُ من صفحةِ الكورس — مجموعةٌ أو حصصٌ خاصّة

**Input**: وثائقُ التصميمِ في `/specs/027-subscribe-group-or-private/`

**Prerequisites**: `plan.md` · `spec.md` · `research.md` · `data-model.md` · `contracts/api.md`

**Tests**: مطلوبةٌ صراحةً — الدستورُ (المبدأ IV) يجعلُ البوّاباتِ الخضراءَ شرطَ قبول، والمسارُ
الحرجُ الثامن («اعتمادُ الدفع ← إنشاءُ التسجيل») **يتوسّعُ في هذه الميزة**. الاختباراتُ مجمَّعةٌ
في نهايةِ كلِّ قصّةٍ لا قبلَ كلِّ ملفّ.

**Organization**: بالقصص، لتُنفَّذَ وتُختبَرَ كلُّ واحدةٍ وحدَها.

---

## ⛔ سبعُ حقائقَ تُبطِلُ التنفيذَ البديهيَّ — تُقرأُ قبلَ أوّلِ مهمّة

مقيسةٌ في الشجرةِ يومَ ٢٠٢٦-٠٩-٠٥، كلُّ واحدةٍ منها كتبتْ نفسَها في مهمّةٍ أدناه:

1. **`SubscriptionEligibility::coversCourse` اسمٌ مأخوذٌ سلفاً** بتوقيعٍ آخرَ ومستدعٍ حيّ
   (`:72` · `EloquentAccountStanding.php:90`). عقدٌ يعيدُ استعمالَ الاسمِ **لا يُترجَم**.
2. **نافذةُ الاشتراكِ `effective_ends_on`، لا `ends_on`.** الثاني «لا يتحرّكُ أبداً»؛ الأوّلُ
   «الوحيدُ الذي يقرؤه أيُّ شرط» (`create_subscriptions_table.php:78-83`).
3. **`after_commit => false` على الاتّصالاتِ الأربعةِ كلِّها** (`config/queue.php:44,53,64,73`).
   كلُّ دفعِ وظيفةٍ داخلَ معاملةٍ يحتاجُ `DB::afterCommit()` صراحةً.
4. **`BookingStatus::Released` قائمةٌ ومعناها «الطالبُ لم يفعلْ شيئاً»** (`:8-20`)، و`isBillable()`
   تُعيدُ لها `false` (`:43`). هي الفرقُ بينَ FR-044 وFR-045، ولا عمودَ جديدَ يلزم.
5. **`Course::isFree()` هو `price_minor === 0`** وهذا العمودُ يُسعِّرُ الشراءَ المفرَدَ وحدَه
   و`default(0)`. تضييقُ FR-004 به **يتركُ البابَ مفتوحاً**.
6. **`CohortMembershipWriter` يرمي على التجديدِ ويسمحُ بالنقلِ الصامت** (`:67-70` · `:91-95`) —
   عكسُ ما تطلبُه FR-028 في الحالتَين.
7. **`ContextIsolationTest` يفحصُ Settlement ⇄ Payments/Store/Community/Compliance فقط.** لا
   يقولُ شيئاً عن LiveSessions ولا Learning. حارسٌ مذكورٌ لا يعملُ أسوأُ من غيابِه.

---

## Phase 1: Setup

**Purpose**: ما لا يعتمدُ عليه شيءٌ دلاليّاً ويُفتَحُ به الطريق.

- [X] T001 [P] أنشئْ هجرةَ الفهارسِ الثلاثةِ في `backend/database/migrations/` — `orders(kind, status, created_at)` و`class_sessions(cohort_id, starts_at)` و`subscriptions(workspace_id, status, effective_ends_on)`، وفي `down()` أسقِطْها بالترتيبِ المعكوس. اكتبْ فوقَ كلِّ فهرسٍ قارئَه وسببَه كما في `plan.md` §Storage: الأوّلُ لأنّ `withoutWorkspaceScope()` يُسقِطُ العمودَ الأيسرَ من `(workspace_id, kind, status)` فيمسحُ الطابورُ الجدولَ مرّتَينِ لكلِّ عرض، والثاني يخدمُ صفحةَ الكورسِ العامّةَ **غيرَ المخزَّنةِ مؤقّتاً** و`nextSessionFor()` و`ClaimSubscriptionSeats`، والثالثُ يخدمُ `subscriberIdsAmong()`.
- [X] T002 [P] أضِفْ أسماءَ حقولِ شاشةِ الاشتراكِ إلى مصفوفةِ `attributes` في `backend/lang/ar/validation.php` — `mode` · `cohort_uuid` · `plan_uuid`، وإلّا ظهرتْ بالإنجليزيّةِ في رسالةِ التحقّق.

---

## Phase 2: Foundational (يَحجُبُ كلَّ القصص)

**⚠️ لا تبدأْ أيَّ قصّةٍ قبلَ اكتمالِ هذه المرحلة.**

- [X] T003 [P] أنشئْ `backend/app/Modules/Payments/Data/SubscriptionIntent.php` — DTO يرثُ `App\Shared\Data\DataTransferObject` بخصائصَ `readonly` للمفاتيحِ الثمانيةِ في `data-model.md` §٢، ومُنشِئٍ مُسمّى `fromOrder(Order $order): ?self` يقرأُ `$order->metadata` ويُعيدُ `null` حينَ ينقصُ مفتاح. **لا فعلَ قارئ**: `App\Shared\Actions\Action` لمنطقِ الأعمالِ لا لفكِّ التسلسل، والمستودعُ يقرأُ الـmetadata مباشرةً في `ApproveOrder.php:144` و`ActivateSubscription.php:125`. `Data/` هو موضعُ الـDTO في ثماني وحداتٍ أخرى.
- [X] T004 [P] **نُفِّذَ في موضعٍ آخرَ عن قصد**: الشرطُ صارَ `SubscriptionDirectory::courseRequiresPurchase(int)` يُنفِّذُه `SubscriptionEligibility`، لا دالّةً على `Course`. السببُ مقيس: لا وحدةَ `Courses` ولا `Learning` تستوردُ `Payments` اليومَ (صفرُ نتائج)، ووضعُ قراءةِ `plans` على نموذجِ الكورسِ كانَ سيصيرُ أوّلَ عبورٍ في ذلك الاتّجاه — بينما `SubscriptionEligibility` يستوردُ `Courses\Models\Course` سلفاً. النصُّ الأصليّ: أضِفْ `requiresPurchase(): bool` إلى `backend/app/Modules/Courses/Models/Course.php` بجوارِ `isFree()` — `price_minor > 0` **أو** وجودُ باقةٍ قابلةٍ للبيعِ تغطّي هذا الكورس (`coverage_type = workspace` أو `coverage_uuid = $this->uuid`) في مساحةِ عملِه، بقراءةٍ تُعلِنُ `withoutWorkspaceScope()`. اكتبْ فوقَها لماذا `isFree()` لا يكفي (الحقيقة ٥ أعلاه).
- [X] T005 أنشئْ `backend/app/Shared/Contracts/SubscriptionDirectory.php` بالتوقيعِ الوحيدِ `subscriberIdsAmong(array $studentUserIds, int $courseId, string $sessionType, DateTimeInterface $moment): array`، ونفِّذْه في `backend/app/Modules/Payments/Support/SubscriptionEligibility.php` فوقَ المُسنَدِ الذي يملكُه سلفاً، واربِطْه بـ`bind()` في `backend/app/Modules/Payments/PaymentsServiceProvider.php`. **`bind()` لا `scoped()` ولا `singleton()`**: يُسألُ مرّةً لكلِّ وظيفةٍ ومرّةً لكلِّ حصّةٍ مجدولة، لا عشراتٍ في الصفحة؛ وحاويةُ العاملِ تعيشُ أطولَ من الوظيفةِ فتخدمُ `singleton()` قائمةَ مشترِكينَ بائتة. اكتبْ في رأسِ الملفِّ القيودَ الثلاثةَ من `contracts/api.md` §د.
- [X] T006 أضِفْ `isJoinable(int $cohortId): bool` إلى `backend/app/Shared/Contracts/CohortDirectory.php` ونفِّذْها في `backend/app/Modules/Learning/Support/EloquentCohortDirectory.php` بتفويضٍ إلى `Cohort::isJoinable()` القائمة — **لا شرطَ ثالثٌ يُكتَب**. وفي الملفِّ نفسِه أضِفْ `->group()` إلى `resolveCohortId()` (`:130-139`) وإلى `joinableCohortsExist()`: مجموعةٌ فرديّةٌ فُتِحتْ سهواً هي اليومَ بابُ دخولٍ إلى غرفةِ طالبٍ آخرَ باسمِه، وصمّامُ أمانِ FR-028ب يُفتَحُ على مجموعةٍ لا يجوزُ لأحدٍ دخولُها.
- [X] T007 [P] أضِفْ `nextSessionFor(int $cohortId): ?array{uuid, starts_at}` إلى `backend/app/Shared/Contracts/CohortScheduleDirectory.php` ونفِّذْها في `backend/app/Modules/LiveSessions/Support/EloquentCohortScheduleDirectory.php` بترتيبٍ تصاعديٍّ وحدٍّ واحد. اكتبْ فوقَها لماذا هذه وحدَها مفردةٌ بينما `schedulePreviewFor()` جَمعيّة: هي تُسألُ مرّةً واحدةً لكلِّ اعتماد، لا مرّةً لكلِّ صفٍّ من قائمة.
- [X] T008 أصلِحْ التحميلَ المسبَقَ في `backend/app/Filament/Resources/OrderResource.php:431` — `'course' => fn ($q) => $q->withoutGlobalScope(WorkspaceScope::class)`. **عطلٌ شُحِنَ بالفعل**: السطرُ ٤٢٨ يُسقِطُ النطاقَ على الاستعلامِ الخارجيِّ والسطرُ ٤٣١ يُعيدُه داخلَ العلاقة، فالموظّفُ المنصّيُّ يقرأُ «—» في عمودِ الكورسِ لكلِّ طلبٍ خارجَ مساحتِه بلا رسالةِ خطأ. طابورُ ٠٢٧ يقرأُ من الشكلِ نفسِه.

**Checkpoint**: العقودُ والـDTO والشروطُ المشتركةُ جاهزة.

---

## Phase 3: User Story 1 — سلمى تشتركُ من صفحةِ الكورسِ في خطوةٍ واحدة (P1) 🎯 MVP

**Goal**: طالبةٌ بلا تسجيلٍ ولا رصيدٍ تنشئُ طلباً معلَّقاً بإيصالٍ ونيّةٍ مسجَّلة، من صفحةِ
الكورسِ، بإجراءٍ واحد.

**Independent Test**: من متصفّحٍ بحسابِ طالبةٍ لا تسجيلَ لها ولا رصيد، افتحْ صفحةَ كورسٍ منشورٍ
له مجموعةٌ مفتوحة، اضغطِ الزرَّ، اختر باقةً، ارفعِ الإيصال. النجاحُ ظهورُ طلبٍ معلَّقٍ في
«الطلبات» يحملُ الإيصالَ والمجموعةَ المختارة.

### الخادم — الحمولةُ العامّةُ وبابُ الشراء

- [X] T009 [P] [US1] احسبْ `is_joinable` داخلَ `publicCohortsFor()` في `backend/app/Modules/Learning/Support/EloquentCohortDirectory.php:141-176` — من الأعمدةِ المحمَّلةِ سلفاً (`status` · `capacity` · `members_count`)، **لا بنداءِ `isJoinable(int)` مرّةً لكلِّ صفّ** (المَوردُ يُنفَّذُ مرّةً لكلِّ صفّ، فاستعلامٌ فيه N+1 بالبناء).
- [X] T010 [US1] أضِفْ `private_subscription_available` إلى `backend/app/Modules/Marketplace/Actions/Public/ReadPublicCourse.php` — بوليانٌ واحدٌ يجمعُ «للمدرّسِ مواعيدُ معلَنة» و«له باقةٌ فرديّةٌ قابلةٌ للبيع». **بولياني واحدٌ لا يتسرّبُ شيء**: `PurchaseSubscription.php:48` يجمعُ «لا باقة» و«مطفأة» و«بلا سعر» في جملةٍ واحدةٍ عمداً كي لا يُعرَفَ أيُّ المدرّسينَ عندَه باقةٌ تنتظرُ تسعيراً، وهذا الحقلُ يحفظُ الجمع.
- [X] T011 [US1] أضِفِ المفتاحَينِ إلى `backend/app/Modules/Marketplace/Support/PublicFieldAllowlist.php` — `is_joinable` إلى `COHORT` (`:231`) و`private_subscription_available` إلى ثابتِ تفصيلِ الكورس. `PublicExposureTest` يمشي على كلِّ عمقٍ ويُسقِطُ البناءَ على مفتاحٍ غيرِ مُدرَج، **في التغييرِ نفسِه**.
- [X] T012 [US1] وسِّعْ `backend/app/Modules/Payments/Actions/PurchaseSubscription.php` ليقبلَ `mode` و`cohortUuid` ويكتبَ المفاتيحَ الثمانيةَ في `orders.metadata` (`data-model.md` §٢) — **من الخادمِ بعدَ الحلّ، لا مفتاحَ منها من جسمِ الطلب**. أضِفْ حارسَ تطابُقِ `mode` مع `plan.session_type` (FR-008 · FR-012).
- [X] T013 [US1] في الفعلِ نفسِه، حُلَّ المجموعةَ **بلا نطاقٍ ثمّ أثبِتِ التغطية** كما في `data-model.md` §٦أ: `cohort.workspace_id === plan.workspace_id`، وللباقةِ الكورسيّةِ `cohort.course->uuid === plan.coverage_uuid`. **لا تمرِّرْ `course_id`**: `coverageCourseId()` يُعيدُ `null` لباقةِ مساحةِ العملِ (`:108-120`)، ومعرِّفٌ فارغٌ يجعلُ الحلَّ غيرَ مقيَّدٍ فيُسمّي الطالبُ **أيَّ** مجموعةٍ على المنصّة — على المسارِ الذي لا يحمي فيه `BelongsToWorkspace` شيئاً (الطالبُ عضوُ لا مساحة).
- [X] T014 [US1] في الفعلِ نفسِه، أضِفِ الحرّاسَ الثلاثةَ للعضويّةِ القائمةِ عبرَ `CohortDirectory::openMembershipCohortId()` و`isCurrentMember()` **القائمتَينِ** (`CohortDirectory.php:46` · `:76`) — لا دالّةَ رابعة: مجموعةٌ **هي مجموعتُه** ⇒ تجديدٌ يُقبَل ولو كانت ممتلئةً به وبزملائه؛ مجموعةٌ **أخرى** في الكورسِ نفسِه ⇒ رفضٌ بجملةٍ تدلُّ على طلبِ النقل (`CohortMembershipWriter:91-95` يُغلِقُ القديمةَ ويفتحُ الجديدةَ بلا قرارِ مدرّس — فالشراءُ يصيرُ نقلاً صامتاً يناقضُ FR-028 والافتراضَ ٦)؛ ولا مجموعةَ ⇒ شرطُ `isJoinable()` كما هو.
- [X] T015 [US1] في الفعلِ نفسِه، امنعِ الطلبَ الثانيَ المعلَّقَ بمفتاحِ **(المشتري · مساحةُ العمل · `kind = subscription` · معلَّق)** ورُدَّ `code: order_pending` (FR-011). **لا `course_id`**: هو `null` لباقةِ مساحةِ العمل، فـ`where('course_id', null)` يطابقُ كلَّ طلبٍ من هذا النوعِ أنشأه المشتري يوماً.
- [X] T016 [US1] وسِّعِ التحقّقَ في `backend/app/Modules/Payments/Http/Controllers/SubscriptionController.php:72-75` بـ`mode` (`in:cohort,private`, مطلوب) و`cohort_uuid` (`required_if:mode,cohort` · `prohibited_unless:mode,cohort`)، وأضِفْ `?session_type=` إلى قراءةِ الباقات. **الباقةُ والمجموعةُ في الجسمِ لا في المسار** — الربطُ الضمنيُّ يُحَلُّ قبلَ أيِّ حارس.
- [X] T017 [US1] أضِفْ مفتاحَ `subscription` إلى `backend/app/Modules/Payments/Http/Resources/OrderResource.php` — اللقطةُ عبرَ `SubscriptionIntent::fromOrder()`، و`null` لغيرِ الاشتراك. **قارئٌ واحدٌ للقطةٍ واحدة**: يقرؤه الطالبُ في «الطلبات» ويقرؤه الموظّفُ في الطابور. ومفتاحٌ ناقصٌ في طلبٍ قديمٍ يُقرأُ «—» ولا يُستكمَلُ باستعلام.
- [X] T018 [US1] ضيِّقْ `enroll()` في `backend/app/Modules/Learning/Http/Controllers/EnrollmentController.php:44-55` بـ`$course->requiresPurchase()` ⇒ ٤٢٢ و`code: purchase_required` (FR-004). **لا تستعملْ `isFree()`** (الحقيقة ٥). المسارُ **لا يُحذَف**: الكورسُ المجّانيُّ حالةٌ حقيقيّة، ولا مستدعيَ لهذا المسارِ في `frontend/src` فالتضييقُ لا يكسرُ شاشة.

### الواجهة — الدعوتانِ والشاشةُ الواحدة

- [X] T019 [P] [US1] أنشئْ `frontend/src/lib/safe-next.ts` — يُقبَلُ ما يبدأُ بـ`/` ولا يبدأُ بـ`//` **ولا بـ`/\`**، ويُعادُ حلُّه بـ`new URL(next, origin)` والمقارنةُ على `origin`. المتصفّحُ يُطبِّعُ الشَّرطةَ الخلفيّةَ إلى أماميّةٍ أثناءَ التحليل، فـ`/\evil.example` عنوانٌ مطلَق — وعلى إعادةِ توجيهٍ **بعدَ الدخول** ذلك تصيُّدٌ يبدأُ من صفحةِ دخولِ المنصّةِ الحقيقيّة. الحارسُ **عندَ إعادةِ التوجيهِ وحدَها**، لا في كلِّ صفحةٍ تحملُ المُعامِل.
- [ ] T020 [P] [US1] أضِفْ زرَّ «اشترك في هذه المجموعة» إلى `frontend/src/components/marketplace/CohortList.tsx` — يُعرَضُ على `is_joinable` **المقروءةِ من الخادم**، ولا يُعرَضُ زرٌّ معطَّلٌ على غيرِها (FR-002 · US1·٢). اشتقاقُ الشرطِ من `status`/`seats_left` في TypeScript هو التهجئةُ الثانيةُ التي تضعُ إجابةً على البطاقةِ وأخرى عندَ الباب.
- [ ] T021 [P] [US1] حوِّلْ فرعَ «غيرِ المسجَّل» في `frontend/src/components/courses/PrivateSessionRequestForm.tsx` من رفضٍ إلى **دعوةِ اشتراك**، مشروطةً بـ`private_subscription_available` (FR-003). الرفضُ الحاليُّ صحيحٌ لطلبِ حصّةٍ، وخطأٌ كبابٍ وحيدٍ أمامَ مشترٍ جديد.
- [ ] T022 [US1] أنشئْ `frontend/src/app/(app)/(shell)/subscribe/page.tsx` و`frontend/src/lib/subscribe.ts` — الشاشةُ الواحدة (FR-006): الاختيارُ (المجموعةُ باسمِها ومواعيدِها أو «حصص خاصّة») · المدرّس · الباقاتُ المطابِقةُ للوضعِ بمدّةِ كلٍّ وسعرِها · تعليماتُ الدفعِ · رفعُ الإيصال · إرسالٌ واحد. الحالةُ كلُّها في العنوان (`?course=…&cohort=…` أو `&mode=private`) — صفحةُ الكورسِ تُصيَّرُ على الخادمِ ولا تعرفُ من يقرؤها.
- [ ] T023 [US1] اقرأِ `?next=` في `frontend/src/app/(app)/login/page.tsx` و`register/page.tsx` و`frontend/src/app/(public)/signup/student/page.tsx` عبرَ `safeNext()` (FR-005) — **وبعدَ التسجيلِ الجديدِ كما بعدَ الدخول**. أغلبُ من يضغطُ الزرَّ على صفحةٍ تُفتَحُ من محرّكِ بحثٍ لا حسابَ له أصلاً؛ وإعادتُه إلى لوحتِه هي «التعقيد» الذي طُلِبَ رفعُه.
- [ ] T024 [US1] أضِفْ رسالةَ الاستئنافِ لمسارِ القاصرِ (FR-005أ) في المسارِ نفسِه — ما ينقصُ وأينَ يُستأنَفُ الاشتراك، بدلَ اختيارٍ يضيعُ صامتاً.

### اختباراتُ القصّةِ الأولى

- [X] T025 [US1] اكتبْ `backend/tests/Feature/Payments/SubscriptionIntentTest.php` — اللقطةُ الثمانيّةُ · تطابُقُ `mode` مع `session_type` · المجموعةُ من كورسٍ آخرَ تُرفَض · الباقةُ من مساحةِ عملٍ أخرى تُرفَض · الطلبُ الثاني المعلَّقُ يُرفَضُ بـ`order_pending` · **التجديدُ على المجموعةِ نفسِها يُقبَلُ ولو كانت ممتلئة** · المجموعةُ الأخرى تُرفَضُ بجملةِ النقل. ⚠️ اترُكْ `last_workspace_id` في الطالبةِ **فارغاً** وأعِدْ ضبطَ مفردِ السياق، وإلّا قِستَ شخصاً لا يوجدُ في الإنتاج.
- [X] T026 [P] [US1] اكتبْ `backend/tests/Feature/Learning/SelfEnrollmentClosedTest.php` — كورسٌ **بلا سعرٍ مفرَدٍ وله باقةٌ مسعَّرة** يُرفَضُ بـ`purchase_required`، وكورسٌ حرٌّ حقّاً يُسجَّلُ فيه. الحالةُ الأولى هي التي يمرُّ فيها `isFree()` أخضرَ فوقَ بابٍ مفتوح.
- [ ] T027 [P] [US1] أضِفْ حالتَي الحقلَينِ الجديدَينِ إلى `backend/tests/Feature/Marketplace/PublicExposureTest.php`، وحالةَ «صفرُ استعلاماتٍ إضافيّةٍ لكلِّ مجموعة» إلى ميزانيّةِ استعلاماتِ صفحةِ الكورسِ العامّة.
- [X] T028 [P] [US1] اكتبْ `frontend/src/lib/safe-next.test.ts` — `/dashboard` يمرّ · `//evil.example` و`/\evil.example` و`https://evil.example` تسقطُ إلى الوجهةِ الافتراضيّة.
- [ ] T029 [P] [US1] اكتبْ `frontend/src/components/marketplace/CohortList.test.tsx` — زرٌّ على `is_joinable: true`، **ولا عنصرَ إطلاقاً** على `false` (لا زرَّ معطَّلاً).

**Checkpoint**: الحلقةُ المغلَقةُ مفتوحة. الطلبُ يُنشَأُ ويُعتمَدُ بأدواتِ اليومِ حتّى قبلَ US2.

---

## Phase 4: User Story 2 — الموظّفُ يقرأُ الطلبَ كاملاً قبلَ أن يقرّر (P2)

**Goal**: طابورٌ فوقَ استمارةِ المنحِ في `/admin/grant-credit-subscription` يحملُ الحقولَ
الثمانيةَ ويُتَّخَذُ منه القرار.

**Independent Test**: بحسابِ موظّفٍ يحملُ صلاحيّةَ اعتمادِ المشترياتِ **ويملكُ مساحةَ عمل**،
افتحِ الصفحةَ بطلبٍ معلَّقٍ في مساحةٍ **أخرى**. النجاحُ قراءةُ الحقولِ الأربعةِ الوسطى وفتحُ
الإيصالِ واتّخاذُ القرارِ من الشاشةِ نفسِها.

- [ ] T030 [US2] نفِّذْ `HasTable` + `InteractsWithTable` + `table(Table $table)` على `backend/app/Modules/Payments/Filament/Pages/GrantCreditSubscription.php`، وأضِفْ `{{ $this->table }}` إلى `backend/resources/views/filament/pages/grant-credit-subscription.blade.php` فوقَ الاستمارةِ القائمة. هذا **أوّلُ** استعمالٍ لهذا الشكلِ في الشجرة (`grep -rl InteractsWithTable app/` ⇒ صفر) — تحقَّقَ أنّ دوالَّ `HasActions` من الجداولِ مسبوقةٌ بـ`...TableAction` فلا تصطدمُ بـ`InteractsWithActions` على `BasePage`.
- [ ] T031 [US2] اكتبِ استعلامَ الطابورِ في تلك الصفحةِ بالشكلِ المكتوبِ في `contracts/api.md` §ج — `withoutWorkspaceScope()` على الخارجيّ **و`withoutGlobalScope` مكرَّرٌ داخلَ تحميلِ `course`**. الصيغةُ المختصَرةُ هي الطبقةُ الخامسةُ من عطلِ ٠٢٤: **بلا رسالةِ خطأٍ إطلاقاً**، قائمةٌ قصيرةٌ تُقرأُ أسبوعاً هادئاً وعمودُ كورسٍ فارغ.
- [ ] T032 [US2] عرِّفِ الأعمدةَ الثمانيةَ (FR-017): الطالبُ · المدرّسُ · الكورسُ · المدّةُ · المجموعةُ باسمِها أو «حصص خاصّة» · المبلغُ والعملةُ · الإيصالُ · التاريخ. الأربعةُ الوسطى **من `SubscriptionIntent`** لا باستعلامٍ داخلَ عمود. ولا `sortable()` ولا `searchable()` عليها — كلاهما يصيرُ `JSON_EXTRACT` في `ORDER BY`/`WHERE` فوقَ استعلامٍ لا فهرسَ له. وإن قيَّدتَ تحميلَ `user` فسمِّ `first_name` و`last_name`: **`users` لا يحملُ عمودَ `name`** وقد أفرغَ ذلك ستَّ شاشاتٍ من أسماءِ أصحابِها.
- [ ] T033 [US2] استعملْ `URL::temporarySignedRoute('orders.receipt', …)` لرابطِ الإيصالِ كما في `app/Filament/Resources/OrderResource.php:117-121` — **لا `getFirstMediaUrl()`**، فهو يسقطُ إلى مسارِ `/storage/` العامّ (FR-035).
- [ ] T034 [US2] استدعِ `OrderResource::approveAction()` و`rejectAction()` **حرفيّاً** كفعلَي صفّ. هما `public static` ويحملانِ `->authorize()` و`refusedForTwoFactor()` واستدعاءَ الفعلِ مع الـIP وuser-agent، فـFR-018…FR-021 وFR-037 تتحقّقُ بإعادةِ الاستخدام. **زرٌّ مُعادُ التنفيذِ يفقدُ الأربعةَ صامتاً.**
- [ ] T035 [US2] لُفَّ استدعاءَ الفعلَينِ بـ`try/catch (DomainException)` يعرضُ الجملةَ العربيّةَ كإشعارٍ، على مثالِ `GrantCreditSubscription.php:281-288`. الفحصُ التمهيديُّ لـFR-026 يجعلُ `DomainException` **نتيجةً عاديّةً** لضغطةِ «اعتمد» على مجموعةٍ أُرشِفت، و`OrderResource.php:221-228` بلا التقاط.
- [ ] T036 [US2] عرِّبْ جملةَ خاسرِ السباقِ في `backend/app/Modules/Payments/Actions/ApproveOrder.php:73` — «Only pending orders can be approved.» نصٌّ إنجليزيٌّ في منتَجٍ عربيٍّ وحدَه، وهو ما يقرؤه الموظّفُ الثاني (FR-020 · SC-010).
- [ ] T037 [US2] أضِفْ تعليقاً فوقَ استعلامِ الطابورِ يشرحُ لماذا **لا يُناقِضُ** هذا FR-008أ من ٠٢٤ («لا زرَّ اعتمادٍ هنا»): استمارةُ المنحِ تُنشئُ `kind = credits` والطابورُ يقرأُ `kind = subscription`، فلا يعتمدُ موظّفٌ ما أنشأه هو. وحدِّثْ تعليقَ الصفحةِ عندَ `:49-53` بدلَ تركِه يناقضُ الشاشةَ التي تحتَه.

### اختباراتُ القصّةِ الثانية

- [ ] T038 [US2] اكتبْ `backend/tests/Feature/Payments/SubscriptionQueueTest.php` — الحقولُ الثمانيةُ تُقرأ · «حصص خاصّة» صريحةٌ لا فراغ · مجموعةٌ مؤرشَفةٌ ما زالت تُقرأُ **بالاسم** (FR-013) · موظّفٌ بلا الصلاحيّةِ يُرفَضُ عندَ فتحِ العنوانِ كتابةً · ميزانيّةُ استعلاماتٍ ثابتةٌ مع صفٍّ واحدٍ ومع عشرة، **وتوكيدٌ أنّ عمودَ الكورسِ حاضرٌ لا فارغ** (ميزانيّةٌ وحدَها تقرأُ إسقاطَ التحميلِ المسبَقِ تحسيناً). ⚠️ **مساحتا عملٍ وموظّفٌ يملكُ إحداهما** — بغيرِ ذلك لا يُرى شيءٌ من هذا، وسطرٌ واحدٌ كهذا كشفَ طبقاتِ ٠٢٤ الخمس.
- [ ] T039 [P] [US2] أضِفْ إلى الملفِّ نفسِه توكيداً أنّ استمارةَ المنحِ وطابورَ الاعتمادِ **لا يتقاطعانِ في مجموعةِ الطلبات** — الفصلُ اليومَ نتيجةُ مُرشِّحٍ لا قاعدةٍ كتبَها أحد، ومن يُضيفُ `kind` إلى المُرشِّحِ غداً يُعيدُ فتحَ متطلَّبِ ٠٢٤ بلا شيءٍ يقول.

**Checkpoint**: الطلبُ يُرى ويُقرَّرُ من شاشةٍ واحدة.

---

## Phase 5: User Story 3 — القبولُ يضعُها في مجموعتِها ويُخبِرُها بالمواعيدِ والرابط (P3)

**Goal**: ضغطةٌ واحدةٌ تُنتِجُ تسجيلاً وعضويّةً ومقاعدَ وإشعاراً بموعدٍ ورابط.

**Independent Test**: اعتمِدْ طلباً معلَّقاً يحملُ مجموعة، ثمّ اقرأ — من حسابِ الطالبةِ —
تسجيلَها وعضويّتَها وجدولَها وإشعارَها. النجاحُ الأربعةُ من ضغطةٍ واحدة.

### الاعتمادُ والتفعيل

- [ ] T040 [US3] أضِفِ الفحصَ التمهيديَّ إلى `backend/app/Modules/Payments/Actions/ApproveOrder.php` **قبلَ الادّعاءِ الشرطيِّ في السطرِ ٦٢**، مُفرَّعاً على `$order->kind`؛ لنيّةِ «مجموعة» يسألُ `CohortDirectory::isJoinable()` ويرمي `DomainException` بجملةٍ عربيّة، فيبقى الطلبُ `pending` ولا يُكتَبُ صفٌّ واحد (FR-026). **في الفعلِ لا في شاشةِ Filament**: للاعتمادِ بابانِ — الشاشةُ و`POST /orders/{orderUuid}/approve` — وحارسٌ في الشاشةِ يحرسُ ما يُضغَطُ ويتركُ ما يُنادى. **ولا في `ActivateSubscription`**: ذاك مصفوفٌ ويعملُ بعدَ الالتزام، فرفضُه يُنتِجُ حرفيّاً الحالةَ التي كُتِبَ FR-026 لمنعِها.
- [ ] T041 [US3] وسِّعْ `backend/app/Modules/Payments/Listeners/ActivateSubscription.php` ليفتحَ العضويّةَ في المجموعةِ المقصودةِ عبرَ `JoinCohort` (FR-025)، **ومعامَلةُ «هو فيها سلفاً» نجاحٌ لا استثناء** (FR-028 · US4·٤). `CohortMembershipWriter:67-70` يرمي `sameCohort()` و`JoinCohort` يرمي `alreadyMember()`؛ وهذا المستمعُ يعملُ بعدَ الالتزام، فالرميُ هنا يعني: طلبٌ معتمَدٌ ودفعةٌ ملتزَمةٌ ثمّ ارتدادُ الاشتراكِ والتسجيلِ والعضويّةِ معاً، ثمّ إعادةُ محاولةٍ ترمي ثانيةً، ثمّ `failed_jobs` — **ولا شيءَ على شاشةِ الموظّفِ يقول**.
- [ ] T042 [US3] في المستمعِ نفسِه، ادفعْ `ClaimSubscriptionSeatsJob` بـ`DB::afterCommit(...)` (أو `->afterCommit()` على الدفعِ المعلَّق) **خارجَ معاملةِ (اشتراك ← تسجيل ← عضويّة)**. `config/queue.php` يكتبُ `after_commit => false` على الاتّصالاتِ الأربعة، و`ShouldHandleEventsAfterCommit` يحكمُ المستمعَ لا وظيفةً يدفعُها هو: وظيفةٌ تصلُ العاملَ قبلَ الالتزامِ تسألُ `refusalReason()` وأوّلُ شروطِه وجودُ تسجيلٍ فعّالٍ — فتُرفَضُ **كلُّ** حصّة، ويصلُ إشعارٌ واحدٌ يسمّيها جميعاً، والشهرُ المدفوعُ بلا مقعدٍ إلى الأبد، والوظيفةُ تُبلِّغُ نجاحاً.
- [ ] T043 [US3] أضِفْ ظهورَ «العملِ الناقص» للموظّف (FR-027 · US3·٦) — تفعيلٌ فشلَ بعدَ اعتمادٍ ملتزَمٍ يجبُ أن يُقرَأَ على شاشةٍ لا في `failed_jobs` وحدَها. أبسطُ شكلٍ يفي: عمودُ حالةِ تفعيلٍ مشتقٌّ في الطابورِ يقرأُ وجودَ الاشتراكِ والعضويّةِ للطلبِ المعتمَد.

### الحجزُ التلقائيّ

- [ ] T044 [US3] أنشئْ `backend/app/Modules/LiveSessions/Actions/ClaimSubscriptionSeats.php` بالخطواتِ الخمسِ في `data-model.md` §٤. ⚠️ النافذةُ `effective_ends_on` **لا `ends_on`** والحدُّ `< القيمة + يوم`. ⚠️ سمِّ `workspace_id` و`course_id` و`cohort_id` معاً كي يخدمَ الفهرسُ القائم. ⚠️ استعملْ `BookSeat::claimGrantedSeat()` **القائمَ** ولا تُنشئْ مدخلاً ثالثاً — جسدُه مطابقٌ حرفاً بحرف. اجمعْ كلَّ رفض.
- [ ] T045 [US3] في الفعلِ نفسِه، افرزِ الصفوفَ القائمةَ بالحالةِ لا «بأيِّ حالة»: `booked` و`cancelled_*` تُتخطّى (FR-044)، و**`released` تُحيا بادّعاءٍ شرطيّ** على الصفِّ القائمِ بعدَ ادّعاءِ السَّعة (FR-045أ). `INSERT` يرتطمُ بالفهرسِ الفريدِ فيردُّ `BookSeat.php:118` «لديك مقعد محجوز في هذه الحصة بالفعل» — جملةٌ كاذبةٌ لصفٍّ محرَّرٍ ولا مخرجَ منها.
- [ ] T046 [US3] في الفعلِ نفسِه، تخطَّ الحصصَ التي `billable_seats !== null` وسمِّها في إشعارِ FR-042 (**FR-039أ** — قرارُ صاحبِ المنتَجِ ٢٠٢٦-٠٩-٠٥). العددُ يُثبَّتُ مرّةً عندَ موعدِ آخرِ إلغاءٍ ولا يُعادُ حسابُه، فحجزٌ بعدَه يضعُ طالبةً في الغرفةِ لا يُدفَعُ للمدرّسِ عنها.
- [ ] T047 [P] [US3] أنشئْ `backend/app/Modules/LiveSessions/Jobs/ClaimSubscriptionSeatsJob.php` — مصفوفةٌ، **مُعادةُ التشغيلِ لا مُبلِّغةٌ مرّةً واحدة**، وتعملُ داخلَ `forWorkspace($workspace, fn () => …)` ولا تستدعي `WorkspaceContext::set()` أبداً.
- [ ] T048 [US3] أنشئْ حدثَ `backend/app/Modules/LiveSessions/Events/SessionsAssignedToCohort.php` وأطلِقْه من `backend/app/Modules/LiveSessions/Actions/AssignSessionsToCohort.php:76-78`. الكتابةُ `update()` جَمعيّةٌ فلا تُقلِعُ حتّى أحداثَ النموذج، فبدونِه تعملُ الميزةُ في مسارِ الجدولةِ وتصمتُ في مسارِ الإسناد. **حدثٌ ثانٍ لا إعادةُ إطلاقِ `SessionScheduled`**: «أُنشئتْ حصّة» و«أُسنِدتْ إلى مجموعة» معنيان، والواحدُ لهما هو انقسامُ `SessionCompleted`/`SessionDelivered` عائداً.
- [ ] T049 [US3] أنشئْ `backend/app/Modules/LiveSessions/Listeners/BookSubscribersOnScheduled.php` على الحدثَينِ معاً (FR-040)، `ShouldQueue` + **`ShouldHandleEventsAfterCommit`** (إطلاقُ الإسنادِ داخلَ معاملة). ابدأْ من `CohortDirectory::activeMemberIdsFor($cohortId)` ثمّ صفِّ بـ`SubscriptionDirectory::subscriberIdsAmong(..., $session->starts_at)`. **الاتّجاهُ المعكوسُ يحجزُ لمشترِكِ مجموعةِ السبتِ في حصّةِ الأحد** — وهو ما تقومُ ٠٢١ كلُّها على منعِه — وباقةُ مساحةِ العملِ تحجزُ لمن ليس في أيِّ مجموعةٍ من الكورس.
- [ ] T050 [US3] اجمعْ إشعاراتِ الرفضِ **لكلِّ عمليّةِ جدولةٍ لا لكلِّ حصّة**. `GenerateSessionsFromAvailability` يستدعي `ScheduleClassSession` في حلقة، فعشرونَ حصّةً هي عشرونَ حدثاً — وثلاثونَ طالباً في مجموعةٍ ضيّقةِ السَّعةِ يعني ستّمئةَ إشعارٍ من ضغطةٍ واحدة، وهو ما يجعلُ الأهلَ يكتمونَ القناةَ فيفقدونَ تنبيهَ الغيابِ معها.

### التسويةُ والإشعارات

- [ ] T051 [US3] وسِّعْ `SessionDelivered` بـ`subscriptionSeats` إلى جانبِ `billableSeats`، واملأْه في `backend/app/Modules/LiveSessions/Actions/CloseClassSession.php:88` بسؤالِ `SubscriptionDirectory` عن حاملي المقاعد (FR-048).
- [ ] T052 [US3] سعِّرْ مقعدَ المشترِكِ على حدةٍ في `backend/app/Modules/Settlement/Actions/AccrueTeachingUnits.php:66`. **ولا تسألْ Settlement عن الاشتراكِ بنفسِه** (FR-048أ): `ContextIsolationTest:277` يُسقِطُ البناءَ على أوّلِ `use App\Modules\Payments` تحتَ `Modules/Settlement/`، والحدثُ هو الجسرُ الوحيدُ المسموح — «bridges to the rest of the product through `SessionDelivered` alone» (`:425`).
- [ ] T053 [US3] أضِفِ النوعَينِ `subscription_activated` و`subscription_seat_unavailable` إلى `backend/app/Modules/Notifications/Support/NotificationType.php`، **مع `requiredGuardianPermission()` للأوّلِ** لأنّه يُصنَّفُ `targetsGuardians()` (إذنُ المدفوعاتِ نفسُه الذي يركبُه `shipment_status_changed`). الاثنانِ يتحرّكانِ معاً أو لا يتحرّكُ أحدُهما: `RecipientResolver` لا يضمُّ الأولياءَ إلّا بوجودِهما، فواحدٌ بلا الآخرِ **يلتقطُ القناةَ الخارجيّةَ ويُحاسَبُ عليها ولا يصلُ وليَّ أمرٍ واحداً**. والثاني **لا** يستهدفُ الأولياءَ (خبرٌ تشغيليٌّ يخصُّ من يستطيعُ التصرّف).
- [ ] T054 [US3] أضِفِ الصفَّينِ إلى `backend/database/seeders/NotificationTemplateSeeder.php` بحالةِ **`pending` لا `approved`**، **وأنشئْ هجرةَ ردمٍ تستدعي `seedMissing()` في التغييرِ نفسِه**. الصفُّ يصلُ قاعدةً جديدةً بالبذرِ ولا يصلُ قاعدةً قائمةً أبداً، وإشعارٌ بلا قالبٍ **يُسقَطُ صامتاً**. `seedMissing()` (`firstOrCreate`) وحدَه من مسارِ النشر: `run()` يستعملُ `updateOrCreate` فيمسحُ كلَّ نصٍّ عدّلَه مشغّلٌ من اللوحة. **سادسُ** تطبيقٍ للآليّةِ نفسِها في هذا المستودع.
- [ ] T055 [US3] ابنِ حمولةَ `subscription_activated` (FR-029): بدءُ الاشتراكِ ونهايتُه · الجدولُ الأسبوعيُّ المعلَنُ **و**أقربُ حصّةٍ بتاريخِها وساعتِها · الرابط. وإن لم تكن هناك حصّةٌ قادمةٌ فقُلْ ذلك صراحةً (FR-029أ) — سطرٌ محذوفٌ يُقرأُ غيابُه عطلاً. ⚠️ كلُّ قراءةٍ هنا (المجموعة · جدولُها · الحصّةُ التالية) تقعُ في مستمعٍ مصفوفٍ بلا سياقِ مساحة، فتُعلِنُ `withoutWorkspaceScope()`: `null` من علاقةٍ مُنطَّقةٍ بعدَ الالتزامِ هو ٥٠٠ **بعدَ** أن ذهبَ المال — الطبقةُ الرابعةُ من عطلِ ٠٢٤.
- [ ] T056 [P] [US3] أضِفْ `‎/sessions/{uuid}/room` و`‎/schedule` و`‎/manage/sessions` إلى قائمةِ الوجهاتِ في `frontend/src/lib/notification-links.test.ts` **في التغييرِ نفسِه** — الاختبارُ يقرأُ شجرةَ المساراتِ الحقيقيّةَ، وسبعُ وجهاتٍ ميّتةٍ شُحِنتْ قبلَ أن يوجَد، منها `‎/sessions/{uuid}` بينما الموجودُ `‎/sessions/[uuid]/room`.
- [ ] T057 [P] [US3] حدِّثِ العددَ في `backend/tests/Feature/Notifications/WhatsAppDefaultsTest.php:44` من **٢٥ إلى ٢٦**. ⚠️ **اقرأِ التوكيدَ نفسَه لا هذه الجملة**: هذا الرقمُ كُتِبَ خطأً في `CLAUDE.md` مرّتَينِ من قبل.

### اختباراتُ القصّةِ الثالثة

- [ ] T058 [US3] اكتبْ `backend/tests/Feature/Payments/SubscriptionActivationTest.php` — اعتمادٌ واحدٌ يُنتِجُ اشتراكاً وتسجيلاً وعضويّةً وإشعاراً · مجموعةٌ امتلأتْ بينَ الطلبِ والاعتمادِ ⇒ **الطلبُ يبقى `pending` ولا صفَّ يُكتَب** · **التجديدُ على المجموعةِ نفسِها يمرُّ ولا يرتدُّ شيء** · نيّةُ «حصص خاصّة» تُنتِجُ تسجيلاً وإشعاراً يدلُّ على مكانِ طلبِ الحصّة.
- [ ] T059 [US3] اكتبْ `backend/tests/Feature/LiveSessions/SubscriptionSeatClaimTest.php` — حصصُ المدّةِ تُحجَزُ بلا ضغطة · **حصّةٌ داخلَ أيّامِ التمديدِ بالتجميدِ تُحجَز** (وهي التي يفقدُها من يقرأُ `ends_on`) · حصّةٌ `billable_seats` مُثبَّتٌ تُتخطّى ويُذكَرُ اسمُها · **صفٌّ `released` يُحيا وصفٌّ `cancelled_in_window` لا** · لا مقعدَينِ لطالبٍ في حصّة · مقعدُ المشترِكِ بصفرِ رصيد. ⚠️ `Queue::fake([CloseClassSessionJob::class])` **وحدَه** — التأجيلُ يعملُ فوراً على `sync` فيُغلِقُ الحصّةَ داخلَ الحجزِ نفسِه، وتزييفٌ عارٍ يبتلعُ مستمعَ الخصمِ فيصيرُ توكيدُ «صفرُ رصيد» ادّعاءً واثقاً عن جدولٍ فارغ.
- [ ] T060 [US3] أضِفْ إلى الملفِّ نفسِه حالةَ **الدفعِ قبلَ الالتزام**: فعِّلْ داخلَ معاملةٍ وتحقَّقْ أنّ المقاعدَ تُحجَزُ فعلاً. مكتوبةً بالشكلِ البديهيِّ تمرُّ فوقَ بناءٍ يرفضُ كلَّ حصّةٍ ويُبلِّغُ نجاحاً.
- [ ] T061 [P] [US3] اكتبْ حالةَ مسارِ الإسنادِ في `backend/tests/Feature/LiveSessions/` — حصّةٌ أُنشئتْ بلا مجموعةٍ ثمّ أُسنِدتْ ⇒ يُحجَزُ للمشترِكين. **سيناريو الجدولةِ وحدَه يمرُّ أخضرَ فوقَ نصفِ ميزةٍ صامتة.**
- [ ] T062 [P] [US3] أضِفْ إلى اختباراتِ التسويةِ حالةَ «عشرونَ مقعدَ اشتراكٍ لا تُنتِجُ عشرينَ مستحقّاً بالسعرِ الكامل» (FR-048)، وحالةَ أنّ المقعدَ المدفوعَ بالأرصدةِ ما زالَ يُنتِجُ مستحقَّه كما كان.

**Checkpoint**: «بدون تعقيدات» مكتملةٌ بنصفَيها — تدفعُ مرّةً، ثمّ موعدٌ ورابط.

---

## Phase 6: User Story 4 — الرفضُ وإعادةُ الرفعِ والمجموعةُ التي امتلأت (P4)

**Goal**: الحوافُّ التي تُنتِجُ تذكرةَ الدعمِ في اليومِ التالي.

**Independent Test**: ارفضْ طلباً بسببٍ مكتوب، واقرأِ السببَ من حسابِ الطالبةِ وارفعْ إيصالاً
جديداً على **الطلبِ نفسِه**. ثمّ أنهِ اشتراكاً واقرأْ أنّ مقاعدَه المستقبليّةَ حُرِّرت.

- [ ] T063 [P] [US4] أضِفْ إعادةَ رفعِ الإيصالِ على الطلبِ المرفوضِ في `frontend/src/app/(app)/(shell)/orders/` — المسارُ `POST /orders/{orderUuid}/receipt` قائمٌ ولا يُنشَأُ ثانٍ (مسارٌ ثانٍ يفقدُ الطريقةَ أو الـIP أو الـuser-agent صامتاً). أوصِلْ سببَ الرفضِ نصّاً (FR-032).
- [ ] T064 [US4] أنشئْ حدثَ نهايةِ الاشتراكِ في `backend/app/Modules/Payments/` وأطلِقْه من `ExpireSubscriptionsJob` ومن `CancelSubscription` (FR-045). لا حدثَ كهذا اليوم، و`SubscriptionAccess::close()` **يُغلِقُ التسجيلاتِ ولا يلمسُ مقعداً واحداً**.
- [ ] T065 [US4] أضِفْ `release(SessionBooking $booking, string $reason)` إلى `backend/app/Modules/LiveSessions/Actions/CancelBooking.php` — يكتبُ `BookingStatus::Released` و`is_billable = false` **ولا يسألُ موعدَ آخرِ إلغاء**. مدخلٌ مُسمّى لا مُعامِلٌ منطقيّ. تحريرٌ يقعُ داخلَ نافذةِ الإلغاءِ المتأخّرِ يُكتَبُ `cancelled_late` بـ`is_billable = true`، فيأخذُ النظامُ المقعدَ **ويُحاسِبُ عليه**.
- [ ] T066 [US4] أنشئْ `backend/app/Modules/LiveSessions/Listeners/ReleaseSeatsOnSubscriptionEnd.php` على ذلك الحدث، `ShouldQueue` + `ShouldHandleEventsAfterCommit`، يُحرِّرُ مقاعدَ الحصصِ التي لم تبدأْ بعدَ نهايةِ المدّةِ عبرَ `release()` — على مثالِ `ReleaseSeatsOnTransfer.php`. ⚠️ **لا تستعملْ `ReleaseIneligibleBookings`**: بلا مستدعٍ إنتاجيٍّ واحدٍ اليوم، ويُحرِّرُ على `allows()` التي تكذبُ أثناءَ أيِّ فترةِ تجميدٍ تغطّي الطالب — فإعلانُ عطلةٍ يُحرِّرُ مقاعدَ حصصٍ خارجَ العطلةِ كما داخلَها.
- [ ] T067 [US4] حوِّلْ `backend/app/Modules/LiveSessions/Listeners/ReleaseSeatsOnTransfer.php:63` من `CancelBooking::handle()` إلى `release()`، وأضِفْ إعادةَ الحجزِ في المجموعةِ الجديدةِ للمشترِكِ الحيِّ (FR-046). التحريرُ اليومَ يُكتَبُ **إلغاءً من الطالب**، فانتقالٌ أ ← ب ← أ يجعلُ حصصَ «أ» غيرَ قابلةٍ للحجزِ التلقائيِّ إلى الأبد.
- [ ] T068 [US4] اكتبْ `backend/tests/Feature/LiveSessions/SubscriptionSeatLifecycleTest.php` — انتهاءُ الاشتراكِ يُحرِّرُ المستقبلَ ولا يلمسُ الماضي · **التجديدُ بعدَ الانقطاعِ يُعيدُ حجزَ ما حُرِّر** · إلغاءُ الطالبةِ بنفسِها **لا** يُعادُ حجزُه · انتقالٌ أ ← ب ← أ يُعيدُ حجزَ حصصِ «أ» · مقعدٌ محرَّرٌ لا يُحاسَبُ عليه.
- [ ] T069 [P] [US4] اكتبْ حالةَ «الباقةُ سُحِبتْ أو تغيّرَ سعرُها بعدَ الطلب ⇒ يُعتمَدُ بالمبلغِ الملتَقَط» (US4·٣) — قائمةٌ في `amount_minor`، والاختبارُ يُثبِتُ أنّها ما زالت تُقرأُ من الطلبِ لا من الباقة.

**Checkpoint**: الحوافُّ مغطّاة.

---

## Phase 7: Polish & Cross-Cutting

- [ ] T070 [P] حدِّثْ `docs/README.md` بالنوعَينِ الجديدَينِ من الإشعاراتِ وبمسارِ `/subscribe` وبالعقدِ المشترَكِ الجديد.
- [ ] T071 وسِّعْ `backend/tests/Feature/Settlement/ContextIsolationTest.php` ليفحصَ `Modules/LiveSessions` و`Modules/Learning` في الاتّجاهَين — **أو** احذفِ الاستشهادَ به من `plan.md`. الاستيراداتُ الثلاثةُ القائمةُ (`ActivateSubscription.php:8` · `SubscriptionEligibility.php:8` · `Plan.php:8`) ستضيءُ حمراءَ، فالتوسيعُ قرارٌ يُتَّخَذُ بعينَينِ مفتوحتَين. **حارسٌ مذكورٌ لا يعملُ أسوأُ من غيابِه: يُنهي الجدالَ ولا يحسمُه.**
- [ ] T072 شغِّلِ البوّاباتِ دفعةً واحدةً في النهاية: `./vendor/bin/pint` ثمّ `./vendor/bin/phpstan analyse` ثمّ `php vendor/bin/pest tests/Feature/Payments tests/Feature/LiveSessions tests/Feature/Learning tests/Feature/Marketplace` — **تشغيلٌ واحدٌ لا اثنانِ متزامنان** (يتشاركانِ قرصَ الاختبارِ فيصنعانِ فشلاً كاذباً)، **ولا الحزمةُ الكاملةُ محليّاً** (التكاملُ المستمرُّ يُشغِّلُها).
- [ ] T073 شغِّلْ بوّاباتِ الواجهةِ مرّةً واحدة: `npx tsc --noEmit` ثمّ `npm test`.
- [ ] T074 نفِّذْ سيناريوهاتِ `quickstart.md` يدويّاً على قاعدةِ التطويرِ مع عاملٍ يعمل، وحدِّثِ الوثيقةَ إن اختلفَ شيء. ⚠️ **أعِدْ تشغيلَ `queue:work` بعدَ كلِّ تغييرٍ في الشيفرة**: العاملُ يحملُ الشيفرةَ التي أقلعَ بها، وهكذا يظلُّ عطلٌ مُصلَحٌ يُبلِّغُ عن نفسِه.
- [ ] T075 نفِّذْ قائمةَ ما بعدَ النشرِ في `quickstart.md` — **ومنها التحقّقُ من صفَّي القالبِ بـ`IN ('subscription_activated','subscription_seat_unavailable')` لا بـ`LIKE 'subscription_%'`**: النمطُ يطابقُ `subscription_expiring` القائمَ من ٠١١، فيقرأُ ٣ على قاعدةٍ سليمةٍ و١ على قاعدةٍ معطوبة.

---

## Dependencies & Execution Order

### تبعيّاتُ المراحل

- **Phase 1 (Setup)**: بلا تبعيّة.
- **Phase 2 (Foundational)**: بعدَ Setup — **يحجُبُ كلَّ القصص**.
- **Phase 3 (US1 · P1)**: بعدَ Foundational. مستقلّةٌ تماماً — تُنتِجُ قيمةً كاملةً وحدَها.
- **Phase 4 (US2 · P2)**: بعدَ Foundational. تقرأُ لقطةَ US1 لكنّها تُختبَرُ بصفٍّ مزروعٍ يدويّاً.
- **Phase 5 (US3 · P3)**: بعدَ Foundational. تحتاجُ طلباً معتمَداً — يُزرَعُ في التجهيزةِ إن لم تُبنَ US1 بعد.
- **Phase 6 (US4 · P4)**: بعدَ US3 (تُحرِّرُ ما حجزتْه US3).
- **Phase 7**: بعدَ كلِّ ما يُرادُ شحنُه.

### داخلَ القصص

- العقودُ قبلَ منفِّذيها · الأفعالُ قبلَ المتحكّماتِ والشاشات · الاختباراتُ مجمَّعةً في النهاية.
- **T040 قبلَ T041**: الفحصُ التمهيديُّ يجعلُ الرفضَ في المستمعِ حالةً نادرةً لا عاديّة.
- **T044 قبلَ T045 وT046**: الفرزُ والتخطّي يقعانِ داخلَ الفعلِ نفسِه.
- **T048 قبلَ T049**: لا مستمعَ بلا حدث.
- **T051 قبلَ T052**: لا تسعيرَ بلا عدد.
- **T065 قبلَ T066 وT067**: كلاهما يستدعي `release()`.

### ما يتوازى

- T001 · T002 معاً.
- T003 · T004 معاً؛ ثمّ T005 · T006 · T007 · T008 معاً (أربعةُ ملفّاتٍ متباعدة).
- T009 · T019 · T020 · T021 معاً (خادمٌ وواجهةٌ لا تتلامسان).
- T025…T029 معاً.
- T047 · T056 · T057 معاً.
- T061 · T062 معاً.
- القصصُ الأربعُ تتوازى بعدَ Foundational إن تعدّدَ المنفِّذون، عدا US4 التي تتبعُ US3.

---

## Implementation Strategy

### MVP أوّلاً (US1 وحدَها)

١) Phase 1 → ٢) Phase 2 → ٣) Phase 3 → ٤) **قِفْ وتحقَّقْ**: طالبةٌ بلا تسجيلٍ ولا رصيدٍ
تُنشئُ طلباً معلَّقاً بإيصالٍ ونيّة. الحلقةُ المغلَقةُ مفتوحة، والموظّفُ يعتمدُ من
`/admin/orders` القائمةِ حتّى قبلَ US2. هذا وحدَه يستحقُّ الشحن.

### تسليمٌ متدرّج

US1 (الطالبُ يستطيعُ الدفعَ) ← US2 (الموظّفُ يرى ويقرّر) ← US3 (القبولُ يفعلُ كلَّ شيء) ←
US4 (الحواف). كلُّ واحدةٍ تضيفُ قيمةً بلا كسرِ سابقتِها.

---

## Notes

- `[P]` = ملفّاتٌ مختلفةٌ بلا تبعيّة.
- التزِمْ بعدَ كلِّ مهمّةٍ أو مجموعةٍ متماسكة.
- **لا `lockForUpdate()` في أيِّ مهمّةٍ هنا**: عديمُ الأثرِ على SQLite، فالاختبارُ يمرُّ محليّاً
  ولا يُثبتُ شيئاً عن MySQL الذي يُشحَنُ إليه.
- **كلُّ اختبارٍ لشيءٍ يواجهُ الطالبَ يتركُ `last_workspace_id` فارغاً ويُعيدُ ضبطَ مفردِ
  السياق**، وكلُّ اختبارٍ لقراءةٍ أو كتابةٍ منصّيّةٍ يحتاجُ **مساحتَي عملٍ وموظّفاً يملكُ
  إحداهما**.
- **لا مُعامِلَ منطقيّاً على فعلٍ قائمٍ في أيِّ مهمّة**: مدخلٌ مُسمّى أو لا شيء.
