---

description: "Task list — 012 التوسّع والتطبيق"
---

# Tasks: التوسّع والتطبيق (Scale, Adaptive Learning & PWA)

**Input**: Design documents from `/specs/012-scale-and-mobile/`

**Prerequisites**: [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/api.md](./contracts/api.md) · [quickstart.md](./quickstart.md)

**النطاق**: US1 (المسارُ التكيّفي · P1) · US2 (الويبُ كتطبيق · P2) · US3 (غرفُ المذاكرة · P3).
**US4 ساقطةٌ** بـQ4 (لا adapter بوّابةٍ في الشجرة) و**US5 مؤجَّلةٌ** بـQ5 — `Session 2026-08-29`.

**Tests**: **مطلوبةٌ في هذه المرحلة.** المبدأُ الرابعُ يجعل البوّاباتِ الخضراءَ شرطَ اندماج،
و**سبعةٌ من معاييرِ النجاحِ تصف بناءَ الاختبارِ نفسِه** لا نتيجتَه: تدخّلٌ بين القراءةِ
والمطالبة · مضيفٌ قدّم امتحاناً ومنضمٌّ لم يقدّمْه · ضابطٌ موجبٌ مع كلِّ نفي · سنتينلُ ASCII
لا حاجةٌ عربيّة · حسابانِ على جهازٍ واحد · فكرةٌ بصعوبةٍ واحدة. فمهامُّ الاختبارِ جزءٌ من
التسليم، لا تابعٌ له.

> ⚠️ **المستنداتُ مُراجَعةٌ بخمسةِ وكلاءَ في 2026-08-29** قبل هذا الملف.
> [`research.md › R19`](./research.md) يحمل الثلاثةَ عشرَ تصحيحاً **بأسبابِها**؛ كلُّ مهمّةٍ
> موسومةٍ ⚠️ أدناه تحمل واحداً منها، **وحذفُ الوسمِ يعيد العطل**.

**⚠️ ولا تشغيلَ لأيِّ مجموعةِ اختباراتٍ كاملةٍ محليّاً** — أمرٌ قائمٌ من المالك، ولا يرفعه نصُّ
مهمّةٍ في هذا الملف. المستهدَفُ محليّاً، والكاملُ على CI.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يجوزُ توازيه (ملفٌّ مختلف، بلا اعتمادٍ على ناقص)
- **[Story]**: US1 · US2 · US3
- كلُّ مهمّةٍ تحمل مسارَ ملفِّها

## Path Conventions

`backend/app/Modules/{Module}/…` · `backend/tests/Feature/{Module}/…` · `frontend/src/…`

---

## Phase 1: Setup

**Purpose**: ما تحتاجه المرحلةُ كلُّها ولا يخصّ قصّةً بعينِها.

- [X] T001 أضِفْ `minishlink/web-push` إلى `backend/composer.json` وشغِّلْ `composer require minishlink/web-push` — التبعيّةُ الوحيدةُ الجديدة، مُبرَّرةٌ في `plan.md › Complexity Tracking` (توقيعُ ES256 على P-256 وتشفيرُ `aes128gcm` باشتقاقِ ECDH؛ مسارُ تعميةٍ لا تُطبَّق عليه قاعدةُ «الأبسطُ الذي يعمل»)
- [X] T002 [P] أنشئْ `backend/config/assessments.php` بقسمِ `adaptive`: `promote_after=2` · `mastery_correct=3` · `max_questions=20` · `start_difficulty=easy` — احتياطٌ لقاعدةٍ لم يُزرع فيها شيء، لا مصدرٌ
- [X] T003 [P] أنشئْ `backend/config/webpush.php` يقرأ `VAPID_PUBLIC_KEY` · `VAPID_PRIVATE_KEY` · `VAPID_SUBJECT` من البيئة، **ولا يُعرِّف قيمةً افتراضيّةً لأيٍّ منها** — مفتاحٌ افتراضيٌّ في هذا الملفِّ سرٌّ في المستودع
- [X] T004 [P] أضِفْ إلى `backend/.env.example` مفاتيحَ VAPID الثلاثةَ و`NEXT_PUBLIC_VAPID_PUBLIC_KEY` فارغةً مع تعليقٍ يشرح أنّ السرّيَّ هو `VAPID_PRIVATE_KEY` وحدَه
- [X] T005 أضِفْ أربعةَ مفاتيحِ `assessments.adaptive.*` إلى `KEYS` في `backend/app/Modules/Tenancy/Support/PlatformSettings.php`
- [X] T006 أضِفْ مُحدِّدَي `adaptive-step` (٦٠/دقيقة) و`study-room-write` (١٠/دقيقة) — كليهما `by('user:'…)` — في `AppServiceProvider::registerRateLimiters()`. ⚠️ **مُحدِّدانِ لا واحد**: المُحدِّدُ المسمّى دلوٌ واحدٌ لكلِّ مستخدم، فإعادةُ استعمالِ `practice` للغرفِ تستنزف ميزانيّةَ الجلساتِ التكيّفيّة — أثرُ العدّادِ المشترَكِ الذي تُمنَع الحدودُ السطريّةُ بسببِه، باسمٍ مسمّى
- [X] T007 [P] أضِفْ إلى `attributes` في `backend/lang/ar/validation.php` تحت تعليقِ «spec 012»: `concept` · `teacher` · `question_count` · `max_participants` · `duration_minutes` · `starts_in_minutes` · `question_id` · `option_ids` · `endpoint` · `keys.p256dh` · `keys.auth` — الحقلُ الغائبُ يُعرَض بمفتاحِه الخام

---

## Phase 2: Foundational — يحجب كلَّ القصص

**Purpose**: تصحيحُ الإجابةِ الواحدةِ يخدم US1 و US3 معاً، والمسُّ بمسارٍ حرجٍ قائمٍ يقع
أوّلاً وتحت اختباراتِه كاملةً.

- [X] T008 أضِفْ `rank(): int` و`easier(): ?self` و`harder(): ?self` إلى `backend/app/Modules/Assessments/Enums/Difficulty.php` — الترتيبُ خاصّيةُ مفردةٍ لا فرعٌ في منطقِ الأعمال، على سابقةِ `NotificationChannel::isExternal()`
- [X] T009 [P] أنشئْ `backend/app/Modules/Assessments/Enums/AdaptiveStatus.php`: `Running` · `Mastered` · `Ended` مع `label()` — كلُّ عمودِ حالةٍ في هذه الوحدةِ له enum (`AttemptStatus`, `ExamStatus`, `SubmissionState`, `ImportStatus`)
- [X] T010 [P] اكتبْ `backend/tests/Feature/Assessments/DifficultyLadderTest.php`: `hard->easier()===Medium` · `easy->easier()===null` · `hard->harder()===null` · و`rank()` مرتَّبٌ تصاعديّاً
- [X] T011 استخرِجْ `backend/app/Modules/Assessments/Support/AnswerMarker.php` من `Actions/GradeAttempt.php`: يصحّح **إجابةً واحدةً** مقابلَ لقطتِها، يكتب صفَّ `Answer`، ويُطلق `MistakeResolved` عند اللزوم. ⚠️ **يأخذ مجموعةَ «ما أخطأ فيه سابقاً» مُعطاةً ولا يشتقُّها**: `previouslyWrongQuestionIds()` استعلامٌ واحدٌ **قبل الحلقة** وتعليقُه يمنع الشكلَ لكلِّ سؤال، **وقبل كتابةِ أيِّ صفٍّ** لأنّ كلَّ سؤالٍ يجد بعد الإدراجِ خطأً لنفسِه
- [X] T012 أعِدْ توجيهَ `Actions/GradeAttempt.php` إلى `AnswerMarker` بلا تغييرِ سلوكِه: الاستعلامُ الواحدُ يبقى قبل الحلقة، والنتيجةُ تُمرَّر إلى كلِّ نداء
- [X] T013 ⚠️ أضِفْ إلى `Actions/GradeAttempt::handle()` حارساً `exists()` على `exam_answers` **قبل `claimForGrading()`** يرمي `DomainException` بجملةٍ عربيّة. **هذا يمنع عطباً دائماً**: `POST /attempts/{attempt}/submit` مربوطٌ ضمنيّاً ويُفوَّض بالملكيّة، ومحاولةُ الجلسةِ التكيّفيّةِ ملكُ الطالب؛ و`GradeAttempt` يكتب صفاً لكلِّ عنصرٍ بـ`Answer::create()` ⇒ اصطدامٌ **مضمون** ⇒ `QueryException` ⇒ ٥٠٠. **وقبلَ المطالبةِ لا بعدَها**، وإلّا ترك المحاولةَ عالقةً وهو يرفض
- [X] T014 ⚠️ لُفَّ جسمَ `GradeAttempt` بـ`try/catch` يحرّر المطالبةَ (`status = in_progress`) ويعيد الرمي — `claimForGrading()` يقع **خارجَ** `DB::transaction()`، فالمعاملةُ تتراجع والمطالبةُ لا، وتبقى المحاولةُ عند `grading` بلا كاتبٍ في الشجرةِ يعيدها. عطلٌ سابقٌ لهذه المرحلةِ تجعله هي قابلاً للوصول
- [X] T015 [P] اكتبْ `backend/tests/Feature/Assessments/AnswerMarkerTest.php`: تصحيحُ إجابةٍ واحدةٍ صحيحاً وخاطئاً · `MistakeResolved` يُطلَق لسؤالٍ سبق الخطأُ فيه ولا يُطلَق لغيرِه · **ونداءانِ على السؤالِ نفسِه يعطيانِ صفّاً واحداً**
- [X] T016 شغِّلْ `php vendor/bin/pest tests/Feature/Assessments` كاملاً وتأكّدْ من بقاءِ اختباراتِ `GradeAttempt` القائمةِ خضراءَ بلا تعديلِ توكيدٍ واحدٍ منها — الاستخراجُ إعادةُ تنظيمٍ لا تغييرُ سلوك

**Checkpoint**: `AnswerMarker` جاهزٌ ومسارُ التصحيحِ القائمُ سليم. القصصُ الثلاثُ يمكن أن تبدأ.

---

## Phase 3: US1 — المسارُ التكيّفي (P1) 🎯 MVP

**Goal**: طالبٌ يتدرّب على فكرةٍ فتتغيّر صعوبةُ السؤالِ التالي بأدائه، حتى يُتقنها.

**Independent Test**: جلسةٌ يخطئ فيها الطالبُ في الصعبِ ويصيب في السهل، **ورصدُ تسلسلِ
الصعوباتِ المقدَّمة** — لا الاستنتاجُ منه.

### الجداولُ والنماذج

- [X] T017 [US1] هجرةٌ `backend/app/Modules/Assessments/Database/Migrations/…_create_adaptive_sessions_table.php` بأعمدةِ `data-model.md`، ومنها ⚠️ `ceiling_difficulty` و⚠️ `running_key string(64) nullable unique` وفهرسُ `created_at`
- [X] T018 [P] [US1] هجرةٌ `…_create_concept_masteries_table.php` بـ`unique(student_user_id, concept_id)` و`threshold_correct` و`threshold_difficulty` وفهرسُ `created_at`
- [X] T019 [P] [US1] `Models/AdaptiveSession.php` — `BelongsToWorkspace, HasFactory, HasUuid`، وcast للحالةِ والصعوبات
- [X] T020 [P] [US1] `Models/ConceptMastery.php` — السمُ نفسُها
- [X] T021 [P] [US1] مصنعان في `backend/database/factories/Modules/Assessments/{AdaptiveSessionFactory,ConceptMasteryFactory}.php`
- [X] T022 [US1] أضِفْ حالتَي `adaptive_sessions` و`concept_masteries` إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php`

### المنطق

- [X] T023 [US1] `Support/AdaptiveSettings.php` يقرأ المفاتيحَ الأربعةَ من `PlatformSettings`. ⚠️ **ويفرض حدودَها عند القراءة**: `threshold_correct` عمودُه `unsignedTinyInt`، ومشغّلٌ يكتب `300` يجعل الكتابةَ رفضاً في MySQL الصارمةِ وقبولاً صامتاً في SQLite
- [X] T024 [US1] `Support/AdaptiveLadder.php`: (أ) `ceilingFor()` — أعلى صعوبةٍ لها سؤالٌ متاحٌ لهذا الطالبِ في هذه الفكرة، **استعلامٌ واحدٌ فوق `PracticePool`**؛ (ب) `next()` — سؤالٌ واحدٌ بـ`inRandomOrder()->limit(1)`، مطروحاً منه ما قُدِّم في هذه الجلسة؛ (ج) المشيُ إلى أقربِ صعوبةٍ متاحةٍ عند النفاد
- [X] T025 [US1] ⚠️ أضِفْ إلى `Support/PracticePool.php` صيغةً تُرجع **باني استعلامٍ** للمطروح بدل مصفوفة، واستعملْها في `AdaptiveLadder`. الصيغةُ القائمةُ تُخرِج كلَّ أسئلةِ الامتحاناتِ المنشورةِ إلى PHP وتُقيّدها بـ`whereNotIn` — **عددُ الاستعلاماتِ ثابتٌ والحمولةُ تنمو خطّيّاً بحجمِ البنك**، على أسخنِ مسارِ كتابةٍ في المرحلة. لا تحذفِ الصيغةَ القائمة: `BuildSelfExam` يستعملها
- [X] T026 [US1] `Actions/StartAdaptiveSession.php`: يقرأ المفتاحَ **بمعرّفِ مساحةِ عملِ المدرّسِ المحلولِ من معامل `teacher`** ⚠️ (لا من `WorkspaceContext`: هو `null` لكلِّ طالب و`(int) null === 0` يخاطب صفَّ المنصّةِ المطفأ ⇒ ٤٠٣ للجميع)؛ يحلّ `concept` **داخلَ تلك المساحة** على سابقةِ `BuildSelfExam::conceptId()`؛ يطالب `running_key`؛ يحسب السقف؛ يفتح `Attempt(is_practice, exam_id=null)`؛ يقدّم أوّلَ سؤال
- [X] T027 [US1] `Actions/AnswerAdaptiveStep.php`: يعنون بـ`question_id` ⚠️ (لا بـ`order`: `attempt_items` فريدٌ على `(attempt_id, question_id)` وحدَه، و`order` يُسنَد تصاعديّاً فيصير عنواناً مبهماً)؛ ينادي `AnswerMarker`؛ **العدّاداتُ تتحرّك بعد نجاحِ الإدراجِ وحدَه** و`served_count` بـ`increment()`؛ يحرّك السُّلَّم؛ يعلن الإتقانَ عند السقف
- [X] T028 [US1] ⚠️ `Actions/EndAdaptiveSession.php` يختم `submitted_at` و`finalized_at` و`score` **بنفسِه** ويُفرِغ `running_key` في المطالبةِ نفسِها — **لا يمرّ بـ`GradeAttempt`** (يكتب صفاً لكلِّ عنصرٍ فيصطدم) **ولا يُطلق `AttemptFinalized`** (مستمعُه في الإشعاراتِ يُطلق لمحاولاتِ التمرينِ عمداً، فكانت كلُّ جلسةٍ ستُنتج إشعارَ «نتيجةُ اختبار»)
- [X] T029 [US1] `Actions/ListAdaptiveConcepts.php` — ⚠️ **`GROUP BY concept_id` واحدٌ فوق المجمَّع**، لا حلقةٌ تسأله لكلِّ فكرة: الشكلُ البديهيُّ N+1 غيرُ محدود وكلُّ دورةٍ تُعيد بناءَ قائمةِ المطروحِ كاملة. ويُرشِّح بخريطةِ المفاتيحِ لكلِّ مساحةٍ (`Flags::map()`)، **ولا يرفض**
- [X] T030 [P] [US1] `Events/ConceptMastered.php` — DTO بسيط: الطالبُ والفكرةُ والمساحةُ وصفُّ الإتقان
- [X] T031 [P] [US1] `Data/{AdaptiveStartData,AdaptiveAnswerData}.php` ترثانِ `DataTransferObject`

### الواجهةُ البرمجيّة

- [X] T032 [US1] `Http/Requests/{StartAdaptiveRequest,AnswerAdaptiveRequest}.php`
- [X] T033 [US1] `Http/Resources/{AdaptiveSessionResource,AdaptiveQuestionResource,AdaptiveConceptResource}.php` — ⚠️ **ولا `correct_option_ids` ولا `is_correct` في سؤالٍ يُقدَّم**؛ الصوابُ في ردِّ الإجابةِ وحدَه
- [X] T034 [US1] `Http/Controllers/AdaptiveController.php` — ويلتقط `DomainException` **و**`RuntimeException` معاً (`DomainException` يرث `LogicException`)
- [X] T035 [US1] أربعةُ مساراتٍ في `backend/app/Modules/Assessments/routes/api.php` بمُحدِّداتِها من `contracts/api.md`
- [X] T036 [US1] سجِّلِ الحمولاتِ الثلاثَ في `Support/AssessmentFieldAllowlist.php` (`forbiddenDuringAttempt()` وقائمةِ الحقولِ المسموحة)

### الكتالوجاتُ والحقوق

- [X] T037 [US1] أضِفْ `concept_mastered` (`xp=25`, `coins=10`, `daily_cap=3`) إلى `backend/database/seeders/GamificationCatalogSeeder.php`
- [X] T038 [US1] ⚠️ هجرةُ ردمٍ `Gamification/Database/Migrations/…_backfill_adaptive_gamification_action.php` تنادي `seedMissing()`، و`down()` **فارغةٌ** — كتالوجٌ يُقرأ وقتَ التشغيلِ لا يصل قاعدةً قائمةً بغيرِها، و`AwardPoints` يعود صامتاً على مفتاحٍ لا صفَّ له. سقط المستودعُ في هذا ثلاثَ مرّات
- [X] T039 [US1] `Listeners/AwardOnConceptMastered.php` في `Gamification` + سطرُ `Event::listen()` في مزوّدِها
- [X] T040 [US1] فئتا `adaptive_session` (1095 · Delete) و`concept_mastery` (1825 · Delete) في `DataCategorySeeder`، وهجرةٌ تنادي `run()` (فيه `firstOrCreate` سلفاً)
- [X] T041 [US1] ⚠️ أضِفِ الجدولَين إلى **المشياتِ الأربع** في `Support/AssessmentsPersonalData.php` (`describe` · `export` · `erase` · `expire`) — `PersonalDataContractCoverageTest` حارسٌ لكلِّ وحدةٍ **لا يرى جدولاً جديداً داخلَ وحدةٍ مسجَّلة**، فلا شيءَ سيقول لك
- [X] T042 [US1] ازرعْ مفتاحَ `adaptive_practice` **مطفأً** عند `workspace_id = 0` في هجرةٍ

### الواجهة

- [X] T043 [P] [US1] `frontend/src/lib/adaptive.ts` — الأنواعُ والنداءات
- [X] T044 [US1] `frontend/src/components/practice/AdaptiveRunner.tsx` — ⚠️ **الاختيارُ يستبدل ولا يتراكم** (نقرةٌ ثانيةٌ حوّلت إجابةً صحيحةً إلى صفرٍ على ورقةٍ مصحَّحة)، ويعرض `difficulty_note` حين يتغيّر، ويقرأ `ceiling_difficulty` من الحمولةِ **ولا يشتقُّه**
- [X] T045 [US1] `frontend/src/app/(app)/(shell)/practice/adaptive/page.tsx` + رابطٌ إليها من `/practice` — ⚠️ **شاشةٌ لا يصل إليها شيءٌ ليست مُسلَّمة**
- [X] T046 [US1] أضِفْ وسومَ الصعوبةِ والحالةِ إلى `frontend/src/lib/labels.ts` وجملَ `feature_off` إلى `frontend/src/lib/errors.ts`
- [X] T047 [P] [US1] `frontend/src/components/practice/AdaptiveRunner.test.tsx` (vitest) — ⚠️ **يستعمل `fireEvent` لا `userEvent`** إن استُعمِلت مؤقّتاتٌ مزيّفة

### الاختبارات

- [X] T048 [P] [US1] `tests/Feature/Assessments/AdaptiveLadderTest.php` (SC-001) — يرصد **التسلسلَ** `easy easy medium easy easy medium medium hard` صفّاً بصفّ، لا «نزلَت» و«صعدَت»: التوكيدانِ يمرّانِ على بناءٍ يُرجع الصعوبةَ نفسَها دائماً
- [X] T049 [P] [US1] `tests/Feature/Assessments/AdaptiveExhaustionTest.php` (SC-002) — تعطيلُ صعوبةٍ وسطى لا يقطع الجلسة، و`difficulty_note` حاضر
- [X] T050 [US1] ⚠️ `tests/Feature/Assessments/AdaptiveSingleDifficultyTest.php` — **فكرةٌ كلُّ أسئلتها `easy` تبلغ الإتقان**. أهمُّ اختبارٍ في القصّة: لو قِيسَ الإتقانُ عند `hard` حرفيّاً لَما بلغَتْه أبداً — لا `mastered` ولا صفَّ إتقانٍ ولا نقطة، **بلا خطأٍ في أيِّ مكان** — عائلةُ «عنصرٌ يدخل المقامَ ولا يُكمَل»، والحالةُ منصوصةٌ في المواصفة
- [X] T051 [P] [US1] `tests/Feature/Assessments/ConceptMasteryTest.php` — الصفُّ يحمل `threshold_correct` و`threshold_difficulty`، **ورفعُ العتبةِ بعدَه لا يسحب الإتقان**
- [X] T052 [US1] ⚠️ `tests/Feature/Assessments/AdaptiveNoOfficialGradeTest.php` (SC-003) — **بضابطٍ موجب**: محاولةٌ رسميّةٌ مصحَّحةٌ تظهر فعلاً في كشفِ الدرجات، والجلسةُ غائبة. نفيٌ بلا ضابطٍ يمرّ فوقَ كشفٍ فارغ. ويؤكّد ظهورَ الخطأِ في `GET /mistakes`
- [X] T053 [P] [US1] `tests/Feature/Assessments/AdaptiveEntitlementTest.php` (FR-006) — سؤالٌ في امتحانٍ منشورٍ لم يقدّمْه الطالبُ **لا يظهر أبداً**
- [X] T054 [US1] ⚠️ `tests/Feature/Assessments/AdaptiveClaimTest.php` — حالتان: (أ) `AdaptiveLadder` يُسأل بين قراءةِ الحالةِ وكتابتِها ويُفتَح في ذلك النداءِ بدءٌ ثانٍ ⇒ صفٌّ واحدٌ و٤٠٩؛ (ب) إجابةٌ مكرّرةٌ لنفسِ `question_id` ⇒ **٤٠٩ نظيفٌ لا ٥٠٠**. **والاختبارُ التتابعيُّ وحدَه يمرّ فوقَ بناءٍ بلا مطالبةٍ فيه** — التدخّلُ بين القراءةِ والكتابةِ هو العاملُ الآخرُ يفوز، بلا خيوطٍ ولا انتظار
- [X] T055 [US1] ⚠️ `tests/Feature/Assessments/AdaptiveAttemptGuardTest.php` — تسليمُ محاولةِ الجلسةِ عبر `POST /attempts/{attempt}/submit` ⇒ **٤٢٢**، **والجلسةُ تكمل بعدها**. بلا الحارسِ يكون ٥٠٠ وتبقى المحاولةُ عند `grading` بلا كاتبٍ يعيدها
- [X] T056 [P] [US1] `tests/Feature/Assessments/AdaptiveFlagTest.php` — مفتاحٌ مطفأٌ ⇒ ٤٠٣ على البدء، **وقائمةٌ فارغةٌ لا ٤٠٣ على القراءة**؛ ومُشعَلٌ لمساحةِ المدرّسِ ⇒ ينجح **لطالبٍ بلا `last_workspace_id` وسياقٍ مُصفَّر**
- [X] T057 [US1] `tests/Feature/Assessments/AdaptiveQueryBudgetTest.php` (NFR-013) — ⚠️ **يقيس الحمولةَ كما يقيس العدد**، وبمقاسَي بياناتٍ لا بمقاسٍ واحد: **ثباتُ** الكلفةِ هو ما يلتقط N+1، والسقفُ وحدَه لا يلتقطه. ويشمل `GET /practice/adaptive/concepts` بزيادةِ عددِ الأفكار

**Checkpoint**: US1 قابلةٌ للتسليمِ وحدَها. **هذا هو الـMVP.**

---

## Phase 4: US2 — الويبُ يعمل كتطبيق (P2)

**Goal**: تثبيتٌ على الشاشةِ الرئيسيّة، وإشعارُ جهاز، وتصفّحُ ما سبق تخزينُه بلا اتصال.

**Independent Test**: تثبيتٌ على هاتف، تلقّي إشعار، وفتحٌ بلا اتصال.

### عاملُ الخدمةِ والبيان

- [X] T058 [P] [US2] `frontend/src/app/manifest.ts` بـ`MetadataRoute.Manifest` — و`dir: "rtl"` و`lang: "ar"`: بيانٌ صامتٌ عنهما يفتح التطبيقَ المثبَّتَ بترتيبٍ مخالفٍ لموقعه. أيقونتانِ 192 و512 في `frontend/public/brand/`
- [X] T059 [US2] `frontend/src/lib/sw-cache-policy.ts` — **دالّةٌ خالصةٌ** تقرّر ما يُخزَّن، **بقائمةِ سماحٍ لا قائمةِ منع**: قائمةُ منعٍ تحرس `/playback/*` اليومَ وتنفتح لأوّلِ مسارٍ يُضاف غداً بلا خطأ
- [X] T060 [US2] `frontend/src/lib/sw-cache-policy.test.ts` (SC-005) — `false` لـ`/playback/{grant}/manifest` ومقاطعِ `.ts` ومسارِ الترجماتِ ومضيفِ Bunny و`/api/*`، ⚠️ **ومعه ضابطٌ موجب** (`/_next/static/…` ⇒ `true`): قائمةُ سماحٍ تردّ `false` على كلِّ شيءٍ تمرّ بالنفيِ وحدَه
- [X] T061 [US2] `frontend/src/lib/service-worker.ts` يستورد الدالّةَ أعلاه — ⚠️ **الاستيرادُ هو ما يجعل T060 صادقاً**؛ نسخةٌ يدويّةٌ في `public/sw.js` تجعله يقيس دالّةً لا يشغّلها أحد
- [X] T062 [US2] `frontend/src/components/app/ServiceWorkerRegistrar.tsx` بـ`new URL('…', import.meta.url)` و`scope: '/'` و`updateViaCache: "none"` وترقيةٍ فوريّة (FR-012)، مركَّبٌ في `(app)/(shell)/layout.tsx`
- [X] T063 [US2] ⚠️ **اقرأِ النطاقَ المسجَّل** في `DevTools › Application › Service Workers`؛ إن لم يكن `/` فأضِفْ رأسَ `Service-Worker-Allowed: /` في `frontend/next.config.ts › headers()`. **بنطاقٍ ضيّقٍ يبدو كلُّ شيءٍ سليماً — تسجيلٌ ناجحٌ بلا خطأ — ولا يُخزَّن شيءٌ ولا يصل دفعٌ أبداً**
- [X] T064 [US2] ⚠️ إن أُضيفت رؤوسٌ في `next.config.ts`، **لا تنسخْ `Referrer-Policy` من دليلِ Next**: منطقةُ Bunny تردّ **403** على طلبٍ بلا `Referer` مهما صحّ توقيعُه، فـ`no-referrer` يُسقط كلَّ فيديو لكلِّ طالب. اتركِ الافتراضَ `strict-origin-when-cross-origin` واكتبِ السببَ فوقَه
- [X] T065 [P] [US2] `frontend/src/app/offline/page.tsx` — يقول ما يحتاج اتصالاً، ولا شاشةَ بيضاء

### الدفع — الخلفيّة

- [X] T066 [US2] هجرةٌ `Notifications/Database/Migrations/…_create_push_subscriptions_table.php` — ⚠️ **بلا `workspace_id`**، و`unique(user_id, endpoint_hash)` **لا `endpoint_hash` وحدَه**، وفهرسُ `created_at`
- [X] T067 [P] [US2] `Notifications/Models/PushSubscription.php` — `HasFactory, HasUuid`، **بلا `BelongsToWorkspace`**، ومصنعُه في `database/factories/Modules/Notifications/`
- [X] T068 [US2] `Notifications/Actions/SavePushSubscription.php` — ⚠️ `upsert()` على `(user_id, endpoint_hash)` **لا `updateOrCreate`** (تلك `firstOrNew` + `save`: لسانانِ يعيدانِ التسجيلَ يصطدمانِ ⇒ ٥٠٠ على المسارِ السعيد)؛ **ويكتب صفوفَ تفضيلٍ لفئاتِ `Sessions` و`Balance` و`Account`** من `NotificationCategory`، وإلّا لم يصلْ أحداً شيءٌ أبداً
- [X] T069 [P] [US2] `Notifications/Actions/ForgetPushSubscription.php` — بـ`endpoint` مقيَّداً بـ`user_id`
- [X] T070 [US2] `Notifications/Channels/WebPushChannel.php` بسطرِ `->tag('notification.channels')` واحدٍ في مزوّدِ الوحدة — `isEnabled()` = مفاتيحُ VAPID مضبوطة، `canReach()` = اشتراكٌ حيٌّ واحدٌ على الأقلّ، والحمولةُ **عنوانٌ ورابطٌ فقط** لا نصُّ الرسالة (الجهازُ قد يكون مشتركاً). و`410 Gone` يحذف الصفَّ ويُصنَّف دائماً
- [X] T071 [US2] ⚠️ **لا تلمسْ `NotificationType::defaultChannels()`**. `Push` تبقى خارجَها: `DispatchNotification` يكتب صفَّ التسليمِ من `isEnabled()` وحدَه ويُطلق الوظيفة (`canReach()` لا يُسأل إلّا داخلَها) ⇒ صفٌّ ووظيفةٌ لكلِّ إشعارٍ لكلِّ مستلِمٍ على المنصّة؛ و`WhatsAppDefaultsTest:68-69` يؤكّد `SecurityAlert->defaultChannels()` بـ`toBe` فينكسر؛ و`PreferenceResolver` يدمج الافتراضاتِ **فوقَ** التفضيلِ للنوعِ الإلزاميّ فيصير دفعاً **لا يُطفأ**
- [X] T072 [US2] `Http/Controllers/PushSubscriptionController.php` + `Http/Requests/SavePushSubscriptionRequest.php` + مسارانِ في `Notifications/routes/api.php` — ⚠️ **`204` في الحالاتِ الأربع** (أنشأ · حدّث · حذفَ موجوداً · حذفَ غيرَ موجود): `201` مقابل `200` عرّافٌ يخبر السائلَ أنّ العنوانَ مسجَّلٌ سلفاً، وهو ما بُني الردُّ الموحَّدُ في `DELETE` لمنعِه
- [X] T073 [US2] ⚠️ صفُّ `push` في `backend/database/seeders/DataProcessorSeeder.php` + هجرةُ ردم — **بغيابِه يفشل البناءُ**: `ProcessorAllowlistTest` يشتقّ قائمتَه من وسمِ `notification.channels`. وليس طقساً: FCM و Mozilla و Apple أطرافٌ ثالثةٌ تستقبل مُعرِّفَ جهازٍ لكلِّ مستخدم
- [X] T074 [US2] فئةُ `push_subscription` (730 · Delete) في `DataCategorySeeder` + هجرةٌ تنادي `run()`، **والمشياتُ الأربعُ** في `Support/NotificationsPersonalData.php`

### الدفع — الواجهة

- [X] T075 [P] [US2] `frontend/src/lib/push.ts` — `urlBase64ToUint8Array` والاشتراكُ والإلغاء
- [X] T076 [US2] `frontend/src/components/app/PushPermissionPrompt.tsx` — ⚠️ يظهر حين يكون الدفعُ مدعوماً وغيرَ مُشترَكٍ فيه، **ورفضُ الإذنِ لا يعطّل شيئاً** (FR-035)، ويقول للمستخدمِ على iOS إنّه يعمل بعد التثبيتِ على الشاشةِ الرئيسيّة
- [X] T077 [US2] اربطْه من `/settings/notifications` وأضِفْ صفَّ «إشعارٌ فوريّ» إلى شاشةِ التفضيلاتِ القائمة

### الاختبارات

- [X] T078 [P] [US2] `tests/Feature/Notifications/WebPushChannelTest.php` — `isEnabled()` بلا مفاتيحَ ⇒ `false` والتسليمُ **متخطّىً لا فاشلاً** · `canReach()` بلا اشتراك ⇒ `false` · و`410` يحذف الصفَّ ويُصنَّف دائماً
- [X] T079 [US2] ⚠️ `tests/Feature/Notifications/PushSharedDeviceTest.php` — **حسابانِ على `endpoint` واحد ⇒ صفّان، وكلاهما يتلقّى**. بتفرّدٍ عالميٍّ يسرق الثاني صفَّ الأوّلِ ويتوقّف الأوّلُ عن تلقّي كلِّ شيءٍ بما فيه `security_alert` الإلزاميّ، بلا خطأٍ وبلا سجلّ — وهي الفئةُ التي بُنيَت لها علاقاتُ الأوصياءِ في 013. **وفحصٌ بمستخدمٍ واحدٍ يمرّ فوقَ هذا تماماً**
- [X] T080 [US2] ⚠️ أضِفْ حالتَي `push_subscriptions` إلى `tests/Feature/Notifications/PlatformOwnershipTest.php`: (١) **تأكيدُ غياب** — لا مسارَ ولا Resource ولا Filament يمسّ الجدول، ويفشل إن أُضيف (لا بابَ يُختبَر منه، فاستعلامُ نموذجٍ عارٍ لا يُثبِت شيئاً)؛ (٢) الطالبُ يرى اشتراكَه **الواحدَ** عبر كلِّ مدرّسيه
- [X] T081 [P] [US2] `tests/Feature/Notifications/PushDefaultsUnchangedTest.php` — `Push` **ليست** في `defaultChannels()` لأيِّ نوع، و`WhatsAppDefaultsTest` كلُّه ما زال أخضر
- [X] T082 [P] [US2] `tests/Feature/Notifications/PushQuietHoursTest.php` — ساعاتُ الهدوءِ تؤجّل الدفعَ **مقابلَ الصنفِ الحقيقيّ لا المزيَّف**، على سابقةِ `WhatsAppDeliveryPathTest`: كُتبت في 003 ولم تعملْ على قناةٍ خارجيّةٍ حتى 020
- [X] T083 [P] [US2] `tests/Feature/Compliance/ProcessorAllowlistTest.php` يمرّ بلا تعديلٍ فيه — إن فشل، فصفُّ `data_processors` ناقص

**Checkpoint**: US2 قابلةٌ للتسليمِ وحدَها، ومستقلّةٌ عن US1 تماماً.

---

## Phase 5: US3 — غرفُ المذاكرةِ الجماعيّة (P3)

**Goal**: طالبٌ ينشئ غرفةً ويدعو أصدقاءه؛ يحلّون المجموعةَ نفسَها بعدّادٍ ولوحةٍ لحظيّة.

**Independent Test**: إنشاءٌ، وانضمامُ مشاركين، وحلُّ المجموعةِ نفسِها، ورصدُ اللوحةِ اللحظيّة.

### الجداولُ والنماذج

- [X] T084 [US3] هجرةٌ `…_create_study_rooms_table.php` — و`max_participants`، ولا عمودَ حالةٍ إطلاقاً، وفهرسا `(workspace_id, ends_at)` و`(host_user_id, ends_at)` وفهرسُ `created_at`
- [X] T085 [P] [US3] هجرةٌ `…_create_study_room_questions_table.php` بـ`unique(study_room_id, order)` و`unique(study_room_id, question_id)`، **بلا `uuid`**
- [X] T086 [US3] هجرةٌ `…_create_study_room_participants_table.php` — ⚠️ ومعها **`index(user_id, joined_at)`**: الطالبُ ليس عضواً في أيِّ مساحةِ عمل فلا فهرسَ على `study_rooms` يخدم «غرفي»، والفهرسُ المركّبُ الآخرُ يُقرأ من عمودِه الأوّلِ فقط ⇒ مسحٌ كاملٌ لكلِّ فتحةِ صفحة
- [X] T087 [P] [US3] ثلاثةُ نماذجَ في `Assessments/Models/` وثلاثةُ مصانعَ — و`StudyRoom` يحمل `state()` مشتقّاً من الساعة
- [X] T088 [US3] أضِفِ الثلاثةَ إلى `WorkspaceIsolationTest`

### المنطق

- [X] T089 [US3] ⚠️ `Support/StudyRoomAccess.php` — **قراءةٌ فقط**، يناديها الانضمامُ **وحارسُ القناةِ** معاً. `JoinStudyRoom` فعلُ كتابةٍ بـ`handle()` واحدة وكلُّ مدخلٍ في `channels.php` ينادي قدرةً للقراءة؛ وشرطانِ مكتوبانِ متجاورَينِ يضعان جواباً على الشاشةِ وآخرَ عند الباب
- [X] T090 [US3] ⚠️ الأهليّةُ فيه: **مجمَّعُ المنضمِّ نفسِه يحتوي كلَّ سؤالٍ مجمَّدٍ في الغرفة** — استعلامٌ واحد. «تسجيلٌ نشِطٌ في المساحة» يفتح عطلَين: `withheldQuestionIds()` **لكلِّ طالبٍ على حدة** (فمضيفٌ قدّم امتحاناً منشوراً يجمّد أسئلتَه، وكلُّ منضمٍّ لم يقدّمْه يقرؤها **مع مفتاحِ الإجابةِ والشرح**)، و`questionsFor()` مقيَّدٌ **بالكورس** لا بالمساحة. وFR-017 يقول «أسئلتها» لا «مساحتها»
- [X] T091 [US3] `Actions/CreateStudyRoom.php` — يقرأ المفتاحَ بمساحةِ `teacher`؛ يجمّد المجموعةَ من مجمَّعِ المضيف؛ ⚠️ **النقصُ جوابٌ لا فشل**: يبني بالمتاحِ ويردّ `requested_count`/`delivered_count`، ويرفض **فقط** عند الصفر — FR-023 و`BuildSelfExam.php:86` كلاهما ينصّ على ذلك؛ ويفرض سقفَي `question_count ≤ 30` و`max_participants ≤ 30` و`points ≤ 100`
- [X] T092 [US3] ⚠️ الكتابةُ الجُمْليّةُ في `CreateStudyRoom` تمرّر `created_at` و`updated_at` **صراحةً** — `insert()` لا يُشغّل النموذج، وأعمدةُ الطوابعِ تقبل `NULL` بلا خطأٍ على المحرّكَين، **فصفوفٌ لا تنتهي صلاحيّتُها أبداً**. سابقةُ `CreditLedger::writeEntry()`
- [X] T093 [US3] ⚠️ `Actions/JoinStudyRoom.php` — **يطالب صفَّ المشاركةِ أوّلاً** ثمّ يُنشئ المحاولةَ وعناصرَها، **والكلُّ في معاملةٍ واحدة**: الترتيبُ المعكوسُ يترك على الخاسرِ محاولةً يتيمةً وN عنصراً بلا مشاركٍ ولا كنس. وينسخ **اللقطةَ المجمَّدةَ** لا السؤالَ الحيّ (**فلا يُعاد استعمالُ `PracticePaper::write()`**: يأخذ المجموعةَ دفعةً ويبني من `QuestionSnapshot::of($question)`)
- [X] T094 [US3] `Actions/AnswerStudyRoomQuestion.php` — يعنون بـ`question_id`، ينادي `AnswerMarker`، يحدّث الصفَّ بعد نجاحِ الإدراج، ⚠️ **ويختم `finished_at` ويُطلق `StudyRoomFinished` حين تكون هذه هي الإجابةَ التي أكملت المجموعة**، ويختم المحاولةَ كما في T028
- [X] T095 [US3] ⚠️ البثُّ في T094 **مخنوقٌ بثانيةٍ لكلِّ غرفة** — SC-006 يطلب ثانيتَين p95، والبثُّ على كلِّ إجابةٍ من N×M إجابةً إلى N مشتركاً بلا خنقٍ عاصفةٌ
- [X] T096 [P] [US3] `Actions/{ReadStudyRoomBoard,ListStudyRooms}.php` — واللوحةُ ⚠️ **تُحمِّل `first_name` و`last_name`، لا `name`**: `users` لا يحمل ذلك العمود (accessor)، وتحميلٌ مقيَّدٌ به يُرجع اسماً فارغاً — شُحن في ستّةِ مواضعَ قبلَ اليوم، **واللوحةُ تُدفَع إلى كلِّ مشترك فلا شاشةَ يلاحظ فيها أحدٌ «» أوّلاً**
- [X] T097 [P] [US3] `Events/StudyRoomFinished.php` و`Events/StudyRoomBoardUpdated.php` — الثاني `ShouldBroadcast` على `PrivateChannel`
- [X] T098 [P] [US3] `Data/StudyRoomDraftData.php` يرث `DataTransferObject`

### الواجهةُ البرمجيّةُ والقناة

- [X] T099 [US3] `Http/Requests/{CreateStudyRoomRequest,AnswerStudyRoomRequest}.php`
- [X] T100 [US3] `Http/Resources/{StudyRoomResource,StudyRoomBoardResource,StudyRoomQuestionResource}.php` — ⚠️ **ولا `correct_option_ids` ولا شرحٌ لسؤالٍ لم يُجَبْ بعد**؛ اللقطةُ المخزَّنةُ تحملهما
- [X] T101 [US3] `Http/Controllers/StudyRoomController.php` + ستّةُ مساراتٍ بمُحدِّداتِها
- [X] T102 [US3] ⚠️ `Broadcast::channel('study-room-board.{uuid}', …)` في `backend/routes/channels.php` — **قناةٌ خاصّةٌ لا قناةَ حضور**: `config/reverb.php:90` يضبط `accept_client_events_from => 'members'`، فقناةُ الحضورِ تفتح **دردشةً غيرَ مُراقَبةٍ بين قاصرين** داخلَ الغرفة، بلا `hidden_at` ولا `ConversationPolicy` ولا فحصِ حظر. ولا حاجةَ إلى قائمةِ الأعضاءِ: **اللوحةُ هي القائمة**
- [X] T103 [US3] ⚠️ حارسُ القناةِ **صفُّ مشاركةٍ لا أهليّة** (عبر `StudyRoomAccess`)، والغرفةُ تُحَلّ بـ`withoutWorkspaceScope()` داخلَ الـcallback (سياقُ الطالبِ `null` فالنطاقُ لا يضيف شرطاً — القاعدةُ مكتوبةٌ لـ`Conversation` في `channels.php:46-51`)
- [X] T104 [US3] حمولةُ `board.updated`: `uuid` هو **`study_room_participants.uuid`** لا uuidُ المستخدم (الثاني مُعرِّفٌ منصّيٌّ لقاصرٍ يُسلَّم إلى أقرانه)، و`name` و`score` و`answered` — **ولا نصَّ سؤالٍ ولا خيارَ إجابةٍ أبداً**
- [X] T105 [US3] سجِّلِ الحمولاتِ الثلاثَ في `AssessmentFieldAllowlist`

### الكتالوجاتُ والحقوق

- [X] T106 [US3] `study_room_finished` (`xp=15`, `coins=5`, `daily_cap=2`) في `GamificationCatalogSeeder` + هجرةُ ردمٍ بـ`down()` فارغة
- [X] T107 [US3] `Listeners/AwardOnStudyRoomFinished.php` + `Event::listen()`
- [X] T108 [US3] فئتا `study_room` و`study_room_participation` (1095 · Delete) في `DataCategorySeeder` + هجرة، **والمشياتُ الأربعُ** في `AssessmentsPersonalData`
- [X] T109 [US3] ازرعْ مفتاحَ `study_rooms` **مطفأً** عند `workspace_id = 0`

### الواجهة

- [X] T110 [P] [US3] `frontend/src/lib/study-rooms.ts`
- [X] T111 [US3] `frontend/src/components/practice/StudyRoomBoard.tsx` — يشترك عبر `echo()` ⚠️ **بمغلّفٍ لكلِّ نداءٍ لا بـ`useCallback` بمصفوفةٍ فارغة**: pusher-js يفكّ الارتباطَ **بمرجعِ الدالّة** ويحذف كلَّ ما يطابقها، والاستدعاءُ المزدوجُ في التطوير يقتل مشترِكاً واحداً. ويعرض العدّادَ ويقرأ `state` من الحمولةِ **ولا يشتقُّه**
- [X] T112 [US3] `frontend/src/app/(app)/(shell)/study-rooms/{page.tsx,[uuid]/page.tsx}` + رابطٌ إليهما من `/practice` — ⚠️ شاشةٌ لا يصل إليها شيءٌ ليست مُسلَّمة
- [X] T113 [US3] جملُ `room_closed` و`room_full` و`not_eligible` في `frontend/src/lib/errors.ts`، ⚠️ **وشاراتُ الدرجةِ والحالةِ برموزِ `@theme` قائمةٍ فقط** — أربعةُ رموزٍ غيرِ معرَّفةٍ شُحنت قبلَ اليوم فطُليَ بها لا شيء، وهذه الشاشةُ بالضبط موضعُ التكرار. أضِفِ الرموزَ الجديدةَ إلى `frontend/src/lib/theme-tokens.test.ts`
- [X] T114 [P] [US3] `frontend/src/components/practice/StudyRoomBoard.test.tsx` (vitest)

### الاختبارات

- [X] T115 [US3] ⚠️ `tests/Feature/Assessments/StudyRoomEligibilityTest.php` (SC-008) — **ثلاثُ حالاتٍ والثانيةُ والثالثةُ هما اللتان تكشفان**: (١) طالبٌ من مساحةٍ أخرى ⇒ 403؛ (٢) **طالبٌ في كورسٍ آخرَ عند المدرّسِ نفسِه ⇒ 403**؛ (٣) **مضيفٌ قدّم امتحاناً منشوراً ومنضمٌّ لم يقدّمْه ⇒ 403**. اختبارٌ يقتصر على الأولى يمرّ فوقَ العطلَين تماماً
- [X] T116 [P] [US3] `tests/Feature/Assessments/StudyRoomResumeTest.php` (SC-007) — انقطاعٌ وعودةٌ بلا فقدِ إجابة
- [X] T117 [US3] ⚠️ `tests/Feature/Assessments/StudyRoomFinishTest.php` — (أ) إكمالُ المجموعةِ ⇒ `finished_at` ونقاطٌ مُقيَّدة؛ (ب) توقّفٌ عند ٨ ومرورُ `ends_at` ⇒ النتيجةُ تُعرَض و`finished_at` فارغٌ **ولا نقطة**. ⚠️ **و`Queue::fake()` بالأسماءِ لا عارياً**: العاري يبتلع مستمعَ المنحةِ المطبورَ فيصير التوكيدُ ادّعاءً عن `award_entries` فارغ
- [X] T118 [P] [US3] `tests/Feature/Assessments/StudyRoomShortPaperTest.php` — طلبُ ٢٠ من فكرةٍ فيها ٦ ⇒ **٢٠١** بـ`delivered_count: 6`؛ والرفضُ عند الصفرِ وحدَه
- [X] T119 [US3] ⚠️ `tests/Feature/Assessments/StudyRoomChannelTest.php` — طالبٌ **مؤهَّلٌ لم ينضمّ** يُرفَض اشتراكُه، **ويُقاس عبرَ `/broadcasting/auth` الحقيقيّ**: `subscribeToChannel()` يعمل من عمليّةٍ ضُبِط فيها مُعرِّفُ الفريقِ سلفاً، فيُثبِت الـcallback ولا يُثبِت الطلبَ — العطلُ الذي تركَ كلَّ اشتراكٍ خاصٍّ مرفوضاً في الإنتاج
- [X] T120 [P] [US3] `tests/Feature/Assessments/StudyRoomNoOfficialGradeTest.php` (SC-003) — **بضابطٍ موجب** كما في T052
- [X] T121 [P] [US3] `tests/Feature/Assessments/StudyRoomClosureTest.php` — الانضمامُ بعد `ends_at` ⇒ 409 `room_closed`، **والعاملُ متوقّفٌ تماماً**: الإغلاقُ مشتقٌّ من الساعةِ فلا وظيفةَ تنتظره
- [X] T122 [P] [US3] `tests/Feature/Assessments/StudyRoomCapacityTest.php` — بلوغُ `max_participants` ⇒ 409 `room_full`؛ وإحدى عشرةَ غرفةً ⇒ 429، ⚠️ **ثمّ بدءُ جلسةٍ تكيّفيّةٍ فوراً ينجح** (مُحدِّدانِ منفصلان)
- [X] T123 [US3] `tests/Feature/Assessments/StudyRoomBoardBudgetTest.php` — ⚠️ يقيس **ثباتَ** كلفةِ اللوحةِ بزيادةِ عددِ المشاركين، **ويؤكّد أنّ `name` غيرُ فارغ**: التوكيدانِ يحرسانِ الخطأَينِ المتضادَّين — إسقاطُ التحميلِ المسبقِ، وإسقاطُ الحقلِ الذي يجعله آمناً

**Checkpoint**: القصصُ الثلاثُ مُسلَّمة.

---

## Phase 6: Polish & Cross-Cutting

- [ ] T124 [P] حدِّثْ `docs/README.md` بقسمِ «المسارُ التكيّفيُّ وغرفُ المذاكرةِ والإشعارُ الفوريّ (spec 012)»: جدولُ النقاطِ الاثنتَي عشرةَ، والمفتاحان، والمُحدِّدانِ الجديدان، وقناةُ البثّ
- [ ] T125 [P] حدِّثْ `docs/erd.md` بمخطّطاتِ الجداولِ الستّةِ وقسمَي تعليلٍ: لماذا `running_key` قابلٌ للإفراغِ وفريد، ولماذا `(user_id, endpoint_hash)` مزدوج
- [ ] T126 أضِفْ إلى `CLAUDE.md` نقاطَ Gotchas: (أ) `claimForGrading()` خارجَ المعاملةِ يترك المحاولةَ عالقةً للأبد؛ (ب) `withheldQuestionIds()` لكلِّ طالبٍ على حدة، فورقةٌ مجمَّدةٌ من مجمَّعِ شخصٍ تسرّب امتحانَ آخرَ؛ (ج) قناةُ الحضورِ تقبل الهمسَ والخاصّةُ ترفضه؛ (د) `Push` خارجَ `defaultChannels()` لأنّ صفَّ التسليمِ يُكتب قبل `canReach()`؛ (هـ) `(int) null === 0` يجعل مفتاحَ الميزةِ مطفأً لكلِّ طالب
- [ ] T127 [P] حدِّثْ `AGENTS.md` بأمرِ `php vendor/bin/pest tests/Feature/Assessments` ضمنَ المسالكِ الحرجة
- [ ] T128 ⚠️ صحِّحْ في `CLAUDE.md` العددَ المذكورَ لـ`WhatsAppDefaultsTest` — يقول «اثنانِ وعشرون» والملفُّ يؤكّد **٢٥**. **اقرأِ التوكيدَ، لا الجملةَ التي تصفه** — النصُّ التشغيليُّ نفسُه خضعَ لقاعدتِه
- [ ] T129 شغِّلِ البوّاباتِ **المستهدَفة**: `pest tests/Feature/{Assessments,Notifications,Tenancy,Compliance}` · `pint` · `phpstan analyse` (صفرٌ، بلا baseline) · `tsc --noEmit` · `npm test`. ⚠️ **ولا مجموعةَ كاملةً محليّاً** — أمرٌ قائمٌ من المالك؛ الكاملُ على CI
- [ ] T130 امشِ على `quickstart.md` يدويّاً: **T063 (نطاقُ عاملِ الخدمة) أوّلاً**، ثمّ US1·ج (فكرةٌ بصعوبةٍ واحدة)، ثمّ US2·ز (حسابانِ على جهاز)، ثمّ US3·ب (الحالاتُ الثلاث) — الأربعُ هي التي لا يراها اختبارٌ بفرضيّةٍ واحدة

---

## Dependencies

```
Phase 1 (Setup)  ──►  Phase 2 (AnswerMarker + الحارسان)  ──┬──► Phase 3 · US1  ──┐
                                                            ├──► Phase 5 · US3  ──┼──► Phase 6
                                    Phase 1 ────────────────┴──► Phase 4 · US2  ──┘
```

- **Phase 2 يحجب US1 و US3** (كلتاهما تنادي `AnswerMarker`)، **ولا يحجب US2** إطلاقاً.
- **US2 مستقلّةٌ تماماً** ويمكن أن تسير موازيةً لـUS1 من أوّلِ يوم.
- **US3 تستفيد من US1** (السُّلَّمُ والـenums و`AdaptiveSettings`) لكنّها لا تحتاجها: يمكن قلبُ
  ترتيبِهما بكلفةِ تكرارٍ صغير.

## Parallel Opportunities

- **Phase 1**: T002 · T003 · T004 · T007 معاً.
- **Phase 2**: T009 · T010 مع T008.
- **US1**: T018–T021 معاً؛ T030 · T031 معاً؛ T043 · T047 معاً؛ T048 · T049 · T051 · T053 · T056 معاً.
- **US2**: T058 · T065 · T067 · T069 · T075 معاً؛ T078 · T081 · T082 · T083 معاً.
- **US3**: T085 · T087 · T096 · T097 · T098 معاً؛ T116 · T118 · T120 · T121 · T122 معاً.
- **Phase 6**: T124 · T125 · T127 معاً.

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 3 (US1).** أرخصُ ما في المرحلةِ وأعلاها عائداً بنصِّ
المواصفة، ومستقلّةٌ تماماً عن الأخريَين. تُدمَج خضراءَ وحدَها، ثمّ US2، ثمّ US3 — **دفعةٌ
واحدةٌ في آخرِ المرحلةِ تُراجَع بلا مراجِع**.

**والترتيبُ الداخليُّ ثابت**: Phase 2 أوّلاً في كلِّ حال. هو المسُّ الوحيدُ بمسارٍ حرجٍ قائم
(تسليمُ الاختبارِ والتصحيح)، والحارسانِ فيه (T013 · T014) يمنعان عطباً دائماً **يفتحه هذا
التصميمُ نفسُه** — فبناءُ US1 قبلَهما يعني شحنَ الثغرةِ ثمّ إغلاقَها.
