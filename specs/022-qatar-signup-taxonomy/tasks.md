---
description: "Task list — مفرداتُ التسجيلِ القَطَريّة"
---

# Tasks: مفرداتُ التسجيلِ القَطَريّة (022)

**Input**: `specs/022-qatar-signup-taxonomy/` — `spec.md` · `plan.md` · `research.md` (بما فيه
**§R11** سجلُّ مراجعةِ الوكلاء) · `data-model.md` · `contracts/api.md` · `quickstart.md`

**Tests**: مطلوبةٌ صراحةً — الدستورُ يجعلُ اختباراتِ Feature شبكةَ الأمانِ الأساسيّة، و`spec.md`
يعرّفُ عشرةَ معاييرِ نجاحٍ كلُّها قابلةٌ للقياس.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يمكنُ تشغيلُه بالتوازي (ملفٌّ مختلف، بلا اعتمادٍ على مهمّةٍ غيرِ منجَزة)
- **[Story]**: US1 (الطالبُ ووليُّ الأمر) · US2 (المدرّس) · US3 (المشغّل) · US4 (كلمةُ المرور)

## ⚠️ قواعدُ تشغيلٍ سارية

- **لا تشغيلَ لأيِّ مجموعةِ اختباراتٍ كاملةٍ محليّاً** — أمرٌ قائمٌ من المالك. المستهدَفُ فقط،
  ومرّةً واحدةً عندَ نهايةِ كلِّ موجة. ولا مجموعتا pest معاً.
- **لا `migrate:fresh` بلا إذن.** `migrate` وحدَه يتحقّقُ من هجرةٍ جديدة.
- **لا commit بلا طلبٍ صريح.**

---

## Phase 1: Setup — المفرداتُ تصلُ **كلَّ** قاعدةِ بيانات

**Purpose**: العطلُ الأوّلُ من اثنَين. بعدَ هذه المرحلةِ تحملُ قاعدةُ الإنتاجِ المفرداتِ بلا
أيِّ بياناتٍ تجريبيّة — ولا شيءَ في الواجهةِ تغيَّرَ بعد.

- [X] T001 أنشئْ `backend/database/seeders/TaxonomySeeder.php` بوَضعَين: `run()` بـ`updateOrCreate` و`seedMissing()` بـ`firstOrCreate`، على نمطِ `RegionSeeder` حرفيّاً، مع docblock يشرحُ لماذا لا يُنادى `run()` في مسارِ النشر
- [X] T002 انقلْ `SUBJECTS` و`GRADE_LEVELS` من `backend/database/seeders/MarketplaceSeeder.php` إلى `TaxonomySeeder`، وأضِفْ `kindergarten` للمراحلِ وأربعَ موادّ (`science` · `social-studies` · `history` · `geography`) — القائمةُ الكاملةُ في `research.md` R4
- [X] T003 اكتبْ `sort_order` **قيماً صريحةً** في ثوابتِ `TaxonomySeeder`، لا دليلَ مصفوفة — `firstOrCreate` يكتبُه للصفوفِ الجديدةِ وحدَها، فدليلُ المصفوفةِ يجعلُ ترتيبَ الإنتاجِ يخالفُ ترتيبَ التطوير (R11-ج-٨)
- [X] T004 أضِفْ `TaxonomySeeder::class` إلى نداءِ `$this->call([...])` **غيرِ المشروطِ** في `backend/database/seeders/DatabaseSeeder.php` بجانبَ `RegionSeeder`
- [X] T005 نادِ `TaxonomySeeder` من رأسِ `MarketplaceSeeder::run()`، وحوِّلْ حرّاسَ `isset($subjects[$slug])` في `:152-162` إلى رميٍ — بدونها يُنتِجُ مدرّسينَ تجريبيّينَ بلا مادّةٍ واحدةٍ وبلا خطأ (R11-ج/م-٢)
- [X] T006 أنشئْ هجرةَ الردمِ `backend/app/Modules/Marketplace/Database/Migrations/2026_08_31_000100_backfill_taxonomy_catalogue.php` تنادي `seedMissing()`، بـ`down()` **فارغٍ ومعلَّلٍ** كما في `backfill_regions_catalogue`
- [X] T007 سجِّلْ `Region::saved(fn () => MarketplaceCache::flush())` في `backend/app/Modules/Marketplace/MarketplaceServiceProvider.php` بجانبَ `Subject` و`GradeLevel` — **إصلاحُ عطلٍ مشحون**: منطقةٌ يُوقِفُها مشغّلٌ تبقى معروضةً دقيقةً ويُردُّ الطالبُ ٤٢٢ عن خيارٍ أمامَ عينِه (R11-أ-٣)
- [X] T008 حوِّلْ تركيباتِ الاختباراتِ التي تُنشئُ `slug => 'math'` أو `'secondary'` أو `'primary'` بـ`create()` صريحٍ إلى `firstOrCreate` — على الأقلّ `backend/tests/Feature/Marketplace/CrossWorkspaceListingTest.php:86` · `backend/tests/Feature/Analytics/RegistrationStillWorksTest.php:33` · `backend/tests/Feature/Marketplace/TeacherApplicationTest.php:77-78` · `StudentRegistrationTest.php:52,121` · `PublicCourseListTest.php:104`. ⚠️ هجرةُ الردمِ تجري قبلَ **كلِّ** اختبارِ `RefreshDatabase`، و`unique(slug)` قائمٌ (R11-ج-٦)
- [X] T009 أضِفْ `$this->seed(TaxonomySeeder::class)` إلى `beforeEach` في `backend/tests/Pest.php` بجانبَ الكتالوجاتِ الأربعةِ القائمة
- [X] T010 [P] اختبارٌ في `backend/tests/Feature/Marketplace/TaxonomyCatalogueTest.php`: بعدَ `migrate` وحدَه (بلا بياناتٍ تجريبيّة) يحملُ الجدولانِ المفرداتِ كاملةً وصفرَ مدرّسينَ معروضين
- [X] T011 [P] اختبارٌ في الملفِّ نفسِه: `seedMissing()` بعدَ إعادةِ تسميةِ مادّةٍ يدويّاً **يُبقي** التسمية ولا يُنشئُ صفّاً مكرّراً (SC-006)

**Checkpoint**: `php vendor/bin/pest tests/Feature/Marketplace` — المفرداتُ موجودةٌ في كلِّ بيئة.

---

## Phase 2: Foundational — الصفوفُ المفردةُ والقراءةُ المشتركة

**Purpose**: العطلُ الثاني، والجدولُ الجديد. **حاجزٌ لكلِّ القصص.**

⚠️ **الترتيبُ الزمنيُّ للهجراتِ في أسمائها لا في ترتيبِ المهامّ** — لارافيل يرتّبُ بالاسم،
فهجرةُ الردمِ الثانيةُ تحملُ طابعاً **بعدَ** `create_school_years` (R11 · plan.md).

- [X] T012 هجرةُ `backend/app/Modules/Marketplace/Database/Migrations/2026_09_01_000100_create_school_years_table.php` — `id` · `uuid` unique · `grade_level_id` **NOT NULL** `constrained()->restrictOnDelete()` · `name_ar` · `slug` unique · `sort_order` · `is_active` · timestamps. بلا `workspace_id` (الصنف ب)
- [X] T013 [P] `backend/app/Modules/Marketplace/Models/SchoolYear.php` — `HasUuid`، بلا `BelongsToWorkspace`، و`$fillable` **يحملُ `grade_level_id`** (إخوتُه لا يحملون مفتاحاً أجنبيّاً فالنسخُ يُسقِطُه — R11-ب-١٠)، وعلاقةُ `gradeLevel()`
- [X] T014 [P] `backend/database/factories/Modules/Marketplace/SchoolYearFactory.php`
- [X] T015 `SchoolYear::scopeActivelyOffered()` — «الصفُّ نشطٌ **و**مرحلتُه نشطة». ⚠️ **هذا هو الشرطُ الوحيد**: يناديه المسارُ العامُّ وكلُّ `Rule::in` (R11-ج-٢)
- [X] T016 `SchoolYear::stageFor(?string $yearSlug, ?string $legacyStageSlug): ?string` — اشتقاقٌ واحدٌ يأخذُ **سلسلتَين لا نموذجاً**، لأنّ `parent_student_relations.student_user_id` قابلٌ للعدمِ فلا `StudentProfile` هناك (R11-ج-٣)
- [X] T017 `backend/app/Modules/Marketplace/Support/SchoolYearDirectory.php` — خريطةُ `slug ⇒ [stage, name]` باستعلامِ `join` **واحد**، محفوظةٌ للطلب، تحتَ `MarketplaceCache::key('signup:school-year-map')`. اربطْها **`scoped()`** في المزوِّد لا `singleton()` (حاويةُ العاملِ تتجاوزُ المهمّة) ولا `bind()` (تُنادى مرّاتٍ في الطلب) — R11-ج-٤
- [X] T018 أضِفْ `Gate::policy(SchoolYear::class, TaxonomyPolicy::class)` و`SchoolYear::saved(fn () => MarketplaceCache::flush())` في `MarketplaceServiceProvider`. ⚠️ سطرُ السياسةِ **صريحٌ**: مُخمِّنُ لارافيل يفشلُ **مفتوحاً** حين تخدمُ سياسةٌ عدّةَ نماذج
- [X] T019 أضِفِ الصفوفَ الأربعةَ عشرَ إلى `TaxonomySeeder` — المراحلُ تُكتَبُ **قبلَ** الصفوفِ في `write()` نفسِها، والبحثُ عن المرحلةِ الأمِّ **يرمي** ولا يتخطّى: هجرةُ الردمِ تجري مرّةً و`firstOrCreate` لا يعود، فصفٌّ متخطّىً مفقودٌ للأبد (R11-ج-٧)
- [X] T020 هجرةُ ردمٍ ثانيةٌ `2026_09_01_000200_backfill_school_years.php` بطابعٍ بعدَ `create_school_years`، بـ`down()` فارغٍ معلَّل
- [X] T021 `backend/app/Modules/Marketplace/Actions/Public/ListSignupTaxonomy.php` — الموادُّ والمراحلُ العريضة، على نمطِ `ListRegions` حرفيّاً، **تُرجِعُ `array`** لا Resource
- [X] T022 `backend/app/Modules/Marketplace/Actions/Public/ListSchoolYears.php` — `activelyOffered()` + `join` واحدٌ يُرجِعُ `slug` و`name_ar` و`grade_level_slug`، **`array`** كذلك. ⚠️ لا يُبنى فعلٌ واحدٌ للثلاثة: استعلامُ الصفوفِ ليس مطابقاً (انضمامٌ + مفتاحٌ ثالث)، وهو معيارُ `ListPublicTaxonomy` المكتوبُ في docblock الخاصِّ به
- [X] T023 ⚠️ مفاتيحُ الكاشِ ببادئةِ **`signup:`** حصراً — الفضاءُ مسطَّحٌ و`ListPublicTaxonomy` يملكُ `subjects` و`grade_levels` حرفيّاً؛ التصادمُ يُبطِلُ `SC-008` بالتناوبِ وبلا فشلٍ في أيِّ مكان (R11-ج-١)
- [X] T024 `backend/app/Modules/Marketplace/Http/Controllers/SignupTaxonomyController.php` + ثلاثةُ مساراتٍ في `routes/api.php` على `throttle:public`، **لا تقبلُ أيَّ مُعامِلِ استعلام** (R11-ج-١٤)
- [X] T025 [P] أضِفْ `PublicFieldAllowlist::SIGNUP_TAXONOMY = ['slug','name_ar','grade_level_slug']` — ثابتٌ **جديدٌ** لا توسيعٌ لـ`TAXONOMY`، وإلّا صارَ `grade_level_slug` مسموحاً على حمولةِ المتجرِ أيضاً
- [X] T026 [P] `backend/tests/Feature/Marketplace/SignupTaxonomyExposureTest.php` على نمطِ `RegionsExposureTest` — ⚠️ `PublicExposureTest` يمشي على قائمةِ عناوينَ مكتوبةٍ يدويّاً ولن يرى المساراتِ الجديدةَ إطلاقاً (R11-ب-٦)
- [X] T027 اختبارُ عزلِ الكاشِ في الملفِّ نفسِه: **سخِّنْ `/marketplace/subjects` أوّلاً** ثمّ اقرأْ `/signup/subjects` على تركيبةٍ بصفرِ مدرّسينَ معروضين — الترتيبُ العكسيُّ يمرُّ فوقَ العطل
- [X] T028 [P] أضِفْ مُدخَلاً **عكسيّاً** لـ`SchoolYear` في `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php:169-181` بجانبَ المادّةِ والمرحلة، و`SchoolYear::class` إلى قائمةِ الصنفِ (ب) في `backend/tests/Feature/Analytics/PlatformReferenceAccessTest.php`

**Checkpoint**: القراءتانِ منفصلتانِ ومقيستان؛ الجدولُ قائمٌ ومزروع.

---

## Phase 3: US1 — الطالبُ ووليُّ الأمرِ يختارانِ صفّاً (Priority: P1) 🎯 MVP

**Goal**: حقلٌ إلزاميٌّ مملوءٌ على نشرةٍ بلا مدرّسٍ معتمَدٍ واحد، ومرحلةٌ تُشتَقُّ من الصفّ.

**Independent Test**: نشرةٌ نظيفةٌ بلا مدرّسٍ معروض ⇐ يُكمَلُ تسجيلُ طالبٍ حتى الحساب.

### الطالب

- [X] T029 [US1] هجرةُ `backend/app/Modules/Identity/Database/Migrations/2026_09_01_000300_add_school_year_to_student_profiles.php` — `school_year_slug` nullable + `index`. ⚠️ و`down()` يُسقِطُ **الفهرسَ في إغلاقٍ مستقلٍّ قبلَ العمود**: SQLite يرفضُ إسقاطَ عمودٍ مفهرَس (نمطُ `add_region_to_student_profiles:33-49`)
- [X] T030 [US1] `backend/app/Modules/Identity/Models/StudentProfile.php` — `school_year_slug` في **`$fillable`** + `@property` + تابعٌ نحيفٌ `stageSlug()` يستدعي `SchoolYear::stageFor()`. ⚠️ عمودٌ خارجَ `$fillable` يُطرَحُ **صامتاً** من `create()`: `201` وصفٌّ فارغ، وثلاثةُ أعمدةٍ سقطت هكذا في ٠١٣
- [X] T031 [US1] `backend/app/Modules/Identity/Http/Requests/RegisterStudentRequest.php` — `school_year_slug` مطلوبٌ عبرَ `SchoolYear::activelyOffered()` (**النطاقُ نفسُه الذي يقرؤه المسار**)، و`grade_level_slug` يُزال
- [X] T032 [P] [US1] رسالةُ الرفضِ ومدخلُ `attributes` للحقلِ الجديدِ في `backend/lang/ar/` — حقلٌ بلا مدخلٍ يظهرُ باسمِه الإنجليزيِّ للطالب
- [X] T033 [US1] `RegisterStudentData` + `backend/app/Modules/Identity/Actions/RegisterStudent.php` يكتبانِ العمودَ الجديد
- [X] T034 [US1] `backend/app/Modules/Identity/Http/Resources/UserResource.php` — `grade_level_slug` يبقى مفتاحاً و**يصيرُ مشتقّاً**، ويُضافُ `school_year_slug` و`school_year_name`
- [X] T035 [P] [US1] أضِفِ المفتاحَينِ الجديدَينِ إلى `GamificationFieldAllowlist::forbidden()`. ⚠️ `grade_level_slug` هناك لا في قائمةِ سماح، وإضافتُهما إلى `fields()` تنشرُ مرحلةَ قاصرٍ على ثلاثِ لوحاتٍ عابرةٍ للمساحات (R11-ب-٥)
- [X] T036 [P] [US1] `backend/app/Modules/Identity/Support/IdentityPersonalData.php` — الصفُّ في مسارِ التصدير

### وليُّ الأمرِ (FR-006أ — إصلاحُ شاشةٍ مكسورة)

- [X] T037 [US1] ⚠️ أصلحْ `frontend/src/components/marketplace/AddChildForm.tsx:34,49` — تنادي `/parent/children` وهو عنوانٌ **أزالته مواصفة ٠٠٣**؛ الحيُّ `GET|POST /family/relations`. الشاشةُ مكسورةٌ في الإنتاجِ اليومَ عند التحميلِ وعند الإرسال (R11-أ-١)
- [X] T038 [US1] هجرةُ `2026_09_01_000400_add_school_year_to_parent_student_relations.php` — `student_school_year_slug` nullable، بالقاعدةِ نفسِها في `down()`
- [X] T039 [US1] `backend/app/Modules/Identity/Models/ParentStudentRelation.php` — العمودُ في **`$fillable`** + تابعٌ نحيفٌ يستدعي `SchoolYear::stageFor()`
- [X] T040 [US1] `LinkGuardianRequest` + `backend/app/Modules/Identity/Actions/LinkGuardian.php` — الصفُّ بدلَ المرحلة، بالنطاقِ المشترك
- [X] T041 [US1] `ParentStudentRelationResource` يقرأُ عبرَ `SchoolYearDirectory` (خريطةٌ جَمعيّة). ⚠️ `FamilyController:49` يُصيِّرُ `::collection()`، والعمودُ **نصٌّ بلا علاقة** فحتّى `->with()` غيرُ متاح — قراءةٌ لكلِّ صفٍّ هنا N+1 بالبناء
- [X] T042 [P] [US1] احذفْ `backend/app/Modules/Identity/Http/Requests/AddChildRequest.php` — ملفٌّ ميّتٌ لا يشيرُ إليه شيءٌ في الشجرة
- [X] T043 [P] [US1] `frontend/src/app/(public)/signup/parent/children/page.tsx` يجلبُ قراءةَ التسجيلِ الجديدةَ بدلَ `publicApi.gradeLevels()`

### الواجهة

- [X] T044 [P] [US1] `frontend/src/lib/public-api.ts` — ثلاثُ قراءاتِ التسجيلِ الجديدة
- [X] T045 [US1] `frontend/src/components/marketplace/StudentSignupForm.tsx` — قائمةُ الصفوفِ ومفتاحُ `school_year_slug`

### اختبارات

- [X] T046 [P] [US1] `backend/tests/Feature/Identity/SchoolYearRegistrationTest.php`: تسجيلٌ بصفٍّ على تركيبةٍ **بصفرِ مدرّسينَ معروضين** ⇒ `201` (SC-001)
- [X] T047 [P] [US1] في الملفِّ نفسِه: **اقرأِ الصفَّ لا الردَّ** — `school_year_slug` مخزَّنٌ فعلاً. ⚠️ `SeedCommand` يلفُّ البذورَ بـ`Model::unguarded()` فلا ترى مجموعةُ الاختباراتِ نقصَ `$fillable` إلّا من بابِ الطلب
- [X] T048 [P] [US1] في الملفِّ نفسِه: طالبٌ قديمٌ بلا صفٍّ وبمرحلةٍ قديمة ⇒ `stageSlug()` تُجيبُ بها (Edge Case)
- [X] T049 [P] [US1] في الملفِّ نفسِه: وليُّ أمرٍ يضيفُ ابناً **بلا حساب** ⇒ الصفُّ يُحفَظُ والمرحلةُ تُشتَقّ
- [X] T050 [P] [US1] اختبارُ اتّساقِ البابِ والشاشة: مجموعةُ ما يُرجِعُه `/signup/school-years` = مجموعةُ ما يقبلُه `Rule::in`، على **كلِّ** حقولِ الاستماراتِ الأربع (SC-003)
- [X] T051 [P] [US1] `backend/tests/Feature/Identity/QueryBudgetTest.php` — `/family/relations` بتركيبتَي حجمٍ وطلبِ إحماءٍ وصفوفٍ **بصفوفٍ دراسيّةٍ مختلفة**، ويؤكِّدُ أنّ حقلَ المرحلةِ **حاضر** لا أنّ الكلفةَ ثابتةٌ وحدَها (إسقاطُ التحميلِ المسبقِ يجعلُ المفتاحَ غائباً والصفحةَ أرخص)
- [X] T052 [P] [US1] `frontend/src/components/marketplace/StudentSignupForm.test.tsx` — القائمةُ تُصيَّرُ من الخاصّيّةِ الممرَّرة

**Checkpoint**: `pest tests/Feature/Identity` ثمّ `pest tests/Feature/Marketplace` (لا معاً) · `npm test`.

---

## Phase 4: US2 — أوّلُ مدرّسٍ يتقدَّم (Priority: P1)

**Goal**: فكُّ القفلِ الدائريّ: قائمتا الموادِّ والمراحلِ مملوءتانِ قبلَ اعتمادِ أيِّ مدرّس.

**Independent Test**: قاعدةٌ فيها المفرداتُ وصفرُ ملفّاتٍ معروضة ⇒ تُقبَلُ خطوةُ البياناتِ المهنيّة.

- [X] T053 [US2] `backend/app/Modules/Marketplace/Http/Requests/TeacherStepTwoRequest.php` — `subjects.*` و`grade_levels.*` بـ`Rule::in` على المفرداتِ النشطة بدلَ `string|max:100`. ⚠️ إحكامُ بابٍ مفتوح: نصٌّ حُرٌّ يُكتَبُ في ملفِّ مدرّسٍ ثمّ لا يطابقُ أيَّ تصفيةٍ أبداً
- [X] T054 [US2] [P] رسائلُ الرفضِ في `backend/lang/ar/`
- [X] T055 [US2] `frontend/src/app/(public)/signup/teacher/page.tsx` يجلبُ قراءتَي التسجيلِ الجديدتَين
- [X] T056 [US2] [P] `backend/tests/Feature/Marketplace/FirstTeacherApplicationTest.php`: **صفرُ مدرّسينَ معروضين** ⇒ القائمتانِ غيرُ فارغتَين والخطوةُ تُقبَل (SC-002)
- [X] T057 [US2] [P] في الملفِّ نفسِه: مادّةٌ خارجَ المفرداتِ تُرفَض (FR-009)
- [X] T058 [US2] [P] في الملفِّ نفسِه: شريطُ تصفيةِ المتجرِ **لا يتّسع** — يبقى خالياً ممّا لا مدرّسَ تحتَه (SC-008)

**Checkpoint**: أوّلُ مدرّسٍ يستطيعُ التقدُّمَ على منصّةٍ من الصفر.

---

## Phase 5: US3 — المشغّلُ يحرّرُ المفردات (Priority: P2)

**Goal**: شاشةُ الصفوفِ الناقصة، وحرّاسُها.

- [X] T059 [US3] `backend/app/Modules/Marketplace/Filament/Resources/SchoolYearResource.php` — **`extends Resource` لا `TaxonomyResource`**، على نمطِ `RegionResource:42`. ⚠️ الأبُ يحملُ حقلَ `icon` (ولا عمودَ له) و`getEloquentQuery()` يفعلُ `withCount('teacherProfiles')` وللجدولِ عمودٌ يقرؤه — أي **انهيارٌ عند كلِّ فتحٍ لصفحةِ الإدراج** (R11-ب-٩)
- [X] T060 [US3] حقلُ المرحلةِ **إلزاميٌّ** في الاستمارة (FR-011أ)، والمُعرِّفُ غيرُ قابلٍ للتحريرِ بعدَ الإنشاءِ كما في `TaxonomyResource`
- [X] T061 [US3] الرباعيُّ `canDelete`/`canDeleteAny`/`canForceDelete`/`canForceDeleteAny` **مكتوباً باليد** + `getHeaderActions(): []` على صفحةِ التعديل. ⚠️ رفضُ `TaxonomyPolicy::delete()` ليس الحارس: `Gate::before` يمرّرُ المشرفَ العامَّ فوقَ كلِّ سياسة وهو الوحيدُ الذي سيضغطُ الزرّ
- [X] T062 [US3] [P] صفحاتُ `SchoolYearResource/Pages/` الثلاث + مجموعةُ التنقّلِ «السوق والتصنيف» والتسمياتُ العربيّة
- [X] T063 [US3] [P] أضِفْ حالاتِ `SchoolYear` إلى `backend/tests/Feature/Analytics/PlatformReferenceAccessTest.php`: `canViewAny()` **false** لـ`finance-admin` و`compliance-officer`، و**true** للمشرفِ العامّ. ⚠️ القياسُ على **المورِد** لا عبرَ `Gate`: طبقةُ Filament هي التي تفشلُ مفتوحة، و`Gate` يفشلُ مغلقاً فيُجيبُ «ممنوع» سواءٌ وُجِدَتِ السياسةُ أم لا
- [X] T064 [US3] [P] في الملفِّ نفسِه: `canDelete()` **false للمشرفِ العامّ**
- [X] T065 [US3] [P] أضِفْ `SchoolYear` إلى توكيدِ `Gate::getPolicyFor` في `backend/tests/Feature/Marketplace/TaxonomyPermissionTest.php` — و`Region` معه، وهو ناقصٌ اليوم
- [X] T066 [US3] [P] في الملفِّ نفسِه: حفظُ صفٍّ يرفعُ `MarketplaceCache::version()`، وكذلك حفظُ منطقةٍ (اختبارُ إصلاحِ T007)
- [X] T067 [US3] [P] اختبار: صفٌّ يُضيفُه مشغّلٌ يظهرُ في `/signup/school-years` **في الطلبِ التالي** لا بعدَ ستّينَ ثانية — نافذةٌ زمنيّةٌ يُرضيها انتهاءُ المدّةِ وحدَه فلا تقيسُ التنظيفَ أصلاً

**Checkpoint**: `pest tests/Feature/Marketplace` + `pest tests/Feature/Analytics` (بالتتابع).

---

## Phase 6: US4 — إظهارُ كلمةِ المرور (Priority: P3) — مستقلٌّ تماماً [P]

**Goal**: زرُّ عينٍ على **كلِّ** حقلِ مرورٍ في المنتَج، وتهجئةٌ واحدةٌ لا ثانيةَ لها.

- [X] T068 [US4] [P] `EyeIcon` و`EyeOffIcon` في `frontend/src/components/icons/index.tsx` على نمطِ `wrap(...)` القائم — لا أيقونةَ عينٍ في الملفِّ اليوم
- [X] T069 [US4] `PasswordField` في `frontend/src/components/ui/Field.tsx` — بلا `className` حُرّ، والـaria موصولةٌ بالبناء، ومسارُ الزرِّ محجوزٌ بـ`pe-10` مع `end-3` (خصائصُ منطقيّةٌ لا `right-`)، وألوانٌ من `@theme` القائمةِ حصراً
- [X] T070 [US4] ⚠️ **احذفْ `"password"` من اتّحادِ أنواعِ `TextField`** في التغييرِ نفسِه — بدونها يبقى `TextField type="password"` تهجئةً ثانيةً **لا تُظهِر**، ويسوقُ `tsc` المواضعَ الثمانيةَ من تلقائِه (نمطُ `NumberField` المكتوبُ في الملفّ)
- [X] T071 [US4] `type="button"` على الزرّ، وإخفاءُ زرِّ المتصفّحِ الأصليِّ (`::-ms-reveal`) في `globals.css`. ⚠️ الزرُّ العاري داخلَ نموذجٍ افتراضُه `submit` — أوّلُ ضغطةٍ على العينِ تُرسِلُ استمارةَ تسجيلٍ نصفَ ممتلئة
- [X] T072 [US4] انقلِ الحقولَ **الأربعةَ عشرَ** إلى `PasswordField`: `settings/page.tsx` (٣) · `settings/security/page.tsx` (٢) · `login/page.tsx` (١) · `register/page.tsx` (٢) · `ParentSignupForm` (٢) · `StudentSignupForm` (٢) · `TeacherSignupWizard` (٢)
- [X] T073 [US4] [P] `frontend/src/components/ui/PasswordField.test.tsx` — الحالةُ الابتدائيّةُ مخفيّة · الضغطةُ **لا تُرسِلُ** النموذج · `aria-label` يتبدّل · لا تُحفَظُ الحالة. استعملْ `fireEvent` لا `userEvent`
- [X] T074 [US4] [P] حدِّثْ `frontend/src/lib/theme-tokens.test.ts` إن استُعمِلَ رمزُ لونٍ جديد — Tailwind v4 لا يُصدِرُ قاعدةً لرمزٍ لم يرَه، وقد شُحِنَ هذا صامتاً أربعَ مرّات

---

## Phase 7: Polish — التدقيقُ والتوثيق

- [X] T075 [P] **تدقيقُ FR-016**: صنِّفْ كلَّ قائمةِ خياراتٍ في التسجيلِ (المناطق · المواد · المراحل · الصفوف · الدول · لغاتُ التدريس) — مفردةٌ تحملُها كلُّ نشرةِ إنتاج، أم قائمةٌ ثابتةٌ في المنتَج. اكتبِ النتيجةَ في `docs/README.md`
- [X] T076 [P] `teaching_languages.*` نصٌّ حُرٌّ (`string|max:5`) وهو منشورٌ في `PublicFieldAllowlist::TEACHER_CARD` — إمّا `Rule::in` على قائمةٍ ثابتة، أو تسجيلُ القرارِ وسببِه في مخرجِ T075 (R11-أ-٤)
- [X] T077 [P] `docs/README.md` — الجدولُ الجديدُ والمساراتُ الثلاثةُ والشاشةُ الرابعة
- [X] T078 [P] `docs/erd.md` — `school_years` وعمودا الصفِّ الجديدان
- [X] T079 [P] `CLAUDE.md` **و**`AGENTS.md` معاً — قاعدتانِ تستحقّانِ التدوين: «القراءةُ للتسجيلِ ليست القراءةَ للمتجر، ومفتاحُ الكاشِ مسطَّحٌ فالبادئةُ إلزاميّة»، و«حقلٌ تُعرَضُ خياراتُه من كتالوجٍ لا يُزرَعُ في الإنتاجِ = بابٌ مغلق»
- [X] T080 البوّاباتُ مرّةً واحدة: `pest tests/Feature/Identity` · `pest tests/Feature/Marketplace` · `pest tests/Feature/Analytics` (**بالتتابعِ لا معاً**) · `pint` · `phpstan` · `tsc --noEmit` · `npm test`

---

## Dependencies

```
Phase 1 (Setup) ──▶ Phase 2 (Foundational) ──┬──▶ Phase 3 (US1)
                                              ├──▶ Phase 4 (US2)
                                              └──▶ Phase 5 (US3)
Phase 6 (US4) ── مستقلٌّ تماماً، في أيِّ وقت
Phase 7 ── بعدَ ما يوثِّقُه
```

- **US2 لا يحتاجُ الصفوفَ المفردة** — يقرأُ الموادَّ والمراحلَ العريضة. فبعدَ T021/T024 يمكنُ
  تنفيذُه بالتوازي مع US1.
- **US1 يحتاجُ T012…T020** كاملةً.
- **US3 يحتاجُ T012** (الجدول) وT018 (السياسة).

## Parallel Opportunities

- Phase 1: T010 · T011 معاً بعدَ T009
- Phase 2: T013 · T014 معاً؛ ثمّ T025 · T026 · T028
- Phase 3: T032 · T035 · T036 معاً؛ ثمّ T042 · T043 · T044؛ ثمّ T046…T052 كلُّها
- Phase 4: T056 · T057 · T058
- Phase 5: T062…T067
- Phase 6: كامل المرحلةِ بالتوازي مع أيِّ مرحلةٍ أخرى
- Phase 7: T075…T079

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 4.** بعدَها تعملُ استمارةُ المدرّسِ فعلاً على منصّةٍ من الصفر
— وهو نصفُ العطلِ الأكبرُ أثراً وأقلُّه عمقاً، ولا يحتاجُ الجدولَ الجديدَ ولا عمودَ الطالب.

ثمّ **Phase 3** (الطالبُ ووليُّ الأمر) وهي أعمقُ تغييرٍ في المرحلة، فوقَ أساسٍ أخضر. ثمّ
**Phase 5** و**Phase 6** بأيِّ ترتيب.

**معاييرُ النجاحِ وأينَ تُقاس**: SC-001 → T046 · SC-002 → T056 · SC-003 → T050 · SC-004 → T067
· SC-005 → T072/T073 · SC-006 → T011 · SC-007 → T048 · SC-008 → T058 · SC-009 → T050 ·
SC-010 → T019 (الرميُ عندَ مرحلةٍ مجهولة).
