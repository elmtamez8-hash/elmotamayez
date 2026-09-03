---
description: "Task list for 018 — الفيديو الترويجي للكورس"
---

# Tasks: الفيديو الترويجي للكورس (Course Promo Video)

**Input**: `/specs/018-public-broadcast/` — plan.md · spec.md · research.md · data-model.md · contracts/ · quickstart.md

**Tests**: مطلوبةٌ هنا. السبيك يحمل ثمانية `SC-` قابلةً للقياس، وثلاثة منها (‏SC-002 التضمين ·
SC-004 المراجعة · SC-005 صفر بايت) لا يثبتها إلّا اختبار.

**Organization**: مجمَّعةٌ بالقصص. **US1 قابلةٌ للشحن وحدها** بصفٍّ يُدخَل من `/admin` — وهذا
نصُّ السبيك: «لا تمنع القصة الأولى من العمل برابطٍ يُدخَل من لوحة الإدارة».

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يمكن التوازي — ملفّاتٌ مختلفة، بلا اعتمادٍ على مهمّةٍ غير مكتملة
- **[Story]**: US1 · US2 — والمراحلُ المشتركة بلا وسم

## Path Conventions

`backend/app/Modules/…` · `backend/tests/Feature/…` · `frontend/src/…` — حسب `plan.md`.

---

## Phase 1: Setup

**Purpose**: العمود الفقري للبيانات. مهمّةٌ واحدة — لا وحدة جديدة ولا مزوّد ولا مدخل في
`phpstan.neon` (‏لا مجلّد هجراتٍ جديد).

- [X] T001 أنشئ هجرة `backend/app/Modules/Courses/Database/Migrations/2026_09_03_000100_add_promo_video_to_courses.php` تضيف إلى `courses`: `promo_video_id` (string 32, nullable) · `promo_video_status` (string 16, NOT NULL, default `'none'`) · `promo_video_reviewed_at` (timestamp nullable) · `promo_video_reviewed_by` (foreignId nullable → `users.id`, `nullOnDelete`). بلا فهارس وبلا ردم — `null` و`'none'` هما الحالة الصحيحة لكلّ صفٍّ قائم. المجلّد `Database/Migrations` بحرف M كبير بالضبط.

**Checkpoint**: `php artisan migrate` يمرّ على قاعدةٍ فيها بيانات — **لا `migrate:fresh`**.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: لا تبدأ أيّ قصّة قبل اكتمال هذه المرحلة.

- [X] T002 في `backend/app/Modules/Courses/Models/Course.php`: أضف `promo_video_id` **وحده** إلى `$fillable`، و`'promo_video_reviewed_at' => 'datetime'` إلى `$casts`، وخصائص `@property` للأعمدة الأربعة. ⚠️ `promo_video_status` و`reviewed_at` و`reviewed_by` تبقى **خارج** `$fillable` — يكتبها الإجراءان وحدهما (سابقة `captured_order_id`)؛ وعمودٌ يُكتب من حمولةٍ وغاب عن `$fillable` يُهمَل **بصمت** (سابقة ٠١٣: `201` وثلاثة `null`).
- [X] T003 في `backend/app/Modules/Courses/Models/Course.php`: أضف `hasApprovedPromoVideo(): bool` = `promo_video_status === 'approved' && promo_video_id !== null`. **التهجئة الوحيدة** لشرط الظهور العامّ؛ يقرؤها المورد العامّ ولوحة المدرّس معاً. الشرطان معاً لا أحدهما (data-model).
- [X] T004 [P] في `backend/database/factories/Modules/Courses/CourseFactory.php`: أضف حالة `withApprovedPromoVideo()` تكتب مُعرِّفاً و`'approved'`. تُستعمَل في كل اختبارات US1، وبدونها كلّ تجهيزةٍ تكرّر الأعمدة الأربعة يدوياً.

**Checkpoint**: الأساس جاهز — US1 وUS2 يمكن أن تتقدّما بالتوازي.

---

## Phase 3: User Story 1 — سارة ترى الأستاذ خالد قبل أن تدفع (P1) 🎯 MVP

**Goal**: كورسٌ منشورٌ يحمل مُعرِّفاً معتمَداً يعرض زرّاً في صفحته العامّة، والزرّ يكشف الإطار.

**Independent Test**: اكتب المُعرِّف والحالة على صفٍّ مباشرةً (‏`/admin` أو `tinker`)، ثمّ افتح
`/courses/{uuid}` في نافذةٍ خاصّة. الزرّ يظهر، والضغط يكشف الإطار. **لا حاجة إلى US2 إطلاقاً.**

### الخلفية

- [X] T005 [US1] في `backend/app/Modules/Marketplace/Http/Resources/PublicCourseDetailResource.php`: أضف مفتاح `'promo_video_id'` يرجع `$this->hasApprovedPromoVideo() ? $this->promo_video_id : null`. مكتوبٌ باليد كبقيّة المفاتيح — **لا `parent::toArray()`**. و`null` لا يفرّق بين «لا فيديو» و«ينتظر المراجعة».
- [X] T006 [US1] في `backend/app/Modules/Marketplace/Support/PublicFieldAllowlist.php`: أضف `'promo_video_id'` إلى `COURSE_DETAIL` **في التغيير نفسه** كـT005. مفتاحٌ بلا توأمه يُسقط `PublicExposureTest` — وهي البوابة المقصودة.
- [X] T007 [US1] اختبار في `backend/tests/Feature/Marketplace/PublicPromoVideoTest.php`: كورسٌ معتمَد يرجع المُعرِّف؛ وكورسٌ حالته `pending` أو `rejected` أو `none` يرجع `null`؛ وكورسٌ حالته `approved` ومُعرِّفه `null` يرجع `null` (‏الشرط الثاني من `hasApprovedPromoVideo()`، وهو الذي يسقط إن كُتب الشرط بنصفه). الطلب **بلا مصادقة**.
- [X] T008 [P] [US1] اختبار في `backend/tests/Feature/Marketplace/PublicPromoVideoTest.php`: كورسٌ **غير منشور** أو لمدرّسٍ غير مُدرَجٍ عامّاً وله فيديو معتمَد ⇒ الاستجابة `404` كما كانت. يثبت أنّ الحارس هو `publiclyListed()` القائم ولم يُضَف حارسٌ ثانٍ يخالفه.

### الواجهة

- [X] T009 [P] [US1] في `frontend/src/lib/courses.ts`: أضف `promo_video_id: string | null` إلى نوع تفاصيل الكورس العامّ.
- [X] T010 [US1] أنشئ `frontend/src/components/courses/PromoVideoButton.tsx` — `"use client"`، خصائصه `{ videoId: string; courseTitle: string }`. الزرّ من `components/ui/Button`؛ عند الضغط **يُركَّب** `<iframe>` (‏لا `hidden` ولا `display:none`) بـ`src` مبنيٍّ من `videoId` ومضيفِ تضمينٍ **ثابتٍ في الكود**، و`title` من `courseTitle`. ألوانٌ من `@theme` وحدها، وخصائصُ منطقيّة (`ms-*`/`start-*`) لا `ml-*`/`left-*`. كشفٌ في الموضع — لا طبقةٌ فوقيّة (R4).
- [X] T011 [US1] اختبار `frontend/src/components/courses/PromoVideoButton.test.tsx` بـ`vitest` و**`fireEvent` لا `userEvent`** (سابقتا `ConfirmButton` و`PasswordField`): (١) **لا `iframe` في DOM قبل الضغط** — ⚠️ هذا التوكيد هو الذي لا يُستغنى عنه، فبدونه يمرّ تنفيذٌ يركّب الإطار مخفيّاً ويحمّل الطرف الثالث كاملاً؛ (٢) بعد الضغط يوجد `iframe` و`src` يحوي المُعرِّف؛ (٣) `src` لا يحوي أيّ نصٍّ خارج المُعرِّف والمضيف الثابت.
- [X] T012 [US1] في `frontend/src/app/(public)/courses/[uuid]/page.tsx`: ركّب `PromoVideoButton` **شرطياً** — `course.promo_video_id !== null` فقط. الزرّ **غائبٌ لا معطَّل** (FR-015). ولا تضف مدخل حجزٍ ثانياً: مدخل ٠٢٣ في مكانه (FR-013).

**Checkpoint**: US1 كاملةٌ وقابلةٌ للشحن. سيناريوهات ١ و٤ في `quickstart.md` تمرّ.

---

## Phase 4: User Story 2 — خالد يلصق رابطه فيُراجَع ثمّ يُقبَل (P2)

**Goal**: المدرّس يلصق رابطاً بنفسه، ولا يظهر شيءٌ عامّاً قبل مراجعةٍ منصّية.

**Independent Test**: مدرّسٌ يلصق رابطاً فلا يظهر الزرّ؛ حاملُ الإذن يعتمد فيظهر؛ مالك مساحة
العمل يُردّ بـ`403`.

### الاستخراج والإذن

- [X] T013 [P] [US2] أنشئ `backend/app/Modules/Courses/Support/PromoVideoUrl.php` بدالّة `extract(string $url): ?string`. **التهجئة الوحيدة** — يستدعيها الإجراء ونموذج الطلب معاً. تُحلَّل الأشكال المعروفة ويُرجَع المُعرِّف وحده؛ وما لا يُستخرَج منه مُعرِّف يرجع `null`.
- [X] T014 [P] [US2] اختبار متّجهات `backend/tests/Feature/Courses/PromoVideoUrlTest.php` — بلا شبكةٍ ولا حساب (سابقة `BunnyTokenVectorTest`): الأشكال المقبولة تعطي المُعرِّف نفسه؛ والرابط بمعاملاتٍ زائدة (`?t=` · `&list=`) يعطي المُعرِّف **وحده**؛ و`javascript:alert(1)` ورابطُ منصّةٍ أخرى ونصٌّ ليس رابطاً كلّها `null`.
- [X] T015 [P] [US2] في `backend/app/Modules/Tenancy/Support/Permissions.php`: أضف `MARKETPLACE_PROMO_REVIEW = 'marketplace.promo.review'` إلى الثوابت وإلى `all()`. **لا تمنحه لأيّ دور مستأجر** — التصنيف المنصّي مشتقٌّ بالطرح.

### الكتابة والمراجعة

- [X] T016 [US2] أنشئ `backend/app/Modules/Courses/Actions/SetCoursePromoVideo.php`: يستخرج بـ`PromoVideoUrl`، ويرفض بـ`DomainException` عند الفشل، ويشترط مدرّساً معتمَداً ومنشوراً في السوق (FR-009 — **في الإجراء** لا في نموذج الطلب وحده، فـFilament والبذور تصله بلا نموذج). يكتب `promo_video_id` **ويصفّر المراجعة إلى `pending` في العبارة نفسها**. و`null` يمحو ويعيد الحالة `none`.
- [X] T017 [US2] في `backend/app/Modules/Courses/Http/Requests/UpdateCourseRequest.php`: أضف `promo_video_url` (nullable, string) مع قاعدةٍ تستدعي `PromoVideoUrl`، وأضف مدخله في `backend/lang/ar/` تحت `attributes` — وإلّا ظهر الاسم البرمجيّ للمدرّس.
- [X] T018 [US2] في `backend/app/Modules/Courses/Http/Resources/CourseResource.php`: أضف `promo_video_id` و`promo_video_status`. هذه شاشة المدرّس وهو يستحقّ أن يعرف لماذا لا يظهر زرّه. ⚠️ **ولا يمسّ هذا `PublicFieldAllowlist`** — موردٌ آخر وجمهورٌ آخر.
- [X] T019 [US2] أنشئ `backend/app/Modules/Courses/Actions/ReviewCoursePromoVideo.php`: قرارٌ `approved`/`rejected` (‏و`reason` إلزاميّ مع الرفض)، يحرسه `Permissions::MARKETPLACE_PROMO_REVIEW`، ويرفض `422` إن كانت الحالة `none`. الاستعلام يعلن `withoutWorkspaceScope()` **بتعليقٍ يشرح السبب**: `WorkspaceContext::id()` يرجع `last_workspace_id` حتى للمنصّيّ، فبقاء النطاق يعني رؤية كورسات مساحةٍ واحدة.
- [X] T020 [US2] في `backend/app/Modules/Courses/routes/api.php` و`Http/Controllers/CourseController.php`: أضف `POST /courses/{course}/promo-video/review` بمتحكّمٍ يتبع التسلسل الإلزاميّ (‏FormRequest ← DTO ← Action ← Resource).
- [X] T021 [US2] في `backend/app/Filament/Resources/CourseResource*`: أضف عمود الحالة وإجراءَ صفٍّ للمراجعة يستدعي `ReviewCoursePromoVideo`. ⚠️ **كرّر الحارس على المورد نفسه**: `Gate::before` يمرّر المدير الأعلى فوق كلّ سياسة (سابقة `CreditPackageResource`).

### اختبارات US2

- [X] T022 [US2] `backend/tests/Feature/Courses/PromoVideoReviewTest.php` — **الاتّجاهان معاً**: حاملُ `marketplace.promo.review` **ينجح**، ومن لا يحمله يُردّ `403`. ⚠️ اتّجاه النجاح هو الذي يكشف سياسةً لم تُسجَّل، لأن Laravel يفشل **مفتوحاً** إلى «لا سياسة تنطبق» — سابقة `taxonomy.manage` في ٠٠٩: إذنٌ مُعلَنٌ ومبذورٌ لم يقرأه ملفٌّ واحد ومرّت كلّ اختباراته.
- [X] T023 [US2] `backend/tests/Feature/Courses/PromoVideoReviewTest.php`: **مالك مساحة العمل يُردّ `403`** عند محاولة اعتماد كورسه — وإلّا اعتمد المدرّس فيديو نفسه وصارت المراجعة اسماً بلا مضمون.
- [X] T024 [US2] `backend/tests/Feature/Courses/PromoVideoTest.php`: **لصقُ رابطٍ جديد على كورسٍ معتمَد يعيد الحالة إلى `pending`**، والحمولة العامّة ترجع `null` فوراً. ⚠️ هذا هو بابُ التبديل الذي يجعل المراجعة بلا معنى إن غاب.
- [X] T025 [P] [US2] `backend/tests/Feature/Courses/PromoVideoTest.php`: رابطٌ غير مقبول ⇒ `422` **وصفر صفٍّ يُكتب**؛ ومدرّسٌ غير معتمَدٍ في السوق ⇒ رفض؛ و`promo_video_status` في حمولة التحديث لا أثر له إطلاقاً.

### واجهة المدرّس

- [X] T026 [US2] في `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/edit/page.tsx`: أضف حقل رابط الفيديو وعرضَ الحالة بجملةٍ عربيّة (`none`/`pending`/`approved`/`rejected` + سبب الرفض). أخطاء `422` تمرّ بـ`fieldErrors()` وتقع تحت حقلها، وما عداها بـ`userMessage()`.

**Checkpoint**: سيناريوهات ٢ و٣ في `quickstart.md` تمرّ.

---

## Phase 5: Polish & Cross-Cutting

- [X] T027 أنشئ `backend/app/Modules/Courses/Listeners/ClearPromoVideoOnOffboarding.php` يستمع `Compliance\Events\TeacherOffboardingCompleted` ويمحو الأعمدة الأربعة عن كورسات المدرّس. ⚠️ **`forWorkspace()` لا `WorkspaceContext::set()`** — المستمع خارج دورة HTTP، والضبط المباشر يسرّب مساحة العمل إلى المهمّة التالية على العامل نفسه (قاعدة دستوريّة).
- [X] T028 في `backend/app/Modules/Courses/Providers/CoursesServiceProvider.php`: سجّل المستمع بـ`Event::listen()` في `boot()`. بجانب `Marketplace\Listeners\UnlistDepartedTeacher` القائم على الحدث نفسه، ولا تعديل في `Compliance` إطلاقاً.
- [X] T029 اختبار `backend/tests/Feature/Courses/PromoVideoOffboardingTest.php` — ⚠️ **بمساحتَي عملٍ لا واحدة**: كلّ عبارات `ExecuteTeacherOffboarding` كانت مقيّدةً بمساحة العمل، فأخفت مساحةٌ واحدةٌ في التجهيزات عطلاً كاملاً. أكّد أنّ كورسات المدرّس المغادر صارت `null`/`none` وأنّ كورسات المدرّس الآخر **لم تُمَسّ**.
- [X] T030 [P] Playwright: أضف إلى مواصفة صفحة الكورس العامّة في `frontend/e2e/` حالةً تؤكّد ظهور الزرّ وكشفه للإطار لزائرٍ بلا حساب. ⚠️ **`test.use({ storageState: { cookies: [], origins: [] } })`** — وإلّا اختُبر مستخدمٌ مسجَّل، والقصّة كلّها عن زائرة. والخادم `PHP_CLI_SERVER_WORKERS=8 php artisan serve --no-reload` (بلا `--no-reload` يُتجاهَل المتغيّر).
- [X] T031 [P] حدّث `docs/README.md` (نقطة النهاية الجديدة والإذن الجديد) و`docs/erd.md` (الأعمدة الأربعة). التوثيق يتحرّك مع الكود — شرطٌ دستوريّ لا تحسين.
- [X] T032 شغّل البوّابات **دفعةً واحدة في النهاية**: `php vendor/bin/pest tests/Feature/Courses tests/Feature/Marketplace` ثمّ `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse` ثمّ `npm test && npx tsc --noEmit`. ⚠️ **لا حزمتَي pest معاً** (تتشاركان قرص الاختبار وتصنعان فشلاً كاذباً)، ولا حزمةً محليّةً كاملة — CI يشغّلها.

---

## Dependencies

```
Phase 1 (T001)
   └─> Phase 2 (T002 · T003 · T004)
          ├─> Phase 3 · US1  (T005…T012)   ← MVP، مستقلّةٌ تماماً
          └─> Phase 4 · US2  (T013…T026)
                 └─> Phase 5 (T027…T032)
```

**US1 لا تعتمد على US2 إطلاقاً.** الصفّ يُكتب من `/admin`، وهذا نصُّ السبيك.

داخل US2: `T013` قبل `T016`/`T017` · `T015` قبل `T019` · `T019` قبل `T020`/`T021`.

---

## Parallel Execution

**بعد T004 مباشرةً** — مساران بلا تلامس:

```
المسار أ (US1):  T005 → T006 → T007 ‖ T008 ‖ T009 → T010 → T011 → T012
المسار ب (US2):  T013 ‖ T015  →  T014 ‖ T016 → …
```

**متوازيةٌ داخل الأفواج**: `T004` · `{T008, T009}` · `{T013, T015}` · `{T030, T031}`.

**ليست متوازية وإن بدت كذلك**: `T005` و`T006` — المفتاح وتوأمُه في الـallowlist **تغييرٌ واحد**؛
فصلُهما يعني بناءً أحمرَ بينهما ويغري بحذف التوكيد بدل إضافة المفتاح.

---

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 3 (T001…T012).** اثنتا عشرة مهمّة، وبعدها زائرةٌ بلا حساب
تشاهد الفيديو — وهو غرض السبيك كلّه. الرابط يُدخَل من `/admin` حتى تصل US2.

ثمّ **Phase 4** يرفع المدرّس عبء الإدخال عن الإدارة، ثمّ **Phase 5** يغلق ما هو عابرٌ للقصص.

**ما يُقاوَم أثناء التنفيذ**: بناءُ مكوّنِ حوارٍ لأنّ «الكشف في الموضع أقلّ أناقة» (R4 — لا حوار
في `components/ui/` والمستودع رفض بناءه صراحةً)، وتركيبُ الإطار مخفيّاً لأنّه «أبسط» (R3 —
يُبطل السبب كلّه، و`T011` هو ما يمنعه).
