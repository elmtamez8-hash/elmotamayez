---
description: "Task list for 016-course-authoring"
---

# Tasks: سطح تأليف الكورسات

**Input**: `specs/016-course-authoring/` — [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md)

**Tests**: **مطلوبة**. الدستور IV يجعل اختبارات الميزة شبكة الأمان الأساسية، والمواصفة تربط كل
`SC-` من العشرين باختبار مسمّى. وهذه المرحلة تعدّل **اثنين من المسارات الحرجة الثمانية** (تقييد
الدروس · إتمام الكورس) وتعيد ترقيم بيانات قائمة — فلا مهمة تنفيذ بلا مهمة اختبار تسبقها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: قابلة للتوازي — ملفات مختلفة، بلا اعتماد على مهمة غير مكتملة
- **[Story]**: `US1`…`US6` لمهام مراحل قصص المستخدم فقط

## Path Conventions

`backend/` أحادية معيارية · `frontend/` Next.js. كل المسارات من جذر المستودع.
**لا وحدة جديدة**: توسعة `Modules/Courses` و`Modules/Media` و`Modules/Learning` القائمة.

## انحراف واحد مقصود عن ترتيب أولويات المواصفة

المواصفة تجعل «المسودّة والنشر» **US3**. وآلتها (عمود `status` + قراءتها في المقام والتسلسل)
تنزل هنا في **Phase 2** لا في Phase 5، لأن المواصفة نفسها تصفها بأنها «شرط لإمكان التأليف لا
ميزة»: بدونها كل عنصر يُنشئه US1 يظهر لطلاب كورس حيّ نصفَ مكتوب. وما يبقى لـUS3 هو **تجربة**
المسودّة: النشر بالدفعة، وسريان السلسلة، والتحذيرات، وضمانات «صفر أثر» — وكلها ما تزال قابلة
للاختبار مستقلةً.

---

## Phase 1: Setup (تعدادات وإعدادات ومحدّد — بلا منطق)

**Purpose**: المفردات المشتركة التي تقرؤها كل مرحلة بعدها.

- [X] T001 [P] وسّع `backend/app/Modules/Courses/Enums/LessonType.php` من أربع قيم إلى عشر: `Video` · `Audio` · `Pdf` · `File` · `Article` · `Note` · `Link` · `Exam` · `Assignment` · `LiveSession` — بدالة `label()` عربية لكل قيمة
- [X] T002 [P] أنشئ `backend/app/Modules/Courses/Enums/ContentStatus.php` (`draft` · `published` · `archived`) بدالة `label()` عربية ودالة `isVisibleToStudents()`
- [X] T003 [P] أنشئ `backend/app/Modules/Media/Enums/MediaKind.php` (`video` · `audio` · `document`) و`MediaRole.php` (`primary` · `attachment`)
- [X] T004 أنشئ `backend/app/Modules/Courses/Support/LessonTypeRegistry.php`: لكل نوع **العائلة** و**قابلية الإتمام** و**الحقول المطلوبة للنشر** و**صنف الأصل المتوقَّع** — المصدر الوحيد لهذه الحقائق ([data-model.md](./data-model.md) § أنواع العناصر). **يُمنع** تكرار أيٍّ منها في FormRequest أو مكوّن واجهة
- [X] T005 [P] أنشئ `backend/app/Modules/Courses/Support/MarkdownRenderer.php` يلفّ `league/commonmark` بـ`html_input: 'strip'` و`allow_unsafe_links: false` (research §R7) — **يُمنع** إضافة أي مُنقّي HTML إلى `composer.json`
- [X] T006 [P] أضف المحدّد المسمّى `authoring` (٦٠/دقيقة بالمستخدم) في `AppServiceProvider::registerRateLimiters()` بـ `backend/app/Providers/AppServiceProvider.php` — **يُمنع** أي `throttle:N,M` سطري
- [X] T007 [P] وسّع `backend/config/media.php`: `allowed_mime_types` المسطّحة تصير خريطة بمفتاح `kind` (`video` · `audio` · `document`)، وحدود الحجم والمدة لكل صنف
- [X] T008 [P] أضف مفاتيح `media.max_document_size_bytes` و`media.max_audio_duration_seconds` إلى `PlatformSettings::KEYS` بـ `backend/app/Modules/Tenancy/Support/PlatformSettings.php` وصفوفها إلى `backend/database/seeders/PlatformSettingsSeeder.php` — الأرقام التشغيلية صفوف يضبطها المشغّل لا ثوابت في الكود (`FR-033`)

**Checkpoint**: التعدادات موجودة · السجلّ هو المصدر الوحيد لخصائص الأنواع · المحدّد مسمّى · الحدود صفوف.

---

## Phase 2: Foundational (متطلبات حاجبة لكل القصص)

**⚠️ حاجبة**: كل مهام Phase 3+ تعتمد على اكتمال هذه المرحلة.

### ٢أ — تصحيح مخالفات قائمة قبل البناء فوقها

> هذه المهام تصحّح كوداً يعمل اليوم. تُشحن **أولاً** لأن سطح التأليف يضاعف كلاً منها.

- [X] T009 أنشئ هجرة `add_uuid_to_course_structure` في `backend/app/Modules/Courses/Database/Migrations/` تضيف `uuid` قابلاً للإفراغ إلى `course_sections` و`course_chapters`، **تملأ القائم**، ثم تجعله `unique` وغير قابل للإفراغ (research §R1)
- [X] T010 أضف `HasUuid` إلى `backend/app/Modules/Courses/Models/Section.php` و`Chapter.php` — `getRouteKeyName()` يصير `uuid`
- [X] T011 حدّث `backend/app/Modules/Courses/routes/api.php`: كل `{section}` و`{chapter}` و`{lesson}` يُحلّ بـuuid؛ وأضف `throttle:authoring` إلى كل مسار كاتب
- [X] T012 استبدل `Rule::exists()` الخام بـ`App\Shared\Support\WorkspaceRules::exists()` في `StoreLessonRequest` · `UpdateLessonRequest` · `StoreChapterRequest` · `UpdateChapterRequest` بـ `backend/app/Modules/Courses/Http/Requests/` — قاعدة Laravel استعلام خام يتجاوز النطاق العام
- [X] T013 اجعل `backend/app/Modules/Courses/Http/Requests/StoreLessonRequest.php` يستقبل `chapter_uuid` وحده ويشتقّ `section_id` منه (`FR-002`)، و**يتحقّق أن الفصل يخصّ القسم والكورس المذكور في المسار** (`FR-059`) — اليوم يمكن إنشاء درس بقسم من فرع وفصل من فرع آخر
- [X] T014 [P] اكتب `backend/tests/Feature/Courses/StructureValidationTest.php`: فصل من كورس آخر ⇒ 404 · فصل لا يخصّ قسمه ⇒ 422 · معرّف تسلسلي في أي حمولة ⇒ فشل الاختبار

### ٢ب — المخطّط

- [X] T015 أنشئ هجرة `add_status_to_course_structure` في `backend/app/Modules/Courses/Database/Migrations/`: عمود `status` على `lessons` و`course_chapters` و`course_sections`؛ ورحّل `course_sections.is_published` (`true → published` · `false → draft`) ثم احذفه؛ **وكل الصفوف القائمة تصل `published`** — الترحيل لا يُخفي محتوى يراه طلاب الآن
- [X] T016 أنشئ هجرة `densify_structure_order` **واحدة** في `backend/app/Modules/Courses/Database/Migrations/` تفعل بالترتيب: (١) ترقيم كثيف لكل مجموعة إخوة بترتيبها الحالي ثم `id` فاصلاً للتعادل، مع إبقاء قسم «تسجيلات الحصص» **آخر** كورسه، ثم (٢) إضافة `unique(course_id, order)` و`unique(section_id, order)` و`unique(chapter_id, order)`. **العكس يفشل على بيانات قائمة**: كل تسجيل حصة يُكتب اليوم بـ`order => 0` ([data-model.md](./data-model.md) § ترتيب الهجرة)
- [X] T017 [P] أنشئ هجرة `add_reference_columns_to_lessons` في `backend/app/Modules/Courses/Database/Migrations/`: `reference_id` (`unsignedBigInteger` قابل للإفراغ) و`external_url` (`string` قابل للإفراغ). **يُمنع** أي مفتاح خارجي إلى `exams` أو `class_sessions` (research §R9)
- [X] T018 [P] أنشئ هجرة `add_structure_version_to_courses` في `backend/app/Modules/Courses/Database/Migrations/`: `structure_version` (`unsignedInteger` افتراضه ١)
- [X] T019 [P] أنشئ هجرة `add_kind_and_role_to_media_assets` في `backend/app/Modules/Media/Database/Migrations/`: `kind` · `role` · `is_downloadable`؛ والصفوف القائمة كلها `kind=video` و`role=primary`
- [X] T020 حدّث النماذج الأربعة في `backend/app/Modules/Courses/Models/` بـ`casts()` للأعمدة الجديدة و`@property` لما يقرأ Larastan نوعه من الهجرة، و`backend/app/Modules/Media/Models/MediaAsset.php` بمثلها
- [X] T021 أضف إلى `backend/app/Modules/Courses/Models/Lesson.php` نطاقين معلَنين: `visibleToStudents()` (الحالة + سلسلة الآباء) و`countableForProgress()` (منشور · قابل للإتمام حسب `LessonTypeRegistry` · **بلا `class_session_id`**) — هذان النطاقان هما المفردة المشتركة مع `Learning` ([contracts/events.md](./contracts/events.md) §٣)
- [X] T022 [P] حدّث `backend/database/factories/Modules/Courses/` بالأعمدة الجديدة؛ **يُمنع** تعريف `newFactory()` على النماذج
- [X] T023 [P] حالات الشجرة في `WorkspaceIsolationTest` — **كانت مفتوحة حتى Polish**: قسم وفصل ودرس تُنشأ بلا `workspace_id` فتُملأ من السياق، وقراءة شجرة كورس مساحة أخرى **404** لا ٢٠٠ بشجرة فارغة. وأثناء كتابتها تبيّن أن `CourseFactory` يثبّت `workspace_id => 1`، فأي تجهيزة تعتمد على السياق وحده تضع الكورسين في مساحة واحدة وتنجح بلا معنى
- [X] T024 [P] `StructureMigrationTest.php` — الوعد الأول (صفر صفّ بلا uuid عبر حدّ الـchunk) كُتب في مراجعة الوكلاء، **والثلاثة الباقية في Polish**: صفر تعادل ترتيب في المستويات الثلاثة · قسم التسجيلات آخر كورسه · كل المحتوى القائم `published`. الحالة الثانية تستدعي `down()` أوّلاً لأن التعادل **غير قابل للإنشاء** والفهارس قائمة — وهي بعينها الجملة التي يكتبها الترحيل بالأحرف الكبيرة: إضافة الفهرس قبل الترقيم تفشل على بيانات حقيقية

### ٢ج — ⚠️ المسارات الحرجة · أصغر دفعة ممكنة

> **هاتان المهمتان تمسّان اثنين من المسارات الحرجة الثمانية في `AGENTS.md`.** تُشحنان معاً
> ومع اختبارهما، ولا تُدمجان مع دفعة أخرى.

- [X] T025 اكتب `backend/tests/Feature/Learning/RecordingProgressTest.php` **أولاً**: طالب مسجَّل **بلا مقعد** في حصة نُشر تسجيلها **في وسط الشجرة** يبلغ ١٠٠٪ وتصدر شهادته، وما بعد التسجيل مفتوح له (`SC-019`). **موضع الوسط شرط**: تسجيل في آخر الشجرة لا يقف أمام شيء، فالاختبار ينجح والعطل حيّ
- [X] T026 [P] اكتب `backend/tests/Feature/Learning/DraftGatingTest.php`: عنصر مسودّة لا يدخل المقام ولا يقف في التسلسل ولا يظهر للطالب (`SC-005` · `SC-006`)
- [X] T027 عدّل `recomputeProgress()` و`shouldCompleteCourse()` في `backend/app/Modules/Learning/Actions/MarkLessonComplete.php` ليستعملا نطاق `countableForProgress()` بدل `$enrollment->course->lessons()->count()` (`FR-026` · `FR-026أ`)
- [X] T028 عدّل `canAccessLesson()` في `backend/app/Modules/Learning/Models/Enrollment.php` ليتخطّى كشرط سابق: المسودّة والمؤرشف (`FR-027`) **ودرس التسجيل** (`FR-027أ`) — الاستبعاد من المقام وحده يترك العطل نفسه عائداً من باب الترتيب
- [X] T029 شغّل المسارات الحرجة الثمانية كاملةً وثبّت خضرتها قبل المتابعة: `php vendor/bin/pest tests/Feature/Learning tests/Feature/Certificates tests/Feature/Tenancy`

**Checkpoint**: `php artisan migrate` يمرّ فوق قاعدة قائمة · صفر تعادل · PHPStan نظيف · المسارات الحرجة خضراء · العطل الأبدي مُصلَح ومُختبَر من بابيه.

---

## Phase 3: US1 — بناء الشجرة (Priority: P1) 🎯 MVP

**Goal**: المدرّس يُنشئ ويسمّي ويرتّب ويحذف الأقسام والفصول والعناصر من داخل المنتج — وعشر
نقاط النهاية القائمة تصبح موصولة لأول مرة.

**Independent Test**: بناء شجرة من الصفر على كورس فارغ عبر الواجهة وحدها، والتحقق من أن
الترتيب المُعاد ينعكس على ما يُفتح للطالب في كورس متسلسل.

### الاختبارات أولاً

- [X] T030 [P] [US1] اكتب `backend/tests/Feature/Courses/TreeOrderingTest.php`: بعد ثلاث عمليات إعادة ترتيب، قيم الإخوة **متمايزة ومتصلة** في المستويات الثلاثة (`SC-003`)
- [X] T031 [P] [US1] أضف إلى `backend/tests/Feature/Courses/TreeOrderingTest.php`: قائمة ترتيب ناقصة عنصراً ⇒ 422 · قائمة فيها uuid ليس من الإخوة ⇒ 422 — الترتيب المكرّر **غير قابل للتعبير عنه** بهذه الحمولة (research §R3)
- [X] T032 [P] [US1] اكتب `backend/tests/Feature/Courses/SequentialAccessTest.php`: تبديل موضع درسين في كورس `is_sequential` يغيّر **ما يُفتح** للطالب — الاختبار يقرأ الفتح لا عمود `order` (`SC-004`)
- [X] T033 [P] [US1] اكتب `backend/tests/Feature/Courses/DeleteGuardTest.php`: حذف درس عليه تقدّم ⇒ **423** بالأرشفة بديلاً، وصفوف التقدّم **باقية**، والنسبة لم تنقص (`SC-008`)

### الخلفية

- [X] T034 [P] [US1] أنشئ `CreateSection` · `UpdateSection` · `DeleteSection` في `backend/app/Modules/Courses/Actions/` — الإنشاء يمنح ترتيباً متمايزاً (آخر الإخوة) لا صفراً (`FR-003`)
- [X] T035 [P] [US1] أنشئ `CreateChapter` · `UpdateChapter` · `DeleteChapter` في `backend/app/Modules/Courses/Actions/` بنفس القواعد
- [X] T036 [P] [US1] أنشئ `CreateLesson` · `UpdateLesson` · `DeleteLesson` في `backend/app/Modules/Courses/Actions/` — والحذف يرفض ما عليه تقدّم (`FR-007`) ويرفض ما يملك أصلاً مرفوعاً (`FR-038أ`)، ويعرض الأرشفة بديلاً
- [X] T037 [US1] أنشئ `backend/app/Modules/Courses/Actions/ReorderTreeNodes.php`: يستقبل قائمة uuid الإخوة كاملةً، ويكتبها في **معاملة واحدة**، ويرفع `structure_version` (`FR-004`)
- [X] T038 [US1] أنشئ `backend/app/Modules/Courses/Actions/ArchiveTreeNode.php` — الأرشفة تُخرج العنصر من المقام والتسلسل و**لا** تنقص نسبة أُحرزت (`FR-008`)
- [X] T039 [US1] أعد بناء `SectionController` · `ChapterController` · `LessonController` بـ `backend/app/Modules/Courses/Http/Controllers/` على الـActions — أربعة أسطر لكل دالة، بلا منطق (الدستور II · research §R14)
- [X] T040 [US1] أضف مسارات إعادة الترتيب الثلاثة إلى `backend/app/Modules/Courses/routes/api.php` ([contracts/api.md](./contracts/api.md) §١)
- [X] T041 [US1] أنشئ `backend/app/Modules/Courses/Http/Resources/CourseTreeResource.php` — شجرة المؤلّف بحالاتها الحقيقية وسبب الحجب؛ **منفصلة** عن الشجرة الطلابية عمداً، فتسريب المسودّة لا يصير نسيانَ مُعامِل ([contracts/api.md](./contracts/api.md) §١)
- [X] T042 [US1] أضف `GET /courses/{course}/tree` بحمل ثابت الاستعلامات: تحميل مسبق للمستويات الثلاثة (`FR-010`)
- [X] T043 [P] [US1] اكتب `backend/tests/Feature/Courses/TreeQueryBudgetTest.php`: عدد استعلامات شجرة بعشرين عنصراً = عددها لشجرة بمئتين (`SC-015`)

### الواجهة

- [X] T044 [P] [US1] أنشئ `frontend/src/lib/courses.ts` وانقل إليه استدعاءات الكورس المتناثرة في صفحات `manage/courses/` (`FR-064`)، وأضف دوال الشجرة وإعادة الترتيب
- [X] T045 [US1] أنشئ `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/content/page.tsx` — سطح التأليف
- [X] T046 [P] [US1] أنشئ `frontend/src/components/courses/TreeOutline.tsx` — الشجرة بمستوياتها الثلاثة، بمسافات بادئة `ms-*` لا `ml-*`
- [X] T047 [P] [US1] أنشئ `frontend/src/components/courses/MoveControls.tsx` — «لأعلى» · «لأسفل» · «انقل إلى…» **بلا سحب وإفلات** (research §R8): صفر تبعية، ووصولية بالبناء، وصحيحة في RTL بلا قلب
- [X] T048 [P] [US1] أنشئ `frontend/src/components/courses/DeleteNodeDialog.tsx` — يعرض ما سيُحذف مع العنصر **قبل** التنفيذ (`FR-006`)، ويعرض الأرشفة عند الردّ 423

**Checkpoint**: كورس فارغ يصير شجرة كاملة من المتصفّح · إعادة الترتيب تغيّر ما يُفتح · الحذف الخطر مرفوض.

---

## Phase 4: US2 — محرّر الدرس لكل نوع (Priority: P2)

**Goal**: كل نوع محرّر يناسبه، لا نموذج واحد بحقول لا تخصّه.

**Independent Test**: إنشاء درس من كل نوع مدعوم والتحقق من أن الطالب يراه ويستهلكه، وأن حقول
غير النوع ليست مطلوبة ولا محفوظة.

- [X] T049 [P] [US2] اكتب `backend/tests/Feature/Courses/MarkdownSanitisationTest.php`: حمولة معروفة تحوي سكربتاً ووسم `<img onerror>` ورابط `javascript:` — تخرج **منزوعةً** في كل مسار عرض (`SC-017`)
- [X] T050 [P] [US2] اكتب `backend/tests/Feature/Courses/LessonTypeTest.php` — حالة لكل نوع: يُنشأ ويصل الطالب إليه؛ و`note` و`link` **لا** يدخلان المقام ولا يحجبان (`SC-009` · `FR-012`)
- [X] T051 [US2] فعّل التحقّق حسب النوع في `backend/app/Modules/Courses/Http/Requests/StoreLessonRequest.php` و`UpdateLessonRequest.php` من `LessonTypeRegistry` — الحقول المطلوبة تُفرض **عند النشر** لا عند الحفظ كمسودّة (`FR-022` … سيناريو US2/٨)
- [X] T052 [US2] أضف `content_html` **مشتقّاً** في `backend/app/Modules/Courses/Http/Resources/LessonResource.php` عبر `MarkdownRenderer` — **لا يُخزَّن**: نسخة ثانية من الكلام نفسه تنحرف عند أول تصحيح مطبعي (research §R7)
- [X] T053 [US2] افرض `https` وصيغة صحيحة على `external_url` في `backend/app/Modules/Courses/Http/Requests/StoreLessonRequest.php` و`backend/app/Modules/Courses/Actions/ChangeLessonType.php` (`FR-018`)
- [X] T054 [US2] أنشئ `backend/app/Modules/Courses/Actions/ChangeLessonType.php` — يعيد **ما سيُفقد** ليعرضه المحرّر قبل التنفيذ (`FR-020`)
- [X] T055 [US2] اجعل مدّة الفيديو والصوت تُشتقّ من الأصل لا من المدرّس في `backend/app/Modules/Courses/Actions/UpdateLesson.php` (`FR-015`)، وأعد حساب `courses.duration_seconds` من العناصر المنشورة القابلة للإتمام عند كل كتابة بنيوية (`FR-016`)
- [X] T056 [P] [US2] أنشئ `frontend/src/components/courses/editors/ArticleEditor.tsx` — مجموعة تنسيق مغلقة ومعاينة، على مصدر Markdown
- [X] T057 [P] [US2] أنشئ `frontend/src/components/courses/editors/NoteEditor.tsx` — ويُظهر أن التنويه لا يُتمّ ولا يحجب
- [X] T058 [P] [US2] أنشئ `frontend/src/components/courses/editors/LinkEditor.tsx` — **بتحذير صريح** أن المحتوى الخارجي خارج حماية 004 كلياً: بلا علامة مائية ولا انتهاء صلاحية ولا حدّ أجهزة (`FR-017`)
- [X] T059 [US2] أنشئ `frontend/src/components/courses/LessonEditor.tsx` يوزّع على محرّر النوع، ويعرض `is_preview` و`is_free` **بمعناهما الفعلي مكتوباً**: `is_preview` إتاحة خارج التسجيل لا تمييز تسويقي (`FR-021`)

**Checkpoint**: كل نوع مكتوب يُنشأ ويُعرَض · صفر سكربت ينفَّذ · التنويه لا يحجب.

---

## Phase 5: US3 — تجربة المسودّة والنشر (Priority: P3)

**Goal**: المدرّس يبني على كورس حيّ ويحفظ ويعود غداً، وطلابه لا يرون شيئاً حتى ينشره.

**Independent Test**: إضافة عنصر مسودّة إلى كورس عليه تسجيل نشط، والتحقق من أن نسبة الطالب وما
يُفتح له **لم يتغيّرا** قبل النشر وتغيّرا بعده.

> آلة المسودّة نزلت في Phase 2 (انظر الانحراف المقصود أعلاه). هذه المرحلة **تجربتها**.

- [X] T060 [P] [US3] اكتب `backend/tests/Feature/Courses/PublishChainTest.php`: عنصر منشور داخل قسم مسودّة **محجوب**، وحالته تُعرَض للمدرّس بسببها لا كأنها حالته هو (`FR-028`)
- [X] T061 [P] [US3] اكتب `backend/tests/Feature/Courses/UnpublishSafetyTest.php`: سحب عنصر إلى المسودّة **لا** يحذف تقدّماً ولا يبطل شهادة (`FR-029` · `SC-007`)
- [X] T062 [P] [US3] اكتب `backend/tests/Feature/Courses/DraftExposureTest.php`: بصفة طالب، عنوان المسودّة **صفر مطابقة** في الاستجابة كاملةً؛ ونفس الفحص على الحمولة العامة (`SC-006` · `FR-062`)
- [X] T063 [US3] أنشئ `backend/app/Modules/Courses/Actions/PublishTreeNodes.php` — دفعة، مع سريان السلسلة (`FR-028`) ورفع `structure_version`
- [X] T064 [US3] أضف `POST /courses/{course}/tree/publish` إلى المسارات ([contracts/api.md](./contracts/api.md) §١)
- [X] T065 [US3] اجعل `GET /courses/{course}/sections` القائم يقدّم الشجرة **المنشورة فقط** — هو المسار الطلابي، ولا يتغيّر عقده حتى لا ينكسر شيء أثناء الترحيل
- [X] T066 [P] [US3] أنشئ `frontend/src/components/courses/StatusBadge.tsx` — يعرض «مسودّة» و«منشور» و«مؤرشف» و**«محجوب بقسمه»** كحالة رابعة مشتقّة
- [X] T067 [US3] أضف أدوات النشر إلى `frontend/src/components/courses/TreeOutline.tsx`: نشر عنصر · نشر دفعة · سحب إلى مسودّة، مع تأكيد على السحب

**Checkpoint**: التأليف على كورس حيّ صار آمناً · صفر مسودّة تصل طالباً.

---

## Phase 6: US4 — المستندات والمرفقات (Priority: P4)

**Goal**: مذكّرة شرح تُعرَض ولا تُحمَّل، وورقة عمل تُحمَّل لتُحلّ بالقلم.

**Independent Test**: رفع مستند بكل قيمة من قيمتي المفتاح، والتحقق من ترويسة الاستجابة وأن
الرابط ينتهي.

- [X] T068 [P] [US4] اكتب `backend/tests/Feature/Media/DocumentUploadTest.php`: PDF بامتداد `.mp4` يُقبل بمحتواه · zip بامتداد `.pdf` يُرفض · ملف يتجاوز الحد يُرفض **برسالة تذكر الحدّ الفعلي** (`FR-032` · `FR-039`)
- [X] T069 [P] [US4] اكتب `backend/tests/Feature/Media/DocumentDispositionTest.php`: «عرض فقط» ⇒ `inline` · «يسمح بالتحميل» ⇒ `attachment` · طلب بلا منحة ⇒ رفض · صفر مسار عام دائم (`SC-010` · `SC-011`)
- [X] T070 [US4] أعد تسمية `VideoProviderInterface` إلى `MediaProviderInterface` في `backend/app/Modules/Media/Contracts/` وحدّث كل مستعمليه ومزوّده المحلي واسم اختبار العقد (research §R5) — PHPStan يمسك ما فات
- [X] T071 [US4] وسّع `backend/app/Modules/Media/Actions/RequestUploadTicket.php`: يستقبل `kind` و`role`، ويقابل الحدود بقائمة الصنف، و**يستبدل الأصل القائم فقط عند `role=primary`** — المرفقات كثيرة (research §R11)
- [X] T072 [US4] وسّع `rejectionReason()` في `backend/app/Modules/Media/Actions/CompleteMediaUpload.php` ليقابل خريطة `kind` بدل القائمة الواحدة، ورسالة الرفض تسمّي الصنف لا «ليس ملف فيديو»
- [X] T073 [US4] اجعل `stream()` في `backend/app/Modules/Media/Http/Controllers/PlaybackController.php` يقدّم المستند بـ`Content-Disposition` حسب `is_downloadable` — **القرار على الخادم**: إخفاء زرّ في الواجهة ليس منعاً (`FR-036`)
- [X] T074 [US4] أضف `PUT /media/assets/{asset}/disposition` وActionها ([contracts/api.md](./contracts/api.md) §٢)
- [X] T075 [US4] اجعل حذف العنصر وأرشفته يُتبعان أصوله ومرفقاته بلا ملف يتيم (`FR-038`) — **انحراف مقصود**: لا وجود لـ`DeleteLesson.php` ولا `ArchiveTreeNode.php`؛ الحذف في `ManageLessons::delete()` والأرشفة حالة `archived` عبر `PublishTreeNodes`. والأرشفة تُبقي ملفاتها بحكم تعريفها، فلا يتيم فيها — الخلل كان في الحذف وحده وقد أُصلح
- [X] T075أ [US4] **مضافة أثناء التنفيذ**: تدقيق الجملة التي صارت T075 تُكذّبها. `contracts/api.md` §٢ كان يقول إن `DELETE /media/assets/{asset}` هو «الباب الوحيد لإتلاف **أصل مرفوع**» — صحيح عن أصل العنصر (`primary`) وغير صحيح عن ورقة عمل معلّقة به بعد T075. صُحّح العقد و`FR-038أ`، وأُضيف **`FR-038ب`** يقرّر الاستثناء صريحاً بعلّته (الأصل الأساسي **هو** العنصر؛ وعنصر بثلاث مرفقات يصير غير قابل للحذف إلا بثلاث تأكيدات ثنائية فيتعلّم المدرّس تجاوز التأكيد)، وصُحّح تعليق `TreeDeletionGuard`. والخطّ **مثبَّت باختبار** في `DeleteGuardTest` في الاتجاهين لا متروكاً نصّاً
- [X] T076 [P] [US4] أنشئ `frontend/src/components/courses/editors/DocumentEditor.tsx` — الرفع، ومفتاح العرض/التحميل، **ونصّ صريح** أن حماية «يسمح بالتحميل» هي انتهاء الرابط لا منع النسخ (`FR-037`)
- [X] T077 [P] [US4] أنشئ `frontend/src/components/courses/AttachmentsPanel.tsx` — مرفقات على أي عنصر مهما كان نوعه (`FR-019`)
- [X] T078 [P] [US4] أنشئ `frontend/src/components/courses/editors/AudioEditor.tsx` — نفس خط الرفع بصنف `audio`
- [X] T078أ [US4] **مضافة أثناء التنفيذ**: جانب الطالب. سيناريو US4/٦ يقول إن المرفقات «تظهر بجواره للطالب» وFR-034 يضع كل أصل خلف منحة — ولم تكن هناك نقطة نهاية تمنح مرفقاً ولا شاشة تعرضه. أُضيف `POST /lessons/{lesson}/assets/{asset}/playback` و`GET /learn/lessons/{lesson}` و`DocumentViewer` و`AttachmentList`، وصار `/learn/{lesson}` يعرف الأنواع كلها بدل الفيديو وحده

**Checkpoint**: نصف الأنواع الذي كان غير قابل للتأليف صار يعمل · صفر مسار دائم.

---

## Phase 7: US5 — عناصر الإحالة (Priority: P5)

**Goal**: اختبار الوحدة في مكانه من التسلسل ببوابة يختارها المدرّس؛ والواجب مردود برسالة
صريحة؛ والحصة القادمة تستقبل تسجيلها في موضعها.

**Independent Test**: وضع اختبار في منتصف كورس متسلسل بكل قيمة من قيمتي بوابته، والتحقق من
سلوك الفتح في الحالات الأربع.

- [X] T079 [P] [US5] اكتب `backend/tests/Feature/Courses/ExamGateTest.php` — الحالات الأربع: (يكفي أن يُحاول × ناجح/راسب) و(يجب أن ينجح × ناجح/راسب)؛ وفي المحجوبة يُعرَض **سبب** الحجب وما يفكّه (`SC-012` · `FR-043`)
- [X] T080 [P] [US5] اكتب `backend/tests/Feature/Courses/ReferenceIntegrityTest.php`: حذف عنصر الإحالة **لا** يمسّ الاختبار ولا محاولة واحدة عليه (`SC-013`)؛ وحذف الاختبار نفسه يُخفي عنصره من الشجرة بلا صفّ يشير إلى معدوم (`FR-045`)
- [X] T081 [P] [US5] اكتب `backend/tests/Feature/Courses/AssignmentReservedTest.php`: `type = assignment` ⇒ **422** برسالة تسمّي سبيك 008؛ ويفشل الاختبار إن صار النوع يعمل بلا 008 (research §R12)
- [X] T082 [US5] أضف `exam_gate` إلى بيانات عنصر الاختبار (يكفي أن يُحاول / يجب أن ينجح) وتحقّقه في `CreateLesson` و`UpdateLesson` (`FR-041`)
- [X] T083 [US5] اجعل `canAccessLesson()` في `backend/app/Modules/Learning/Models/Enrollment.php` يقرأ بوابة عنصر الاختبار من محاولات الطالب (`FR-042`) — ⚠️ **مسار حرج**: تُشحن مع T079
- [X] T084 [US5] أضف فلترة «الهدف معدوم» إلى نطاق الشجرة في `backend/app/Modules/Courses/Models/Lesson.php` — الحماية **عند القراءة** لا بمستمع حذف: مستمع يمكن ألا يُسجَّل، وسطر الفلترة يمرّ به كل قارئ بالضرورة (research §R9)
- [X] T085 [US5] عدّل `backend/app/Modules/LiveSessions/Listeners/PublishRecordingAsLesson.php`: يبحث **أولاً** عن عنصر `live_session` بـ`reference_id` فيحوّله في مكانه (`type → video` · `class_session_id` يُضبط · `reference_id` يُفرَّغ · `chapter_id` و`order` و`uuid` كما هي)، وفي غيابه يبقى سلوك 005؛ و**يضبط `status = published` صراحةً** ([contracts/events.md](./contracts/events.md) §١)
- [X] T086 [P] [US5] اكتب `backend/tests/Feature/LiveSessions/RecordingPlacementTest.php`: حصة لها عنصر ⇒ **عنصر واحد** في موضع العنصر لا في آخر الشجرة · إعادة الاستيعاب تحدّث الدرس نفسه · التسجيل يصل `published` (`SC-014` · `SC-020`)
- [X] T087 [P] [US5] أنشئ `frontend/src/components/courses/editors/ExamPicker.tsx` — اختيار اختبار منشور من الكورس ومفتاح بوابته
- [X] T088 [P] [US5] أنشئ `frontend/src/components/courses/editors/LiveSessionPicker.tsx` — الحصة القادمة، وحالة نهائية مفهومة لحصة مضى موعدها بلا تسجيل (`FR-048`)
- [X] T089 [US5] أضف `assignment` إلى قائمة الأنواع في `frontend/src/components/courses/LessonEditor.tsx` **معطّلاً برسالته** — لا يظهر كخيار يعمل ثم يفشل صامتاً (`FR-046`)
- [X] T089أ [US5] **مضافة أثناء التنفيذ**: إتمام عنصر الاختبار. النوع `exam` قابل للإتمام فيدخل مقام النسبة، ولا كاتب لصفّ تقدّمه إلا `POST /enrollments/…/complete` — والاختبار يُؤدّى من صفحته لا من العنصر. فأي كورس فيه عنصر اختبار كان يعجز عن بلوغ ١٠٠٪، فلا `CourseCompleted` ولا شهادة، **إلى الأبد** — العطل نفسه الذي كُتب له `FR-026أ` من باب آخر. أُضيف `Learning\Listeners\CompleteExamLessonOnSubmission` على `ExamSubmitted` (لا `ExamPassed`: أيّ الحدثين يُعتدّ به قرارُ **العنصر** بـ`FR-041`)
- [X] T089ب [US5] **مضافة أثناء التنفيذ**: جانب الطالب. `FR-043` يوجب عرض سبب الحجب، و`FR-047`/`FR-048` يوجبان عرض موعد الحصة وحالتها النهائية — وصفحة `/learn/{lesson}` كانت تُسقط الحمولة كلها عند الحجب فتُظهر عنواناً فارغاً. أُضيف `Learning\Support\LessonAccess` و`Courses\Support\ReferenceSummary`، وحقول `blocked_reason`/`blocked_message`/`reference` في حمولة الطالب، و`SessionSlot` في الصفحة. ووُحّد رفض `completeLesson` على الجملة نفسها بدل نصّه الإنجليزي
- [X] T089د [US5] **مضافة أثناء التنفيذ**: النصف الآخر من T089أ. المستمع على `ExamSubmitted` يغطّي من يؤدّي الاختبار من الآن، ولا يغطّي **من أدّاه قبل أن يوضع العنصر** — والاختبار يُؤدّى من صفحته، فالطالب ينهيه أسابيع قبل أن يقرّر المدرّس موضعه. لن يُطلَق له حدث ثانياً، والعنصر يدخل مقامه عند النشر فيسقف نسبته دون ١٠٠٪ إلى الأبد، و«أعِد المحاولة» ليست جواباً لأن `max_attempts` قد تكون استُنفدت. أُضيف حدث `Courses\Events\ExamItemOpened` (نشر عنصر اختبار · أو تغيّر بوابته أو اختباره وهو منشور) و`CompleteExamLessonsAlreadyAnswered`، ووُحّد شرط «أدّى» في `Learning\Support\ExamGateSatisfaction` — ثلاث نسخ منه ثلاثة تعريفات، وأوّل ما ينحرف يقرّر هل يُمكن إتمام الكورس
- [X] T089ج [US5] **مضافة أثناء التنفيذ**: `GET /courses/{course}/reference-targets` — المنتقيان يحتاجان قائمتين مقصورتين على الكورس، و`/exams` و`/class-sessions` تُرقّمان صفحاتٍ بلا مرشّح كورس؛ فالصفحة الثانية تُخفي هدفاً من قائمة منسدلة بصمت

**Checkpoint**: الاختبار صار عنصراً ببوابة · الواجب محجوز بلا وهم · التسجيل يحلّ في موضعه.

---

## مراجعة بستة وكلاء بعد US5 — ما وجدته وما صُحّح

راجع ستة وكلاء كلَّ تنفيذ السبيك (`5581e9c..HEAD`)، بعدٍ لكل وكيل: التعارض · N+1 ·
الحماية · كلين كود · ممارسات Laravel · قابلية التوسّع. **٢٢ علّة مؤكَّدة**، كلٌّ منها تحقّقتُ
منها بقراءة الكود قبل الإصلاح، وأخطرها أُثبتت **باختبار يفشل قبل الإصلاح ويمرّ بعده**.

- [X] R01 **هجرة تُسقط نصف الصفوف بصمت** — `chunk()` يُصفّح بالـOFFSET ضد `whereNull('uuid')` الذي يتقلّص، فتُقفَز ٥٠١–١٠٠٠ وتنتهي الحلقة. والهجرة **تنجح** لأن الفهرس الفريد يسمح بأي عدد من `NULL`، فيظهر الأثر بعد النشر: ٤٠٤ على كل مسار قسم وفصل، و`ReorderTreeNodes` يرفض كل إعادة ترتيب لتلك المجموعة. صار `chunkById`، و`StructureMigrationTest` صار موجوداً بعد أن كان التعليق يزعم وجوده (٥٠٠ بلا uuid بالقديم · صفر بالجديد)
- [X] R02 **طريق رابع إلى عطل «إلى الأبد»** — `countableForProgress` يُغفل سلسلة الأب التي يفرضها `visibleToStudents`، فدرس منشور داخل فصل مسوّدة يدخل المقام ولا يُفتح أبداً
- [X] R03 **الباب الثاني إلى إتلاف أصل بلا تحقّق ثنائي** — `RequestUploadTicket` كان يحذف الأصل الأساسي بنفسه على مسار يحمل `auth:sanctum` وحدها، بلا `LESSONS_DELETE` وخارج `DeleteMediaAsset` (فلا إلغاء منح ولا تنظيف ترجمات). صار **٤٢٣** بـ`alternative: delete_asset` — الجملة صارت صادقة بدل أن تُصاغ ثالثةً
- [X] R04 **الحذف الجماعي الذي يمنعه `FR-038ب` بالاسم** في `ManageChapters` و`ManageSections`: بايتات المرفقات تبقى على القرص بلا صفّ، والمنح لا تُلغى
- [X] R05 **مسوّدة ومؤرشف يُقدَّمان للطالب كاملَين** — لا `accessTo` ولا `mayWatch` يفحص حالة ما يُفتح، و`HasUuid` يحلّ بالمعرّف التسلسلي فلا حاجة لتخمين uuid. وثالثة بجوارهما: `is_free`/`is_preview` يُقرآن قبل أي حالة وهما يفتحان لزائر. صار مسنداً واحداً `Lesson::isVisibleChain()`، و**٤٠٤ للمسوّدة** لا حمولةً بسبب (`FR-025`: صفر حقول، والعنوان حقل)
- [X] R06 **`FR-040` مفروض في المنتقي لا في الـAction** — واختبار مُنشور ثم أُعيد إلى مسوّدة كان قفلاً دائماً؛ حُلّ بجعل `ReferenceIntegrity` يعدّه **غائباً**، وهو الغياب نفسه من جهة الطالب
- [X] R07 **المستمعان لا يفحصان نشاط التسجيل** → شهادة تصدر لتسجيل منتهٍ لا يُسمح لصاحبه بإتمام درس واحد
- [X] R08 **`is_preview`/`is_free` يُمحى أحدهما بالآخر** — عطل حيّ في الواجهة: كل مربّع يُرسل حقله وحده، والـDTO يجعل الغياب و`false` سواءً. صار `?bool`، والدمج في الـAction لا في قائمة المتحكّم التي انحرفت عن شكل الـDTO
- [X] R09 **`StructureVersion` قراءة-ثم-كتابة** فلا يمنع الفقد الذي وُجد له: مدرّسان بالنسخة ٥ يمرّان كلاهما. صار `claim()` بـUPDATE شرطي داخل المعاملة — بنفس أسلوب حجز المقعد، ولا `lockForUpdate()` الذي لا يفعل شيئاً على SQLite
- [X] R10 **إزاحة الإيقاف ١٠٠٠ ثابتة** و`max('order')` ينمو بعمر المجموعة لا بعدد صفوفها → تعارض فهرس فريد و٥٠٠ برسالة SQL خام. صارت محسوبة
- [X] R11 **نقل درس بين فصول** يُبقي `order` القديم فيخالف الفهرس الفريد، والتحديث بلا معاملة وبلا رفع `structure_version`
- [X] R12 **التسجيل الآلي لا يرفع `structure_version`** — الكاتب الوحيد الذي لا يراه المدرّس هو الوحيد الذي لا يُعلن عن نفسه
- [X] R13 **الفان-آوت داخل طلب المدرّس** (~١٣ استعلاماً لكل طالب × كل طلاب الكورس، ومرّة أخرى مع كل تحريك لقائمة البوابة) → `ShouldQueue` + `chunkById` + `with('course')` + استبعاد المُتمّين مسبقاً
- [X] R14 **`MarkLessonComplete` يحسب العددين مرّتين** — أربعة `COUNT` مكان اثنين، على كل إتمام درس في المنتج
- [X] R15 **`ExamSubmitted` يُطلَق داخل معاملة `GradeAttempt`** فأي خطأ في محاسبة التقدّم يُلغي **محاولة الطالب المُصحَّحة**. صار `ShouldQueue` + `ShouldHandleEventsAfterCommit`
- [X] R16 **الشجرة تجرّ `content` (longText) لترسم مخططاً لا يحتويه** — قائمة أعمدة على قراءة الشجرة. و**لم** تُطبَّق على `PublishTreeNodes`: `PublishReadiness` يقرأ `content` ليعرف أن المقالة غير فارغة، فقائمة أعمدة هناك ترفض نشر مقالة مكتملة
- [X] R17 **سباق `max('order')+1`** عند إنشاء متزامن → ٥٠٠ خام. صار `SiblingOrderRetry` بإعادة محاولة محدودة
- [X] R18 **`PUT /media/assets/{asset}/disposition` بلا محدّد مسمّى**
- [X] R19 **`is_downloadable` متروك لافتراض العمود** والمفتاح في `casts()` → التذكرة تقول `null` وكل قراءة لاحقة تقول `false`
- [X] R20 **تسميات الأنواع مكرّرة ومنحرفة** في `labels.ts` («مقال» مقابل «مقالة») وأربعة أنواع من عشرة، و`StatusBadge` يكرّر الثلاثة التي يملكها `labels.ts`
- [X] R21 **ثغرة في حارس ميزانية الاستعلامات** — كل الدروس `article` في قسم واحد، ففرع الإحادة في `CourseTreeResource` لا يُنفَّذ أصلاً تحت الميزانية
- [X] R22 **تعليقان يسمّيان اختبارين غير موجودين** (`MarkdownSanitisationTest`, `StructureMigrationTest`) و**تجهيزة تكتب `is_completed`** وهو ليس عموداً ولا `fillable`، فالاختبار يؤكّد بقاء صفٍّ حالته `null`

> **~~مؤجَّل~~ — حُسم في Polish**: `LessonTypeRegistry::family()` كان بلا مُنادٍ بينما
> `LessonEditor.tsx` يحمل `INLINE` و`DOCUMENT` — عملَ السجلّ مُعاداً بلغة أخرى، فإضافة نوع
> تعني تذكّر ملفٍّ لا يذكره السجلّ ولا شيء يفشل. الحمولة صارت تحمل `family` و`asset_kind`،
> والمصفوفتان حُذفتا.

---

## Phase 8: US6 — تحرير كورس مأهول (Priority: P6)

**Goal**: المدرّس يرى أثر ما سينشره على ثلاثين طالباً قبل أن يضغط.

**Independent Test**: نشر دفعة تعديلات على كورس عليه تسجيلات، ومقارنة ما عُرِض قبل النشر بما
وقع فعلاً.

- [X] T090 [P] [US6] اكتب `backend/tests/Feature/Courses/PublishImpactTest.php`: المعروض يطابق الواقع بعد النشر ١٠٠٪ (`SC-018`) — ١١ حالة، كلٌّ منها تقرأ المعاينة ثم تنشر `items` التي أعادتها ثم تقارن `progress_pct` **المخزَّنة** بما عُرِض
- [X] T091 [P] [US6] محرّران على الشجرة نفسها ⇒ **409** بالشجرة المحدَّثة في الردّ، بلا دهس صامت (`FR-009`) — **أُضيفت إلى `StructureConcurrencyTest.php` بدل ملف ثانٍ**: نصف المهمة (منع الدهس) كان قد سبق تنفيذه في مراجعة الوكلاء (`StructureVersion::claim`) واختباره هناك، وملفّ `ConcurrentEditTest.php` كان سيكرّر تجهيزته وحالتَيه
- [X] T092 [US6] أنشئ `backend/app/Modules/Courses/Actions/PreviewPublishImpact.php` — **نفس حساب النشر** لا تقدير ثانٍ ينحرف عنه (`FR-049`)
- [X] T093 [US6] أضف `GET /courses/{course}/tree/publish-preview` (خارج `throttle:authoring` — قراءة)
- [X] T094 [US6] افرض فحص `structure_version` في `ReorderTreeNodes` و`PublishTreeNodes` ⇒ 409 بحالة الشجرة الجديدة (`FR-009`) — الفرض نفسه سبق في المراجعة؛ **الجديد هنا أن الردّ صار يحمل `tree`** لا الرقم وحده: الرقم يقول «خريطتك قديمة» فيذهب المحرّر ليقرأ الشجرة في لحظة تالية لِلحظة الرفض، فقد تكون قديمة هي الأخرى
- [X] T095 [US6] تحذيرات الحالات الخطرة: `recording_hidden` (`FR-053`) و`course_emptied` (`FR-055`) في حمولة المعاينة — لا رفض: المدرّس يملك إخفاء تسجيله وسحب كورسه، وما لا يملكه هو أن يكتشف المعنى بعد التنفيذ. ونصف `FR-053` الآخر — **الحذف** لا المرور بالمعاينة — جملةٌ خاصّة في تأكيد الحذف تقرأ `is_recording` من الشجرة
- [X] T096 [US6] امنع تعديل `class_session_id` من أي مسار تأليف (`FR-054`) — **المسمّيان في المهمة غير موجودين**: لا `UpdateLesson.php` ولا كتابةً للعمود أصلاً، فالحقل غائب عن `UpdateLessonRequest::rules()` و`ManageLessons::update()` يبني مصفوفته حقلاً حقلاً. فالمُسلَّم هو الحارس: `RecordingAuthoringTest.php` — سطر واحد في أيٍّ منهما يفتح الباب ولا شيء آخر في المجموعة ينتبه. وفيه نصف `FR-052`: إعادة التسمية والنقل **تُقبَل** والتسجيل يبقى تسجيلاً
- [X] T097 [P] [US6] أنشئ `frontend/src/components/courses/PublishImpactDialog.tsx` — العناصر الداخلة/الخارجة · الطلاب وأكبر تغيّر · ما يتغيّر ترتيب فتحه · التحذيرات
- [X] T098 [US6] عالج 409 في صفحة المحتوى برسالة عربية عبر `errorMessage()` وإعادة بناء الشجرة **من جسم الردّ** لا بقراءة ثانية — **يُمنع** خطأ خام على الشاشة

### مضافة أثناء التنفيذ

- [X] T098أ [US6] **النسبة المخزَّنة كانت تكذب بعد كل نشر.** `progress_pct` يُكتب عند إتمام **درس** ولا شيء غيره، والنشر يُحرّك المقام للجميع دفعةً — فالانخفاض الذي يَعِد به `FR-051` كان يظهر للمدرّس في المعاينة ولا يصل شاشة طالب واحد. أُضيف `Courses\Events\CourseStructurePublished` و`Learning\Listeners\ResyncCourseProgressAfterPublish` (مطبور، `chunkById`، المقام يُحسب مرّة للكورس لا مرّة لكل طالب)
- [X] T098ب [US6] **الطريق الخامس إلى عطل «إلى الأبد».** المسار نفسه يؤرشف: أرشِف العنصر الوحيد المتبقّي لطالب فيصير المتبقّي صفراً — والإتمام لا يُقرَّر إلا عند إتمام درس، ولم يبقَ درس يُتَمّ. فيجلس على ١٠٠٪ بلا `CourseCompleted` وبلا شهادة، دائماً. القرار كلّه انتقل إلى `Learning\Support\CourseProgress::sync()` ليصل إليه `MarkLessonComplete` والمستمع بالطريق نفسه، و`status` يُكتب في اتجاه واحد فقط — فـ`FR-050` شكلُ الدالة لا شرطٌ فيها
- [X] T098ج [US6] **المعاينة كانت ستكذب على من أدّى الاختبار قبل وضعه.** `CompleteExamLessonsAlreadyAnswered` يكتب صفوفهم لحظة النشر، فقراءة `lesson_progress` كما هي تَعِد بانخفاضٍ لا يقع. المعاينة تسأل `ExamGateSatisfaction` نفسها، **مجموعةً لكل عنصر** لا سؤالاً لكل طالب
- [X] T098د [US6] **`Courses` لا يستورد `Learning`.** الاعتماد بين الوحدتين باتجاه واحد، وحساب أثر النشر كان سيقلبه. فُصل نصفه إلى `Shared\Contracts\ProgressImpact` و`Learning\Support\EloquentProgressImpact` — بشكل `EnrollmentDirectory` نفسه. و`Lesson::progressEligible()` فُصلت عن `countableForProgress` ليُحاكى **شرط الحالة وحده**، وحمّال الشجرة انتقل إلى `CourseTreeResource::for()` لأن قائمة الأعمدة صار لها قارئان

> **~~مؤجَّل~~ — حُسم في Polish**: الطريق السادس. الحدث كان يُطلَق من `PublishTreeNodes` وحده،
> فالحذف يُحرّك المقام بلا مزامنة ولا درسَ بقي ليُطلقها. أُعيدت تسميته `CourseStructureChanged`
> — الاسم الذي يصف مُنادياً واحداً هو سبب نسيان الثاني — ويُطلَق الآن من أفعال الحذف ومن نقل
> عنصر منشور أو إعادة توجيه مرجعه، **مرّة لكل فعلٍ خارجي** (الكنس يمرّر `announce: false`).
> و`ChangeLessonType` لا يحتاجه: هو يرفض العنصر المنشور أصلاً.

**Checkpoint**: التحرير على شجرة مأهولة صار مرئي الأثر ومحميّاً من الدهس.

---

## Phase 9: Polish & Cross-Cutting

- [X] T099 حدّث نصّ الحالة الفارغة في `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/page.tsx` من «سطح التأليف قيد الإعداد» إلى **دعوة تقود إلى السطح** (`FR-063`) — السطح لا يُعدّ مكتملاً بلا مدخل إليه
- [X] T100 [P] أضف زرّ «محتوى الكورس» بجوار «تعديل الكورس» في `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/page.tsx` — مدخل ثانٍ ظاهر لا مخفيّ خلف حالة فارغة
- [X] T101 [P] أضف `logActivity()` إلى Actions التأليف بمن نفّذ ووقته على العنصر (`FR-056`) — سبعة أفعال. الحذف يُسجَّل **قبل** ذهاب الصفّ وإلا سجّل `performedOn` موضوعاً لا يُحلّ، والنشر سطر لكل عنصر لا سطر للدفعة. و`AuthoringAuditTest` هو المُسلَّم: السمة على الصنف لا تغطّي شيئاً وحدها، والدالة المنسيّة لا تظهر حتى يسأل مدقّق عنها بالذات
- [X] T102 [P] صفر حقل من عنصر مسودّة أو مؤرشف في أي حمولة عامة (`FR-062`) — **القائمة لم تحتج مفتاحاً جديداً؛ التسريب كان في العدّ**: `withCount('lessons')` يعدّ كل صفّ، فمسودّات المدرّس وأرشيفه تنفخان الرقم المعروض للزائر — كورس يُعلن ثلاثة عناصر ويفتح واحداً. حالةٌ في `PublicExposureTest` مثبَّتة بـ٣ مقابل ١ قبل الإصلاح
- [X] T103 [P] `frontend/e2e/course-authoring.spec.ts` (`SC-016`) — يدخل من روابط المنتج نفسها لا بـ`page.goto`، ويمرّ بحوار الأثر. **ويُنشئ كورساً لكل مشروع متصفّح**: «نشر كل المسودّات» ينشر كل مسودّات الكورس الذي هو عليه، ومشاركة كورس بين ستّة مشاريع متوازية تجعلها تنشر أشجار بعضها وتصطدم على `structure_version` — الحارس يعمل في أسوأ لحظة ممكنة
- [X] T104 [P] `/manage/courses/[uuid]/content` في تدقيق RTL وaxe — في `describe` منفصل لأن المسار يحمل uuid، وبحساب **المدرّس**: `storageState` المشاريع طالب، وعنده `/manage/courses` لا يفتح شيئاً فيمرّ التدقيق فوق قائمة فارغة
- [X] T105 [P] كورس «Authoring Showcase» في `ScenarioSeeder`: قسمان · أربعة فصول · تسعة أنواع مدعومة · مسودّة بجوار إخوتها المنشورين · مستند بكل قيمة من قيمتي المفتاح. أصول العناصر المرفوعة صفوفٌ **بلا بايتات** — تزوير الملفات تزويرٌ لما وُجد خطّ الوسائط من أجله، والمعروض هنا هو الشجرة. تُحقّق على قاعدة مؤقّتة لا على قاعدة المستخدم
- [X] T106 `docs/README.md` (قسم كامل: القاعدتان · نقاط النهاية · الأنواع · الطرق الستّة إلى العطل الدائم · التدقيق · المحدِّد) و`docs/erd.md` (الأعمدة الجديدة، ولوحة `course_sections` كانت لا تزال تقول `is_published`)
- [X] T107 `CLAUDE.md` و`AGENTS.md` معاً: الترتيب كتابةٌ على حقوق وصول · المقام يستثني التسجيل من بابيه · الترقيم قبل الفهرس و`chunkById` قبل `chunk` · Markdown يُصيَّر ولا يُخزَّن · والمعاينة تُحسب بكود النشر
- [X] T108 `docs/roadmap.md`: 016 ✅ **مُنفَّذة (2026-08-08)**، فاكتملت الموجة أ عدا 003
- [X] T109 البوابات الأربع وPlaywright على بناء إنتاج، مُسجَّلة في [quickstart.md](./quickstart.md): pest **752/752** · pint · PHPStan L8 صفر · tsc · Playwright **550 ناجحة** وواحدة متذبذبة من 014 تنجح منفردة (مُسجَّلة لا مُخفاة). **والجولة وحدها أمسكت عطلين حيَّين** لم تمسّهما أي بوابة: صفحة الطالب تقول «لا دروس منشورة بعد» عن كورس مكتمل لأنها بقيت ترشّح على `is_published` الذي استبدلته هذه المرحلة بـ`status`، وكل مقالة تعرض «تعذّرت المشاهدة» بالأحمر فوق نصّ يُقرأ لأن الصفحة تطلب منحة تشغيل قبل وصول نوع العنصر

---

## Dependencies

```
Phase 1 (Setup)
   └─► Phase 2 (Foundational)  ⚠️ ٢ج يمسّ مسارين حرجين
          ├─► Phase 3 · US1  🎯 MVP
          │      ├─► Phase 4 · US2   (يحتاج شجرة يضع فيها المحرّر)
          │      ├─► Phase 5 · US3   (يحتاج شجرة تُنشَر)
          │      └─► Phase 8 · US6   (يحتاج US1 و US3 معاً)
          ├─► Phase 6 · US4  (مستقلة عن US2 و US3 — تحتاج US1 وحدها)
          └─► Phase 7 · US5  (مستقلة — تحتاج US1 وحدها)
```

**داخل Phase 2 ترتيب ملزم**: ٢أ ← ٢ب ← ٢ج. الترقيم الكثيف (T016) **قبل** الفهرس الفريد في
الهجرة نفسها، وتصحيح المسارات الحرجة (٢ج) بعد وجود عمود `status` (T015) والنطاقين (T021).

**US4 و US5 متوازيتان تماماً** بعد US1: الأولى في `Modules/Media`، والثانية في
`Modules/Courses` و`LiveSessions`. لا ملف مشترك بينهما.

## فرص التوازي

| المجموعة | المهام | لماذا آمنة |
|---|---|---|
| تعدادات Setup | T001 · T002 · T003 | ثلاثة ملفات جديدة منفصلة |
| هجرات ٢ب | T017 · T018 · T019 | جداول مختلفة — لكن **بعد** T015 و T016 على الشجرة |
| اختبارات US1 | T030 · T031 · T032 · T033 | أربعة ملفات اختبار جديدة |
| Actions US1 | T034 · T035 · T036 | ثلاث مجموعات على ثلاثة نماذج |
| محرّرات US2 | T056 · T057 · T058 | ثلاثة مكوّنات جديدة |
| واجهة US4 | T076 · T077 · T078 | ثلاثة مكوّنات جديدة |
| Polish | T100 · T101 · T102 · T103 · T104 · T105 | ملفات متفرّقة بلا تقاطع |

## استراتيجية التسليم

**MVP = Phase 1 + Phase 2 + Phase 3 (US1)** — عند هذا الحدّ يصير المدرّس قادراً على بناء
شجرة كورسه من المنتج لأول مرة، وعشر نقاط النهاية القائمة موصولة، والعطل الأبدي في الشهادات
مُصلَحاً. وهو تسليم ذو قيمة قائمة بذاته حتى لو توقّف العمل بعده.

**ثم بالترتيب**: US2 (المحتوى المكتوب) ← US3 (الأمان على كورس حيّ) ← US4 (المستندات) ←
US5 (الإحالة) ← US6 (أثر النشر) ← Polish.

**والدفعة التي لا تُدمج مع غيرها**: ٢ج. تعديل على `MarkLessonComplete` و`canAccessLesson`
يمسّ اثنين من المسارات الحرجة الثمانية — تُشحن وحدها، ومع اختبارها، وبعد تثبيت خضرة المسارات
الثمانية (T029).
