# Tasks: صفحةُ الكورسِ ومجموعاتُه وطلبُ الحصّةِ الخاصّة

**Input**: `specs/023-course-detail-booking/` — plan.md · spec.md · research.md · data-model.md · contracts/ · quickstart.md

**Tests**: مطلوبةٌ صراحةً. الوثيقةُ تحملُ عشرةَ معاييرِ نجاحٍ مقيسة، وثلاثةَ أفخاخٍ لكلٍّ منها اختبارٌ يحمرُّ (`quickstart.md`).

## Format: `[ID] [P?] [Story] الوصف`

- **[P]**: يجري بالتوازي — ملفٌّ مختلفٌ ولا يعتمدُ على مهمّةٍ غيرِ منجَزة
- **[Story]**: US1 · US2 · US3

---

## ⚠️ تصحيحاتٌ على الخطّةِ قِيسَت قبلَ كتابةِ هذه المهامّ

| ما قالته `plan.md` | ما تقولُه الشجرة |
|---|---|
| «`CohortDirectory` عقدٌ **جديد**» | **قائمٌ منذ ٠٢١** في `app/Shared/Contracts/CohortDirectory.php` بتسعِ طرائق، وتنفيذُه `Learning/Support/EloquentCohortDirectory.php`. يُوسَّعُ ولا يُنشَأ |
| `frontend/src/app/(public)/courses/[slug]/` | **`[uuid]`** — القياسُ حسمَه (`research.md` · ما لم يُقَسْ ٢) |
| `Cohort::$status` يُكتَبُ مع الإنشاء | **ليس `$fillable`** عن قصد (يتحرّكُ عبرَ `ArchiveCohort` وحدَها) — فإنشاءُ المجموعةِ الفرديّةِ مغلقةً يحتاجُ إسناداً صريحاً لا مصفوفةَ `create()` |
| «`PublicCohortResource` صفٌّ جديد» | **لا صفّ**: المجموعاتُ شكلٌ متداخلٌ داخلَ حمولةِ الكورس، كـ`subject` و`teacher` — دالّةٌ خاصّةٌ لا Resource. والدمجُ في المتحكّمِ كما يدمجُ `teacher()` كورساتِه وتقييماتِه |
| «`schedule` كائناتُ (يوم، بداية، نهاية)» | **قائمةُ نصوص** من `CohortScheduleDirectory` القائمِ منذ ٠٢١ — اشتقاقٌ ثانٍ يتباعدُ عن منتقي الطالب |
| «`pending_key` قابلٌ لـNULL يحملُ `student_user_id:starts_at`» | **`pending_slot`** — نفسُ حارسِ `closed_slot`/`cohort_transfer_requests` القائمِ منذُ ٠٢١: صفرٌ ما دامَ الطلبُ قائماً ومعرّفُ الصفِّ بعدَ البتّ. الحارسُ واحدٌ، والنسخةُ النصّيّةُ تكرارٌ مُسلسَلٌ لعمودَينِ حاضرَين. واسمُ الفهرسِ صريحٌ — المُولَّدُ ٧١ حرفاً فوقَ سقفِ MySQL |
| «الحصّةُ الفرديّةُ تنتمي إلى مجموعةٍ بمقعدٍ واحد» ⇒ عضويّةٌ فيها | **لا صفَّ عضويّةٍ إطلاقاً.** `cohort_memberships` يحملُ `unique(student_user_id, course_id, closed_slot)` — عضويّةٌ مفتوحةٌ **واحدةٌ** لكلِّ (طالب × كورس) — ففتحُ واحدةٍ هنا **يُغلِقُ مجموعةَ السبتِ** ويسلبُ الطالبَ صفَّه الأسبوعيَّ مقابلَ ساعةٍ خاصّة. الحصّةُ تصلُ صاحبَها بحجزِه |
| `DecidePrivateSessionRequest` يُنشئُ المجموعةَ بنفسِه | **عبرَ `CohortDirectory::ensureIndividualCohort()`.** `Cohort` نموذجُ Learning، و`CohortSessionVisibility` مكتوبٌ فيها أنّ LiveSessions لا تستوردُه — واستعارةُ وحدةٍ لنموذجِ أخرى مرّةً واحدةً هي كيفَ يتوقّفُ الحدُّ عن كونِه حدّاً |
| صفحةُ الكورسِ تحملُ فتراتِ التوفّر | **تُقرَأُ من `/marketplace/teachers/{key}`** المنشورِ منذُ ٠٠١. نسخةٌ ثانيةٌ على حمولةِ الكورسِ جوابٌ ثانٍ لـ«متى هو متاح؟» يتباعدُ عن صفحةِ المدرّسِ أوّلَ تعديل |
| — | **و`private_session_minutes` أُضيفَ إلى `COURSE_DETAIL`**: مدّةٌ لا سعر، والنموذجُ يقرؤها بلا منفذٍ سادس |
| — | **`errorCode()` يقرأُ الجسمَ لا الخطأ.** تمريرُ `ApiError` إليها يُجيبُ null، فيصيرُ كلُّ رفضٍ «مسجَّل» ويُعرَضُ زرٌّ يُرفَضُ عندَ الضغط — عينُ ما يمنعُه `FR-015أ` |

---

## Phase 1: Setup

- [x] T001 وسّعْ `backend/database/seeders/DemoDataSeeder.php` بتركيبةِ `quickstart.md`: كورسٌ منشورٌ لمدرّسٍ معتمَدٍ في مساحةٍ مشارِكة، ثلاثُ مجموعات (مفتوحةٌ بمقاعد · ممتلئة · مؤرشَفة)، فتراتُ توفّرٍ معلَنة، وطالبٌ مسجَّلٌ برصيدٍ كافٍ — **ويُترَكُ `users.last_workspace_id` فارغاً لطالبِ الاختبار**، وإلّا كانت كلُّ تجربةٍ تقيسُ شخصاً لا وجودَ له في الإنتاج

---

## Phase 2: Foundational (يسبقُ US2 و US3)

- [x] T002 هجرةُ `cohorts.individual_for_user_id` — `bigint nullable` + `unique(course_id, individual_for_user_id)` في `backend/app/Modules/Learning/Database/Migrations/` (**بحرفِ M كبير**: التطابقُ حرفيٌّ ويُحمّلُ صفرَ هجرةٍ على لينكس عندَ اختلافِ الحالة)
- [x] T003 `backend/app/Modules/Learning/Models/Cohort.php` — أضِفِ العمودَ إلى `$fillable` وإلى `@property`، ونطاقَي `scopeGroup()` (`whereNull`) و`scopeIndividual()`
- [x] T004 [P] `backend/tests/Feature/Learning/IndividualCohortIndexTest.php` — الفهرسُ **يعضُّ** على مجموعةٍ فرديّةٍ ثانيةٍ لنفسِ (طالب × كورس)، و**لا يعضُّ** على خمسِ مجموعاتٍ جماعيّةٍ في الكورسِ نفسِه

---

## Phase 3: US1 — الكورسُ له صفحةٌ يفتحُها الزائر (P1) 🎯 MVP

**الهدف**: عنوانٌ عامٌّ للكورسِ يُفتَحُ بضغطةٍ واحدةٍ من البطاقة، يُصيَّرُ على الخادم.

**الاختبارُ المستقلّ**: اضغطْ بطاقةَ كورسٍ من `/courses` — العنوانُ الذي تصلُ إليه عنوانُ الكورسِ لا عنوانُ المدرّس، وكلُّ ما عليه يخصُّ هذا الكورس.

### الخادم

- [x] T005 [P] [US1] `backend/app/Modules/Marketplace/Support/PublicFieldAllowlist.php` — ثابتا `COURSE_DETAIL` و`COHORT` كما في `data-model.md` §٥
- [x] T006 [US1] `backend/app/Modules/Marketplace/Actions/Public/ReadPublicCourse.php` — يبدأُ من `publiclyListed()`، ويستعيرُ محدِّدَ «المنشور» من شجرةِ الطالبِ المسجَّلِ ولا يكتبُ محدِّداً ثانياً (`research.md` · قرار ٢)
- [x] T007 [P] [US1] `backend/app/Modules/Marketplace/Http/Resources/PublicCourseResource.php` — **صفرُ `id` تسلسليّ · صفرُ `uuid` لعنصرِ منهج · صفرُ مسارِ وسائط · صفرُ مبلغ**
- [x] T008 [US1] `GET /marketplace/courses/{uuid}` في `backend/app/Modules/Marketplace/routes/api.php` بـ`throttle:public` (**محدِّدٌ مُسمّىً لا `throttle:60,1` سطريّ**) + `course()` في `Http/Controllers/PublicMarketplaceController.php`
- [x] T009 [US1] `backend/tests/Feature/Marketplace/PublicExposureTest.php` — حالةُ حمولةِ الكورس؛ البناءُ يسقطُ عندَ حقلٍ غيرِ مُدرَج
- [x] T010 [P] [US1] `backend/tests/Feature/Marketplace/PublicCourseNotFoundTest.php` — أربعُ حالات (مسوّدة · مدرّسٌ غيرُ معتمَد · مساحةٌ منسحبة · uuid مخترَع) **بجسمِ ردٍّ متطابقٍ حرفيّاً**
- [x] T011 [US1] `backend/tests/Feature/Marketplace/QueryBudgetTest.php` — سقفٌ ثابتٌ لا ينمو بعددِ الدروس، **مع توكيدِ حضورِ كلِّ حقل**: إسقاطُ تحميلٍ مسبقٍ مع `whenLoaded` يُنتِجُ صفحةً أرخصَ بحقلٍ غائب، فيُقرَأُ التراجعُ تحسُّناً

### الواجهة

- [x] T012 [US1] `frontend/src/app/(public)/courses/[uuid]/page.tsx` — تصييرٌ على الخادم، `generateMetadata` بعنوانٍ ووصفٍ ورابطٍ قانونيّ، وحالةُ خطأٍ عبرَ `userMessage()` **لا `.catch(() => undefined)`**
- [x] T013 [P] [US1] `frontend/src/components/marketplace/CourseCurriculum.tsx` — عناوينُ وأنواعٌ ومُدَدٌ، بلا رابطٍ إلى أيِّ درس
- [x] T014 [US1] `frontend/src/components/marketplace/CourseCard.tsx` — **الرابطانِ معاً** (الصورةُ والعنوان) إلى `/courses/{uuid}`؛ وامشِ كلَّ موضعٍ يُصيَّرُ فيه (الرئيسيّة · `/courses` · تبويبُ كورساتِ المدرّس)
- [x] T015 [P] [US1] `frontend/src/components/marketplace/CourseCard.test.tsx` — الـ`href` هو عنوانُ الكورس، مقيساً على العنصرَين
- [x] T016 [US1] `frontend/e2e/course-detail.spec.ts` — بطاقة ← صفحةُ الكورسِ بضغطةٍ **واحدة** (SC-001)، مع `test.use({ storageState: { cookies: [], origins: [] } })` وإلّا اختبرَ مستخدماً مسجَّلاً وهو يظنُّ نفسَه زائراً

---

## Phase 4: US2 — المجموعاتُ المتاحةُ ومقاعدُها (P2)

**الهدف**: الزائرُ يعرفُ متى تُعطى المادّةُ وكم بقيَ من مقعدٍ بلا حساب.

**الاختبارُ المستقلّ**: ثلاثُ مجموعاتٍ بثلاثِ حالات ⇒ الصفحةُ تعرضُ الحالاتِ بأسمائها الصحيحة، والمؤرشَفةُ غائبة.

- [x] T017 [US2] وسّعْ `backend/app/Shared/Contracts/CohortDirectory.php` وتنفيذَه `backend/app/Modules/Learning/Support/EloquentCohortDirectory.php` بقراءةٍ عامّةٍ لمجموعاتِ كورس — **تُصفّى بـ`individual_for_user_id IS NULL`** لا بالحالة (`data-model.md` §٢)
- [x] T018 [P] [US2] `backend/app/Modules/Marketplace/Http/Resources/PublicCohortResource.php` — الحالةُ من ثلاثٍ، و`seats_left` **غائبٌ لا صفرٌ** حينَ لا سَعة، **وصفرُ حقلٍ عن الأعضاء**
- [x] T019 [US2] أدخِلِ المجموعاتِ في حمولةِ `backend/app/Modules/Marketplace/Actions/Public/ReadPublicCourse.php` نفسِها — لا منفذَ ثانٍ (`research.md` · قرار ١)
- [x] T020 [US2] `backend/tests/Feature/Marketplace/PublicCohortsTest.php` — الحالاتُ الثلاث · المؤرشَفةُ غائبة · بلا سَعةٍ ⇒ لا مفتاحَ `seats_left` · صفرُ حقلٍ عن الأعضاء
- [x] T021 [US2] ⚠️ **الفخّ ٣** — في `PublicCohortsTest.php`: افتحِ المجموعةَ الفرديّةَ عمداً (`status = open`) ثمّ توكّدْ أنّها **غائبةٌ عن الحمولة**. تركُها مغلقةً يُصادِقُ على تصفيةٍ بالحالةِ وهو يظنُّ أنّه قاسَ تصفيةً بالمالك
- [x] T022 [US2] `frontend/src/components/marketplace/CohortList.tsx` — ألوانٌ من `@theme` وحدَها (**لا `bg-success`: لا وجودَ لهذا الرمز، ويُطلى شيءٌ لا شيء**)، وجملةٌ صريحةٌ حينَ لا مجموعات
- [x] T023 [P] [US2] `frontend/src/components/marketplace/CohortList.test.tsx` — الحالاتُ الثلاثُ بألفاظِها، وغيابُ الرقمِ عندَ غيابِ السَّعة

---

## Phase 5: US3 — طلبُ حصّةٍ خاصّةٍ من جدولِ المدرّس (P3)

**الهدف**: طلبٌ يُرسَلُ ويُبَتُّ فيه إنسانٌ، فتُنشَأُ حصّةٌ فرديّةٌ داخلَ مجموعةِ الطالبِ في الكورس.

**الاختبارُ المستقلّ**: أرسِلْ ثمّ اقبلْ ⇒ أربعةُ آثارٍ كلٌّ منها **واحد**؛ ثمّ اقبلْ ثانياً ⇒ المجموعةُ **لم تتكرّر**.

### البياناتُ والأعمدة

- [x] T024 [US3] هجرةُ `courses.private_session_minutes` (`unsignedSmallInteger nullable`) في `backend/app/Modules/Courses/Database/Migrations/` **وإضافتُه إلى `$fillable` في `backend/app/Modules/Courses/Models/Course.php` في نفسِ التغيير**
- [x] T025 [P] [US3] ⚠️ **الفخّ ٢** — `backend/tests/Feature/Courses/PrivateSessionMinutesTest.php`: اكتبِ القيمةَ عبرَ المسارِ الحقيقيِّ ثمّ **اقرأْها من القاعدة** (`refresh()`). توكيدٌ على جسمِ الردِّ يمرُّ مهما كان — الردُّ يُصدِّرُ ما أُرسِلَ لا ما خُزِّن
- [x] T026 [US3] هجرةُ `private_session_requests` في `backend/app/Modules/LiveSessions/Database/Migrations/` بأعمدةِ `data-model.md` §١، و**`pending_key` بدلَ الفهرسِ الجزئيّ** (موجودٌ في SQLite ومعدومٌ في MySQL — الصيغةُ الخاطئةُ تمرُّ خضراءَ محلّيّاً وتُسقِطُ الهجرةَ على الإنتاج) + `unique(class_session_id)` + `index(teacher_profile_id, status)` + `index(status, expires_at)`
- [x] T027 [US3] `backend/app/Modules/LiveSessions/Models/PrivateSessionRequest.php` — `HasUuid`، تحويلاتٌ للتواريخِ والحالة، **و`pending_key` خارجَ `$fillable`** (يُكتَبُ داخلَ الـAction وحدَها، اصطلاحُ `captured_order_id`)
- [x] T028 [P] [US3] مفتاحا `PlatformSettings` — مهلةُ الطلبِ وحدُّ الطلباتِ القائمة (`FR-022ب`) — في `backend/app/Modules/Tenancy/Support/PlatformSettings.php` **مع مدخلِ `config/` مقابلٍ لكلٍّ**: مسارٌ بلا ملفٍّ يُرجِعُ `null` ويُسقِطُ البذرَ على الإنتاج بـ`Column 'value' cannot be null`، والحارسُ القائمُ في `PlatformSettingsTest` يمشي كلَّ مدخل

### الأفعال

- [x] T029 [US3] `backend/app/Modules/LiveSessions/Actions/RequestPrivateSession.php` — يسألُ `EnrollmentDirectory` صراحةً (`FR-015`)، ويفحصُ وقوعَ **المدّةِ كاملةً** داخلَ فترةِ توفّرٍ معلَنةٍ لا البدايةَ وحدَها (`FR-016ب`)، ويفرضُ حدَّ الثلاثةِ بـ`INSERT … SELECT … WHERE (SELECT COUNT(*) …) < :limit` — **عبارةٌ واحدة**، فـ`count()` ثمّ `insert()` هو تعريفُ السباق
- [x] T030 [P] [US3] `backend/app/Modules/LiveSessions/Http/Requests/RequestPrivateSessionRequest.php` + مدخلاتُ `attributes` في `backend/lang/ar/` — وإلّا صُيِّرَ `starts_at` كما هو أمامَ الطالب
- [x] T031 [US3] `backend/app/Modules/LiveSessions/Actions/DecidePrivateSessionRequest.php` — البتُّ كتابةٌ شرطيّةٌ (`UPDATE … WHERE status = 'pending'`)، ثمّ المجموعةُ الفرديّةُ (الفهرسُ الفريدُ هو حارسُ التكرار، لا قراءةٌ ثمّ كتابة، و**`status` يُسنَدُ صراحةً لأنّه ليس `$fillable`**)، ثمّ الحصّةُ `individual` بمقعدٍ واحد، ثمّ المقعد — كلُّه في معاملةٍ واحدة (`FR-020`)، و`pending_key` يُصفَّرُ إلى `NULL`
- [x] T032 [US3] `backend/app/Modules/LiveSessions/Actions/BookSeat.php` — مدخلٌ ثانٍ **مُسمّىً** (`claimGrantedSeat()`) يسألُ `refusalReason()` دونَ `openingRefusal()`، ويتقاسمُ المطالبةَ الذَّرّيّةَ نفسَها. **لا معاملَ منطقيّ** (يُقلَبُ خطأً في أوّلِ نداءٍ بعدَه) **ولا نسخةَ ثانيةً من المطالبة** (`research.md` · قرار ٨)
- [x] T033 [US3] `backend/app/Modules/LiveSessions/Actions/WithdrawPrivateSessionRequest.php` — سحبٌ ما دامَ `pending`، و`409` بعدَ البتّ
- [x] T034 [US3] `backend/app/Modules/LiveSessions/Jobs/ExpirePrivateSessionRequestsJob.php` + جدولتُه في `backend/routes/console.php` بـ`WithoutOverlapping` **وسيطاً على الوظيفةِ** مع `expireAfter()` — الوسيطُ وحدَه قفلٌ لا ينتهي، فعاملٌ يُقتَلُ يُوقِفُ المهلةَ إلى الأبد

### التفويضُ والإشعارات

- [x] T035 [US3] `backend/app/Modules/LiveSessions/Policies/PrivateSessionRequestPolicy.php` **وتسجيلُها بـ`Gate::policy()`** — مُخمِّنُ لارافيل يفشلُ **مفتوحاً** حينَ لا تُسجَّل
- [x] T036 [US3] أربعةُ أنواعٍ في `backend/app/Modules/Notifications/Support/NotificationType.php` + صفوفٌ في `backend/database/seeders/NotificationTemplateSeeder.php` + **هجرةُ ردمٍ تنادي `seedMissing()` في نفسِ التغيير** — إشعارٌ بلا قالبٍ يُسقَطُ بصمت، والاختباراتُ خضراءُ لأنّ `tests/Pest.php` تبذرُ القوالبَ قبلَ كلِّ حالة. **ولا واحدٌ منها يستهدفُ وليَّ الأمر**، فلا `requiredGuardianPermission()`
- [x] T037 [P] [US3] أحداثٌ ومستمعون في `backend/app/Modules/LiveSessions/Events/` و`Listeners/` — `Event::listen()` في مزوّدِ الوحدة، والإشعارُ بنوعِه عبرَ `DispatchNotification` وحدَها

### المنافذ

- [x] T038 [US3] `backend/app/Modules/LiveSessions/Http/Controllers/PrivateSessionRequestController.php` + المساراتُ الخمسةُ في `routes/api.php` **داخلَ مجموعةِ `api`** (فيها `EnsureCurrentWorkspace`، وهي ما يدفعُ معرّفَ الفريقِ إلى spatie)، والقائمةُ تُصفّى **صراحةً** بالمالكِ أو بـ`teacher_profile_id` — **لا رَبْطَ نموذجٍ ضمنيّ**
- [x] T039 [P] [US3] `backend/app/Modules/LiveSessions/Http/Resources/PrivateSessionRequestResource.php` — صفرُ مبلغ (`FR-028`)

### الاختبارات

- [x] T040 [US3] ⚠️ **الفخّ ١** — `backend/tests/Feature/LiveSessions/PrivateSessionRequestUniquenessTest.php` بثلاثِ خطوات: طلبٌ ⇒ `201` · ثانٍ على نفسِ اللحظةِ ⇒ `422` · **ارفضِ الأوّلَ ثمّ اطلبْ نفسَ اللحظةِ ⇒ `201`**. بلا الثالثةِ يمرُّ على بناءٍ لا يُصفّرُ `pending_key` إطلاقاً، فيصيرُ كلُّ موعدٍ رُفِضَ فيه طلبٌ محجوزاً على الطالبِ إلى الأبد
- [x] T041 [US3] `backend/tests/Feature/LiveSessions/PrivateSessionAcceptTest.php` — SC-006: أربعةُ آثارٍ كلٌّ منها **واحد**، وقبولٌ ثانٍ ⇒ المجموعةُ لم تتكرّرْ (SC-006أ)
- [x] T042 [US3] `backend/tests/Feature/LiveSessions/PrivateSessionConcurrencyTest.php` — SC-007 بردِّ نداءٍ **في منتصفِ النافذة** بينَ القراءةِ والكتابة. اختبارٌ متسلسلٌ («اقبلْ مرّتَين») يمرُّ على بناءٍ **لا مطالبةَ فيه إطلاقاً**، لأنّ النداءَ الثاني يرتدُّ من سطرٍ أعلى
- [x] T043 [US3] `backend/tests/Feature/LiveSessions/PrivateSessionUnlockGateTest.php` — **الاتّجاهانِ معاً**: طالبٌ متأخّرٌ في واجبٍ **يُقبَلُ** طلبُه الخاصّ، و**يُرفَضُ** حجزُه الجماعيُّ العاديّ. الأوّلُ وحدَه يمرُّ على بناءٍ ألغى بوّابةَ الواجبِ من المنتَجِ كلِّه
- [x] T044 [US3] `backend/tests/Feature/LiveSessions/PrivateSessionEligibilityTest.php` — الرفضُ عندَ **الإرسال** لا عندَ القبول (`FR-025`) · حدُّ الثلاثةِ ويُفرَجُ عنه فورَ البتّ · وقتٌ يبدأُ داخلَ الفترةِ وينتهي خارجَها ⇒ `422` · رصيدٌ نزلَ بينَ الإرسالِ والقبولِ ⇒ `422` بلا قيدٍ ناقص
- [x] T045 [US3] حالةٌ في `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — مدرّسٌ لا يقرأُ طلباً في مساحةٍ أخرى؛ **وحالةٌ يقرأُ فيها الطالبُ طلبَه و`last_workspace_id` فارغٌ والسياقُ مُصفَّر**، وإلّا كان الاختبارُ يقيسُ شخصاً لا وجودَ له في الإنتاج
- [x] T046 [P] [US3] `backend/tests/Feature/LiveSessions/PrivateSessionExpiryTest.php` — الانتهاءُ يُخبِرُ الطالبَ، ولا يُعادُ إخبارُه، ولا يمسُّ رصيداً (SC-005)
- [x] T047 [P] [US3] مغادرةُ المدرّسِ تُنهي طلباتِه القائمة (`FR-026`) — مستمعٌ على حدثِ الإخلاءِ القائمِ في `backend/app/Modules/Community/`

### الواجهة

- [x] T048 [US3] `frontend/src/components/courses/PrivateSessionRequestForm.tsx` — المدّةُ **تُقرَأُ ولا تُرسَل**، وغيرُ المسجَّلِ يقرأُ دعوةً إلى التسجيلِ لا زرّاً يُضغَطُ ثمّ يُرفَض (`FR-015أ`)
- [x] T049 [US3] شاشةُ واردِ المدرّسِ في `frontend/src/app/(app)/(shell)/manage/` **ورابطٌ إليها من قائمةِ التنقّل** — سطحٌ لا يصلُه شيءٌ سطحٌ غيرُ مُسلَّم، والصلاحيّةُ التي لا يُشارُ إليها صلاحيّةٌ لا يملكُها أحد
- [x] T050 [P] [US3] `frontend/src/components/courses/PrivateSessionRequestForm.test.tsx` — الحقولُ المرسَلةُ حقلانِ لا ثلاثة، وكلُّ رفضٍ جملةٌ عربيّةٌ عبرَ `fieldErrors()`/`userMessage()`

---

## Phase 6: Polish

- [x] T051 [P] `backend/tests/Feature/Learning/ProgressDenominatorTest.php` — نشرُ تسجيلِ حصّةٍ خاصّةٍ **لا يُحرّكُ نسبةَ إنجازِ أحد** (`FR-019ب`). الخاصيّةُ قائمةٌ بالبناءِ (`progressEligible()` تُسقِطُ أيَّ درسٍ يحملُ `class_session_id`) — وتبقى **صدفةً حتّى يُثبِتَها اختبار**
- [x] T052 [P] جداولُ الوحداتِ والمنافذِ والأذونِ في `docs/README.md` والمخطّطُ في `docs/erd.md`
- [x] T053 البوّاباتُ الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit && npm test`
- [ ] T054 `cd frontend && npx playwright test` بـ`PHP_CLI_SERVER_WORKERS=8 php artisan serve` — الخيطُ الواحدُ يُسقِطُ **البناءَ** قبلَ اختبارٍ واحد

---

## التبعيّات

```
Setup (T001)
   └─ Foundational (T002–T004)
         ├─ US1 (T005–T016)  ← مستقلّةٌ تماماً · MVP
         ├─ US2 (T017–T023)  ← تحتاجُ T006 (الحمولة) و T002 (العمود)
         └─ US3 (T024–T050)  ← تحتاجُ T002؛ ولا تحتاجُ US1 ولا US2
                └─ Polish (T051–T054)
```

**US1 وحدَها منتَجٌ صالح**: صفحةٌ تُفتَحُ من بطاقةٍ وتُشارَكُ برابطٍ وتُفهرِسُها محرّكاتُ البحث. الحدُّ الطبيعيُّ للتسليمِ على مراحلَ بينَ US2 و US3، لا داخلَ أيٍّ منهما.

## ما يجري بالتوازي

| الدفعة | المهامّ |
|---|---|
| بعدَ T002 | T004 · T005 · T007 · T010 · T013 · T015 |
| داخلَ US2 | T018 · T023 |
| داخلَ US3 | T025 · T028 · T030 · T037 · T039 · T046 · T047 · T050 |
| Polish | T051 · T052 |

**ولا تُشغِّلْ حزمتَي pest في وقتٍ واحد** — تتقاسمانِ قرصَ الاختبارِ فتُنتِجانِ فشلاً مصطنَعاً.
