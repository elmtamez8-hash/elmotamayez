# Tasks: بنك الأسئلة والتقييم التحليلي (‏008 `question-bank-analytics`)

**Input**: `specs/008-question-bank-analytics/` — `plan.md` · `spec.md` · `research.md` ·
`data-model.md` · `contracts/` · `quickstart.md`

**Tests**: **مطلوبة صراحةً.** عشرةٌ من اثنين وعشرين معيار نجاح تقول «**مُثبَتة باختبار**»،
و`NFR-006` يجعل البوابات الأربع معياراً، و`quickstart.md` يعدّد أربعة عشر سيناريو تحقّق.

**Organization**: بالقصص السبع، لتُسلَّم كلٌّ منها وتُختبر وحدها.

> **مبنيّةٌ بعد مراجعة خمسة وكلاء (‏2026-08-15، `192fc56`).** تسعة أعطالٍ كانت ستُشحن صامتة
> صارت مهامَّ صريحة، وهي موسومة ⚠️ **[‏مر‏]** حيث غيّرت المهمّةَ لا حيث زادت الشرح.

---

## الشكل: `[ID] [P?] [Story] الوصف + المسار`

- **[P]** — ملفٌّ مختلف وبلا تبعية على مهمّةٍ ناقصة. **تُفحَص القاعدة داخل الطور وعبره.**
- **[Story]** — في أطوار القصص وحدها.
- **⚠️ سلسلة الهجرات لا تحمل `[P]` أبداً.** ترتيبها تصميمٌ لا تفصيل: خطوةٌ قبل موضعها تُسقط
  النشرة على بياناتٍ حيّة (`data-model.md` §ط).

---

## ⚠️ ما لا يُبنى، مكتوباً هنا كي لا يُبنى سهواً

| ما قد يبدو مطلوباً | القرار | السند |
|---|---|---|
| `mistake_entries` جدولاً | **لا مهمّة له** — الدفتر مشتقّ | `research.md` §ج |
| `question_versions` | **لا مهمّة له** — اللقطة على عناصر المحاولة | `research.md` §ب |
| عمود `is_unlocked` في أي جدول | **لا مهمّة له** — يُحسَب عند كل طلب | `research.md` §ح |
| جدول تقديرات / كشفٌ تراكمي | **لا مهمّة له** — «رسمي» وسمٌ يُرشَّح، والكشف وعدُ ‎010‎ | `Q4` · `data-model.md` §ي |
| `student_needs` ككيان منصّة | **لا مهمّة له** — الشكل جاهزٌ إن طُلب، ولم يُطلَب | `research.md` §و |
| `bank.manage` · `analytics.questions.view` صلاحيتين جديدتين | **لا مهمّة لهما** — ⚠️ **[‏مر‏]** `questions.manage` و`analytics.view` مشحونتان منذ ‎003‎، واسمان لصلاحيةٍ واحدة يتنازعان شاشةً واحدة | `contracts/api.md` §١ |
| مستوى شرط فتحٍ على الحصة | **لا مهمّة له** — ⚠️ **[‏مر‏]** مستويان لا ثلاثة | `Q5` · `FR-037` |
| مكتبة جداول بيانات (`maatwebsite/excel`) | **لا مهمّة لها** — CSV/TSV بـ`fgetcsv` | `research.md` §ط |
| SCORM · xAPI · LTI · QTI | **خارج النطاق عمداً** | `spec.md` §Assumptions |
| `PlaybackGrant` لملفّ التسليم | **لا مهمّة له** — رابطٌ موقَّع، لا آلةُ بثّ | `research.md` §ز |
| مفتاح `media_asset_id` إلى `media_assets` | **لا مهمّة له** — ⚠️ **[‏مر‏]** الجدول متعدّد الأشكال ويملك أصحابه؛ المكتبة على القرص الخاص كسابقة الإيصالات | `research.md` §ز |
| تصحيحٌ آليّ للمقاليّ | **ممنوع صراحةً** | `FR-034` |

**وثلاثة متطلبات تُثبَّت باختبار ولا تُبنى**: `FR-003` (الأنواع الثلاثة قائمة) ·
`FR-013` (شكل `null` مشحونٌ في ‎006‎ ويُعاد استعماله) · `FR-030` نصفُه (عقد الشهادات قائم).

---

## Phase 1: Setup — الصلاحيات والتعدادات والمحدِّدات

**Purpose**: ما تحتاجه كل قصّةٍ بعدها، ولا يلمس بياناتٍ قائمة.

- [x] T001 أضف الثمانية الجديدة كثوابت في `backend/app/Modules/Tenancy/Support/Permissions.php`: `BANK_VIEW` · `GRADING_PERFORM` · `GRADING_REVISE` · `ANALYTICS_CROSS_TEACHER_VIEW` · `ASSIGNMENTS_MANAGE` · `SUBMISSIONS_GRADE` · `ACCOMMODATIONS_MANAGE` · `UNLOCK_RULES_MANAGE`
- [x] T002 ⚠️ **[‏مر‏]** أضف الثمانية إلى `Permissions::all()` في نفس الملف — ثابتٌ خارجها **لا يُزرع فلا يحمله أحد ولا المشرف الأعلى**، وكل فحصٍ عليه يفشل بلا سببٍ ظاهر
- [x] T003 في `backend/app/Modules/Tenancy/Support/RolePermissionMatrix.php`: ضع السبعة المستأجرة في مصفوفة **المدرّس** لا المساعد (‏`FR-031` «إن مُنح»)، واترك `ANALYTICS_CROSS_TEACHER_VIEW` خارج كل مصفوفة فتصير منصّية بالاشتقاق
- [x] T004 ⚠️ **[‏مر‏]** **اسحب `Permissions::QUESTIONS_MANAGE` من مصفوفة `$assistantTeacher`** في نفس الملف — هي عنده اليوم، أي أن المساعد يملك البنك كاملاً قبل أن يُكتب سطرٌ واحد
- [x] T005 [P] أضف تسميات الثمانية العربية إلى `backend/app/Modules/Tenancy/Support/PermissionLabels.php` (`SUBJECTS` و`ACTIONS`) لتظهر مقروءةً في شاشة الأدوار
- [x] T006 ⚠️ **[‏مر‏]** سجّل محدِّد `practice` **بمفتاح المستخدم لا بعنوان الشبكة** في `AppServiceProvider::registerRateLimiters()` بـ`backend/app/Providers/AppServiceProvider.php` (`FR-026`) — بالعنوان وحده تُسكِت مدرسةٌ خلف عنوانٍ واحد صفَّها كلَّه بطالبٍ واحد. `authoring` و`upload` **مسجَّلان سلفاً ويُعاد استعمالهما**
- [x] T007 [P] أنشئ التعدادات المغلقة في `backend/app/Modules/Assessments/Enums/`: `BloomLevel` · `SubmissionType` · `LatePolicy` · `SubmissionState` · `DuplicatePolicy` · `ImportStatus`
- [x] T008 [P] أنشئ مجلدات الوحدة الناقصة تحت `backend/app/Modules/Assessments/`: `Http/Requests/` · `Http/Resources/` · `Data/` · `Events/` · `Jobs/` (‏`Http/Controllers` و`Actions` و`Models` و`Policies` قائمة)
- [x] T009 اختبار: `backend/tests/Feature/Tenancy/PermissionSeedTest.php` — كل ثابتٍ في `Permissions::all()` مزروعٌ بعد `RolesAndPermissionsSeeder`، ولا صلاحية منصّية يحملها دورٌ بـ`team_id`

---

## Phase 2: Foundational — سلسلة الهجرات واللقطة

**Purpose**: إعادة توجيه `questions` إلى البنك، وكتابة «ما رآه». **تحجب كل قصّةٍ بعدها.**

⚠️ **الترتيب مُلزِم ولا `[P]` فيه.** والخطوة ‎٦‎ (حذف `exam_id`) **ليست هنا**: نشرةٌ تالية،
`T180`.

### أ — البنك: الجداول والوسوم

- [x] T010 هجرة ‎١‎: أنشئ `concepts` (‏`unique(workspace_id, name)` · `created_by` قابل للإفراغ) في `backend/app/Modules/Assessments/Database/Migrations/2026_08_15_000100_create_concepts_table.php`
- [x] T011 هجرة ‎١‎ب: أنشئ فكرة «غير مصنّف» لكل مساحة عملٍ قائمة في `..._000110_seed_unclassified_concept.php` — ⚠️ الكتابة بـ`DB::table()` لأن `BelongsToWorkspace` بلا سياقٍ في CLI، **فيُمرَّر `uuid` و`created_at` صراحةً**
- [x] T012 هجرة ‎٢‎: أضف إلى `questions` أعمدةً **كلَّها قابلة للإفراغ بعد**: `uuid` · `concept_id` · `lesson_id` · `bloom_level` · `content_hash` · `is_active` في `..._000200_add_bank_columns_to_questions.php`
- [x] T013 هجرة ‎٣‎: املأ `uuid` و`concept_id` (‏«غير مصنّف») و`bloom_level` (‏`unclassified`) و`content_hash` بـ**`chunkById`** في `..._000300_backfill_question_bank_columns.php` — ⚠️ `chunk` يرقّم بالإزاحة والشرط يتقلّص تحته، فيقفز صفوفاً **ويبلّغ نجاحاً**
- [x] T014 هجرة ‎٤‎: حوّل `concept_id` و`bloom_level` إلى `NOT NULL` **ثم** أنشئ `unique(uuid)` و`unique(workspace_id, content_hash)` في `..._000400_lock_question_bank_columns.php` — ⚠️ **[‏مر‏]** التحويل يعيد بناء الجدول على SQLite، فالفهرس الفريد **بعده لا معه** وإلّا سقط بصمت
- [x] T015 هجرة ‎٥‎: أنشئ `exam_items` (‏`unique(exam_id, question_id)` · `index(question_id)`) **واملأه من `questions.exam_id`** في `..._000500_create_exam_items_table.php` — الضمّ يُبنى قبل أن يُحذف مصدره

### ب — المحاولة: اللقطة والمقام

- [x] T016 هجرة ‎٧‎أ: أنشئ `attempt_items` (‏`snapshot` json · `points` · `order` · `unique(attempt_id, question_id)`) في `..._000700_create_attempt_items_table.php`
- [x] T017 هجرة ‎٧‎ب: أضف إلى `exam_answers`: `uuid` · `student_user_id` · `answer_text` · `requires_grading` · `graded_at` · `graded_by` · `grading_version` في `..._000710_add_grading_columns_to_exam_answers.php` — ⚠️ **[‏مر‏]** الجدول **لا يحمل `uuid` اليوم**، ومسارا التصحيح يربطان به؛ البديل كشفُ المعرّف المتسلسل
- [x] T018 هجرة ‎٧‎ج: املأ `exam_answers.uuid` و`student_user_id` من `exam_attempts` بـ`chunkById` في `..._000720_backfill_exam_answer_columns.php`
- [x] T019 هجرة ‎٧‎د: ⚠️ **[‏مر‏]** ابنِ `attempt_items` **للمحاولات القائمة** من `exam_answers` + `questions` الحيّة، موسومةً `backfilled: true` في اللقطة، في `..._000730_backfill_attempt_items.php` — بدونها مقامُ كل محاولةٍ قديمة **صفر**، و**اختبار `SC-015` يمرّ وهو أعمى** لأنه يقيس `score` وحده
- [x] T020 هجرة ‎٧‎هـ: أضف `unique(attempt_id, question_id)` إلى `exam_answers` في `..._000740_add_answer_uniqueness.php` — حارس `NFR-011` عند المحرّك
- [x] T021 هجرة ‎٧‎و: على `exam_attempts` أضف `pending_grading` إلى الحالات · `finalized_at` · `is_practice` · **اجعل `exam_id` قابلاً للإفراغ** · `index(workspace_id, status, submitted_at)` في `..._000750_extend_exam_attempts.php`

### ج — النماذج والأفعال القائمة

- [x] T022 [P] نموذج `Concept` في `backend/app/Modules/Assessments/Models/Concept.php` بـ`HasUuid` و`BelongsToWorkspace`
- [x] T023 [P] نموذج `ExamItem` في `.../Models/ExamItem.php` بـ`HasUuid` و`BelongsToWorkspace`
- [x] T024 [P] نموذج `AttemptItem` في `.../Models/AttemptItem.php` بـ`BelongsToWorkspace` وصبّ `snapshot` إلى `array`
- [x] T025 [P] مصانع الثلاثة في `backend/database/factories/Modules/Assessments/`
- [x] T026 وسّع `.../Models/Question.php`: علاقات `concept` و`examItems`، ونطاق `active()`، وإسقاط `exam_id` من `$fillable`
- [x] T027 وسّع `.../Models/Answer.php` بـ`HasUuid` والأعمدة الجديدة، وعلاقة `gradingRecords`
- [x] T028 وسّع `.../Actions/StartAttempt.php`: يكتب صفّ `attempt_items` لكل سؤالٍ **عند البدء** بلقطته وترتيبه ودرجته
- [x] T029 ⚠️ **[‏مر‏]** في `StartAttempt::guardAttemptLimit()`: استثنِ `is_practice` من العدّ — العدّاد المشحون يَعُدّ **كل** المحاولات، فأوّل تدريبٍ يلتهم فرصةً رسمية (`FR-026أ`)
- [x] T030 ⚠️ **[‏مر‏]** استبدل `count()` ثمّ `insert()` في `guardAttemptLimit()` بمطالبةٍ شرطية ذرّية — الشكل الذي يمنعه المشروع نصّاً، ومحاولتان متزامنتان عند `max_attempts - 1` تمرّان اليوم كلتاهما
- [x] T031 وسّع `.../Actions/GradeAttempt.php`: المقام من `attempt_items` لا من أسئلة الاختبار الحيّة، والتصحيح يقرأ **اللقطة**
- [x] T032 ⚠️ **[‏مر‏]** في `GradeAttempt`: اكتب صفّ إجابةٍ **لكل `attempt_item`** لا للمُجاب عنه وحده — السؤال المتروك بلا صفٍّ يغيب عن دفتر الأخطاء، وهو **أقوى دليلٍ على فجوةٍ معرفية** فيه
- [x] T033 ⚠️ **[‏مر‏]** في `backend/app/Modules/Assessments/Http/Controllers/AttemptController.php`: استبدل فحص `isGraded()` بمطالبةٍ ذرّية `UPDATE … WHERE status = 'in_progress'` قبل أي كتابة — القراءةُ ثم الكتابة تعريف السباق، ونقرتان تكتبان مجموعة الإجابات مرّتين
- [x] T034 [P] `AssessmentFieldAllowlist` في `.../Support/AssessmentFieldAllowlist.php` — ⚠️ **[‏مر‏]** ويكتب في ترويسته أن الحظورات الصفّية (‏إجابةُ غيرك · تسليمُ غيرك · وجودُ تسهيل) **ليست من اختصاصه**، لأن حارساً موصوفاً بلا حدودٍ يُقرأ كتغطية

### د — حرّاس الطبقات

- [x] T035 اختبار: أضف الجداول الجديدة إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — `concepts` · `exam_items` · `attempt_items` وبقيّة الأحد عشر: **صفر تسريبٍ لسؤالٍ أو محاولةٍ أو تحليلٍ بين مساحات العمل** (`SC-014`)
- [x] T036 ⚠️ **[‏مر‏]** اختبار: `backend/tests/Feature/Assessments/BridgeOwnershipTest.php` — لكلٍّ من الجسور السبعة اتجاهان: مدرّسٌ يسمّي طالباً **بلا تسجيلٍ نشط عنده** يُمنع، والطالب يرى صفّه (`NFR-001ب` كان يُستوفى بصفر اختبارات)
- [x] T037 اختبار: `backend/tests/Feature/Assessments/MigrationIntegrityTest.php` — درجات المحاولات القائمة **بفارق صفر** بعد السلسلة، **ومراجعةُ محاولةٍ قديمة تُفتح بمقامها الصحيح لا بصفر** (`SC-015`)
- [x] T038 اختبار: `backend/tests/Feature/Assessments/AttemptConcurrencyTest.php` — تقديمٌ متزامن مرّتين ⇒ **مجموعة إجاباتٍ واحدة وحدثٌ واحد**؛ ومحاولتان متزامنتان عند حدّ المحاولات ⇒ واحدة (`NFR-011` · `SC-021`)
- [x] T039 [P] أضف الوحدة إلى `phpstan.neon` إن لزم، وشغّل `./vendor/bin/phpstan analyse` للتأكد من نظافة الشجرة بعد الأعمدة الجديدة

**Checkpoint**: البنك موجود، واللقطة تُكتب، والمحاولات القديمة سليمة. **كل قصّةٍ بعدها مستقلّة.**

---

## Phase 3: US1 — بنك أسئلة موسوم قابل لإعادة الاستعمال (P1)

**Goal**: مدرّس يبني بنكه مرّة، يوسمه، ويبني منه اختباراتٍ متعدّدة — ويستورد مئات الأسئلة من ملف.

**Independent Test**: بناء بنك، وسم أسئلته، بناء اختبارين منه، واستيراد دفعة — **بلا أي محاولة**.

### أ — العقد والحرّاس

- [x] T040 [P] [US1] `QuestionPolicy` في `.../Policies/QuestionPolicy.php` على `Permissions::QUESTIONS_MANAGE` و`BANK_VIEW`
- [x] T041 [P] [US1] `ConceptPolicy` في `.../Policies/ConceptPolicy.php`
- [x] T042 [US1] سجّل البوليصتين في `backend/app/Modules/Assessments/AssessmentsServiceProvider.php`

### ب — الأسئلة والوسوم

- [x] T043 [P] [US1] `SaveQuestionData` DTO في `.../Data/SaveQuestionData.php` يرث `DataTransferObject`
- [x] T044 [US1] وسّع `.../Actions/SaveQuestion.php`: الوسوم الأربعة **إلزامية** ويُرفَض الناقص (`FR-002`)، و`content_hash` يُحسب عند الكتابة
- [x] T045 [US1] `DisableQuestion` في `.../Actions/DisableQuestion.php` — **تعطيلٌ لا حذف** متى وُجدت محاولة (`FR-005`)
- [x] T046 [P] [US1] `SaveQuestionRequest` و`SaveConceptRequest` في `.../Http/Requests/` — ⚠️ التحقّق بـ`WorkspaceRules::exists()` لا `exists:table,id`
- [x] T047 [P] [US1] أضف أسماء الحقول العربية إلى `backend/lang/ar/validation.php` تحت `attributes` — بدونها يُعرض `concept_id` نصّاً للمدرّس
- [x] T048 [P] [US1] `BankQuestionResource` و`ConceptResource` في `.../Http/Resources/`

### ج — البحث والتصفّح

- [x] T049 [US1] `BankSearch` في `.../Support/BankSearch.php`: الترشيح (‏فكرة · درس · صعوبة · مستوى · نشط) **استعلام SQL مفهرَس**، والنصّ الحرّ بـScout **مع `->where('workspace_id', …)` صريح** (`NFR-007`)
- [x] T050 [US1] `BankController` في `.../Http/Controllers/BankController.php` — ⚠️ **بخطّة تحميلٍ مسبق مُعلَنة** (`concept` · `lesson` · `stats` · عدد الاستعمال)، فـ`Resource` بلا تحميلٍ مسبق **‎N+1‎ بالبناء**
- [x] T051 [US1] `ConceptController` في `.../Http/Controllers/ConceptController.php`
- [x] T052 [US1] المسارات في `backend/app/Modules/Assessments/routes/api.php` بمحدِّداتها المسمّاة (`throttle:authoring`)

### د — عناصر الاختبار

- [x] T053 [P] [US1] `SyncExamItems` في `.../Actions/SyncExamItems.php` — **القائمة الكاملة** لا التعديل الجزئي، على سابقة إعادة ترتيب الشجرة في ‎016‎
- [x] T054 [US1] `ExamItemsController` + `SyncExamItemsRequest` + `ExamItemResource`

### هـ — الاستيراد

- [ ] T055 [US1] هجرة ‎١٠‎أ: `question_imports` (‏`duplicate_policy` · `skipped_count` · `report` json · `status`) في `..._001000_create_question_imports_table.php`
- [ ] T056 [P] [US1] نموذج `QuestionImport` ومصنعه
- [ ] T057 [US1] `ImportQuestions` في `.../Actions/ImportQuestions.php`: `fgetcsv` بلا مكتبة، **وإزالة BOM من أول الملف** وإلّا صار اسم أول عمودٍ في كل ملفٍ صادرٍ من Excel غير مطابق
- [ ] T058 [US1] في `ImportQuestions`: تقريرٌ صفّاً صفّاً — الناجح والفاشل **والسبب بالضبط** — و**الدفعة لا تسقط لسقوط صفّ** (`FR-007`)
- [ ] T059 [US1] في `ImportQuestions`: طبّق `duplicate_policy` على `unique(workspace_id, content_hash)` — ⚠️ **[‏مر‏]** التخطّي **حكمٌ من المحرّك** لا «ابحث ثم أدرج»، وإلّا فنافذتان تُدرجان معاً
- [ ] T060 [US1] `ImportQuestionsJob` في `.../Jobs/ImportQuestionsJob.php` — ⚠️ `forWorkspace()` **لا** `WorkspaceContext::set()`
- [ ] T061 [US1] ⚠️ **[‏مر‏]** في `ImportQuestionsJob`: انتقال `status` من `queued` بمطالبة `UPDATE … WHERE status = 'queued'` — إعادةُ محاولة Horizon بعد مهلةٍ منتصف الملف تُعيد استيراد ما التزم إدراجه
- [ ] T062 [US1] `ImportController` + `StartImportRequest` (‏الملف **و`duplicate_policy`** معاً) + `ImportReportResource`
- [ ] T063 [US1] `QuestionImported` في `.../Events/QuestionImported.php` ومستمع الإشعار عبر `DispatchNotification`
- [ ] T064 [P] [US1] أضف قالب إشعار «تقرير الاستيراد جاهز» إلى `backend/database/seeders/NotificationTemplateSeeder.php` — ⚠️ **إشعارٌ بلا قالبٍ يُسقَط بصمت**

### و — سحب الأبواب الخلفية

- [x] T065 [US1] ⚠️ **[‏مر‏]** احذف المسارات الأربعة `‏/exams/{exam}/questions` من `backend/app/Modules/Assessments/routes/api.php` و`QuestionController` القديم — `DELETE` منها **حذفٌ نهائي** لسؤالٍ له محاولات (‏خرق `FR-005`)، و`POST` ينشئ سؤالاً **بلا وسوم** (‏خرق `FR-002`)، وحارس ملكيّتها الوحيد `exam_id` الذي تحذفه الهجرة ‎٦‎
- [x] T066 [US1] حدّث `backend/database/seeders/ScenarioSeeder.php` لبناء البنك بالوسوم وضمّ الأسئلة بـ`exam_items`

### ز — الواجهة

- [ ] T067 [P] [US1] `frontend/src/lib/bank.ts` — عميل البنك والاستيراد
- [ ] T068 [US1] `frontend/src/app/(app)/(shell)/manage/bank/page.tsx` — تصفّحٌ وترشيحٌ وبحث
- [ ] T069 [US1] `frontend/src/app/(app)/(shell)/manage/bank/[uuid]/page.tsx` — تحرير سؤالٍ ووسومه
- [ ] T070 [US1] `frontend/src/app/(app)/(shell)/manage/bank/import/page.tsx` — ⚠️ **تقول «‏CSV — وXLSX غير مدعوم بعد»** بدل أن ترفض ملفاً بلا سبب، **وتختار سياسة التكرار قبل الرفع**
- [ ] T071 [US1] ⚠️ **رابطٌ وارد**: أضف «بنك الأسئلة» إلى قائمة القشرة في `frontend/src/components/` — صفحةٌ لا يصلها شيءٌ غير مُسلَّمة
- [ ] T072 [US1] اربط شاشة بناء الاختبار بضمّ أسئلة البنك في `frontend/src/app/(app)/(shell)/manage/exams/`

### ح — اختبارات US1

- [ ] T073 [P] [US1] `backend/tests/Feature/Assessments/BankReuseTest.php` — السؤال الواحد في ثلاثة اختبارات، صفٌّ واحد في البنك، و`points_override` يختلف في أحدها (`SC-001`)
- [ ] T074 [P] [US1] `backend/tests/Feature/Assessments/QuestionTaggingTest.php` — صفر سؤالٍ محفوظ بوسومٍ ناقصة (`SC-002`)
- [ ] T075 [P] [US1] `backend/tests/Feature/Assessments/QuestionEditSafetyTest.php` — تعديل سؤالٍ له محاولات **وحذف خيارٍ منه**: الدرجة لا تتغيّر، والمراجعة تعرض النصّ والخيار المحذوف **من اللقطة** (`SC-003`)
- [ ] T076 [P] [US1] `backend/tests/Feature/Assessments/QuestionImportTest.php` — ‎١٬٠٠٠‎ صفّ منها ‎١٠‎ معطوبة ⇒ ‎٩٩٠‎ مستورداً وتقريرٌ يسمّي العشرة برقم الصفّ والسبب، **وBOM لا يفسد أول عمود** (`SC-004`)
- [ ] T077 [P] [US1] `backend/tests/Feature/Assessments/ImportIdempotencyTest.php` — ⚠️ **[‏مر‏]** رفعٌ مرّتين بـ`skip` ⇒ لا نسخة ثانية؛ و**إعادة تشغيل الوظيفة نفسها** لا تُعيد إدراج ما أُدرج
- [ ] T078 [P] [US1] `backend/tests/Feature/Assessments/BankAccessTest.php` — مدرّسٌ لا يرى ولا يضمّ سؤالاً من بنك غيره، **والبحث مقيَّدٌ بمساحة العمل على المُنشئ** (‏يُفحَص بالاستعلام لا بنتيجةٍ من محرّكٍ معطَّل في الاختبارات)

**Checkpoint**: **‏US1 وحدها منتجٌ قابل للنشر.** بنكٌ موسوم، بحثٌ، استيراد، واختباراتٌ تُبنى منه.

---

## Phase 4: US2 — تحليل الفقرات يكشف أين يخطئ الطلاب (P2)

**Goal**: المدرّس يرى أي الأفكار يخطئ فيها طلابه، وأي الأسئلة أخطأ فيها الأغلبية.

**Independent Test**: محاولاتٌ معلومة النتيجة على أسئلةٍ موسومة، ومقارنة أرقام اللوحة بحسابٍ يدويّ.

- [ ] T079 [US2] هجرة ‎١٠‎ب: `question_stats` (‏`unique(question_id)`) و`concept_stats` — ⚠️ **[‏مر‏]** `lesson_id` **`NOT NULL` والصفر يعني «الفكرة إجمالاً»**، وإلّا لم يعضّ القيدُ الفريد على الصفّ الذي يقرؤه كل شيء — في `..._001010_create_analytics_rollup_tables.php`
- [ ] T080 [P] [US2] نموذجا `QuestionStat` و`ConceptStat` ومصنعاهما
- [ ] T081 [US2] أضف `assessments.min_sample_size` إلى `backend/database/seeders/PlatformSettingsSeeder.php` — ⚠️ حدٌّ لا يتغيّر إلا بنشرةٍ هو حدٌّ لا يُضبَط أبداً
- [ ] T082 [US2] `RollUpQuestionStatsJob` في `.../Jobs/RollUpQuestionStatsJob.php` على طابور `maintenance` بـ`forWorkspace()` — ⚠️ **[‏مر‏]** و**بـ`chunkById`**: `chunk` يرقّم بالإزاحة على أسرع الجداول نموّاً
- [ ] T083 [US2] في الوظيفة: ⚠️ **[‏مر‏]** **استثنِ `is_practice`** بوصلةٍ إلى `exam_attempts` — بدونها تخلط النِّسَبُ تدريبَ الطالب باختبار المدرّس، ورقمٌ يقرّر حذف سؤالٍ يُحسب على محاولاتٍ لم تكن اختباراً
- [ ] T084 [US2] في الوظيفة: العيّنة دون الحدّ الأدنى تُكتب **`null` لا صفراً** (`FR-013`) — «صفر بالمئة أخطأوا» و«لا نعرف» جملتان مختلفتان
- [ ] T085 [US2] جدولة الوظيفة ليلياً في `backend/routes/console.php`
- [ ] T086 [P] [US2] `QuestionStatResource` و`ConceptStatResource` — ⚠️ نسبةٌ فارغة تُقدَّم ببيان «بيانات غير كافية»، لا بصفر
- [ ] T087 [US2] `AnalyticsController` في `.../Http/Controllers/AnalyticsController.php` على `Permissions::ANALYTICS_VIEW` — **يقرأ من الجدولين حصراً** (`FR-014`)
- [ ] T088 [US2] القراءة العابرة للمدرّسين على `ANALYTICS_CROSS_TEACHER_VIEW` — ⚠️ **`withoutWorkspaceScope()` وتكرارُه في كل تحميلٍ مسبق**: `WorkspaceContext::id()` يرجع إلى `users.last_workspace_id` **للمشرف الأعلى أيضاً**، فتقريرٌ منصّي مُبقًى في نطاقه يعرض مساحةً واحدة ويسمّيها المنصّة
- [ ] T089 [US2] المسارات في `routes/api.php`
- [ ] T090 [P] [US2] `frontend/src/lib/analytics.ts` و`frontend/src/app/(app)/(shell)/manage/analytics/questions/page.tsx`
- [ ] T091 [US2] ⚠️ **رابطٌ وارد** لشاشة التحليل من قائمة القشرة
- [ ] T092 [P] [US2] `backend/tests/Feature/Assessments/ItemAnalysisTest.php` — عشرون محاولةً معلومة النتيجة، والنِّسَب **تطابق الحساب اليدوي بفارق صفر** (`SC-005`)
- [ ] T093 [P] [US2] `backend/tests/Feature/Assessments/InsufficientSampleTest.php` — سؤالٌ حلّه طالبان ⇒ `null` وبيانٌ صريح، **لا صفر** (`SC-006`)
- [ ] T094 [P] [US2] ⚠️ **[‏مر‏]** `backend/tests/Feature/Assessments/RollupIdempotencyTest.php` — **تشغيل الوظيفة ليلتين ⇒ صفٌّ واحد لكل فكرة**؛ تشغيلةٌ واحدة تمرّ خضراء إلى الأبد وتثبت العكس
- [ ] T095 [P] [US2] `backend/tests/Feature/Assessments/CrossTeacherAnalyticsTest.php` — ⚠️ **بمساحتَي عملٍ اثنتين**: مساحةٌ واحدة تُمرّر الاختبارَ الخاطئ

---

## Phase 5: US3 — دفتر الأخطاء و«اختبرني في أخطائي» (P3)

**Goal**: الطالب يرى كل ما أخطأ فيه ومعه الصواب وشرحه، ويبني منه اختباراً بضغطة.

**Independent Test**: طالبٌ أخطأ في أسئلة معروفة ⇒ قراءةُ دفتره وبناءُ اختبارٍ منه.

- [ ] T096 [US3] `MistakeNotebook` في `.../Support/MistakeNotebook.php` — ⚠️ **استعلامٌ واحد مجمَّع لكل صفحة** (`GROUP BY question_id` مع `MAX(is_correct)`)، لا استعلامٌ لكل خطأ: الاشتقاق ينمو مع **تاريخ الطالب** لا مع طول الصفحة
- [ ] T097 [US3] في `MistakeNotebook`: «مُصلَح» = وجودُ إجابةٍ صحيحةٍ **لاحقة** للطالب نفسه على السؤال نفسه — سؤالٌ يُسأل، لا عمودٌ يُحدَّث
- [ ] T098 [US3] ⚠️ **[‏مر‏]** الدفتر **مقيَّدٌ بمساحة العمل** (`FR-016أ`): السؤال وفكرته ملكُ بنكِ مدرّسٍ بعينه، وجمعُهما إمّا يعرض للطالب نصف أخطائه ويسمّيه كلَّها وإمّا يُخرج سؤال مدرّسٍ إلى سياق آخر
- [ ] T099 [P] [US3] `MistakeResource` في `.../Http/Resources/MistakeResource.php` — ⚠️ السؤالُ بلا شرحٍ يُعرض بإجابته الصحيحة **ولا يفشل العرض**
- [ ] T100 [US3] `MistakeController` على **ملكية الصفّ** في `.../Http/Controllers/MistakeController.php`، بالترشيح بالفكرة والدرس والفترة
- [ ] T101 [US3] `BuildPracticeFromMistakes` في `.../Actions/BuildPracticeFromMistakes.php` — **الأخطاء القائمة وحدها**، والمُصلَح مستثنًى افتراضياً (`FR-019`)
- [ ] T102 [P] [US3] `MistakeResolved` في `.../Events/MistakeResolved.php` يُطلَق من `GradeAttempt` — ⚠️ **بلا مستهلكٍ اليوم ومقصود**: ‎009‎ تسمّيه فعلاً مُلعَّباً، وإطلاقُه الآن يوفّر تعديلَ مسار التصحيح الساخن لاحقاً
- [ ] T103 [US3] المسارات `‏/mistakes` و`‏/practice/from-mistakes` بـ`throttle:practice`
- [ ] T104 [P] [US3] `frontend/src/lib/mistakes.ts` و`frontend/src/app/(app)/(shell)/mistakes/page.tsx` — **بحالة فراغٍ مفهومة** لطالبٍ بلا أخطاء، من `components/ui/states/`
- [ ] T105 [US3] ⚠️ **رابطٌ وارد** لدفتر الأخطاء من قائمة الطالب
- [ ] T106 [P] [US3] `backend/tests/Feature/Assessments/MistakeNotebookTest.php` — ‎١٠٠٪‎ من أخطاء الطالب و**صفر خطأٍ لغيره**، **والسؤال المتروك بلا إجابة يظهر** (`SC-007`)
- [ ] T107 [P] [US3] `backend/tests/Feature/Assessments/PracticeFromMistakesTest.php` — خمسة أخطاء، اثنان أُصلحا ⇒ الاختبار يُبنى من **الثلاثة القائمة** (`SC-008`)

---

## Phase 6: US4 — الاختبار الذاتي من البنك (P4)

**Goal**: الطالب يختار فكرةً وصعوبةً وعدداً ومدّة فيُولَّد له اختبارٌ ويُصحَّح فوراً.

**Independent Test**: توليدٌ بمعايير محدّدة والتحقّق من مطابقة الأسئلة لها.

- [ ] T108 [US4] `BuildSelfExam` في `.../Actions/BuildSelfExam.php`: الترشيح بالفكرة والصعوبة والعدد والمدّة، **وبالمتاح مع إبلاغ الطالب عند النقص** (`FR-023`)
- [ ] T109 [US4] ⚠️ **[‏مر‏]** في `BuildSelfExam`: بِركة السحب = أسئلة الدروس التي يخوّلها **تسجيلٌ نشط** (`EnrollmentDirectory`) — **لا عضويةُ مساحة**، فطالبٌ انتهى تسجيله وبقيت عضويته يقرأ البنك كاملاً (`FR-022`)
- [ ] T110 [US4] ⚠️ **[‏مر‏]** في `BuildSelfExam`: **اطرح كل سؤالٍ في `exam_items` لاختبارٍ منشور لم يُكمل الطالب فيه محاولة** (`FR-022أ`) — بدونه يستدعي الطالب فكرةَ امتحان الغد وصعوبته، فيُصحَّح له فوراً **مع الشروح**، ويقرأ الامتحان بإجاباته
- [ ] T111 [US4] المحاولة المُولَّدة تُوسَم `is_practice` و`exam_id = null` — الوسم **على المحاولة لا على الاختبار**، لأن الاختبار نفسه قد يُحلّ رسمياً وتدريباً (`FR-025`)
- [ ] T112 [P] [US4] `BuildSelfExamRequest` و`PracticeAttemptResource`
- [ ] T113 [US4] `PracticeController` والمسار `‏/practice/exams` بـ`throttle:practice`
- [ ] T114 [US4] التصحيح الآليّ الفوريّ مع الشروح للاختبار الذاتي (`FR-024`) في `GradeAttempt`
- [ ] T115 [P] [US4] `frontend/src/app/(app)/(shell)/practice/page.tsx` — الاختيار والنتيجة والشروح
- [ ] T116 [US4] ⚠️ **رابطٌ وارد** للتدريب من قائمة الطالب
- [ ] T117 [P] [US4] `backend/tests/Feature/Assessments/SelfExamTest.php` — مطابقة المعايير في ‎١٠٠٪‎، والنقص يُبنى بالمتاح مع إبلاغ (`SC-009`)
- [ ] T118 [P] [US4] ⚠️ **[‏مر‏]** `backend/tests/Feature/Assessments/ExamLeakTest.php` — امتحانٌ منشور، والطالب يطلب تدريباً **بالمعايير نفسها** ⇒ **لا سؤالٌ منه يظهر**؛ ثم يُكمل محاولته الرسمية ⇒ تدخل البِركة (`SC-020`)
- [ ] T119 [P] [US4] ⚠️ **[‏مر‏]** `backend/tests/Feature/Assessments/PracticeBudgetTest.php` — اختبارٌ بمحاولتين، تدريبٌ ثلاث مرّات، ثم **محاولتان رسميّتان تُقبلان** (`SC-020`)

---

## Phase 7: US5 — لوحة تصحيح الأسئلة المقالية (P5)

**Goal**: المصحّح يرى المنتظر، يوزّع الدرجة على معايير، يعلّق، فيُحتسب المجموع.

**Independent Test**: اختبارٌ فيه مقاليّ ⇒ محاولةٌ ⇒ تصحيح ⇒ التحقّق من المجموع.

- [ ] T120 [US5] هجرة ‎٨‎: `rubric_criteria` و`grading_records` في `..._000800_create_grading_tables.php` — ⚠️ **[‏مر‏]** **بلا قيدٍ فريد على `grading_records`**: `unique(answer_id, rubric_criterion_id, revision_of)` وعمودان منه قابلان للإفراغ، و**NULL لا يصطدم بـNULL**، فلا يعضّ في الحالة الأساسية بالضبط
- [ ] T121 [US5] في نفس الهجرة: `rubric_criteria.max_points` و`grading_records.points` من نوع `decimal(5,2)`، و`submissions.score` **بإشارة** — ⚠️ **[‏مر‏]** عمودٌ صحيح يقصّ ‎٢٫٥‎ **بعد** أن يمرّ فحصُ `SUM ≤ points`، وعديمُ الإشارة يرمي `ERROR 1690` على MySQL وحدها
- [ ] T122 [P] [US5] نموذجا `RubricCriterion` و`GradingRecord` ومصنعاهما
- [ ] T123 [US5] `SaveRubric` في `.../Actions/SaveRubric.php` — **مجموع `max_points` ≤ درجة السؤال، يُفرَض في الـAction** (`FR-028` · `SC-010`)
- [ ] T124 [US5] `GradeEssayAnswer` في `.../Actions/GradeEssayAnswer.php` — ⚠️ **[‏مر‏]** الكشف بمطالبةٍ ذرّية على **`exam_answers`**: `UPDATE … WHERE graded_at IS NULL`، والصفر المُعاد هو التعارض؛ ثم تُدرَج صفوف `grading_records` في المعاملة نفسها. **و`lockForUpdate()` ممنوع** — بلا أثرٍ على SQLite فيمرّ الاختبار محلياً ولا يثبت شيئاً
- [ ] T125 [US5] `ReviseGrade` في `.../Actions/ReviseGrade.php` — قيدٌ جديد بـ`revision_of` و**سببٍ إلزامي**، بمطالبة `WHERE grading_version = ?` (`FR-032`)
- [ ] T126 [US5] `FinalizeAttempt` في `.../Actions/FinalizeAttempt.php` — المجموع، `finalized_at`، الإشعار، **ثم** `ExamPassed`/`ExamFailed`
- [ ] T127 [US5] في `GradeAttempt`: الاختبار ذو المقاليّ يقف عند `pending_grading` — ⚠️ **ولا يُطلَق `ExamPassed` ولا `ExamFailed`**: عقد الشهادات مشحون، وإطلاقه على درجةٍ ناقصة يصدر شهادةً على نصف اختبار — والمستمع idempotent فلا يُصدرها مرّتين، **لكنه لا يسحب واحدةً صدرت**
- [ ] T128 [P] [US5] `AttemptPendingGrading` و`AttemptFinalized` في `.../Events/` ومستمعاهما في مركز الإشعارات
- [ ] T129 [P] [US5] قوالب الإشعارين في `NotificationTemplateSeeder.php`
- [ ] T130 [P] [US5] `GradingPolicy` على `GRADING_PERFORM` و`GRADING_REVISE`
- [ ] T131 [US5] `GradingController` — طابور المنتظر مُرشَّحاً ومرتَّباً (`FR-027`)، ⚠️ **بتحميلٍ مسبق مُعلَن**: ‎٥٠٠‎ محاولة × ثلاثة استعلاماتٍ للصفّ = ‎١٥٠٠‎، والسقف ‎١٥‎
- [ ] T132 [US5] إخفاء الهوية: إعدادُ مساحةٍ يُطبَّق **في الـResource** (`FR-033`) — ⚠️ صفحةٌ تخفي الاسم بينما الحمولة تحمله إخفاءٌ يكشفه فتحُ أدوات المطوّر؛ **وإطفاؤه فعلٌ يُسجَّل في `activity_log`**
- [ ] T133 [US5] ⚠️ **[‏مر‏]** وسّع `.../Policies/AttemptPolicy.php`: القراءة تشترط **تسجيلاً نشطاً أو محاولةً وقعت داخل تسجيلٍ سابق في هذه المساحة** — الحارس اليوم `ATTEMPTS_VIEW_ALL` وحدها، فيقرأ المساعدُ نصّ إجابة طالبٍ انتهى تسجيله قبل عام؛ والفرع الثاني يمنع محاولةً منتظرةً من أن تعلق بلا مصحّح
- [ ] T134 [P] [US5] `frontend/src/lib/grading.ts` و`frontend/src/app/(app)/(shell)/manage/grading/page.tsx` و`.../[uuid]/page.tsx`
- [ ] T135 [US5] ⚠️ **رابطٌ وارد** للوحة التصحيح، **بعدّاد المنتظر** في قائمة المدرّس
- [ ] T136 [P] [US5] `backend/tests/Feature/Assessments/EssayGradingTest.php` — المجموع يُحتسب، الطالب يُبلَّغ، **والشهادة تُقيَّم الآن لا قبل** (`SC-011`)
- [ ] T137 [P] [US5] `backend/tests/Feature/Assessments/CertificateDeferralTest.php` — ⚠️ **يجب أن يفشل قبل الإصلاح**: محاولةٌ فيها مقاليّ تُسلَّم ⇒ **لا شهادة صدرت**
- [ ] T138 [P] [US5] ⚠️ **[‏مر‏]** `backend/tests/Feature/Assessments/GradingConflictTest.php` — مصحّحان على إجابةٍ **بلا معايير** (‏الحالة التي كان القيد يخرج فيها من الخدمة) ⇒ الثاني يُكشَف. **يُدرِج مرّتين فعلاً** (`SC-021`)
- [ ] T139 [P] [US5] `backend/tests/Feature/Assessments/RubricBoundsTest.php` — صفر مجموع معايير يتجاوز درجة سؤاله (`SC-010`)

---

## Phase 8: US6 — تسليم الواجبات (P6)

**Goal**: واجبٌ بموعد، تسليمٌ نصّاً أو ملفاً أو أسئلة، وتصحيحٌ يعود بدرجته.

**Independent Test**: نشرُ واجبٍ بموعد، تسليمه قبله وبعده، وتصحيحه.

- [ ] T140 [US6] هجرة ‎٩‎: `assignments` (‏`late_penalty_pct_per_day` · `late_penalty_cap_pct`) و`submissions` (‏`late_penalty_applied_pct` · `extension_until`) و`accommodations` (‏`extra_time_pct` · `extended_days`) في `..._000900_create_assignment_tables.php`
- [ ] T141 [P] [US6] نماذج `Assignment` و`Submission` و`Accommodation` ومصانعها
- [ ] T142 [P] [US6] `SaveAssignment` في `.../Actions/SaveAssignment.php` — العنوان والدرجة والموعد ونوع التسليم وسياسة التأخير
- [ ] T143 [US6] `LatePenalty` في `.../Support/LatePenalty.php` — **نسبةٌ لكل يوم بسقف، واليوم المبدوء كاملاً** (`Q6`)
- [ ] T144 [US6] ⚠️ **[‏مر‏]** في `LatePenalty`: **قاعُ الصفر والسقف يُفرَضان في الـAction** (`FR-046أ`) — بلا سقف، تأخيرُ عشرة أيام بخصم ‎٢٠٪‎ يُنتج **‎−١٠٠٪‎**
- [ ] T145 [US6] `SubmitAssignment` في `.../Actions/SubmitAssignment.php` — الوسم `on_time`/`late` **وقت وقوعه**، و`late_by_minutes`
- [ ] T146 [US6] `GradeSubmission` في `.../Actions/GradeSubmission.php` — ⚠️ **`late_penalty_applied_pct` يُثبَّت وقت الاعتماد ولا يُشتقّ بعدها**: السياسة عمودٌ قابل للتعديل، ومدرّسٌ يخفّفها آخر الفصل يعيد تسعير كل ما صُحِّح
- [ ] T147 [US6] `GrantAccommodation` و`GrantExtension` في `.../Actions/` — ⚠️ **[‏مر‏]** كلاهما يسأل `EnrollmentDirectory` **قبل أن يكتب**: بارامترُ uuid عارٍ **مسبارُ هوية** يعود جوابه حاملاً اسمَ صاحبه (`NFR-001أ`)، **والجواب واحدٌ في الحالتين (404)** — ردٌّ يميّز «غير موجود» عن «ليس لك» هو المسبار نفسه بصيغةٍ أدقّ
- [ ] T148 [US6] `ApplyAccommodation` في `.../Support/` — **نسبةُ وقتٍ للاختبار وأيامُ مهلةٍ للواجب**، آلياً على كل تقييمٍ تالٍ (`FR-054` · `Q8`)
- [ ] T149 [US6] رفع الملف على **القرص الخاص** بمكتبة الوسائط، كسابقة الإيصالات — ⚠️ بلا مفتاحٍ إلى `media_assets`
- [ ] T150 [US6] ⚠️ **[‏مر‏]** مسار الملف: `signed` **و`auth:sanctum`** معاً، **خمس دقائق**، **موقَّعٌ للقارئ لا للمسار**، **وتُعاد سياسته عند الفتح** (`FR-048أ`) — توقيعٌ على المسار يُلصق في مجموعةٍ فيفتحه كل من فيها، وقارئٌ سُحبت صلاحيته يظلّ يقرأ حتى انتهاء المدّة
- [ ] T151 [US6] `MarkMissedSubmissionsJob` في `.../Jobs/MarkMissedSubmissionsJob.php` — ⚠️ `forWorkspace()` لا `set()`، وهي **الوظيفة الوحيدة العابرة للمساحات** فأخطرهنّ
- [ ] T152 [US6] ⚠️ **[‏مر‏]** في المكنسة: **مرّر `uuid` و`created_at` صراحةً** في مصفوفة `insertOrIgnore` واقرأ الصفوف بعدها — `insertOrIgnore` لا يُقلع النموذج فلا يعمل `HasUuid`، فيُخزَّن `''` على MySQL و**كل تسليمٍ لاحق على المنصّة يُقرأ «مسجَّل سلفاً» ويُتخطّى بصمت**
- [ ] T153 [US6] ⚠️ **[‏مر‏]** في المكنسة: التحديث `WHERE submitted_at IS NULL` لا كتابةٌ عمياء، **وتستشير `accommodations`** — طالبٌ مُنح يومين يُوسَم `missed` في الليلة الأولى فيحجبه شرطُ الفتح، وهو أوّل من بُني له التسهيل
- [ ] T154 [US6] جدولة المكنسة ليلياً في `routes/console.php`
- [ ] T155 [P] [US6] `AssignmentSubmitted` و`SubmissionGraded` في `.../Events/` ومستمعاهما وقالباهما
- [ ] T156 [P] [US6] `AssignmentPolicy` · `SubmissionPolicy` · `AccommodationPolicy`
- [ ] T157 [US6] المتحكّمات والطلبات والموارد للواجبات والتسليمات والتسهيلات، بمحدِّداتها المسمّاة
- [ ] T158 [US6] ⚠️ **[‏مر‏]** في `SubmissionResource`: `state` و`submitted_at` و`extension_until` **خاصّةٌ بصاحب الصفّ** — تسليمٌ بعد الموعد حالته «في الموعد» يقول لكل قارئٍ إنّ لصاحبه تأجيلاً (`FR-056`)
- [ ] T159 [P] [US6] `frontend/src/lib/assignments.ts` وشاشات `manage/assignments/` و`assignments/`
- [ ] T160 [US6] ⚠️ **رابطان واردان**: الواجبات في قائمة المدرّس، والمستحقّة في قائمة الطالب
- [ ] T161 [P] [US6] `backend/tests/Feature/Assessments/SubmissionStateTest.php` — الحالات الثلاث والتأجيل، **وصفر درجةٍ سالبة عند الحدّين** (`SC-016`)
- [ ] T162 [P] [US6] `backend/tests/Feature/Assessments/SubmissionAccessTest.php` — صفر وصولٍ إلى تسليم غيره، **ورابطٌ لا يعمل لغير من وُقِّع له ولا بعد سحب صلاحيته ولا بعد مدّته** (`SC-017` · `SC-022`)
- [ ] T163 [P] [US6] `backend/tests/Feature/Assessments/AccommodationTest.php` — التسهيل يُطبَّق آلياً **على اختبارٍ وواجبٍ معاً**، و**زميلٌ لا يستنتج وجوده من الحمولة** (`SC-018`)
- [ ] T164 [P] [US6] ⚠️ **[‏مر‏]** `backend/tests/Feature/Assessments/MissedSweepTest.php` — المكنسة تكتب صفوفاً بـ`uuid` صحيح، ولا تكتب فوق تسليمٍ وقع، ولا تَسِم من له تأجيل

---

## Phase 9: US7 — الواجب الإجباري يفتح الحصة التالية (P7)

**Goal**: لا تُفتح الحصة التالية إلا بحضور السابقة وحلّ واجبها بنسبةٍ يحدّدها المدرّس.

**Independent Test**: ضبطُ الشرط ومحاولةُ الوصول قبل استيفائه وبعده.

- [ ] T165 [US7] هجرة ‎١١‎: `unlock_rules` (‏⚠️ **[‏مر‏]** `course_id` **`NOT NULL` والصفر = الافتراضي**، `unique(workspace_id, course_id)`، **ولا مستوى حصة**) و`unlock_exemptions` في `..._001100_create_unlock_tables.php`
- [ ] T166 [US7] هجرة ‎١١‎ب: ⚠️ **[‏مر‏]** فهرسا الشرط اللحظي في `..._001110_add_unlock_lookup_indexes.php` — `assignments(class_session_id)` و**`class_sessions(workspace_id, course_id, starts_at)`**: العمود شُحن قابلاً للإفراغ **بلا فهرس** وليس في مقدّمة أيٍّ من فهارس الجدول الثلاثة، والشرط يمرّ به عند كل طلب
- [ ] T167 [P] [US7] نموذجا `UnlockRule` و`UnlockExemption` ومصنعاهما
- [ ] T168 [US7] `UnlockResolver` في `.../Support/UnlockResolver.php` — الأسبقية **كورس ← مساحة عمل**، والأخصّ يفوز بلا اندماج، **وغياب الأخصّ رجوعٌ إلى الأعمّ لا تعطيل**
- [ ] T169 [US7] ⚠️ **[‏مر‏]** `UnlockReader` في `.../Support/UnlockReader.php` بـ`stamp(Collection)` على سابقة `WithholdingReader` — تقويمٌ فيه عشرون حصة يعني **مئةً وعشرين استعلاماً داخل Resource** بلا هذا، وهو حرفياً العطل الذي تحرسه `QueryBudgetTest`
- [ ] T170 [US7] ⚠️ **[‏مر‏]** وسّع `backend/app/Shared/Contracts/SessionAttendanceDirectory.php` بسؤالٍ **جماعي** `attendedSessionIds(User, array)` — لا مفرد؛ ونفّذه في `EloquentSessionAttendanceDirectory`. ⚠️ **والحضور الفعليّ لا الحجز**: التنفيذ القائم يَعُدّ من ألغى متأخراً «مخوَّلاً»، و`FR-036` يسأل عن الحضور
- [ ] T171 [US7] في `UnlockResolver`: `FR-042` — **واجبٌ لم يُنشَر لا يحجب**، وإلّا حجب المدرّسُ صفَّه كلَّه بمسوّدةٍ نسيها
- [ ] T172 [US7] `GrantUnlockExemption` بسببٍ إلزامي — ⚠️ **وبحارس `EnrollmentDirectory`** كـ`T147`
- [ ] T173 [US7] `EligibilityController` على المسار `‏/class-sessions/{uuid}/eligibility` — ⚠️ **`class-sessions` لا `sessions`**: الكلمة محجوزة لجلسات المصادقة، وثالثُ معنًى لكلمةٍ واحدة هو ما تجنّبته ‎005‎
- [ ] T174 [US7] جواب `eligibility` يقول **ما ينقص بالضبط** (`FR-038`) **ويسمّي القاعدة التي حكمت** — الأسبقية لا تُرى، ومنعٌ بلا تفسير يحوّل ميزةَ تحفيزٍ إلى عطلٍ يراسل الطالبُ مدرّسَه عنه
- [ ] T175 [US7] اربط الحجب في `frontend/src/app/(app)/(shell)/` بجواب `eligibility`، **برسالةٍ تسمّي الناقص** لا «غير متاح»
- [ ] T176 [US7] شاشة ضبط الشرط في `manage/unlock-rules/` — **تعرض عند التخصيص أيَّ افتراضيٍّ يُلغى**
- [ ] T177 [P] [US7] `backend/tests/Feature/Assessments/UnlockGateTest.php` — الحالات الخمس في `quickstart.md` §٩، **وصفر فتحٍ لغير مستوفٍ وصفر حجبٍ لمستوفٍ** (`SC-012`)
- [ ] T178 [P] [US7] ⚠️ **[‏مر‏]** `backend/tests/Feature/Assessments/UnlockPrecedenceTest.php` — افتراضيٌّ عند ‎٥٠٪‎ وتخصيصٌ لكورسٍ عند ‎٨٠‎: طالبٌ بـ‎٦٠‎ **يُفتح له في كورسٍ ويُمنع في الآخر**، وكورسٌ بلا تخصيص **يرث ولا يُقرأ غيابه فتحاً**
- [ ] T179 [P] [US7] `backend/tests/Feature/Assessments/UnlockQueryBudgetTest.php` — ⚠️ **بعيّنتين تُعرَّفان بصفوف الحمولة لا بحجم الجدول** (‏ثلاثة صفوف مقابل صفحةٍ ممتلئة)، **وبمساواةٍ وسقف ‎١٥‎ معاً**: المسار مرقَّم فالمساواة وحدها صحيحةٌ بالبناء (`SC-013`)

---

## Phase 10: Polish — النشرة الثانية والبوابات

- [ ] T180 ⚠️ **[‏مر‏]** **هجرة ‎٦‎ في نشرةٍ تالية منفصلة**: احذف `questions.exam_id` في `..._000600_drop_exam_id_from_questions.php` — عاملُ طابورٍ قديم لم يُعَد تشغيله ينفّذ `$attempt->load('exam.questions.options')` فيسقط **كل** تصحيحٍ وكل صفحة اختبار حتى تكتمل النشرة
- [ ] T181 في `T180`: **اكتب حدود التراجع الثلاث في الهجرة** — سؤالٌ في اختبارين لا يتراجع · سؤالُ بنكٍ في صفر اختبارات لا قيمة له · والعمود **لا يعود `NOT NULL`**، فالمخطّط بعد `rollback` ليس الذي سبق
- [ ] T182 هجرة ‎١٢‎: فهارس الأداء وحدها في `..._001200_add_performance_indexes.php` — ⚠️ **[‏مر‏]** **لا تُعاد القيود المولودة مع جداولها** (`unique(uuid)` · `unique(exam_id, question_id)` · `unique(assignment_id, student_user_id)`) وإلّا ردّ MySQL `Duplicate key name`
- [ ] T183 [P] احذف `Exam::questions()` وكل قارئٍ لـ`exam_id` من `backend/app/Modules/Assessments/` — الجرد الكامل: النموذج · `SaveQuestion` · `GradeAttempt` · `StartAttempt::questionsForAttempt` · `ExamResource`
- [ ] T184 [P] `backend/tests/Feature/Assessments/QueryBudgetTest.php` — البنك ولوحة التصحيح ودفتر الأخطاء، **بطلب إحماءٍ واحد قبل القياس** لأن ذاكرة صلاحيات spatie تُملأ في أول طلبٍ مُصادَق
- [ ] T185 [P] `backend/tests/Feature/Assessments/AssessmentExposureTest.php` — كل حمولةٍ مُعدَّدة ضدّ `AssessmentFieldAllowlist`، **و«طالب ب يطلب موارد طالب أ» على كل مسارٍ يقبل uuid**
- [ ] T186 [P] حدّث `backend/database/seeders/ScenarioSeeder.php` ببنكٍ وواجباتٍ وتسهيلٍ وشرطِ فتحٍ للعرض المحلّي
- [ ] T187 [P] حدّث `docs/README.md` (‏جداول الوحدات والمسارات والصلاحيات) و`docs/erd.md` بالجداول الأحد عشر الجديدة
- [ ] T188 [P] أضف إلى `CLAUDE.md` و`AGENTS.md` الدروس التي لا يُمسكها اختبار: القيدُ الفريد على عمودٍ قابل للإفراغ · اللقطة والمقام · بِركةُ التدريب · `insertOrIgnore` في المكنسة
- [ ] T189 [P] `frontend/e2e/assessments.spec.ts` — البنك والتصحيح والدفتر والواجب، ⚠️ بـ`PHP_CLI_SERVER_WORKERS=8 php artisan serve`
- [ ] T190 شغّل البوابات الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit` (`SC-019`)

---

## Dependencies — ترتيب القصص

```
Setup (T001–T009)
   ↓
Foundational (T010–T039)   ← تحجب الكلّ. سلسلةٌ مرتَّبة بلا [P]
   ↓
US1 (P1) ──────────────┐   ← المنتج القابل للنشر وحده
   ↓                   │
US2 (P2) · US3 (P3) · US4 (P4) · US5 (P5)   ← أربعتها متوازية بعد US1
   ↓
US6 (P6)                   ← الواجب: كيانٌ مستقلّ
   ↓
US7 (P7)                   ← يقرأ US6 و005، فيأتي أخيراً
   ↓
Polish (T180–T190)         ← ‏T180 نشرةٌ ثانية، لا مهمّةٌ في الأولى
```

**والتبعيات الحقيقية وحدها:**

- `US2` و`US3` و`US4` **لا يعتمد بعضها على بعض** — تُسلَّم بأي ترتيب بعد `US1`.
- `US4` يعتمد على `US1` (البنك) وعلى `T029`/`T110` (البِركة والرصيد).
- `US5` يعتمد على `Foundational` (اللقطة) لا على `US1`.
- `US7` يعتمد على `US6` (‏`FR-052` يقرأ التسليم) **وعلى ‎005‎** (‏الحضور بعقد).
- `T180` بعد `T183` **وبعد نشرةٍ كاملة** — لا تُشحن معها.

---

## فرصُ التوازي

| الطور | المتوازي | العدد |
|---|---|---|
| Setup | T005 · T007 · T008 | ‎٣‎ |
| Foundational | T022–T025 (النماذج) · T034 · T039 | ‎٦‎ |
| US1 | T040–T041 · T043 · T046–T048 · T067 · T073–T078 | ‎١٣‎ |
| US2 | T080 · T086 · T090 · T092–T095 | ‎٧‎ |
| US3 | T099 · T102 · T104 · T106–T107 | ‎٥‎ |
| US4 | T112 · T115 · T117–T119 | ‎٥‎ |
| US5 | T122 · T128–T130 · T134 · T136–T139 | ‎٩‎ |
| US6 | T141–T142 · T155–T156 · T159 · T161–T164 | ‎٩‎ |
| US7 | T167 · T177–T179 | ‎٤‎ |
| Polish | T183–T189 | ‎٧‎ |

⚠️ **ولا `[P]` على سلسلة الهجرات ولا على أي مهمّةٍ تلمس `routes/api.php`** — ملفٌّ واحد.

---

## استراتيجية التسليم

**‏MVP = Setup + Foundational + US1** (‏T001–T078، ‎٧٨‎ مهمّة). عندها: بنكٌ موسوم يُبحث فيه
ويُستورَد إليه وتُبنى منه اختباراتٌ متعدّدة — وهو ما تسمّيه الوثيقة «تملك بالفعل كل ما يلزم»
للمسار التكيّفي في ‎012‎.

**والزيادة بعده قصةً قصة**، وكلٌّ منها تُشحن وحدها. أعلاها قيمةً بعد `US1`: **`US5`** (‏تفتح
نوع السؤال المقاليّ بالكامل وهو مفقودٌ اليوم) ثم **`US2`** (‏المتطلّب الرابع في الوثيقة).

⚠️ **و`US7` لا تُشحن قبل `US6`**: شرطٌ يقرأ درجةَ واجبٍ من كيانٍ غير موجود يقرأ صفراً
فيحجب الجميع.

---

## التحقّق من الشكل

- **‏١٩٠ مهمّة**، كلٌّ منها `- [ ]` + معرّف + مسارُ ملفٍ صريح.
- `[Story]` في أطوار القصص وحدها؛ Setup وFoundational وPolish بلا وسم.
- `[P]` على ‎٧١‎ مهمّة، **ولا واحدة منها على هجرةٍ أو على `routes/api.php`**.
- كل معيار نجاحٍ من `SC-001` إلى `SC-022` له مهمّةُ اختبارٍ تسمّيه.
