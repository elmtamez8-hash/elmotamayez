# Tasks: لمن هذا العنصر ومتى يظهر

**Input**: `specs/026-cohort-scoped-content/` — spec.md · plan.md · research.md · data-model.md ·
contracts/lesson-audience.md · quickstart.md

**Tests**: **مطلوبةٌ**، ولكلِّ حارسٍ في هذه القائمةِ سطرٌ يقولُ **ما الذي يُخرَّبُ عمداً
فيسقطُ الاختبار**. حارسٌ لا يسقطُ عندَ تخريبِ ما يحرسُه ليسَ حارساً، وهذا المستودعُ يسجّلُ
اثنتَي عشرةَ حالةَ اختبارٍ أخضرَ كاذب.

**Format**: `[ID] [P?] [Story] الوصفُ ومعه مسارُ الملفّ`

## الحالةُ اليومَ — ٢٠٢٦-٠٩-١٥

**شُحِنَ وعُمِلَ به على الإنتاج**: US3 كاملةً (T035–T039) ومعها T005 · T006، وشِقّانِ من
مهمّتَين: `NO_SESSION_CONTENT` وحدَه من رموزِ T010، وإسقاطُه وحدَه من T017. الرمزانِ
الآخرانِ ينتظرانِ قصّتَيهما.

**وشُحِنَ بعدَه**: المرحلتانِ ١ و٢ كاملتَينِ (T001–T011) — الجدولُ والعمودُ والنموذجُ
والعلاقةُ والقارئُ الواحد. ورمزا `OUT_OF_SCOPE` و`UNRELEASED` لهما اليومَ **كاتبٌ
وقارئٌ في الشحنةِ نفسِها** (`LessonAudience` يُصدِرُهما و`LessonAudienceTest` يقرؤُهما)،
ولا يبلغانِ باباً حتّى US1 — وهي التاليةُ مباشرةً، وإلّا كانا رمزَينِ بلا كاتبٍ حقيقيّ.

**وشُحِنَ بعدَه (US1)**: محورُ «لمن هذا العنصر» كاملاً — الكتابةُ
(T012 · T013 · T015)، والأبوابُ الأربعةُ (T016 · T017 · T018)، والمقامُ (T019)،
والشاشاتُ (T020–T023)، والاختباراتُ (T024–T029).

⚠️ **ومعَها T030 وT031 من US2**، وليسَ ذلكَ توسيعاً: `LessonAudience` يُجيبُ
**حكماً واحداً** لا فرعًا لكلِّ رمز، فسؤالُ «أهوَ `out_of_scope`؟» عندَ الأبوابِ
الأربعةِ تهجئةٌ ثانيةٌ لـ«أمخفيٌّ هو». وما دامَ `unreleased` يسري، فشرطُ
المقامِ (T031) **لا يؤجَّلُ يوماً واحداً**: عنصرٌ يدخلُ المقامَ ولا يمكنُ
إتمامُه يُبقي كلَّ طالبٍ تحتَ ١٠٠٪ للأبد. فما بقيَ من US2 هو **الكاتبُ
والشاشةُ واختباراتُهما** لا أكثر.

**وشُحِنَ بعدَه (US2 كاملةً)**: محورُ «متى يظهر» من طرفِه إلى طرفِه — الكاتبُ
(T014 كاملاً · `LessonRelease` · `SaveLessonAudience` صارَ يكتبُ المحورَينِ في
معاملةٍ واحدةٍ وحدثٍ واحد)، والشاشةُ (T032)، والاختباراتُ (T033 · T034).

⚠️ **وأخرجَ اختبارُ T033 عطلَينِ قائمَينِ خارجَ نطاقِ هذه المواصفة**، وكلاهما
من عائلةِ «القراءةُ مُنطَقةٌ فتختلفُ باختلافِ القارئ»:

١) **`Enrollment::orderedLessons()` كانت تُرجِعُ صفرَ دروسٍ لطالبٍ مختومٍ
بمساحةِ عملٍ **غيرِ مساحةِ الكورسِ الذي اشتراه** — طالبٌ أضافَه مدرّسٌ إلى
مساحتِه ثمّ اشترى من مدرّسٍ آخر** — `course()` تحملُ التجاوزَ و`Course::lessons()` استعلامٌ
جديدٌ لا يحملُه. فالمنهجُ ٢٠٠ بصفرِ صفوف: كورسٌ دُفِعَ ثمنُه يُقرَأُ كأنّه
فارغ، بلا خطأٍ في أيِّ مكان. والإسنادُ المُسبَقُ نصفٌ ثانٍ من العطلِ نفسِه:
`->with(['section','chapter'])` مُنطَقةً تُرجِعُ `null` للاثنَين، فيجدُهما
`isVisibleChain()` «محمَّلَين» فلا يُعيدُ جلبَهما. الحارسُ في
`CurriculumStudentContextTest`.

٢) **«المؤلّف» كانَ يُقاسُ بالعضويّةِ لا بالدور** في ثلاثةِ أبواب
(`mayWatch()` · `mayWatchMany()` · `/learn/lessons/{uuid}`)، والأخيرُ يفتحُ
**دروساً منشورةً بلا تسجيلٍ** لعضوٍ بدورِ «طالب» غيرِ مسجَّل. الحارسُ في
`AuthorBranchIsRoleBasedTest`.

**وشُحِنَ بعدَه (US4 والصقل)**: الأبوابُ الثلاثةُ الباقيةُ في وحدةِ التقييمات
(T040 · T041 · T042) واختبارُها (T043)، وميزانيّةُ منهجٍ فيه نطاقاتٌ ومواعيدُ
إفراج (T044)، والتوثيقُ (T045 · T046).

⚠️ **ورابعُ موضعٍ لم يكنْ في القائمة**: `BuildPracticeFromMistakes` لا يمرُّ من
`PracticePool::questionsFor()` إطلاقاً — يبني ورقتَه من معرّفاتِ الدفترِ مباشرةً
— فبلا استبعادٍ مكتوبٍ فيه كذلك يبقى للبِركةِ بابانِ أحدُهما مفتوح. والأربعةُ
مثبَّتةٌ بالتخريب: كلُّ حارسٍ حُذِفَ وسقطَ اختبارُه وحدَه.

**ما زالَ**: T047 (البوّابات) · T048 (مشيةٌ يدويّةٌ بحسابِ طالبٍ من كلِّ مجموعة —
تحتاجُ حساباتٍ حقيقيّةً، فهي بيدِ المالك).

⚠️ **وشُحِنَ خارجَ هذه القائمةِ شيئان** خرَجا من مشيةٍ على الإنتاج، لا من تخطيطٍ مسبَق:
قسمُ «المجموعة والحصص» على شاشةِ الطلب، وإصلاحُ منطقةِ الموعدِ الزمنيّةِ في
`schedulePreviewFor()` — التي كانت تُعلِنُ مواعيدَ كلِّ المجموعاتِ بـUTC على شاشاتِ
الإدارةِ **وعلى صفحةِ الكورسِ العامّة** معاً.

---

---

## Phase 1 — الأساسُ المشترك (يسبقُ كلَّ قصّة)

- [X] T001 [P] ترحيلٌ ينشئُ `lesson_cohort_scopes` بالأعمدةِ والفهارسِ **المسمّاةِ باليد** في `backend/app/Modules/Courses/Database/Migrations/2026_09_15_000100_create_lesson_cohort_scopes_table.php` — أطولُ اسمٍ `lesson_cohort_scopes_workspace_index` (٣٦ محرفاً مقيسة)، والحدُّ ٦٤
- [X] T002 [P] ترحيلٌ يضيفُ `lessons.release_session_id` مفرَّغاً بعدَ `class_session_id` مع فهرسٍ مسمّى في `backend/app/Modules/Courses/Database/Migrations/2026_09_15_000200_add_release_session_id_to_lessons.php`
- [X] T003 نموذجُ `LessonCohortScope` بـ`BelongsToWorkspace` و`HasUuid` في `backend/app/Modules/Courses/Models/LessonCohortScope.php`
- [X] T004 علاقةُ `cohortScopes(): HasMany` و`releaseSession` (بلا علاقةٍ عابرةٍ للوحدات — معرّفٌ فقط) وخاصّيّةُ `release_session_id` في `backend/app/Modules/Courses/Models/Lesson.php`
- [X] T005 دالّةُ `releasedSessionIds(array): array` على `backend/app/Shared/Contracts/SessionAttendanceDirectory.php` — المُفرَجُ عنه `delivered_at IS NOT NULL` **أو** `status = 'cancelled'`
- [X] T006 تنفيذُها في `backend/app/Modules/LiveSessions/Support/EloquentSessionAttendanceDirectory.php` — استعلامٌ واحدٌ جماعيّ، ويردُّ المُفرَجَ عنه فقط لا المُدخَلَ كلَّه

**نقطةُ تحقُّق**: `php artisan migrate` ثمّ `php vendor/bin/pest tests/Feature/Seeding/SchemaIdentifierLengthTest.php` — الحارسُ القائمُ يقرأُ كلَّ اسمٍ بعدَ الترحيلِ ويُسقِطُ ما جاوزَ ٦٤.

---

## Phase 2 — القارئُ الواحد (يحجبُ كلَّ ما بعدَه)

⛔ **هذه المرحلةُ هي تصحيحُ المراجعة.** الأبوابُ أربعةٌ والحكمُ واحد؛ حكمٌ مكتوبٌ في أربعةِ
مواضعَ هو «بابانِ يختلفان» الذي جعلَ تسجيلاً مدفوعاً غيرَ قابلٍ للفتحِ في ٠١٨.

- [X] T007 صنفُ `LessonAudience` في `backend/app/Modules/Courses/Support/LessonAudience.php` — `hiddenAmong(User, iterable<Lesson>): array<int,string|null>` جماعيّاً، و`hiddenFor(User, Lesson): ?string` **مشتقّاً منه** لا مكتوباً ثانيةً
- [X] T008 داخلَه: مجموعاتُ القارئِ المفتوحةُ من `CohortDirectory::openMembershipCohortIdsFor()` (لا `everMemberCohortIdsFor` — سؤالٌ آخر، انظر research.md · ق-٦)، وصفوفُ النطاقِ بـ`whereIn('lesson_id', …)`، وحالُ حصصِ الإفراجِ من T005 — **ثلاثةُ استعلاماتٍ ثابتةٍ مهما كَبُرَت الشجرة**
- [X] T009 داخلَه: استثناءُ المؤلّفِ (عضوِ مساحةِ عملِ الدرس) **مرّةً واحدةً هنا** لا في أربعةِ أبواب (FR-011)
- [X] T010 ثلاثةُ رموزٍ على `backend/app/Modules/Learning/Support/LessonAccess.php`: `OUT_OF_SCOPE` · `UNRELEASED` · `NO_SESSION_CONTENT`، ومعَ كلٍّ منها في دفترِ تعليقِه سببُ إخفائِه لا عرضِه
- [X] T011 اختبارُ وحدةٍ للقارئِ في `backend/tests/Feature/Courses/LessonAudienceTest.php` — يغطّي: بلا نطاقٍ ⇒ null · نطاقٌ يشملُ ⇒ null · نطاقٌ لا يشملُ ⇒ `out_of_scope` · حصّةٌ مجدولةٌ ⇒ `unreleased` · حصّةٌ سُلِّمت ⇒ null · حصّةٌ أُلغيت ⇒ null · مؤلّفٌ ⇒ null دائماً
  - **كيفَ يمسك**: احذفْ استثناءَ المؤلّفِ ⇒ تسقطُ الحالةُ الأخيرةُ وحدَها

---

## Phase 3 — US1 · «لمن هذا العنصر» (P1)

**الهدف**: مفتاحٌ صريحٌ بيدِ المدرّس، وما قُصِرَ على مجموعةٍ غيرُ موجودٍ عندَ غيرِها.

**اختبارُها المستقلّ**: ارفعْ عنصراً مقصوراً، وافتحِ المنهجَ بحسابِ طالبٍ من مجموعةٍ أخرى ⇒
العنصرُ غيرُ موجودٍ في الحمولة.

### الكتابة

- [X] T012 [US1] Action `SaveLessonAudience` في `backend/app/Modules/Courses/Actions/SaveLessonAudience.php` — يكتبُ النطاقَ والموعدَ في معاملةٍ واحدة، ويرفضُ مجموعةً أو حصّةً **من غيرِ كورسِ الدرس**
- [X] T013 [US1] يُطلِقُ `CourseStructureChanged` **مرّةً واحدةً للكورس** إن تغيّرَ محور (FR-016) — الحدثُ والمستمعُ القائمانِ، بلا سطرِ مزامنةٍ جديد
- [X] T014 [US1] حقلا `cohort_uuids` و`release_session_uuid` في `backend/app/Modules/Courses/Http/Requests/UpdateLessonRequest.php` بـ`WorkspaceRules::exists` لا `exists:`، ومصفوفةٌ فارغةٌ = «للجميع»
- [X] T015 [P] [US1] مدخلا `attributes` العربيّانِ في `backend/lang/ar/validation.php` — بدونَهما يقرأُ المدرّسُ `cohort_uuids`

### القراءة

- [X] T016 [US1] فرعُ `out_of_scope` في `LessonGate::for()` **و**`forTree()` معاً في `backend/app/Modules/Learning/Support/LessonGate.php` — بعدَ فرعِ `class_session_id` وقبلَ سؤالِ التسلسل
- [X] T017 [US1] إسقاطُ الرموزِ الثلاثةِ في `backend/app/Modules/Learning/Http/Resources/CurriculumResource.php:127` — الشرطُ يصيرُ مجموعةً بدلَ مقارنةٍ واحدة، ويُسقَطُ الفصلُ ثمّ القسمُ إذا فرغ
- [X] T018 [US1] ⛔ سؤالُ `LessonAudience` في `mayWatch()` **و**`mayWatchMany()` في `backend/app/Modules/Media/Actions/IssuePlaybackGrant.php` — الرفضُ بالرسالةِ العامّةِ القائمةِ لا برسالةٍ تكشفُ وجودَ العنصر

### المقام ⛔

- [X] T019 [US1] `->whereDoesntHave('cohortScopes')` في `Lesson::scopeProgressEligible()` في `backend/app/Modules/Courses/Models/Lesson.php` — **خاصّيّةُ العنصرِ لا حالُ القارئ** (FR-013أ)، فالمقامُ يبقى واحداً لكلِّ كورس

### الشاشات

- [X] T020 [P] [US1] `audience` و`release` على حمولةِ المدرّسِ في `backend/app/Modules/Courses/Http/Resources/LessonResource.php` و`CourseTreeResource.php` — بإسنادٍ مُسبَقٍ للمجموعات، فالمورِدُ يعملُ مرّةً لكلِّ صفّ
- [X] T021 [P] [US1] نوعا `LessonAudience` و`LessonRelease` في `frontend/src/lib/courses.ts`
- [X] T022 [US1] مكوّنُ `frontend/src/components/courses/LessonAudienceFields.tsx` — «للجميع» أو اختيارُ مجموعاتٍ متعدّدة، **ولا يُعرَضُ إن كان الكورسُ بلا مجموعات** (FR-003)
- [X] T023 [US1] وصلُه في `frontend/src/components/courses/LessonEditor.tsx`، وعرضُ النطاقِ بجوارِ كلِّ صفٍّ في `frontend/src/components/courses/LessonRow.tsx` (FR-011)

### الاختبارات

- [X] T024 [US1] `backend/tests/Feature/Learning/CohortScopedCurriculumTest.php` — طالبُ مجموعةٍ أخرى لا يرى الصفّ · طالبُ المجموعةِ يراه ويفتحُه · «للجميع» يراه الكلّ · طالبٌ بلا مجموعةٍ يرى «للجميع» وحدَها (٠٣٤) · المنقولُ يرى مجموعتَه الجديدة · طالبٌ في مجموعتَينِ يرى الاتّحاد
  - **كيفَ يمسك**: احذفْ فرعَ `out_of_scope` من `forTree()` وحدَه ⇒ يسقطُ هذا الملفُّ **و**`LessonGateParityTest`. سقوطُ الأوّلِ وحدَه يعني أنّ المقارنةَ لا تغطّي الرمزَ الجديد
- [X] T025 [US1] حالتانِ في `backend/tests/Feature/Learning/LessonGateParityTest.php` تُدخِلانِ الرموزَ الثلاثةَ في تجهيزةِ المقارنة
- [X] T026 [US1] ⛔ `backend/tests/Feature/Media/PlaybackAudienceTest.php` — طالبٌ **مسجَّلٌ ونشط** من مجموعةٍ أخرى يُرفَضُ إذنُ التشغيل، مفرداً وجماعيّاً
  - **كيفَ يمسك**: أزِلِ السؤالَ من `mayWatch()` وحدَه ⇒ تسقطُ الحالةُ المفردةُ وحدَها؛ ثمّ من `mayWatchMany()` وحدَه ⇒ تسقطُ الجماعيّةُ وحدَها
  - ⚠️ **التجهيزةُ بطالبٍ مسجَّلٍ نشطٍ حتماً**: الفرعُ الأخيرُ اليومَ هو `hasActiveEnrollment`، فطالبٌ غيرُ مسجَّلٍ يُرفَضُ لسببٍ آخرَ والاختبارُ أخضرُ كاذب
- [X] T027 [US1] ⛔ `backend/tests/Feature/Learning/CohortScopedProgressTest.php` — كورسٌ فيه عنصرانِ مشتركانِ وثالثٌ مقصور؛ طالبُ المجموعةِ الأخرى يُكمِلُ الاثنَينِ ⇒ **١٠٠٪** وحدثُ الإتمامِ وشهادة. وطالبُ المجموعةِ المقصورِ عليها كذلك (FR-012أ · FR-013ب)
  - **كيفَ يمسك**: احذفْ `whereDoesntHave('cohortScopes')` ⇒ يسقطُ بـ«٦٦.٦ ≠ ١٠٠»
  - ⚠️ العنصرُ المقصورُ **منشورٌ ومن نوعٍ قابلٍ للإتمام**، وإلّا فهو خارجُ المقامِ لسببٍ آخرَ أصلاً
- [X] T028 [P] [US1] `backend/tests/Feature/Courses/LessonAudienceWriteTest.php` — مجموعةٌ من كورسٍ آخرَ تُرفَض · حصّةٌ من كورسٍ آخرَ تُرفَض · مصفوفةٌ فارغةٌ تُلغي التضييق · `CourseStructureChanged` يقعُ **مرّةً واحدةً** لا مرّةً لكلِّ عنصر
- [X] T029 [P] [US1] `frontend/src/components/courses/LessonAudienceFields.test.tsx` — يُعرَضُ مع مجموعات، **ولا يُعرَضُ بلا مجموعات**، ويرسلُ مصفوفةً فارغةً عندَ «للجميع»

---

## Phase 4 — US2 · «لا شيءَ يظهرُ قبلَ حصّتِه» (P2)

**اختبارُها المستقلّ**: اربطْ عنصراً بحصّةٍ لم تُبَثَّ، وافتحِ المنهجَ بحسابِ طالبٍ في تلك
المجموعة ⇒ غيرُ موجود. سلِّمِ الحصّةَ وأعِدِ الفتح ⇒ ظهر.

- [X] T030 [US2] فرعُ `unreleased` في `LessonGate::for()` **و**`forTree()` في `backend/app/Modules/Learning/Support/LessonGate.php`
- [X] T031 [US2] `->whereNull('lessons.release_session_id')` في `Lesson::scopeProgressEligible()` — **إلى الأبدِ لا حتّى الإفراج** (FR-013)
- [X] T032 [US2] اختيارُ حصّةِ الإفراجِ في `frontend/src/components/courses/LessonAudienceFields.tsx` مع «يظهر الآن» خياراً افتراضيّاً، وفكُّ الربطِ من المكانِ نفسِه — وهو مخرجُ FR-008 لحصّةٍ لم تُسلَّمْ ولم تُلغَ
- [X] T033 [US2] `backend/tests/Feature/Learning/SessionTimedReleaseTest.php` — مجدولةٌ ⇒ مخفيّ · سُلِّمت ⇒ ظاهرٌ ومفتوح · **أُلغيت ⇒ ظاهر** (FR-008) · بلا ربطٍ ⇒ ظاهرٌ فوراً
  - **كيفَ يمسك**: أزِلْ `status = cancelled` من شرطِ الإفراج ⇒ تسقطُ الحالةُ الثالثةُ وحدَها
  - ⚠️ الأحوالُ الثلاثةُ في ملفٍّ واحد: تجهيزةٌ تُسلِّمُ الحصّةَ دائماً لا ترى شيئاً
- [X] T034 [US2] ⛔ `backend/tests/Feature/Learning/ProgressNeverDropsTest.php` — طالبٌ على ١٠٠٪، ثمّ يُنشَرُ عنصرٌ مربوطٌ بحصّةٍ لم تُسلَّم ⇒ ما زال ١٠٠٪؛ **وبعدَ التسليمِ كذلك** (FR-014 · SC-003)
  - **كيفَ يمسك**: اجعلِ الشرطَ «لم يُفرَجْ عنه بعد» بدلَ «مربوطٌ بحصّة» ⇒ يسقطُ الشقُّ الثاني وحدَه — وهو الفرقُ بين FR-013 وFR-013أ حرفيّاً

---

## Phase 5 — US3 · «لا يُعرَضُ ما لا يُفتَح» (P3)

**اختبارُها المستقلّ**: حصّةٌ `completed` و`delivered_at` مفرَّغٌ ولها درسُ تسجيل ⇒ الصفُّ غيرُ
موجودٍ في المنهج.

- [X] T035 [US3] شرطُ التسليم/الإلغاءِ في **فرعَي** `unlockableSessionIds()` في `backend/app/Modules/Payments/Support/EloquentSessionContentAccess.php` (المقعدُ والتسجيلُ+المجموعة) — انظر research.md · ق-٤
- [X] T036 [US3] فرعُ `no_session_content` في `LessonGate::for()` **و**`forTree()` — يقعُ بعدَ `unlockableSessionIds` ويُسقِطُ الصفَّ بدلَ الوعدِ بفتحٍ يُرفَض
- [X] T037 [US3] التأكّدُ من أنّ `lockedSessionCount()` في `CurriculumResource:233` **لا يعُدُّ** صفّاً أُسقِط — اليومَ يشترطُ `NO_SEAT` فهو آمنٌ بالبناء؛ تُكتَبُ الحراسةُ في اختبارٍ لا في شرطٍ جديد
- [X] T038 [US3] حالتانِ تُضافانِ إلى `backend/tests/Feature/Learning/SessionContentPromiseTest.php` القائم — **لا ملفٌّ منافس**: ذلك الملفُّ هو العقدُ الذي يقرأُ الحمولةَ ويضغطُ النقطةَ ويؤكّدُ اتّفاقَهما
  - **كيفَ يمسك**: أعِدْ `unlockableSessionIds()` إلى صيغتِها قبلَ T035 ⇒ تسقطُ الحالتانِ برسالةٍ تقارنُ الحمولةَ بجوابِ نقطةِ الفتح
- [X] T039 [P] [US3] حالةٌ في `backend/tests/Feature/Learning/CurriculumEndpointTest.php` تؤكّدُ أنّ `locked_session_count` لا يشملُ الصفوفَ المُسقَطة

---

## Phase 6 — US4 · «الامتحانُ يتبعُ القاعدةَ نفسَها» (P4)

**اختبارُها المستقلّ**: امتحانٌ صفُّه في الشجرةِ مقصورٌ على مجموعةٍ أخرى ⇒ غيرُ موجودٍ في
فهرسِ الاختبارات، وبدءُ المحاولةِ مرفوض.

- [X] T040 [US4] توسيعُ `guardSessionContent()` في `backend/app/Modules/Assessments/Actions/StartAttempt.php` بسؤالِ `LessonAudience` عبرَ **صفِّ الشجرةِ الذي يقرؤُه الآن**
- [X] T041 [US4] استبعادُ الامتحاناتِ المخفيّةِ في `backend/app/Modules/Assessments/Http/Controllers/ExamController.php::index()` — بعدَ `StudentScope` وداخلَ مجموعتِه، فلا يُكسَرُ ترتيبُ الشروطِ المحروسُ بتعليقِه
- [X] T042 [US4] استبعادُها من بركةِ التدريبِ في `backend/app/Modules/Assessments/Support/PracticePool.php` (FR-020) — ودفترُ الأخطاءِ يقرأُ منها
- [X] T043 [US4] `backend/tests/Feature/Assessments/ExamAudienceTest.php` — الفهرسُ لا يحملُه · بدءُ المحاولةِ مرفوض · بركةُ التدريبِ صفرُ أسئلةٍ منه · **ومحاولةٌ بدأت قبلَ التضييقِ تُكمَلُ وتُحفَظُ درجتُها** (FR-019)
  - **كيفَ يمسك**: اكتفِ بإخفائِه من الفهرسِ وأبقِ `StartAttempt` كما هو ⇒ تسقطُ حالةُ البدء. شاشةٌ تُخفي زرّاً ليست حارساً

---

## Phase 7 — الصقلُ والحراساتُ العابرة

- [X] T044 حالةٌ في `backend/tests/Feature/Learning/CurriculumQueryBudgetTest.php` بمنهجٍ **فيه نطاقاتٌ ومواعيد** — الملفُّ يؤكّدُ تساويَ العددَينِ (١٠ مقابلَ ٢٠٠)، وبلا هذه الحالةِ يقيسُ الفرعَ الذي لا يُسأَلُ أصلاً
  - **كيفَ يمسك**: اسألِ النطاقَ داخلَ حلقةِ العناصر ⇒ يسقطُ بعددٍ ينمو
- [X] T045 [P] تحديثُ `docs/README.md` و`docs/erd.md` بالجدولِ والعمودِ الجديدَين
- [X] T046 [P] فقرةٌ في `CLAUDE.md` تحتَ Gotchas: **أبوابُ محتوى الدرسِ أربعةٌ لا واحد**، ومنها `IssuePlaybackGrant::mayWatch()` التي تنتهي عندَ `hasActiveEnrollment` — مع أنّ الحكمَ في `LessonAudience`
- [ ] T047 بوّاباتٌ: `./vendor/bin/pint` · `./vendor/bin/phpstan analyse` · `php vendor/bin/pest tests/Feature/Learning tests/Feature/Courses tests/Feature/Assessments tests/Feature/Media` · `npx tsc --noEmit` · `npm test`
- [ ] T048 مشيةٌ يدويّةٌ: بحسابِ طالبٍ من كلِّ مجموعةٍ، افتحِ المنهجَ وقارنْ **ما يُعرَضُ بما يُفتَح** ⇒ صفرُ فروق (SC-001)

---

## التبعيّات

```
Phase 1 (T001–T006)
   └─ Phase 2 (T007–T011)   ← يحجبُ كلَّ القصص
        ├─ US1 (T012–T029)  ← MVP
        ├─ US2 (T030–T034)  ← يعتمدُ على T007/T010 فقط
        ├─ US3 (T035–T039)  ← مستقلٌّ تماماً، يجوزُ شحنُه وحدَه
        └─ US4 (T040–T043)  ← يعتمدُ على US1 (يقرأُ صفَّ الشجرة)
   └─ Phase 7 (T044–T048)
```

**المتوازي**: T001∥T002 · T015∥T020∥T021 · T028∥T029 · T039 · T045∥T046.

**نطاقُ MVP**: Phase 1 + Phase 2 + US1. وUS3 **شحنةٌ مستقلّةٌ صغيرةٌ** (٥ مهامّ) تُصلِحُ عطلاً
قائماً اليومَ ولا تنتظرُ شيئاً — يجوزُ تقديمُها.

---

## ما لا يدخلُ هذه الشحنةَ صراحةً

- **صفحةُ الكورسِ العامّةُ** تسردُ عناوينَ العناصرِ بـ`visibleToStudents()` بلا قارئٍ تُنسَبُ
  إليه، فعنوانُ عنصرٍ مقصورٍ يبقى فيها. تضييقُها قرارُ مالك.
- **FR-007 الأصليّة** (إفراجٌ لكلِّ مجموعةٍ عندَ حصّتِها) — مؤجَّلةٌ بقرارِ المالكِ
  ٢٠٢٦-٠٩-١٥، تحتاجُ جدولَ ربطٍ بعدّةِ حصص.
- **امتحانٌ ليس في شجرةِ الكورسِ أصلاً** لا نطاقَ له ولا موعد، ويبقى مرئيّاً لكلِّ مسجَّل.
