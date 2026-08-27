---

description: "Task list — 021 صفحةُ المادّةِ منهجاً ومجموعات"
---

# Tasks: صفحةُ المادّةِ منهجاً ومجموعات

**Input**: Design documents from `/specs/021-course-cohort-hub/`

**Prerequisites**: [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/api.md](./contracts/api.md) · [quickstart.md](./quickstart.md)

**Tests**: **مطلوبةٌ في هذه المرحلة.** المبدأُ الرابعُ في الدستورِ يجعلُ البوّاباتِ الخضراءَ شرطَ اندماج، و`NFR-006` تُكرِّرُه، وعشرةٌ من العشرين `SC` تصفُ **بناءَ الاختبارِ نفسِه** لا نتيجتَه فقط (تجهيزان بحجمين · تدخّلٌ بين القراءةِ والاقتناص · مساحتا عملٍ لا واحدة). فمهامُّ الاختبارِ هنا جزءٌ من التسليمِ لا زينةٌ فوقَه.

**Organization**: المهامُّ مجمَّعةٌ بقصّةِ المستخدم؛ كلُّ قصّةٍ تُنفَّذُ وتُختبَرُ وتُشحَنُ وحدَها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يجوزُ توازيه (ملفٌّ مختلف، بلا اعتمادٍ على ناقص)
- **[Story]**: US1 … US5
- كلُّ مهمّةٍ تحملُ مسارَ ملفِّها

## Path Conventions

`backend/app/Modules/{Module}/…` · `backend/tests/Feature/{Module}/…` · `frontend/src/…`

---

## Phase 1: Setup

**Purpose**: ما تحتاجُه المرحلةُ كلُّها ولا يخصُّ قصّةً بعينِها.

- [X] T001 أضِفْ محدّدَ المعدّلِ المُسمّى `cohort-write` (٢٠/دقيقة، مفتاحُه المستخدم) في `backend/app/Providers/AppServiceProvider.php` داخلَ `registerRateLimiters()`. ⚠️ السطريُّ `throttle:N,M` ممنوع: `ThrottleRequests` يُفهرِسُ على `domain|ip` بلا مسارٍ في التجزئة، فكلُّ حدٍّ سطريٍّ يتقاسمُ عدّاداً واحداً.
- [X] T002 [P] أضِفْ `CohortSettings` (السعةُ الافتراضيّةُ · مدّةُ منعِ الشاتِ الافتراضيّة) في `backend/app/Modules/Learning/Support/CohortSettings.php` على نمطِ `Community/Support/CommunitySettings.php`، تقرأُ من `platform_settings` وترتدُّ إلى `config/`. ⚠️ رقمٌ لا يتغيّرُ إلّا بشحنِ كودٍ هو رقمٌ لا يضبطُه أحد.
- [X] T003 [P] أضِفْ ملفَّ العميلِ `frontend/src/lib/cohorts.ts` (أنواعُ TypeScript ونداءاتُ `api` لعقودِ §ب/§ج في `contracts/api.md`) — بلا مكوّنٍ بعد.

⚠️ **لا صلاحيّةَ جديدة.** إدارةُ المجموعاتِ والإسنادُ والموافقةُ على `COURSES_UPDATE` القائمة، والكتمُ والمنعُ على `CHAT_MODERATE`، و`ATTENDANCE_VIEW` تبقى بلا لمسةٍ لأنّها ما **تمنعُه** القائمةُ لا ما تشترطُه. صلاحيّةٌ جديدةٌ تعني صفَّ بذرةٍ وهجرةَ تعبئةٍ لقواعدَ قائمةٍ (`SeedDefaultRoles` يعملُ مرّةً عندَ إنشاءِ المساحةِ ولا يعودُ) — مقابلَ صفرِ مكسبٍ في التفويض.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: لا تبدأُ قصّةٌ قبلَ اكتمالِ هذه المرحلة.

### أ. استخراجُ بوّابةِ الدرس — أخطرُ مهمّةٍ في المرحلة

> ⚠️ **هذا استخراجٌ، لا كتابةُ حسابٍ جماعيٍّ بجانبِ الفرديّ.** قيدُ السبيك: «**يُمنَعُ** أن تُعيدَ صياغتَه أو تُقاربَه». وهو يمسُّ الطريقَ الوحيدَ لفتحِ درسٍ في المنتَجِ كلِّه.
> **الانضباطُ هنا معكوسٌ عن TDD**: اختباراتُ `accessTo` القائمةُ **خضراءُ قبلَ الاستخراجِ وبعدَه بلا تعديلِ حرفٍ فيها**. تعديلُ اختبارٍ قائمٍ لإرضاءِ الاستخراجِ يعني أنّ الاستخراجَ غيّرَ معنًى.

- [X] T004 شغِّلْ `php vendor/bin/pest tests/Feature/Learning` وسجِّلْ خطَّ الأساسِ الأخضرَ في `specs/021-course-cohort-hub/` — هو شبكةُ الأمانِ لـT005–T007.
- [X] T005 أنشئْ `backend/app/Modules/Learning/Support/LessonGate.php` بتابعِ `for(Enrollment, Lesson): LessonAccess` يحملُ **منطقَ `Enrollment::accessTo()` منقولاً حرفيّاً** بتعليقاتِه (`Enrollment.php:130–284`).
- [X] T006 حوِّلْ `Enrollment::accessTo()` في `backend/app/Modules/Learning/Models/Enrollment.php` إلى تفويضٍ لسطرٍ واحدٍ إلى `LessonGate::for()`. ⚠️ لا يتغيّرُ توقيعٌ ولا مُنادٍ من الثلاثةِ في `EnrollmentController` (`:65`, `:92`, `:204`).
- [X] T007 أعِدْ تشغيلَ `php vendor/bin/pest tests/Feature/Learning` — **يجبُ أن يكونَ خطُّ الأساسِ نفسُه، وبلا تعديلِ أيِّ ملفِّ اختبار**.
- [X] T008 أضِفْ `LessonGate::forTree(Enrollment): array<int, LessonAccess>` في `backend/app/Modules/Learning/Support/LessonGate.php`: تجلبُ الشجرةَ والتقدّمَ ومحاولاتِ الامتحانِ و`bookedLessonIdsFor()` **مرّةً واحدةً لكلٍّ**، ثمّ تمشي العناصرَ بترتيبِ `(section.order, chapter.order, lesson.order)` في مرورٍ واحدٍ حاملةً «آخِرَ عنصرٍ مؤهَّلٍ سبق» — بنفسِ شروطِ الاستبعادِ (`class_session_id IS NULL` · الحالاتُ الثلاثُ منشورة · `completableValues()` · `ReferenceIntegrity`).
- [X] T009 [P] اختبارٌ يُثبِتُ **اتّفاقَ الصيغتين**: لكلِّ درسٍ في تجهيزٍ يغطّي الأسبابَ الستّةَ، `forTree()[id]` يساوي `for()` في `allowed` وفي `reason`. في `backend/tests/Feature/Learning/LessonGateParityTest.php`. ⚠️ هذا الاختبارُ هو الحارسُ الوحيدُ ضدَّ «الجوابين» بعدَ اليوم.

### ب. مكوّنُ التبويبات — لا وجودَ له اليوم

- [X] T010 أنشئْ `frontend/src/components/ui/Tabs.tsx` بمجموعةٍ **مغلقةٍ** من الخصائصِ كبقيّةِ المجلَّد (بلا `className` حرّة): `role="tablist"` · `aria-selected` · إدارةٌ بالأسهمِ و`Home`/`End` · التبويبةُ النشطةُ في العنوانِ عبرَ `?tab=` (FR-021). ⚠️ **الأسهمُ منطقيّةٌ في RTL**: «التالي» هو `ArrowLeft` بصريّاً — الربطُ على الاتّجاهِ لا على اسمِ المفتاح.
- [X] T011 [P] اختبارُ `frontend/src/components/ui/Tabs.test.tsx`: التنقّلُ بالأسهمِ **في RTL**، و`Home`/`End`، وبقاءُ التبويبةِ بعدَ إعادةِ التحميلِ من `?tab=`. ⚠️ لا يصلُ إلى أيٍّ من هذا اختبارُ خلفيّة.

**Checkpoint**: البوّابةُ صيغتان متّفقتان، والتبويباتُ جاهزة — تبدأُ القصص.

---

## Phase 3: User Story 1 — المنهجُ يقولُ ما هو مفتوحٌ قبلَ الضغط (P1) 🎯 MVP

**Goal**: `/enrollments/{course}` تصيرُ منهجاً بغلافٍ وحالةٍ لكلِّ عنصرٍ وسببٍ لكلِّ قفل، والمقفولُ **لا يُضغَط**.

**Independent Test**: طالبةٌ في كورسٍ متسلسلٍ لم تُكمِلْ درسَه الثاني ترى الثالثَ مقفولاً بجملةٍ تُسمّي الثاني، ولا تستطيعُ فتحَه من الصفحة.

### اختباراتُ US1

- [X] T012 [P] [US1] اختبارُ عقدِ المنهجِ في `backend/tests/Feature/Learning/CurriculumEndpointTest.php`: الأسبابُ الستّةُ تظهرُ برموزِها · المسوّدةُ والمؤرشَفُ **لا يظهرانِ إطلاقاً** (FR-004) · `resume_lesson_uuid` هو أوّلُ مفتوحٍ غيرِ مكتمل.
- [X] T013 [P] [US1] ⚠️ اختبارُ ميزانٍ في `backend/tests/Feature/Learning/CurriculumQueryBudgetTest.php` بتجهيزَين — **١٠ دروسٍ ⇄ ٢٠٠ درساً** — والعددُ **هو هو** (SC-004). ⚠️ ويؤكِّدُ **حضورَ حقولِ `state` و`lock` و`cover_url`** كذلك: إسقاطُ جلبٍ مُتلهِّفٍ مع `whenLoaded` لا يُنتِجُ N+1 — المفتاحُ يغيبُ والصفحةُ تصيرُ أرخصَ فيُقرَأُ التراجعُ تحسّناً.
- [X] T014 [P] [US1] ⚠️ اختبارُ سياقِ الطالبِ الحقيقيِّ في `backend/tests/Feature/Learning/CurriculumStudentContextTest.php`: يُبنى الطالبُ **بلا `last_workspace_id`** ومُفردةُ السياقِ مُصفَّرة. بغيرِ ذلك يقيسُ الاختبارُ شخصاً لا تمنحُه الحياةُ ذلك السياقَ أبداً.

### الخلفيّة

- [X] T015 [US1] أضِفْ `ReadCurriculum` في `backend/app/Modules/Learning/Actions/ReadCurriculum.php` — تبني الحمولةَ من `LessonGate::forTree()` و`CourseProgress`.
- [X] T016 [US1] أضِفْ `CurriculumResource` في `backend/app/Modules/Learning/Http/Resources/CurriculumResource.php` بشكلِ `contracts/api.md §أ`. ⚠️ `not_visible` و`not_enrolled` لا يظهرانِ فيها بحالٍ: الأوّلُ يحذفُ الصفَّ، والثاني يجعلُ الطلبَ `403`.
- [X] T017 [US1] أضِفْ `GET /courses/{course}/curriculum` في `backend/app/Modules/Learning/routes/api.php` وتابعَه في `backend/app/Modules/Learning/Http/Controllers/EnrollmentController.php`. ⚠️ الحارسُ ملكيّةُ التسجيلِ صراحةً لا النطاق — `WorkspaceScope` خاملٌ على الطالبِ تماماً.
- [X] T018 [US1] أضِفْ `cover_url` إلى موردِ الكورسِ المُصادَقِ عليه في `backend/app/Modules/Courses/Http/Resources/CourseResource.php` **بإملاءِ `PublicCourseCardResource.php:31` حرفاً بحرف** (`asset('storage/'.$cover_path)`). ⚠️ إملاءان لعنوانٍ واحدٍ يفترقان عندَ أوّلِ تغييرٍ في قرصِ التخزين.

### الواجهة

- [X] T019 [P] [US1] أضِفْ `frontend/src/lib/curriculum.ts` — الأنواعُ ونداءُ الجلبِ ومُخطِّطُ `lock.code` إلى نصٍّ احتياطيّ.
- [X] T020 [P] [US1] أنشئْ `frontend/src/components/courses/CourseBanner.tsx`. ⚠️ **`<img>` عاديّةٌ، لا `next/image`** — الغلافُ صورةٌ يرفعُها المدرّس، و`CLAUDE.md` تُسمّي تمريرَ صورةٍ من المستخدمِ إلى `next/image` **مُبطِلاً** لقبولِ استشارةِ `sharp`. السوابقُ بأسبابِها في `MessageList.tsx:238` و`ReviewsTab.tsx:103` و`ParticipantsPanel.tsx:498`. وطبقةُ التعتيمِ تُنسَخُ من `PageBanner.tsx` لا تُخترَع.
- [X] T021 [P] [US1] أنشئْ `frontend/src/components/courses/LessonRow.tsx`: الحالةُ بشكلٍ **وعلامةٍ** لا بلونٍ وحدَه (FR-005) · جملةُ القفلِ ظاهرةٌ في الصفّ · ⚠️ **المقفولُ ليس `<Link>` ولا `<button>`** — لا رابطَ ولا زرّ (FR-007).
- [X] T022 [US1] أنشئْ `frontend/src/components/courses/CurriculumTree.tsx`: أقسامٌ ⇽ فصولٌ ⇽ دروسٌ تحتَ بعضِها، بظهورٍ متدرّجٍ مقيَّدٍ السقفِ عبرَ `banner-rise` وحشوِ `both` (FR-012).
- [X] T023 [US1] أعِدْ بناءَ `frontend/src/app/(app)/(shell)/enrollments/[course]/page.tsx`: بنر + رأسٌ بالنسبةِ والعدّادِ و«تابعْ من هنا» (FR-010) + `Tabs` بتبويبةِ «المنهج» وحدَها في هذه المرحلة.
- [X] T024 [P] [US1] حدِّثْ `frontend/src/app/(app)/(shell)/enrollments/[course]/page.test.tsx`: الصفُّ المقفولُ **ليس رابطاً** · جملةُ سببِه معروضة · الكورسُ بلا غلافٍ يعرضُ البديلَ ولا ينكسر · الكورسُ غيرُ المتسلسلِ بلا قفلٍ واحد.
- [X] T025 [P] [US1] اختبارُ `frontend/src/components/courses/LessonRow.test.tsx`: الحالاتُ الثلاثُ تُميَّزُ بنصٍّ يقرأُه قارئُ الشاشة، لا بلونٍ فقط.

**Checkpoint**: US1 تشحنُ وحدَها. **صفرُ كياناتٍ جديدة، وصفرُ هجرات.**

---

## Phase 4: User Story 2 — كلُّ ما يخصُّ المادّةِ خلفَ تبويبةٍ واحدة (P2)

**Goal**: ٦ تبويباتٍ زائدَ رأسِ الحصّةِ القادمة، تجمعُ ما هو مبعثرٌ في ستّةِ أماكن.

**Independent Test**: كورسٌ فيه حصّةٌ قادمةٌ واختبارٌ منشورٌ وواجبٌ مستحقٌّ وتنبيه — الأربعةُ تظهرُ من صفحةِ المادّةِ وحدَها.

### اختباراتُ US2

- [X] T026 [P] [US2] اختبارُ مرشِّحاتِ الكورسِ في `backend/tests/Feature/Learning/CourseTabFiltersTest.php`: `?course=` على الاختباراتِ والواجباتِ والشهادات — ⚠️ ومعرّفٌ مجهولٌ يُطابِقُ **لا شيء**، لا كلَّ شيء.
- [X] T027 [P] [US2] اختبارُ `backend/tests/Feature/Learning/CourseAnnouncementsTest.php`: الطالبُ يقرأُ تنبيهاتِ مادّتِه المنشورةَ غيرَ المخفيّة، ولا يقرأُ تنبيهاتِ مادّةٍ أخرى.

### الخلفيّة

- [X] T028 [P] [US2] أضِفْ مرشِّحَ `?course={uuid}` (مُطابَقاً **عبرَ العلاقةِ بالـuuid**) في `backend/app/Modules/Assessments/Http/Controllers/ExamController.php` — `index` بلا مرشِّحٍ اليوم و`exams.course_id` موجود.
- [X] T029 [P] [US2] أضِفْ نفسَ المرشِّحِ في `backend/app/Modules/Assessments/Http/Controllers/AssignmentController.php`.
- [X] T030 [P] [US2] أضِفْ نفسَ المرشِّحِ في `backend/app/Modules/Certificates/Http/Controllers/CertificateController.php`.
- [X] T031 [US2] أضِفْ `ReadCourseAnnouncements` في `backend/app/Modules/Learning/Actions/ReadCourseAnnouncements.php` والمسارَ `GET /courses/{course}/announcements`. ⚠️ **قراءةٌ جديدةٌ بالكامل**: لا مسارَ تنبيهاتٍ للطالبِ في المنتَجِ اليوم — `/manage/announcements` للمدرّسِ وحدَه، والتنبيهُ يبلغُ الطالبَ عبرَ صفوفِ `notifications` لا غير.
- [X] T032 [US2] أضِفْ `GET /courses/{course}/next-session` في `backend/app/Modules/LiveSessions/…`. ⚠️ `join_open` يُحسَبُ **على الخادم** من نافذةِ الدخولِ وحالةِ الغرفة، لا من ساعةِ المتصفّح؛ و`room_closed` يفوقُ الحالةَ على الشارةِ والزرِّ معاً (درسُ ٠١٨: حصّةٌ تبقى `live` بعدَ إغلاقِ غرفتِها).

### الواجهة

- [X] T033 [P] [US2] `frontend/src/components/courses/NextSessionHeader.tsx` — العدّادُ وزرُّ الدخولِ وجملةُ «لا حصّةَ قادمة».
- [X] T034 [P] [US2] `frontend/src/components/courses/tabs/SessionsTab.tsx` — ماضيةٌ وقادمةٌ ومدخلُ التسجيلِ لمن له مقعد.
- [X] T035 [P] [US2] `frontend/src/components/courses/tabs/ExamsTab.tsx`
- [X] T036 [P] [US2] `frontend/src/components/courses/tabs/AssignmentsTab.tsx` — الحالةُ والاستحقاقُ وأثرُ التأخير.
- [X] T037 [P] [US2] `frontend/src/components/courses/tabs/AnnouncementsTab.tsx`
- [X] T038 [P] [US2] `frontend/src/components/courses/tabs/CertificateTab.tsx` — الشهادةُ أو **شرطُ إصدارِها نصّاً** حين لا توجد (FR-020).
- [X] T039 [US2] اربطِ التبويباتِ الستَّ ورأسَ الحصّةِ في `frontend/src/app/(app)/(shell)/enrollments/[course]/page.tsx`. ⚠️ **تبويبةٌ لا مضمونَ لها في هذا الكورسِ لا تُعرَض** (FR-014): كورسُ `recorded` بلا تبويبةِ حصصٍ وبلا رأسٍ أصلاً.
- [X] T040 [P] [US2] اختبارٌ في `frontend/src/app/(app)/(shell)/enrollments/[course]/page.test.tsx`: كورسُ `recorded` لا يعرضُ تبويبةَ الحصص · «لا حصّةَ قادمة» جملةٌ لا عدّادٌ فارغ · `?tab=exams` يفتحُ على تبويبتِها.

**Checkpoint**: US1 + US2 تعملان معاً. **ما زالت صفرُ هجرات.**

---

## Phase 5: User Story 3 — للمادّةِ أكثرُ من مجموعة (P3)

**Goal**: المجموعةُ والعضويّةُ والسجلُّ والطلبُ والموافقة، وحجبُ الحصّةِ غيرِ المُسنَدة.

**Independent Test**: كورسٌ فيه مجموعتان — بعدَ الانتقالِ جدولُ الطالبةِ تغيّر ونسبةُ إنجازِها بالرقمِ نفسِه، وسجلُّها يحملُ صفَّي خروجٍ ودخولٍ مؤرَّخَين.

### الهجراتُ والنماذج

- [ ] T041 [US3] هجرةُ `cohorts` في `backend/app/Modules/Learning/Database/Migrations/` بأعمدةِ data-model §١ و`unique(course_id, name)`. ⚠️ **`Migrations` بحرفٍ كبير** — التطابقُ حرفيٌّ في `Module::registerMigrations()`، والخطأُ يُحمِّلُ **صفرَ** هجراتٍ على Linux ويعملُ على Windows.
- [ ] T042 [US3] هجرةُ `cohort_memberships` بـ`closed_slot` **`NOT NULL DEFAULT 0`** و`unique(student_user_id, course_id, closed_slot)`. ⚠️ `unique` على `closed_at IS NULL` **لا يعضّ** — NULL لا يساوي NULL على أيٍّ من المحرّكين، والفهرسُ الجزئيُّ ميزةُ Postgres لا وجودَ لها في MySQL. السوابقُ: `concept_stats.lesson_id` · `unlock_rules.course_id` · `award_entries.reversal_of_id`.
- [ ] T043 [US3] هجرةُ `cohort_membership_events` بـ`UPDATED_AT = null` والفهرسين.
- [ ] T044 [US3] هجرةُ `cohort_transfer_requests` بـ`pending_slot` **`NOT NULL DEFAULT 0`** و`unique(student_user_id, course_id, pending_slot)`.
- [ ] T045 [US3] هجرةُ `class_sessions`: `+ cohort_id` قابلٌ للعدم، **وفهرسٌ `(workspace_id, course_id, cohort_id, starts_at)`**. ⚠️ يُضافُ الفهرسُ **قبلَ** أن يقرأَ استعلامٌ العمودَ الجديد؛ وإسقاطُ عمودٍ مُفهرَسٍ يحتاجُ `dropIndex()` في جملةٍ مستقلّةٍ أوّلاً — MySQL يتساهلُ وSQLite **يرفض**، وكلُّ اختبارٍ هنا على SQLite. وإغلاقتا `Schema::table` منفصلتان.
- [ ] T046 [P] [US3] نموذجُ `backend/app/Modules/Learning/Models/Cohort.php` — `BelongsToWorkspace` + `HasUuid`، و`members_count` و`status` **خارجَ `$fillable`**.
- [ ] T047 [P] [US3] نموذجُ `backend/app/Modules/Learning/Models/CohortMembership.php` — ⚠️ `closed_slot` **خارجَ `$fillable`**: تُكتَبُ داخلَ الجملةِ التي تملكُ الإغلاق، ومُسنَدةً جماعيّاً تصيرُ باباً ثانياً لعضويّةٍ ثانية.
- [ ] T048 [P] [US3] نموذجُ `backend/app/Modules/Learning/Models/CohortMembershipEvent.php` مع `booted()` يرمي على `updating` و`deleting` — سابقةُ `LedgerEntry`. ⚠️ و**لا كتابةَ جماعيّةً على هذا الجدولِ إطلاقاً**: `update()` الجماعيُّ لا يجلبُ نماذجَ فيتخطّى الحارس.
- [ ] T049 [P] [US3] نموذجُ `backend/app/Modules/Learning/Models/CohortTransferRequest.php` — `pending_slot` و`status` خارجَ `$fillable`.
- [ ] T050 [P] [US3] مصانعُ الأربعةِ في `backend/database/factories/Modules/Learning/`.

### العقدُ والأفعال

- [ ] T051 [US3] عقدُ `backend/app/Shared/Contracts/CohortDirectory.php` بالتوقيعاتِ السّتِّ في data-model §ثالثاً. ⚠️ **كلُّ توقيعٍ يُسألُ عن قائمةٍ جماعيٌّ**؛ وموردُ العرضِ يعملُ مرّةً لكلِّ صفٍّ فقراءةٌ مفردةٌ داخلَه N+1 بالبناء.
- [ ] T052 [US3] تنفيذُ `backend/app/Modules/Learning/Support/EloquentCohortDirectory.php` وربطُه في `LearningServiceProvider`.
- [ ] T053 [US3] `JoinCohort` في `backend/app/Modules/Learning/Actions/JoinCohort.php` — ⚠️ اقتناصُ المقعدِ بـ**UPDATE شرطيّةٍ ذرّيّةٍ واحدة** (`WHERE capacity IS NULL OR members_count < capacity`)، صفرُ صفوفٍ = مكتملة. **لا `count()` ثمّ `insert()`** ولا `lockForUpdate()` (بلا أثرٍ على SQLite، فاختبارٌ محلّيٌّ يمرُّ ولا يُثبِتُ شيئاً عن MySQL).
- [ ] T054 [US3] `RequestTransfer` في `…/Actions/RequestTransfer.php` — ⚠️ **لا يمسُّ العضويّةَ القائمةَ بشيء** (FR-028و)، ويرفضُ `same_cohort` عندَ التقديمِ لا عندَ الموافقة.
- [ ] T055 [US3] `DecideTransferRequest` في `…/Actions/DecideTransferRequest.php` — ⚠️ **الاقتناصُ يقعُ هنا**، فالسعةُ مقيسةٌ لحظةَ الموافقة (FR-028ز)؛ والرفضُ يشترطُ `reason` ولا يمسُّ العضويّةَ القائمة.
- [ ] T056 [P] [US3] `MoveMember` و`RemoveMember` في `…/Actions/` — مباشرتان بلا طلبٍ (FR-028ط)، وتُسقِطان أيَّ طلبٍ معلَّقٍ بسببٍ مكتوب.
- [ ] T057 [P] [US3] `CreateCohort` و`ArchiveCohort` و`UpdateCohort` في `…/Actions/` — ⚠️ الإنشاءُ لنوعِ `group` وحدَه (FR-037)، و**لا حذفَ**: الأرشفةُ هي البديلُ ولا `SoftDeletes` (الحذفُ الناعمُ يضعُ الصفَّ خلفَ نطاقٍ عامّ، وهو بالضبط حيثُ لا تراهُ شاشةُ المدرّسِ ولا التدقيق — سابقةُ `hidden_at`).
- [ ] T058 [US3] `ReleaseSeatsOnTransfer` — الإفراجُ يمرُّ بدلالةِ `CancelBooking` **لا بحذفٍ خام**، وعلى الحصصِ التي **لم تبدأْ** فقط. ⚠️ حذفُ صفِّ حجزٍ يكسرُ ثابتَ `ReconcileCreditBalancesJob` («صفُّ استهلاكٍ لكلِّ مقعدٍ في حصّةٍ مشحونة») بلا سببٍ يجدُه أحد. و`billable_seats` تُكتَبُ مرّةً ولا تُعادُ حسابُها — فانتقالٌ بعدَ موعدِ الإلغاءِ لا يحرّكُ ريالاً **ويجبُ ألّا يحاول**.

### السياساتُ والمسارات

- [ ] T059 [P] [US3] `CohortPolicy` و`CohortTransferRequestPolicy` في `backend/app/Modules/Learning/Policies/` على `COURSES_UPDATE`. ⚠️ سجِّلْهما في `Gate::policy()` صراحةً: مُخمِّنُ Laravel يفشلُ **مفتوحاً** حين تخدمُ سياسةٌ واحدةٌ نموذجين، وهو الشكلُ الذي يقعُ فيه هذا المستودعُ باستمرار.
- [ ] T060 [US3] مساراتُ الطالبِ (`contracts §ب/§ج`) في `backend/app/Modules/Learning/routes/api.php` خلفَ `throttle:cohort-write` للكتابات.
- [ ] T061 [US3] مساراتُ المدرّسِ (`contracts §د`) — ⚠️ **بلا `DELETE` للمجموعة**.
- [ ] T062 [US3] `CohortResource` و`CohortMembershipEventResource` و`TransferRequestResource` في `backend/app/Modules/Learning/Http/Resources/`. ⚠️ `seats_left` **`null`** بلا سعةٍ معلَنة، لا صفر.

### بوّابةُ العضويّةِ وصمّامُها

- [ ] T063 [US3] أضِفْ سببَ `no_cohort` إلى `backend/app/Modules/Learning/Support/LessonAccess.php` وفرعَه في `LessonGate` — **يُسألُ بعدَ `isActive()` وقبلَ فرعِ التسجيل**.
- [ ] T064 [US3] ⚠️ **الصمّام**: الفرعُ يسألُ `joinableCohortsExist()`، وحين تكونُ الإجابةُ `false` **لا يقفلُ شيئاً**. شرطٌ لا يوجدُ فعلٌ من أفعالِ الطالبِ يُحقِّقُه هو قفلٌ دائمٌ على محتوًى مدفوع — وهي عائلةُ أسوأِ عيبٍ يسجّلُه هذا المستودع.
- [ ] T065 [US3] أضِفْ كتلةَ `cohort_gate` إلى `CurriculumResource` بحقولِ `required` · `satisfied` · `joinable_exists` · `message`.

### حجبُ Q3 والإسنادُ الجماعيّ

- [ ] T066 [US3] احجبِ الحصّةَ غيرَ المُسنَدةِ من الاكتشافِ ورشِّحْ بالمجموعةِ في `backend/app/Modules/LiveSessions/Http/Controllers/ClassSessionController.php` (`index`) — واحذفِ التعليقَ القائمَ «**«المجموعة» IS THE COURSE**» فقد بطل.
- [ ] T067 [US3] ⚠️ **اترُكْ `backend/app/Modules/LiveSessions/Actions/GetStudentSchedule.php` بلا لمسةٍ واحدة**، وأضِفْ فوقَه تعليقاً يقولُ لماذا: «حصصي» مبنيّةٌ على حجوزاتِ الطالب، وهي ما يجعلُ FR-025د متحقّقةً بالبناءِ — مقعدٌ محجوزٌ يبقى ظاهراً لصاحبِه سواءٌ أُسنِدَتِ الحصّةُ أم لا.
- [ ] T068 [US3] مسارا `GET /manage/courses/{course}/unassigned-sessions` و`POST …/assign-sessions` — ⚠️ **إجراءٌ واحدٌ لا حلقةٌ عندَ العميل**: أربعون طلباً هي أربعون فرصةً لأن يفشلَ واحدٌ في المنتصفِ فيبقى نصفُ الجدولِ محجوباً بلا ما يقولُ أيُّ نصف (قاعدةُ ٠١٨ لإجراءِ المضيفِ الجماعيّ).
- [ ] T069 [US3] امنعْ إسنادَ حصّةٍ **بدأت أو انتهت** بحيثُ يفقدُ أحدٌ حضوراً أو مقعداً (FR-025و) داخلَ `backend/app/Modules/Learning/Actions/AssignSessionsToCohort.php` — الإسنادُ بعدَ الوقوعِ يُصنِّفُ الحصّةَ ولا يُعيدُ توزيعَ حقوقِها.

### إصلاحُ «الحصّةِ السابقة» — Q2

- [ ] T070 [US3] ⚠️ أضِفْ `cohort_id` إلى الجلبِ والمطابقةِ في `backend/app/Modules/LiveSessions/Support/EloquentSessionAttendanceDirectory.php` عندَ `previousCountableSessionIds()` (`:193–240`، والمطابقةُ عند `:236–238`). الحصّةُ بلا مجموعةٍ تُطابِقُ الحصّةَ بلا مجموعةٍ فقط. ⚠️ **لا شيءَ فوقَه يتغيّر**: `UnlockResolver` تسألُ العقدَ ولا تحسبُ جدولاً، و`EloquentUnlockDirectory` «تُنسّقُ حكمَ المُحلِّلِ ولا تحسبُ شيئاً من عندِها» — فالإصلاحُ هنا يصلحُ الشاشةَ والبابَ معاً.

### اختباراتُ US3

- [ ] T071 [P] [US3] ⚠️ `backend/tests/Feature/Learning/CohortConcurrencyTest.php`: انضمامان متزامنان ⇒ **عضويّةٌ مفتوحةٌ واحدة** (SC-008)، وطلبان معلَّقان على **مقعدٍ واحد** ⇒ يدخلُ واحدٌ ويُرفَضُ الآخَرُ بـ`cohort_full` (SC-008أ). ⚠️ **اختبارٌ متسلسلٌ يمرُّ على بناءٍ خالٍ من الاقتناصِ تماماً** — الاستدعاءُ الثاني يعودُ من فرعٍ أعلى؛ التدخّلُ يقعُ **بين** القراءةِ والاقتناصِ بنداءٍ راجعٍ داخلَ تلك النافذة، بلا خيوطٍ ولا `sleep` (نمطُ `OpenBroadcastRoom`).
- [ ] T072 [P] [US3] `backend/tests/Feature/Learning/CohortTransferPreservesEverythingTest.php` (SC-006): `progress_pct` والمكتملُ والدرجاتُ والشهاداتُ ودفترُ الأخطاءِ والرصيدُ — **متطابقةٌ قبلَ وبعد**.
- [ ] T073 [P] [US3] `backend/tests/Feature/Learning/CohortPendingRequestTest.php` (SC-008ب): أثناءَ التعليق، جدولُ الطالبِ وحجزُه كما هما بلا فرقٍ واحد.
- [ ] T074 [P] [US3] ⚠️ `backend/tests/Feature/Learning/CohortGateSafetyValveTest.php` (SC-009أ): كلُّ المجموعاتِ مغلقةٌ أو مكتملة ⇒ **كلُّ الدروسِ المتاحةِ تُفتَح**، وصفرُ أقفالِ `no_cohort`. **احذفِ الصمّامَ وتأكَّدْ أنّ الاختبارَ يسقط**، وإلّا فهو يقيسُ شرطاً آخَر.
- [ ] T075 [P] [US3] `backend/tests/Feature/Learning/CohortEventLogTest.php` (SC-007): صفٌّ واحدٌ لكلِّ تغييرٍ ومعه الوقتُ والمنفِّذُ والسبب، و**الرفضُ مُقيَّدٌ كالقَبول**، ومحاولةُ تعديلِ صفٍّ أو حذفِه **تُرفَض**.
- [ ] T076 [P] [US3] ⚠️ `backend/tests/Feature/LiveSessions/CohortPreviousSessionTest.php` (SC-011أ): مجموعتان بمواعيدَ متباعدة — طالبُ الأحدِ يحجزُ ولا يُرفَضُ بسببِ حصّةِ السبت. ⚠️ ويُزيَّفُ **الخطُّ الزمنيُّ وحدَه** عبرَ `fakeSessionTimeline()`؛ `Queue::fake()` عارياً يبتلعُ مستمعَ الشحنِ فتصيرُ نصفُ التأكيداتِ ادّعاءً عن جدولٍ فارغ.
- [ ] T077 [P] [US3] ⚠️ `backend/tests/Feature/LiveSessions/UnassignedSessionVisibilityTest.php` (SC-011ب): الحصّةُ غيرُ المُسنَدةِ تختفي من الاكتشاف، **ويبقى صاحبُ المقعدِ يراها في «حصصي» ويفتحُ تسجيلَها**؛ والمدرّسُ يرى العددَ الصحيحَ ويُسنِدُ في إجراءٍ واحد.
- [ ] T078 [P] [US3] `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — أضِفْ حالةً للكياناتِ الأربعةِ الجديدة (المبدأُ الأوّل، غيرُ قابلٍ للتفاوض).
- [ ] T079 [P] [US3] ⚠️ `backend/tests/Feature/Learning/CohortPlatformReadTest.php`: أيُّ قراءةٍ أو كتابةٍ بصلاحيّةٍ عابرةٍ تُختبَرُ **بمساحتَي عملٍ لا واحدة** — `WorkspaceContext::id()` يرتدُّ إلى `last_workspace_id` لكلِّ مستخدم، فتجهيزٌ بمساحةٍ واحدةٍ يمرُّ ولا يُثبِتُ شيئاً (درسُ `ExecuteTeacherOffboarding`).

### واجهةُ US3

- [ ] T080 [P] [US3] `frontend/src/components/courses/CohortPicker.tsx` — ⚠️ يعرضُ لكلِّ مجموعةٍ **مواعيدَها ومقاعدَها المتبقّية**: الاختيارُ بين أسماءٍ مجرّدةٍ ليس اختياراً (FR-028أ).
- [ ] T081 [P] [US3] `frontend/src/components/courses/CohortSwitcher.tsx` — طلبُ الانتقالِ وحالةُ التعليقِ وسببُ الرفضِ المقروء.
- [ ] T082 [US3] اربطِ البوّابةَ في `frontend/src/app/(app)/(shell)/enrollments/[course]/page.tsx`: `cohort_gate.required && !satisfied && joinable_exists` ⇒ شاشةُ اختيارٍ قبلَ أيِّ محتوى؛ وإن كانت `joinable_exists` **`false`** ⇒ المنهجُ كاملاً وجملةٌ تقولُ لماذا.
- [ ] T083 [P] [US3] اختبارُ `frontend/src/components/courses/CohortPicker.test.tsx`: المجموعةُ المكتملةُ غيرُ قابلةٍ للاختيار · «اكتملت» تُعرَضُ بعدَ رفضٍ متزامنٍ ويُعادُ عرضُ الباقي.
- [ ] T084 [P] [US3] شاشةُ المدرّسِ `frontend/src/app/(app)/(shell)/manage/courses/[course]/cohorts/page.tsx`: المجموعاتُ والأعضاءُ والطابورُ والسجلُّ وقائمةُ الحصصِ المحجوبةِ بعددِها وزرِّ إسنادٍ واحد.
- [ ] T085 [US3] ⚠️ اربطْ شاشةَ المدرّسِ من صفحةِ إدارةِ الكورس. **سطحٌ لا يصلُه رابطٌ سطحٌ غيرُ مُسلَّم** — ولا يراهُ `tsc` ولا `npm test`.

**Checkpoint**: المجموعاتُ تعمل. **كورسٌ بلا مجموعاتٍ يجتازُ US1 وUS2 كاملتين بلا تعديلِ صفٍّ واحد** (SC-009).

---

## Phase 6: User Story 4 — شاتُ المجموعةِ ومَن يُديرُه (P4)

**Goal**: خيطٌ لكلِّ مجموعةٍ يصلُه العضوُ تلقائيّاً، وكتمٌ ومنعٌ مؤقّتٌ ومفتوح.

**Independent Test**: مدرّسٌ يمنعُ طالباً عشرَ دقائق — يقرأُ ولا يكتب، ويكتبُ في خيطِه الخاصّ، ويعودُ بعدَ المدّةِ بلا تدخّل.

- [ ] T086 [US4] أضِفْ `Cohort = 'cohort'` إلى `backend/app/Modules/Community/Enums/ConversationKind.php` — و`isPublic()` تظلُّ «ليس `Private`» فتشملُه بلا تعديل.
- [ ] T087 [US4] هجرةُ `conversations`: `+ cohort_id` قابلٌ للعدمِ و`unique(cohort_id)` — ⚠️ الحلُّ الكسولُ يجعلُ السباقَ ممكناً، فالفهرسُ هو الحارسُ لا الفعل.
- [ ] T088 [US4] فرعُ المجموعةِ في `publicRoom()` بـ`backend/app/Modules/Community/Policies/ConversationPolicy.php` (بعدَ فرعَي الحصّةِ `:227` والدرسِ `:233`). ⚠️ **القراءةُ لمن كان عضواً يوماً (`wasEverMember`) والكتابةُ لمن عضويّتُه مفتوحةٌ الآن** — سؤالان مختلفان في `view()` و`post()`، وهو المعنى الوحيدُ الذي لا يُشتَقُّ من صفٍّ واحد (FR-046).
- [ ] T089 [US4] ⚠️ **لا صفوفَ `conversation_participants` تُنشَرُ عندَ الانضمام**: الاستحقاقُ مُشتَقٌّ من العضويّة، والجدولُ لتتبّعِ القراءةِ وحدَه — كما في غرفِ الحصص. أضِفِ الحلَّ الكسولَ في `backend/app/Modules/Community/Actions/ResolveCohortConversation.php` على نمطِ `ResolveSessionConversation`.
- [ ] T090 [US4] وسِّعْ `SetConversationLock` في `backend/app/Modules/Community/Actions/SetConversationLock.php` للنوعِ الجديدِ — ⚠️ **توسيعٌ لا إعادةُ كتابة**، وإعفاءُ `CHAT_MODERATE` من القفلِ يبقى (FR-041): مدرّسٌ مقفولٌ خارجَ نقاشٍ أغلقَه للتوِّ لا يستطيعُ أن يقولَ لماذا أغلقَه.
- [ ] T091 [US4] هجرةُ `conversation_write_bans` (data-model §٥) في `backend/app/Modules/Community/Database/Migrations/`.
- [ ] T092 [P] [US4] نموذجُ `backend/app/Modules/Community/Models/ConversationWriteBan.php` ومصنعُه.
- [ ] T093 [US4] ⚠️ `backend/app/Modules/Community/Support/WriteBanReader.php` — الانتهاءُ يُحكَمُ **في PHP** (`expires_at === null || isFuture()`) وأحدثُ صفٍّ يفوز، وقراءةٌ **جماعيّةٌ** لشاشةِ القائمة. المقارنةُ العكسيّةُ (`expires_at > now()` وحدَها) تقرأُ **المنعَ الدائمَ منتهياً** — وهو المنعُ الوحيدُ الذي لا يجوزُ أن ينتهيَ وحدَه. و`DATE_ADD` مقابلَ `datetime()` لهجتان لسؤالٍ واحد.
- [ ] T094 [US4] فرعُ المنعِ في `ConversationPolicy::post()` بجملتَي «حتى {وقت}» و«{السبب}» — ⚠️ **بعدَ** فرعِ الحظرِ على مستوى المساحةِ وفرعِ انتهاءِ نشاطِ المدرّس، فكلاهما يعملُ بلا سطرٍ واحدٍ ويُحقِّقُ FR-047 مجّاناً.
- [ ] T095 [US4] مسارا `POST`/`DELETE` لمنعِ الكتابةِ في `backend/app/Modules/Community/routes/api.php` خلفَ `throttle:chat-write`، وفعلاهما في `…/Actions/`.
- [ ] T096 [US4] أضِفْ `SCOPE_COHORT` إلى `backend/app/Modules/Community/Models/Announcement.php` وذراعاً رابعةً في `backend/app/Modules/Community/Support/AnnouncementAudience.php` تسألُ `CohortDirectory->activeMemberIdsFor()`. ⚠️ **احذفْ تعليقَ ت-٣** («Groups are out of scope») فقد بطل — بشرطِه نفسِه: نموذجُ عضويّةٍ وشاشةٌ وصلاحيّة، لا قيمةُ تعدادٍ مُهرَّبة. والذراعُ `default => []` تبقى: نطاقٌ غيرُ مفهومٍ يبلغُ لا أحدَ لا الجميع.
- [ ] T097 [US4] أضِفِ الخيارَ إلى `backend/app/Modules/Community/Http/Requests/SaveAnnouncementRequest.php` وإلى شاشةِ نشرِ التنبيه.
- [ ] T098 [US4] بثُّ الرسالةِ على قناةِ الخيطِ الجديد — ⚠️ **الحمولةُ معرّفانِ لا جسمُ الرسالة**: القناةُ تُصرَّحُ مرّةً عندَ الاشتراكِ ولا يملكُ البروتوكولُ سحبَ التصريح، فوضعُ الجسمِ في الإطارِ يجعلُ سحبَ العضويّةِ ساريَ المفعولِ عندَ إغلاقِ التبويبةِ لا قبل. ⚠️ **ولا يُلمَسُ `/api/broadcasting/auth`**: قائمةُ وسائطَ تُمرَّرُ لـ`withBroadcasting()` تستبدلُ مجموعةَ `api` فتُسقِطُ `EnsureCurrentWorkspace`، وبلا مُعرِّفِ فريقٍ لا أدوارَ إطلاقاً.
- [ ] T099 [P] [US4] `frontend/src/components/courses/tabs/ChatTab.tsx` — يُعادُ استعمالُ مكوّناتِ الرسائلِ القائمةِ في `components/community/`.
- [ ] T100 [P] [US4] أدواتُ الإشرافِ في `frontend/src/components/community/` — ⚠️ الكتمُ والمنعُ خلفَ `ConfirmButton` (تسليحٌ بضغطتين ومؤقّتُ نزعٍ)، فهما ضابطان يجاوران بعضَهما على هاتفٍ عندَ هدفِ ٤٠ بكسل.
- [ ] T101 [P] [US4] ⚠️ `backend/tests/Feature/Community/CohortChatBanTest.php` (SC-010): يقرأُ · لا يكتبُ · يرى السببَ والوقتَ · **يكتبُ في خيطِه الخاصّ** · يفتحُ دروسَه وحصّتَه المحجوزة · ثمّ يكتبُ بعدَ المدّةِ بلا تدخّل. والمنعُ **لا يُلاحِقُه** إلى مجموعتِه الجديدةِ بعدَ الانتقال.
- [ ] T102 [P] [US4] ⚠️ `backend/tests/Feature/Community/CohortChatIsolationTest.php` (SC-011): طالبُ مجموعةٍ أخرى مرفوضٌ في القائمةِ **وفي الطلبِ المباشرِ بالمعرّف**. ⚠️ ونصُّ التسريبِ يُقرَأُ بـ`JSON_UNESCAPED_UNICODE` أو بشواهدِ ASCII: `getContent()` يهربُ ما ليس ASCII، فإبرةٌ عربيّةٌ صادقةٌ فراغاً مهما حملت الحمولة.
- [ ] T103 [P] [US4] `backend/tests/Feature/Community/CohortAnnouncementScopeTest.php`: تنبيهُ المادّةِ يبلغُ كلَّ المجموعات؛ تنبيهُ المجموعةِ يبلغُ أعضاءَها وحدَهم؛ ومَن انتقلَ لا تبلغُه بعدَ انتقالِه ويبقى ما بلغَه مقروءاً.
- [ ] T104 [P] [US4] `backend/tests/Feature/Community/CohortChatPersistenceTest.php` (SC-015): مع تعطيلِ البثِّ بالكاملِ **تُقرَأُ الرسالةُ عندَ أوّلِ تحديث** — الحفظُ هو المرجع.
- [ ] T105 [P] [US4] اختبارُ `frontend/src/components/community/…test.tsx` لأدواتِ الإشراف. ⚠️ يستعملُ `fireEvent` لا `userEvent`: الأخيرُ ينتظرُ مؤقّتاتٍ حقيقيّةً بين خطواتِه، فتحتَ `useFakeTimers` يتعلّقُ على ساعةٍ لا يُحرِّكُها شيءٌ **وينتهي بمهلةٍ بدلَ أن يفشل**.

**Checkpoint**: الشاتُ يعملُ بأدواتِه الثلاث.

---

## Phase 7: User Story 5 — زملاءُ المجموعةِ بأسمائهم ونياشينهم (P5)

**Goal**: القائمةُ بالصورةِ والاسمِ والمستوى والنياشينِ والمرتبة — ولا شيءَ عن الحضورِ أو الدرجات.

**Independent Test**: الطالبةُ ترى زملاءَها ولا ترى درجةً واحدةً، وعضوٌ جديدٌ بلا مرتبةٍ يظهرُ **بلا رقم**.

- [ ] T106 [US5] `backend/app/Modules/Learning/Actions/ReadCohortRoster.php` — ⚠️ **بشكلِ `LiveSessions/Actions/ReadSessionRoster.php` القائمِ حرفاً**: `ReadBadgesFor` جماعيّاً، و`avatar_url` من `student_profiles.avatar_path`. ⚠️ و**`first_name` و`last_name`، لا `name`**: `users` بلا عمودٍ بذلك الاسمِ — جلبٌ مُتلهِّفٌ مقيَّدٌ يُسمّي `name` أرجعَ اسماً فارغاً في **ستّةِ** مواضعَ عبرَ أربعِ وحدات.
- [ ] T107 [US5] أضِفِ المرتبةَ من `backend/app/Modules/Gamification/Actions/ReadRanksFor.php` — ⚠️ **المفتاحُ يُحذَفُ كلّيّاً لمن لا مرتبةَ له** (FR-051): الغيابُ حالةٌ، و«المركز ٠» رقمٌ يُطبَعُ جنبَ اسمِ طالبٍ أمامَ صفِّه. والمستوى تراكميٌّ من جدولٍ آخَرَ وصحيحٌ اليومَ لمن سجّلَ هذا الصباح.
- [ ] T108 [US5] `CohortRosterResource` والمسارُ `GET /cohorts/{cohort}/roster`. ⚠️ **صفرُ حقولِ حضورٍ أو مدّةِ بقاءٍ أو درجةٍ أو ملاحظةِ مدرّس** — تلك أسئلةُ `ATTENDANCE_VIEW`، وهذا المسارُ يفتحُ لكلِّ عضو.
- [ ] T109 [P] [US5] `frontend/src/components/courses/tabs/RosterTab.tsx`.
- [ ] T110 [P] [US5] ⚠️ `backend/tests/Feature/Learning/CohortRosterExposureTest.php` (SC-012): يمشي **كلَّ حقلٍ** في الحمولةِ ضدَّ قائمةِ سماحٍ — لا حضورَ ولا درجةَ ولا ملاحظة؛ ومَن أُغلِقَتْ عضويّتُه **لا يظهر**.
- [ ] T111 [P] [US5] ⚠️ `backend/tests/Feature/Learning/CohortRosterBudgetTest.php` (SC-013): تجهيزان — **٥ ⇄ ٥٠** عضواً — والعددُ هو هو، **مع تأكيدِ حضورِ حقلِ الاسمِ والنياشينِ** لا الكلفةِ وحدَها.
- [ ] T112 [P] [US5] اختبارُ `frontend/src/components/courses/tabs/RosterTab.test.tsx`: عضوٌ بلا مرتبةٍ **لا يعرضُ رقماً**.

---

## Phase 8: Polish & Cross-Cutting

- [ ] T113 [P] بذرةٌ **إضافيّةٌ فقط** `backend/database/seeders/CohortDemoSeeder.php` — بمفاتيحَ ثابتةٍ و`firstOrCreate`، **غيرُ مسجَّلةٍ في `DatabaseSeeder`** (سابقةُ `StudentDashboardSeeder`). تزرعُ ما تصفُه `quickstart.md`: مجموعتين بمواعيدَ متباعدةٍ · ثالثةً مكتملةً · حصّةً لكلٍّ · ⚠️ **حصّتين غيرَ مُسنَدتين إحداهما لطالبِنا فيها مقعدٌ محجوز** — بغيرِ ذلك الصفِّ لا يستطيعُ أحدٌ رؤيةَ ما إذا كان حجبُ Q3 يبتلعُ مقعداً مدفوعاً.
- [ ] T114 [P] مراجعةُ الوصولِ على الصفحةِ كلِّها — تبويباتٌ بلوحةِ المفاتيح، وحالاتٌ مقروءةٌ لقارئِ الشاشة، وحركةٌ تُلغى بـ`prefers-reduced-motion` (NFR-010 · SC-015).
- [ ] T115 [P] تحقّقْ من رموزِ السمة: **كلُّ صنفِ لونٍ يُسمّي رمزاً موجوداً في `@theme`**. ⚠️ Tailwind v4 لا يُصدِرُ قاعدةً لرمزٍ غيرِ معرَّف، فيُطلى **لا شيء** بصمت — و`bg-success` **لا وجودَ له**؛ `success` اسمُ نبرةٍ في `TONE_CLASSES` لا رمزُ لونٍ في `@theme`. شحنَ هذا العيبُ ثلاثَ مرّاتٍ ومرّةً باختبارٍ أخضرَ فوقَ علامةٍ غيرِ مطليّة.
- [ ] T116 [P] حدِّثْ جداولَ الوحداتِ والمساراتِ والصلاحيّاتِ في `docs/README.md` و`docs/erd.md` بالكياناتِ الخمسةِ والمساراتِ الجديدة.
- [ ] T117 [P] أضِفْ إلى `CLAUDE.md` مصائدَ هذه المرحلةِ الثلاثَ: صمّامُ بوّابةِ العضويّة · «السابقة» في مجموعةِ الطالب · حجبُ Q3 لا يمسُّ مقعداً مدفوعاً.
- [ ] T118 امشِ فحوصَ `quickstart.md` الثمانيةَ يدويّاً في المتصفّح. ⚠️ **الفحصُ ٢(ب) هو الفحصُ كلُّه**: بناءٌ ينجحُ في (أ) ويفشلُ في (ب) يحبسُ طالباً دفعَ ثمنَ مادّةٍ خلفَ شرطٍ لا فعلَ يُحقِّقُه.
- [ ] T119 ⚠️ **اضغطْ كلَّ صفٍّ مقفولٍ في الشجرة** على `http://127.0.0.1:3000/enrollments/{course}` (SC-002)، وتحقّقْ من `frontend/src/components/courses/LessonRow.tsx` أنّ المقفولَ ليس `<Link>` ولا `<button>`. فشلٌ يُقرَأُ نجاحاً: صفٌّ يبدو مقفولاً ويُبحِرُ عندَ الضغط.
- [ ] T120 شغِّلِ البوّاباتِ الخضراءَ كلَّها: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` (بلا خطِّ أساسٍ جديدٍ وبلا `@phpstan-ignore`) · `npx tsc --noEmit` · `npm test`.
- [ ] T121 `php artisan migrate` على قاعدةِ البياناتِ المحلّيّة. ⚠️ **لا `migrate:fresh` بلا إذنٍ صريحٍ من المالك.**

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (P1)**: بلا اعتماد — تبدأُ فوراً
- **Foundational (P2)**: بعدَ Setup — **تحجبُ كلَّ القصص**
- **US1 (P3)**: بعدَ Foundational
- **US2 (P4)**: بعدَ US1 (التبويبةُ الأولى هي المنهجُ نفسُه)
- **US3 (P5)**: بعدَ US1 (البوّابةُ تُركَّبُ في `LessonGate`)
- **US4 (P6)**: بعدَ US3 (لا خيطَ بلا مجموعة)
- **US5 (P7)**: بعدَ US3
- **Polish (P8)**: بعدَ ما يُنوى شحنُه

### الاستقلالُ الحقيقيّ

- **US2 وUS3 متوازيتانِ تماماً** بعدَ US1 — لا ملفَّ مشتركاً بينهما إلّا صفحةُ المادّة، وT039 وT082 تلمسانِها في موضعين مختلفين.
- **US4 وUS5 متوازيتانِ** بعدَ US3.
- ⚠️ **US1 وUS2 لا تعتمدانِ على المجموعاتِ إطلاقاً**، وهذا هو مقياسُ SC-009: كورسٌ بلا مجموعاتٍ يجتازُهما كاملتين.

### Parallel Opportunities

- T002 · T003 معاً في Setup
- T041–T045 (الهجرات) بالتتابع، ثمّ **T046–T050 معاً**
- **T028 · T029 · T030 معاً** — ثلاثُ وحداتٍ وثلاثةُ ملفّات
- **T033–T038 معاً** — ستُّ تبويباتٍ في ستّةِ ملفّات
- كلُّ اختباراتِ قصّةٍ المعلَّمةِ `[P]` معاً

---

## Parallel Example: User Story 3

```bash
# النماذجُ الأربعةُ بعدَ اكتمالِ الهجرات:
Task: "نموذجُ Cohort في backend/app/Modules/Learning/Models/Cohort.php"
Task: "نموذجُ CohortMembership في …/CohortMembership.php"
Task: "نموذجُ CohortMembershipEvent في …/CohortMembershipEvent.php"
Task: "نموذجُ CohortTransferRequest في …/CohortTransferRequest.php"

# اختباراتُ US3 معاً:
Task: "CohortConcurrencyTest" · "CohortTransferPreservesEverythingTest"
Task: "CohortGateSafetyValveTest" · "CohortEventLogTest" · "CohortPreviousSessionTest"
```

---

## Implementation Strategy

### MVP — US1 وحدَها

1. Phase 1 (٣ مهامّ) → Phase 2 (٨ مهامّ) → Phase 3 (١٤ مهمّة)
2. **قِفْ وتحقّقْ**: اضغطْ كلَّ صفٍّ مقفول
3. **يُشحَنُ هنا.** لا هجرةَ ولا كيانَ جديدٍ ولا خطرَ على بياناتٍ قائمة — ويُصلِحُ العيبَ الوحيدَ القائمَ فعلاً اليوم: الطالبُ يكتشفُ المنعَ بعدَ الضغط.

### التسليمُ التدريجيّ

US1 (يُشحَن) → US2 (يُشحَن) → US3 (⚠️ أوّلُ هجرةٍ · أوّلُ خطرٍ على بياناتٍ قائمة) → US4 → US5

⚠️ **الحدُّ الفاصلُ عندَ US3**: كلُّ ما قبلَه قراءاتٌ ومكوّنات؛ US3 تحجبُ صفوفاً حيّةً وتُوسِّعُ فهرساً ساخناً. تُشحَنُ وحدَها ويُمشى فحصا quickstart §٤ و§٦ عليها قبلَ أيِّ شيءٍ بعدَها.

---

## Notes

- `[P]` = ملفّاتٌ مختلفةٌ بلا اعتماد
- كلُّ قصّةٍ تُشحَنُ وتُختبَرُ وحدَها
- ⚠️ **T004 و T007 ليستا احتفاليّتين**: خطُّ الأساسِ الأخضرُ قبلَ الاستخراجِ وبعدَه هو الدليلُ الوحيدُ على أنّ أخطرَ مهمّةٍ في المرحلةِ لم تُغيِّرْ معنًى. **تعديلُ ملفِّ اختبارٍ قائمٍ لإرضاءِ T005–T006 يعني أنّ الاستخراجَ فشل.**
- ⚠️ **أربعُ مصائدَ تجعلُ الاختبارَ أخضرَ وهو يُثبِتُ العكس**، مفصَّلةٌ في `quickstart.md`: الطالبُ بلا `last_workspace_id` · `Queue::fake()` عارياً · مساحةُ عملٍ واحدةٍ لقراءةِ صلاحيّةٍ عابرة · `getContent()` يهربُ ما ليس ASCII.
- ⚠️ **ميزانُ الاستعلاماتِ يؤكِّدُ حضورَ الحقلِ كما يؤكِّدُ ثباتَ الكلفة**: إسقاطُ جلبٍ مُتلهِّفٍ مع `whenLoaded` يجعلُ الصفحةَ أرخصَ ويُقرَأُ تراجعُه تحسّناً — ثمّ تُعرَضُ القائمةُ بلا اسمِ أحد.
- ⚠️ **لا `migrate:fresh`** بلا إذنٍ صريح، **ولا سرَّ ولا مفتاحَ في المستودع**.
