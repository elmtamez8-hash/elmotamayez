---

description: "Task list for 001-public-marketplace-pages"
---

# Tasks: سوق المدرّسين العام (Public Teacher Marketplace)

**Input**: Design documents from `/specs/001-public-marketplace-pages/`

**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ ✅

**Tests**: مطلوبة صراحةً — FR-007 يفرض اختبارات حارس التسريب، SC-009 يشترط إثباتها،
والدستور v1.0.0 (المبدأ I) يفرض حالة في `WorkspaceIsolationTest` لكل نموذج تابع لمستأجر،
والمبدأ IV يفرض بقاء البوابات الأربع خضراء.

**Organization**: المهام مجمّعة بحسب قصة المستخدم لتمكين التنفيذ والاختبار المستقل.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: قابلة للتوازي (ملفات مختلفة، بلا اعتماد على مهمة غير مكتملة)
- **[Story]**: القصة التي تنتمي إليها المهمة (US1…US6)

## Path Conventions

مستودع أحادي: `backend/` (Laravel 13) و `frontend/` (Next.js 15) — حسب `plan.md`.

---

## ⚠️ اقرأ هذا أولاً — الفخّ الأخطر في الميزة

`WorkspaceScope` **معطّل بالكامل** أمام الزوار غير المسجّلين
(`backend/app/Shared/Scopes/WorkspaceScope.php:28` — يخرج مبكراً عندما
`WorkspaceContext::id()` تُرجع `null`، وهي كذلك دائماً بلا مستخدم مصادَق عليه).

**كل استعلام عام بلا نطاق `publiclyListed()` = تسريب بيانات عبر كل مساحات العمل.**

المهمتان **T010** و**T029** هما الحارس البديل. لا تُنفَّذ أي مهمة في مرحلة قصة قبلهما.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: تهيئة الوحدة والأدوات ورموز التصميم

- [X] T001 إنشاء هيكل وحدة `Marketplace` في `backend/app/Modules/Marketplace/` بمجلدات `Models/`, `Actions/Public/`, `Http/Controllers/`, `Http/Requests/`, `Http/Resources/`, `Policies/`, `Events/`, `Listeners/`, `Jobs/`, `Support/`, `Filament/`, `Database/Migrations/`, `Database/factories/`, `routes/`
- [X] T002 إنشاء `backend/app/Modules/Marketplace/MarketplaceServiceProvider.php` يمتد `App\Shared\Modules\Module` (اكتشاف تلقائي — **ممنوع** تسجيله في `bootstrap/providers.php`)
- [X] T003 إضافة `app/Modules/Marketplace/Database/Migrations` إلى `databaseMigrationsPath` في `backend/phpstan.neon` (شرط الدستور، المبدأ III)
- [X] T004 [P] إنشاء `backend/config/marketplace.php` بأوزان درجة الثقة وحدود العرض والفئات حسب عقد `contracts/teacher-admin-api.md`
- [X] T005 [P] إضافة رموز التصميم (لوحة الألوان، الخطوط) كـ `@theme` في `frontend/src/app/globals.css` — أسلوب Tailwind v4، لا `tailwind.config.js`
- [X] T006 [P] تهيئة خط Cairo عبر `next/font/google` في `frontend/src/app/(public)/layout.tsx` (استضافة ذاتية، بلا طلب خارجي وقت التشغيل)
- [X] T007 [P] تثبيت `@playwright/test` و `@axe-core/playwright` في `frontend/package.json` وإضافة سكربت `test:e2e`
- [X] T008 [P] إنشاء `frontend/playwright.config.ts` بثلاثة أحجام عرض (360 / 768 / 1440) ووضعين (فاتح/ليلي)
- [X] T009 [P] إنشاء `frontend/public/marketplace/` و `frontend/public/marketplace/LICENSES.md` لتوثيق مصدر ورخصة كل صورة (R10)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: البنية التي **يجب** اكتمالها قبل أي قصة

**⚠️ CRITICAL**: لا تبدأ أي قصة قبل اكتمال هذه المرحلة

### الحارس البديل لعزل المستأجرين (الأولوية القصوى)

- [X] T010 إنشاء سمة `backend/app/Shared/Traits/IsPubliclyListed.php` بنطاق محلي `publiclyListed()` يشترط: علم النشر على العنصر **و** `participates_in_marketplace` على مساحة العمل **و** حالة اعتماد صالحة — مع تعليق يشرح أن `WorkspaceScope` معطّل أمام الزوار (الدستور، المبدأ I)
- [X] T011 إنشاء `backend/app/Modules/Marketplace/Support/PublicFieldAllowlist.php` بقوائم الحقول العامة المصرّح بها لكل مورد حسب `contracts/public-marketplace-api.md`

### الهجرات وتعديلات النماذج القائمة

- [X] T012 [P] هجرة `participates_in_marketplace` (boolean, **default false**) على `workspaces` في `backend/app/Modules/Tenancy/Database/Migrations/`
- [X] T013 [P] هجرة `platform_role`, `phone`, `country`, `grade_level_slug`, `registered_by_parent` على `users` في `backend/app/Modules/Identity/Database/Migrations/`
- [X] T014 إضافة `platform_role` إلى `$guarded` و`$hidden` في `backend/app/Models/User.php` أسوةً بـ `is_super_admin`
- [X] T015 [P] هجرات `subjects` و`grade_levels` وجدولَي الربط `teacher_profile_subject` و`teacher_profile_grade_level` في `backend/app/Modules/Marketplace/Database/Migrations/`
- [X] T016 هجرة `teacher_profiles` بكل الحقول والفهارس المركّبة الثلاثة من `data-model.md` في `backend/app/Modules/Marketplace/Database/Migrations/`

### النماذج الأساسية

- [X] T017 [P] نموذج `backend/app/Modules/Marketplace/Models/Subject.php` بـ `BelongsToWorkspace` و`HasUuid`
- [X] T018 [P] نموذج `backend/app/Modules/Marketplace/Models/GradeLevel.php` بـ `BelongsToWorkspace` و`HasUuid`
- [X] T019 نموذج `backend/app/Modules/Marketplace/Models/TeacherProfile.php` بـ `BelongsToWorkspace`, `HasUuid`, `IsPubliclyListed` وعلاقات `user`, `subjects`, `gradeLevels`
- [X] T020 [P] مصانع `TeacherProfileFactory`, `SubjectFactory`, `GradeLevelFactory` في `backend/database/factories/Modules/Marketplace/` (مركزية — الدستور)
- [X] T021 [P] بذرة `backend/database/seeders/MarketplaceSeeder.php` تُنشئ نفس قائمة المواد والمراحل لكل مساحة عمل

### الأذونات والتفويض والمسارات

- [X] T022 [P] إضافة ثوابت الأذونات الستة (`marketplace.teachers.review`, `.approve`, `.suspend`, `marketplace.reviews.moderate`, `marketplace.complaints.manage`, `marketplace.participation.manage`) إلى `backend/app/Modules/Tenancy/Support/Permissions.php`
- [X] T023 [P] إنشاء `backend/app/Modules/Marketplace/Policies/TeacherProfilePolicy.php` باستخدام ثوابت `Permissions` — **بلا نصوص حرفية** (الدستور، المبدأ V)
- [X] T024 إنشاء `backend/app/Modules/Marketplace/routes/api.php` بمجموعة عامة (خارج `auth:sanctum`) ومجموعة محمية، مع `throttle` 60/دقيقة على القوائم العامة
- [X] T025 [P] إنشاء `backend/app/Modules/Marketplace/Support/TrustScoreCalculator.php` يقرأ الأوزان من `config/marketplace.php` ويقصّ الناتج على 0–100 قبل الإرجاع (عمود `unsignedTinyInteger` — الدستور، قيود البيئة)

### أساس الواجهة العامة

- [X] T026 إنشاء `frontend/src/app/(public)/layout.tsx` بـ `<html lang="ar" dir="rtl">` وترويسة وتذييل — **بلا** تعديل `frontend/src/app/layout.tsx` حتى لا ينقلب التطبيق المحمي (R5)
- [X] T027 [P] إنشاء `frontend/src/lib/public-api.ts` — عميل خادم بلا `localStorage` وبلا رمز مصادقة (R6)
- [X] T028 [P] إنشاء مكوّنات الحالات المشتركة في `frontend/src/components/marketplace/states/` (`LoadingSkeleton.tsx`, `EmptyState.tsx`, `ErrorState.tsx`) — FR-077/078/079

### اختبارات الأساس

- [X] T029 إنشاء `backend/tests/Feature/Marketplace/PublicExposureTest.php` — اختبار الحارس: عنصر غير منشور لا يظهر ولا عبر رابطه المباشر، حقول خاصة لا تظهر في أي استجابة عامة، انسحاب مساحة عمل يُخفي عناصرها (FR-007, SC-009)
- [X] T030 إضافة حالات `TeacherProfile`, `Subject`, `GradeLevel` إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` (الدستور، المبدأ I)

**Checkpoint**: الأساس جاهز — الحارس البديل قائم ومختبَر، ويمكن بدء القصص

---

## Phase 3: User Story 1 - اكتشاف المدرّسين والوصول إلى ملف مدرّس (Priority: P1) 🎯 MVP

**Goal**: موقع عام قابل للنشر يكتشف فيه الزائر المدرّسين عبر مساحات عمل متعدّدة ويفتح ملفاتهم

**Independent Test**: فتح الصفحة الرئيسية كزائر غير مسجّل، التنقّل إلى قائمة المدرّسين، تطبيق
فلترين، فتح ملف مدرّس، وتصفّح تبويباته الأربعة — دون أي تدفّق تسجيل

> **ملاحظة استقلال**: هذه القصة لا تحتاج US4 (تسجيل المدرّس) ولا US5 (التقييمات).
> ملفات المدرّسين تُنشأ بالمصانع والبذور، ودرجة الثقة تُعرض بحالة "قيد التكوين" — وهو
> السلوك الصحيح المطلوب في FR-024، لا نقص في القصة.

### Tests for User Story 1

- [X] T031 [P] [US1] اختبار عقد `GET /marketplace/teachers` في `backend/tests/Feature/Marketplace/PublicTeacherListTest.php` — الفلاتر، الترتيب، الترقيم، النتائج الفارغة بـ 200
- [X] T032 [P] [US1] اختبار عقد `GET /marketplace/teachers/{uuid}` في `backend/tests/Feature/Marketplace/PublicTeacherDetailTest.php` — بما فيه 404 الموحّد للحالات الأربع
- [X] T033 [P] [US1] اختبار تكامل السوق العابر لمساحات العمل في `backend/tests/Feature/Marketplace/CrossWorkspaceListingTest.php` — مدرّسون من 3 مساحات في قائمة واحدة

### Implementation for User Story 1 — الخلفية

- [X] T034 [P] [US1] نموذج `backend/app/Modules/Marketplace/Models/AvailabilitySlot.php` بـ `BelongsToWorkspace` و`HasUuid` وهجرته
- [X] T035 [US1] `backend/app/Modules/Marketplace/Actions/Public/ListPublicTeachers.php` — فلترة SQL مفهرسة + ترتيب + ترقيم، مع `publiclyListed()` إلزامي وتعليق يشرح السبب
- [X] T036 [US1] `backend/app/Modules/Marketplace/Actions/Public/ShowPublicTeacher.php` — الملف الكامل بتبويباته الأربعة
- [X] T037 [P] [US1] `backend/app/Modules/Marketplace/Actions/Public/GetMarketplaceStats.php` — أرقام محتسبة حقيقية لا ثابتة (FR-038)
- [X] T038 [US1] `backend/app/Modules/Marketplace/Http/Resources/PublicTeacherCardResource.php` و`PublicTeacherDetailResource.php` مبنيان من `PublicFieldAllowlist` — **ممنوع** `toArray()` للنموذج
- [X] T039 [US1] `backend/app/Modules/Marketplace/Http/Requests/ListPublicTeachersRequest.php` — التحقق من معاملات الاستعلام، مع `WorkspaceRules::exists` لأي جدول تابع لمستأجر
- [X] T040 [US1] `backend/app/Modules/Marketplace/Http/Controllers/PublicMarketplaceController.php` — نمط: Request ← DTO ← Action ← Resource (الدستور، المبدأ II)
- [X] T041 [US1] `backend/app/Modules/Marketplace/Actions/Public/GetMarketplaceHome.php` ونقطة `GET /marketplace/home` المجمّعة (شرط SC-007)
- [X] T042 [P] [US1] نقطتا `GET /marketplace/subjects` و`GET /marketplace/grade-levels` عبر `ListPublicTaxonomy` (إجراء واحد للتصنيفَين — الاستعلام متطابق)
- [X] T043 [US1] تخزين مؤقت 60 ثانية لاستجابات القوائم والإحصائيات بمفتاح مشتق من المعاملات (R7 / SC-010)

### Implementation for User Story 1 — الواجهة

- [X] T044 [P] [US1] مكوّن `frontend/src/components/marketplace/TeacherCard.tsx` — صورة، اسم، تخصص، خبرة، نجوم، عدد تقييمات، شارة ثقة، سعر، زران (FR-049)
- [X] T045 [P] [US1] مكوّن `frontend/src/components/marketplace/TrustScoreBadge.tsx` — الفئات الثلاث + حالة "قيد التكوين"، ببديل نصي للقارئ الصوتي
- [X] T046 [P] [US1] مكوّن `frontend/src/components/marketplace/StarRating.tsx` ببديل نصي
- [X] T047 [P] [US1] مكوّنات `frontend/src/components/marketplace/SiteHeader.tsx` و`SiteFooter.tsx` (FR-032, FR-033)
- [X] T048 [US1] `frontend/src/app/(public)/page.tsx` — الصفحة الرئيسية بالأقسام التسعة بترتيب المخطط، Server Component
- [X] T049 [P] [US1] مكوّن `frontend/src/components/marketplace/SubjectsGrid.tsx` — النقر ينتقل إلى `/teachers?subject=…` (FR-043)
- [X] T050 [P] [US1] مكوّن `frontend/src/components/marketplace/FaqAccordion.tsx` قابل للتشغيل بلوحة المفاتيح (FR-044)
- [X] T051 [P] [US1] مكوّن `frontend/src/components/marketplace/TestimonialsCarousel.tsx` قابل للتنقّل بلوحة المفاتيح (FR-042)
- [X] T052 [US1] `frontend/src/app/(public)/teachers/page.tsx` — Server Component يقرأ الفلاتر من `searchParams`
- [X] T053 [US1] مكوّن `frontend/src/components/marketplace/TeacherFilters.tsx` — Client Component يكتب الفلاتر في عنوان الصفحة (FR-051, SC-015)
- [X] T054 [P] [US1] مكوّن `frontend/src/components/marketplace/ViewToggle.tsx` — تبديل شبكة/قائمة محفوظ خلال الجلسة (FR-048)
- [X] T055 [US1] `frontend/src/app/(public)/teachers/[uuid]/page.tsx` — ترويسة الملف + زر حجز ثابت + التبويبات الأربعة
- [X] T056 [P] [US1] مكوّن `frontend/src/components/marketplace/TrustScoreBreakdown.tsx` — الدائرة + العوامل الخمسة + الشرح النصي (FR-025, FR-026)
- [X] T057 [P] [US1] مكوّن `frontend/src/components/marketplace/AvailabilityCalendar.tsx` — تحويل من UTC إلى توقيت الزائر مع تسمية صريحة (FR-029)
- [X] T058 [US1] مكوّن `frontend/src/components/marketplace/ProfileTabs.tsx` — التبويب النشط في عنوان الصفحة (FR-062)
- [X] T059 [US1] توجيه أزرار الحجز إلى `/signup/student` مع الاحتفاظ بسياق المدرّس (FR-031 من المواصفة، سيناريو 11)
- [X] T060 [P] [US1] اختبار `frontend/e2e/discovery.spec.ts` — رحلة الاكتشاف كاملة + التحقق من قابلية الفهرسة بلا تنفيذ سكربتات (SC-016)

**Checkpoint**: 🎯 **MVP قابل للنشر** — موقع عام يعمل بالكامل. أوقف هنا وتحقّق قبل المتابعة.

---

> **حالة التنفيذ (2026-08-01)**: المراحل 1–3 (T001–T060) **منفّذة ومختبَرة**.
> البوابات الأربع خضراء: Pest 162/162 · Pint · PHPStan level 8 · tsc.
> Playwright: 48/48 عبر ثلاثة عروض ووضعين.
>
> **إضافات لم تكن في القائمة، لزمت أثناء التنفيذ:**
> - `Support/MarketplaceCache.php` — seam إبطال التخزين المؤقت بعدّاد إصدار. بدونه لا يمكن لأي Action في Phase 6 أن تحقّق SC-010.
> - `ListPublicTaxonomy` بديلاً عن `ListPublicSubjects` + نقطة `grade-levels`، وإلا كان فلتر المرحلة الدراسية (FR-045) فارغاً.
> - `tests/Support/WithWorkspace::asGuest()` — يعيد ضبط مفردة `WorkspaceContext` لاختبار مسار الزائر بصدق.
> - `frontend/src/app/(app)/` — إعادة هيكلة إلى root layouts متعددة. `app/layout.tsx` كان يفرض `<html lang="en">` على كل شيء، و`app/page.tsx` كان يحتجز `/`. كل الـ URLs محفوظة.
> - شريط حجز ثابت للجوال + إعادة بناء شبكة صفحة البروفايل: `sticky` كان داخل `<header>` فاختفى الزر مع التمرير على كل المقاسات (FR-054).
>
> **مؤجّل عمداً إلى قصص لاحقة:** الكورسات في الرئيسية والبروفايل (US3)، التقييمات (US5)،
> صفحات `/signup/*` (US2/US4/US6) — الروابط موجودة وتؤدي إلى 404 حتى تُبنى.

---

## Phase 4: User Story 2 - تسجيل طالب بدور على مستوى المنصة (Priority: P2)

**Goal**: تحويل الزائر إلى مستخدم فعلي بدور منصة، دون إنشاء مساحة عمل

**Independent Test**: فتح صفحة تسجيل الطالب مباشرة، إتمام التسجيل، والتحقق من
`platform_role = student` وعدم إنشاء مساحة عمل

### Tests for User Story 2

- [X] T061 [P] [US2] اختبار `backend/tests/Feature/Marketplace/StudentRegistrationTest.php` — الدور يُضبط، **لا** مساحة عمل، الموافقة إلزامية، البريد المكرر
- [X] T062 [P] [US2] اختبار ارتداد في `backend/tests/Feature/Tenancy/` يثبت بقاء مسار إنشاء الأكاديمية القائم عاملاً بلا تغيير (FR-011)

### Implementation for User Story 2

- [X] T063 [P] [US2] DTO `backend/app/Modules/Identity/Data/RegisterStudentData.php` يمتد `DataTransferObject`
- [X] T064 [US2] `backend/app/Modules/Identity/Actions/RegisterStudent.php` — يضبط `platform_role`، **لا** ينشئ مساحة عمل ولا يمنح دوراً داخلها (FR-010)
- [X] T065 [US2] `backend/app/Modules/Identity/Http/Requests/RegisterStudentRequest.php` — `terms_accepted` بقاعدة `accepted`، والمرحلة تُتحقق بالـ slug لا بـ `exists:` (الدستور، المبدأ I)
- [X] T066 [US2] نقطة `POST /auth/register/student` في وحدة `Identity` مع دعم `Idempotency-Key` ضد الإرسال المزدوج
- [X] T067 [US2] إضافة `platform_role` إلى استجابة `POST /auth/login` لتوجيه الواجهة (FR-012)
- [X] T068 [P] [US2] `frontend/src/app/(public)/signup/student/page.tsx` — نموذج بخطوتين كحد أقصى
- [X] T069 [P] [US2] مكوّن `frontend/src/components/marketplace/PhoneInput.tsx` برمز الدولة
- [X] T070 [US2] مفتاح "التسجيل بواسطة ولي الأمر" يُظهر/يُخفي الحقول فوراً في `frontend/src/app/(public)/signup/student/page.tsx` (FR-064)
- [X] T071 [US2] حالة تحميل على زر الإرسال تمنع النقرة الثانية في `frontend/src/components/marketplace/SubmitButton.tsx`
- [X] T072 [US2] توجيه ما بعد الدخول بحسب `platform_role` في `frontend/src/lib/auth-context.tsx`

**Checkpoint**: US1 و US2 يعملان مستقلين

---

## Phase 5: User Story 3 - تصفّح الكورسات وفلترتها (Priority: P3)

**Goal**: مسار اكتشاف بالمحتوى بجانب مسار الاكتشاف بالشخص

**Independent Test**: فتح قائمة الكورسات مباشرة، تطبيق فلتر النوع، والتحقق من شارات الخصم و"الأكثر طلباً"

### Tests for User Story 3

- [X] T073 [P] [US3] اختبار عقد `GET /marketplace/courses` في `backend/tests/Feature/Marketplace/PublicCourseListTest.php` — الفلاتر، الخصم، الكورسات غير المنشورة لا تظهر

### Implementation for User Story 3

- [X] T074 [US3] إضافة نطاق النشر العام إلى `backend/app/Modules/Courses/Models/Course.php` بإعادة استخدام `status` و`visibility` و`IsPublishable` — **بلا** عمود جديد (R9)
- [X] T075 [US3] تعديل `toSearchableArray()` في `backend/app/Modules/Courses/Models/Course.php` لإضافة علم النشر العام حتى تُستبعد الكورسات غير المنشورة من فهرس Scout لا بعد استرجاعها (R2)
- [X] T076 [US3] `backend/app/Modules/Marketplace/Actions/Public/ListPublicCourses.php` مع `publiclyListed()` إلزامي
- [X] T077 [US3] `backend/app/Modules/Marketplace/Http/Resources/PublicCourseCardResource.php` من `PublicFieldAllowlist` — `price_before_discount` = `null` بلا خصم
- [X] T078 [US3] نقطة `GET /marketplace/courses` في `backend/app/Modules/Marketplace/routes/api.php`
- [X] T079 [P] [US3] مكوّن `frontend/src/components/marketplace/CourseCard.tsx` — الغلاف، الشارات، السعر المشطوب، نسبة الخصم كنص للقارئ الصوتي (FR-053)
- [X] T080 [US3] `frontend/src/app/(public)/courses/page.tsx` — Server Component بفلاتر أفقية علوية
- [X] T081 [P] [US3] مكوّن `frontend/src/components/marketplace/CourseFilters.tsx` — Client Component يكتب في عنوان الصفحة
- [X] T082 [P] [US3] ربط الكورسات المميزة في الصفحة الرئيسية بالمصدر الحقيقي في `frontend/src/app/(public)/page.tsx`

**Checkpoint**: القصص الثلاث الأولى تعمل مستقلة

---

## Phase 6: User Story 4 - انضمام مدرّس ومراجعة طلبه (Priority: P4)

**Goal**: تدفّق انضمام كامل من المعالج إلى الاعتماد إلى الظهور في السوق

**Independent Test**: إتمام الخطوات الأربع، الوصول إلى "قيد المراجعة"، التحقق من الغياب عن
السوق قبل الاعتماد والظهور بعده خلال ≤ 60 ثانية

### Tests for User Story 4

- [X] T083 [P] [US4] اختبار `backend/tests/Feature/Marketplace/TeacherApplicationTest.php` — الخطوات الأربع، الاستئناف، الاعتماد والرفض وطلب التعديل
- [X] T084 [P] [US4] اختبار `backend/tests/Feature/Marketplace/TeacherVisibilityTest.php` — `pending`/`rejected`/`suspended` لا يظهرون؛ الاعتماد يُظهر خلال ≤ 60 ثانية (SC-010)
- [X] T085 [P] [US4] اختبار يثبت أن `PUT /teacher/application/step-3` **يرفض أي حمولة ملف** بـ 422 (FR-071 — قيد أمني)

### Implementation for User Story 4

- [X] T086 [P] [US4] نموذج وهجرة `backend/app/Modules/Marketplace/Models/TeacherApplication.php` بـ `step_data` و`current_step` و`status`
- [X] T087 [P] [US4] DTOs الخطوات الأربع في `backend/app/Modules/Marketplace/Data/`
- [X] T088 [US4] `backend/app/Modules/Marketplace/Actions/SubmitTeacherApplication.php` يُصدر `TeacherApplicationSubmitted`
- [X] T089 [US4] `backend/app/Modules/Marketplace/Actions/ApproveTeacherApplication.php` — يشتقّ `is_publicly_listed` من (الاعتماد × اشتراك المساحة)، **لا** يضبطه يدوياً، ويبطل التخزين المؤقت، ويُصدر `TeacherApproved`
- [X] T090 [P] [US4] `backend/app/Modules/Marketplace/Actions/RejectTeacherApplication.php` و`RequestApplicationChanges.php`
- [X] T091 [P] [US4] `backend/app/Modules/Marketplace/Actions/SetAvailability.php` — رفض الفترات المتداخلة (FR-028) مفروضاً في الـ Action لا في التحقق فقط
- [X] T092 [P] [US4] `backend/app/Modules/Marketplace/Actions/SuspendTeacher.php` و`ReinstateTeacher.php` مع إبطال فوري للتخزين المؤقت
- [X] T093 [US4] `backend/app/Modules/Marketplace/Actions/SetMarketplaceParticipation.php` — الانسحاب يُخفي كل عناصر المساحة **دون** تغيير `approval_status` لأي مدرّس
- [X] T094 [P] [US4] أحداث `TeacherApplicationSubmitted`, `TeacherApproved`, `TeacherRejected` في `backend/app/Modules/Marketplace/Events/`
- [X] T095 [US4] ربط مستمعي الإخطارات بـ `Event::listen()` في `boot()` بـ `MarketplaceServiceProvider.php` — **لا** `EventServiceProvider` (الدستور، المبدأ III)
- [X] T096 [P] [US4] إخطارات القبول والرفض وطلب التعديل في `backend/app/Modules/Notifications/`
- [X] T097 [US4] متحكّمات ونقاط المعالج والمراجعة حسب `contracts/registration-api.md` و`contracts/teacher-admin-api.md`
- [X] T098 [P] [US4] مورد Filament للمراجعة في `backend/app/Modules/Marketplace/Filament/Resources/TeacherApplicationResource.php` ينادي **نفس** الـ Actions (الدستور، المبدأ II)
- [X] T099 [US4] `frontend/src/app/(public)/signup/teacher/page.tsx` — معالج أربع خطوات بمؤشّر تقدّم واستئناف من الخطوة المحفوظة (FR-069, FR-070)
- [X] T100 [US4] شاشة "طلبك قيد المراجعة من فريقنا الأكاديمي" مع المدة المتوقعة في `frontend/src/app/(public)/signup/teacher/submitted/page.tsx`

**Checkpoint**: تدفّق العرض كامل — مدرّسون حقيقيون يدخلون السوق

---

## Phase 7: User Story 5 - تقييمات الطلاب ودرجة الثقة (Priority: P5)

**Goal**: تحويل درجة الثقة من "قيد التكوين" إلى قيمة محتسبة حقيقية

**Independent Test**: إنشاء حصص مكتملة وتقييمات، والتحقق من المتوسط وتوزيع النجوم ودرجة
الثقة وفئتها اللونية

### Tests for User Story 5

- [X] T101 [P] [US5] اختبار `backend/tests/Feature/Marketplace/ReviewTest.php` — منع التقييم بلا حصة مكتملة (422)، سجل فعّال واحد لكل زوج، المتوسط لا يتضخّم
- [X] T102 [P] [US5] اختبار `backend/tests/Feature/Marketplace/TrustScoreTest.php` — كل حالات جدول `quickstart.md` سيناريو 3 بما فيها "قيد التكوين" ≠ صفر
- [X] T103 [P] [US5] اختبار يثبت أن وظيفة إعادة الاحتساب تستخدم `forWorkspace()` ولا تسرّب مساحة العمل إلى مهمة تالية على نفس العامل (الدستور، المبدأ I)

### Implementation for User Story 5

- [X] T104 [P] [US5] نموذج وهجرة `backend/app/Modules/Marketplace/Models/Review.php` بقيد فريد `(teacher_profile_id, student_id)`
- [X] T105 [P] [US5] نموذج وهجرة `backend/app/Modules/Marketplace/Models/Complaint.php`
- [X] T106 [US5] `backend/app/Modules/Marketplace/Actions/SubmitReview.php` — يرفض بلا حصة مكتملة (FR-018)، ويُحدّث السجل القائم بدل إنشاء ثانٍ (FR-019)
- [X] T107 [US5] `backend/app/Modules/Marketplace/Actions/RecalculateTrustScore.php` — يستخدم `TrustScoreCalculator`، ويقصّ على 0–100، ويكتب `trust_score_factors` و`trust_score_calculated_at`
- [X] T108 [US5] `backend/app/Modules/Marketplace/Jobs/RecalculateTrustScoreJob.php` — **يستخدم `WorkspaceContext::forWorkspace()` حصراً، ممنوع `set()`**
- [X] T109 [P] [US5] أحداث `ReviewSubmitted` و`ComplaintConfirmed` ومستمعوها في `backend/app/Modules/Marketplace/`
- [X] T110 [P] [US5] `backend/app/Modules/Marketplace/Actions/ConfirmComplaint.php` و`DismissComplaint.php`
- [X] T111 [P] [US5] `backend/app/Modules/Marketplace/Actions/ModerateReview.php` — الإخفاء يعيد الاحتساب
- [X] T112 [P] [US5] سياسة `backend/app/Modules/Marketplace/Policies/ReviewPolicy.php`
- [X] T113 [US5] نقاط التقييمات والشكاوى حسب `contracts/teacher-admin-api.md`
- [X] T114 [US5] تحديث العدّادات المادّية (`reviews_count`, `average_rating`, `completed_sessions_count`) عند كل حدث في `backend/app/Modules/Marketplace/Listeners/`
- [X] T115 [P] [US5] مكوّن `frontend/src/components/marketplace/ReviewsTab.tsx` — المتوسط + توزيع النجوم كأشرطة + القائمة الزمنية العكسية
- [X] T116 [P] [US5] مكوّن `frontend/src/components/marketplace/ReviewForm.tsx` للطالب المؤهّل
- [X] T117 [US5] استبدال حالة "قيد التكوين" بالقيم الحقيقية في `TrustScoreBadge.tsx` و`TrustScoreBreakdown.tsx`
- [X] T118 [US5] تفعيل الفلترة والترتيب بدرجة الثقة مع استبعاد "قيد التكوين" من المقارنة العددية (FR-026)

**Checkpoint**: نظام الثقة يعمل — الميزة المميِّزة للمنصة مكتملة

---

## Phase 8: User Story 6 - وليّ أمر يربط أبناءه (Priority: P6)

**Goal**: حساب وليّ أمر مرتبط بأبنائه مع تفضيلات إشعارات

**Independent Test**: إنشاء حساب وليّ أمر، إضافة طفلين، ضبط الإشعارات، والتحقق من أن وليّ
أمر آخر لا يراهما

### Tests for User Story 6

- [X] T119 [P] [US6] اختبار `backend/tests/Feature/Marketplace/ParentAccountTest.php` — الربط، ومنع وليّ أمر من الوصول لابن غير مرتبط (403)

### Implementation for User Story 6

- [X] T120 [P] [US6] هجرة ونموذج `backend/app/Modules/Identity/Models/ParentChildLink.php`
- [X] T121 [P] [US6] هجرة ونموذج `backend/app/Modules/Identity/Models/NotificationPreference.php`
- [X] T122 [P] [US6] `backend/app/Modules/Identity/Actions/RegisterParent.php`
- [X] T123 [US6] `backend/app/Modules/Identity/Actions/AddChild.php`
- [X] T124 [P] [US6] سياسة `backend/app/Modules/Identity/Policies/ParentChildLinkPolicy.php` (FR-075)
- [X] T125 [US6] نقاط `POST /auth/register/parent`, `POST /parent/children`, `GET|PUT /parent/notification-preferences`
- [X] T126 [P] [US6] `frontend/src/app/(public)/signup/parent/page.tsx`
- [X] T127 [P] [US6] شاشة "إضافة طفل" مع إمكانية التخطّي في `frontend/src/app/(public)/signup/parent/children/page.tsx`
- [X] T128 [P] [US6] لوحة تفضيلات الإشعارات في `frontend/src/components/marketplace/NotificationPreferences.tsx`

**Checkpoint**: كل القصص الست تعمل مستقلة

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: الجودة العرضية والتحقق النهائي

- [X] T129 الوضع الليلي: متغيّرات `@theme` للوضعين + سمة على الجذر + احترام تفضيل النظام في `frontend/src/app/globals.css` و`frontend/src/app/(public)/layout.tsx` (FR-084)
- [X] T130 **إصلاح التباين المتوقّع**: `#FF8A34` و`#F5B301` لا يحقّقان 4.5:1 مع نص أبيض — نص داكن عليهما أو درجة أغمق كخلفية للنص، في `frontend/src/app/globals.css` (FR-081)
- [X] T131 [P] اختبار `frontend/e2e/accessibility.spec.ts` — السبع صفحات × وضعين × ثلاثة عروض بـ axe، صفر مخالفات (SC-012, SC-013)
- [X] T132 [P] تسميات ARIA عربية لكل عنصر تفاعلي وصورة ذات معنى عبر `frontend/src/components/marketplace/` (FR-082)
- [X] T133 [P] بنية HTML دلالية + `metadata` فريدة لكل صفحة في `frontend/src/app/(public)/**/page.tsx` (FR-083, SC-016)
- [X] T134 [P] احترام تفضيل تقليل الحركة في كل الحركات في `frontend/src/app/globals.css` (FR-086)
- [ ] T135 [P] تنزيل الصور واستضافتها ذاتياً في `frontend/public/marketplace/` وتوثيق المصادر والتراخيص في `LICENSES.md` (R10) — **معلّقة**: Unsplash/Pexels غير متاحين من بيئة البناء. الأشكال البديلة SVG محلية، وخطوات التنزيل والتوثيق مكتوبة في `LICENSES.md`.
- [X] T136 [P] إضافة أيقونات SVG من مجموعة مفتوحة المصدر إلى `frontend/src/components/icons/` — بلا CDN وبلا مكتبات مدفوعة
- [X] T137 بذرة الحجم `backend/database/seeders/MarketplaceLoadSeeder.php` (50,000 مدرّس + 10,000 كورس) والتحقق من SC-008 — **نُفِّذت. النتيجة: 6 من 7 استعلامات ضمن الميزانية.**
  - كشف القياس عيباً حقيقياً: البحث بالاسم عبر `whereHas('user')` كلّف 1116ms (استعلام مترابط لكل صف، يُنفَّذ مرتين لأن المرقّم يعدّ ثم يختار). عولج بعمود `search_name` غير مطبَّع ⇒ ~800ms.
  - يبقى فلتر المادة عند 1019ms (تجاوز 2%). خطة التنفيذ مثالية بالفعل — كل خطوة تستخدم فهرساً، ولا فهرس ناقص. التكلفة متأصّلة في `EXISTS` مترابطَين على 50 ألف صف يُقيَّمان مرتين.
  - **تحفّظ**: القياس على SQLite على جهاز تطوير؛ الإنتاج MySQL + Redis، والنقطة نفسها مخزّنة مؤقتاً 60 ثانية. الأرقام المطلقة ليست أرقام إنتاج. **SC-008 لم يُثبَت نهائياً** — يلزم إعادة القياس على MySQL.
- [X] T138 [P] تحديث `docs/README.md` بجداول الوحدة والنقاط والأذونات الجديدة، و`docs/erd.md` بالمخطّط (الدستور، سير العمل)
- [X] T139 [P] توثيق الفخّ المكتشف في `CLAUDE.md` و`AGENTS.md`: `WorkspaceScope` معطّل أمام الزوار، وكل مسار عام يلزمه `publiclyListed()` (research R1)
- [X] T140 تشغيل كل سيناريوهات `quickstart.md` والبوابات الأربع + المسارات الحرجة الثمانية في `AGENTS.md`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: بلا اعتماديات — يبدأ فوراً
- **Foundational (Phase 2)**: يعتمد على Phase 1 — **يحجب كل القصص**
- **User Stories (Phase 3–8)**: كلها تعتمد على Phase 2
- **Polish (Phase 9)**: يعتمد على القصص المطلوبة

### User Story Dependencies

- **US1 (P1)**: بعد Phase 2 — **بلا اعتماد على أي قصة أخرى**
- **US2 (P2)**: بعد Phase 2 — مستقلة
- **US3 (P3)**: بعد Phase 2 — مستقلة
- **US4 (P4)**: بعد Phase 2 — مستقلة (تُغذّي US1 ببيانات حقيقية بدل المصانع، لكن US1 لا تنتظرها)
- **US5 (P5)**: بعد Phase 2 — مستقلة تقنياً؛ **قيمتها** تظهر بعد وجود حصص مكتملة
- **US6 (P6)**: بعد Phase 2 — مستقلة

### داخل كل قصة

الاختبارات ← النماذج ← الـ Actions ← الموارد والمتحكّمات ← الواجهة

### Parallel Opportunities

- Phase 1: T004–T009 كلها بالتوازي
- Phase 2: T012, T013, T015 بالتوازي · T017, T018 بالتوازي · T020–T023, T025, T027, T028 بالتوازي
- Phase 3: T031–T033 بالتوازي · T044–T047, T049–T051 بالتوازي
- Phase 6: T086, T087, T090–T092, T094, T096, T098 بالتوازي
- Phase 7: T104, T105, T109–T112, T115, T116 بالتوازي
- Phase 9: معظم المهام بالتوازي عدا T137 و T140
- بعد Phase 2، القصص الست قابلة للتوزيع على مطوّرين مختلفين

---

## Parallel Example: User Story 1

```bash
# اختبارات US1 معاً:
Task: "اختبار عقد GET /marketplace/teachers في backend/tests/Feature/Marketplace/PublicTeacherListTest.php"
Task: "اختبار عقد GET /marketplace/teachers/{uuid} في backend/tests/Feature/Marketplace/PublicTeacherDetailTest.php"
Task: "اختبار السوق العابر لمساحات العمل في backend/tests/Feature/Marketplace/CrossWorkspaceListingTest.php"

# مكوّنات الواجهة المستقلة معاً:
Task: "مكوّن TeacherCard في frontend/src/components/marketplace/TeacherCard.tsx"
Task: "مكوّن TrustScoreBadge في frontend/src/components/marketplace/TrustScoreBadge.tsx"
Task: "مكوّن StarRating في frontend/src/components/marketplace/StarRating.tsx"
Task: "مكوّنات SiteHeader و SiteFooter في frontend/src/components/marketplace/"
```

---

## Implementation Strategy

### MVP First (US1 فقط)

1. Phase 1: Setup (T001–T009)
2. Phase 2: Foundational (T010–T030) — **حرج، يحجب كل شيء**
3. Phase 3: US1 (T031–T060)
4. **قف وتحقّق**: سيناريو 1 و2 في `quickstart.md`
5. انشر — موقع عام كامل يعمل

### Incremental Delivery

Setup + Foundational → US1 (**MVP**) → US2 (تحويل) → US3 (اكتشاف بالمحتوى) →
US4 (جانب العرض) → US5 (نظام الثقة) → US6 (أولياء الأمور) → Polish

كل قصة تضيف قيمة بلا كسر ما قبلها.

### Parallel Team Strategy

بعد اكتمال Phase 2: مطوّر على US1، وثانٍ على US2+US6 (كلاهما في وحدة `Identity`)،
وثالث على US4+US5 (كلاهما في نطاق المدرّس). US3 صغيرة ويلتقطها أي منهم.

---

## Notes

- **الفخّ الأخطر**: كل استعلام عام بلا `publiclyListed()` = تسريب. T010 و T029 هما الحارس.
- **ممنوع في الطابور**: `WorkspaceContext::set()` — استخدم `forWorkspace()` حصراً (T108).
- **عرض العمود**: `trust_score` هو `unsignedTinyInteger` — SQLite يقبل أي رقم وMySQL الصارم يرفض. القصّ على 0–100 في T025 لا في مكان آخر.
- **حالة أحرف الهجرات**: `Database/Migrations` بـ M كبيرة — خطأ الحالة يحمّل صفر هجرات على Linux بصمت.
- **بوابات الدمج**: `pest` · `pint --test` · `phpstan analyse` · `tsc --noEmit` — الأربعة خضراء.
- كل نموذج جديد تابع لمستأجر يلزمه `BelongsToWorkspace` **وحالة في `WorkspaceIsolationTest`**.
- التزم بالنمط: Request ← DTO ← Action ← Resource. القواعد تُفرض في الـ Action لا في التحقق فقط.
