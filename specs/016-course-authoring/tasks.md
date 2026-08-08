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
- [ ] T023 [P] أضف حالات الأعمدة الجديدة إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — الشجرة وعناصرها ومرفقاتها كلها مملوكة لمساحة العمل ([data-model.md](./data-model.md) § طبقة الملكية)
- [ ] T024 [P] اكتب `backend/tests/Feature/Courses/StructureMigrationTest.php`: صفر صفّ بلا uuid · صفر تعادل ترتيب في أي مستوى · كل المحتوى القائم `published` · قسم التسجيلات آخر كورسه (quickstart §٩)

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
- [ ] T041 [US1] أنشئ `backend/app/Modules/Courses/Http/Resources/CourseTreeResource.php` — شجرة المؤلّف بحالاتها الحقيقية وسبب الحجب؛ **منفصلة** عن الشجرة الطلابية عمداً، فتسريب المسودّة لا يصير نسيانَ مُعامِل ([contracts/api.md](./contracts/api.md) §١)
- [ ] T042 [US1] أضف `GET /courses/{course}/tree` بحمل ثابت الاستعلامات: تحميل مسبق للمستويات الثلاثة (`FR-010`)
- [ ] T043 [P] [US1] اكتب `backend/tests/Feature/Courses/TreeQueryBudgetTest.php`: عدد استعلامات شجرة بعشرين عنصراً = عددها لشجرة بمئتين (`SC-015`)

### الواجهة

- [ ] T044 [P] [US1] أنشئ `frontend/src/lib/courses.ts` وانقل إليه استدعاءات الكورس المتناثرة في صفحات `manage/courses/` (`FR-064`)، وأضف دوال الشجرة وإعادة الترتيب
- [ ] T045 [US1] أنشئ `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/content/page.tsx` — سطح التأليف
- [ ] T046 [P] [US1] أنشئ `frontend/src/components/courses/TreeOutline.tsx` — الشجرة بمستوياتها الثلاثة، بمسافات بادئة `ms-*` لا `ml-*`
- [ ] T047 [P] [US1] أنشئ `frontend/src/components/courses/MoveControls.tsx` — «لأعلى» · «لأسفل» · «انقل إلى…» **بلا سحب وإفلات** (research §R8): صفر تبعية، ووصولية بالبناء، وصحيحة في RTL بلا قلب
- [ ] T048 [P] [US1] أنشئ `frontend/src/components/courses/DeleteNodeDialog.tsx` — يعرض ما سيُحذف مع العنصر **قبل** التنفيذ (`FR-006`)، ويعرض الأرشفة عند الردّ 423

**Checkpoint**: كورس فارغ يصير شجرة كاملة من المتصفّح · إعادة الترتيب تغيّر ما يُفتح · الحذف الخطر مرفوض.

---

## Phase 4: US2 — محرّر الدرس لكل نوع (Priority: P2)

**Goal**: كل نوع محرّر يناسبه، لا نموذج واحد بحقول لا تخصّه.

**Independent Test**: إنشاء درس من كل نوع مدعوم والتحقق من أن الطالب يراه ويستهلكه، وأن حقول
غير النوع ليست مطلوبة ولا محفوظة.

- [ ] T049 [P] [US2] اكتب `backend/tests/Feature/Courses/MarkdownSanitisationTest.php`: حمولة معروفة تحوي سكربتاً ووسم `<img onerror>` ورابط `javascript:` — تخرج **منزوعةً** في كل مسار عرض (`SC-017`)
- [ ] T050 [P] [US2] اكتب `backend/tests/Feature/Courses/LessonTypeTest.php` — حالة لكل نوع: يُنشأ ويصل الطالب إليه؛ و`note` و`link` **لا** يدخلان المقام ولا يحجبان (`SC-009` · `FR-012`)
- [ ] T051 [US2] فعّل التحقّق حسب النوع في `backend/app/Modules/Courses/Http/Requests/StoreLessonRequest.php` و`UpdateLessonRequest.php` من `LessonTypeRegistry` — الحقول المطلوبة تُفرض **عند النشر** لا عند الحفظ كمسودّة (`FR-022` … سيناريو US2/٨)
- [ ] T052 [US2] أضف `content_html` **مشتقّاً** في `backend/app/Modules/Courses/Http/Resources/LessonResource.php` عبر `MarkdownRenderer` — **لا يُخزَّن**: نسخة ثانية من الكلام نفسه تنحرف عند أول تصحيح مطبعي (research §R7)
- [ ] T053 [US2] افرض `https` وصيغة صحيحة على `external_url` في `backend/app/Modules/Courses/Http/Requests/StoreLessonRequest.php` و`backend/app/Modules/Courses/Actions/ChangeLessonType.php` (`FR-018`)
- [ ] T054 [US2] أنشئ `backend/app/Modules/Courses/Actions/ChangeLessonType.php` — يعيد **ما سيُفقد** ليعرضه المحرّر قبل التنفيذ (`FR-020`)
- [ ] T055 [US2] اجعل مدّة الفيديو والصوت تُشتقّ من الأصل لا من المدرّس في `backend/app/Modules/Courses/Actions/UpdateLesson.php` (`FR-015`)، وأعد حساب `courses.duration_seconds` من العناصر المنشورة القابلة للإتمام عند كل كتابة بنيوية (`FR-016`)
- [ ] T056 [P] [US2] أنشئ `frontend/src/components/courses/editors/ArticleEditor.tsx` — مجموعة تنسيق مغلقة ومعاينة، على مصدر Markdown
- [ ] T057 [P] [US2] أنشئ `frontend/src/components/courses/editors/NoteEditor.tsx` — ويُظهر أن التنويه لا يُتمّ ولا يحجب
- [ ] T058 [P] [US2] أنشئ `frontend/src/components/courses/editors/LinkEditor.tsx` — **بتحذير صريح** أن المحتوى الخارجي خارج حماية 004 كلياً: بلا علامة مائية ولا انتهاء صلاحية ولا حدّ أجهزة (`FR-017`)
- [ ] T059 [US2] أنشئ `frontend/src/components/courses/LessonEditor.tsx` يوزّع على محرّر النوع، ويعرض `is_preview` و`is_free` **بمعناهما الفعلي مكتوباً**: `is_preview` إتاحة خارج التسجيل لا تمييز تسويقي (`FR-021`)

**Checkpoint**: كل نوع مكتوب يُنشأ ويُعرَض · صفر سكربت ينفَّذ · التنويه لا يحجب.

---

## Phase 5: US3 — تجربة المسودّة والنشر (Priority: P3)

**Goal**: المدرّس يبني على كورس حيّ ويحفظ ويعود غداً، وطلابه لا يرون شيئاً حتى ينشره.

**Independent Test**: إضافة عنصر مسودّة إلى كورس عليه تسجيل نشط، والتحقق من أن نسبة الطالب وما
يُفتح له **لم يتغيّرا** قبل النشر وتغيّرا بعده.

> آلة المسودّة نزلت في Phase 2 (انظر الانحراف المقصود أعلاه). هذه المرحلة **تجربتها**.

- [ ] T060 [P] [US3] اكتب `backend/tests/Feature/Courses/PublishChainTest.php`: عنصر منشور داخل قسم مسودّة **محجوب**، وحالته تُعرَض للمدرّس بسببها لا كأنها حالته هو (`FR-028`)
- [ ] T061 [P] [US3] اكتب `backend/tests/Feature/Courses/UnpublishSafetyTest.php`: سحب عنصر إلى المسودّة **لا** يحذف تقدّماً ولا يبطل شهادة (`FR-029` · `SC-007`)
- [ ] T062 [P] [US3] اكتب `backend/tests/Feature/Courses/DraftExposureTest.php`: بصفة طالب، عنوان المسودّة **صفر مطابقة** في الاستجابة كاملةً؛ ونفس الفحص على الحمولة العامة (`SC-006` · `FR-062`)
- [ ] T063 [US3] أنشئ `backend/app/Modules/Courses/Actions/PublishTreeNodes.php` — دفعة، مع سريان السلسلة (`FR-028`) ورفع `structure_version`
- [ ] T064 [US3] أضف `POST /courses/{course}/tree/publish` إلى المسارات ([contracts/api.md](./contracts/api.md) §١)
- [ ] T065 [US3] اجعل `GET /courses/{course}/sections` القائم يقدّم الشجرة **المنشورة فقط** — هو المسار الطلابي، ولا يتغيّر عقده حتى لا ينكسر شيء أثناء الترحيل
- [ ] T066 [P] [US3] أنشئ `frontend/src/components/courses/StatusBadge.tsx` — يعرض «مسودّة» و«منشور» و«مؤرشف» و**«محجوب بقسمه»** كحالة رابعة مشتقّة
- [ ] T067 [US3] أضف أدوات النشر إلى `frontend/src/components/courses/TreeOutline.tsx`: نشر عنصر · نشر دفعة · سحب إلى مسودّة، مع تأكيد على السحب

**Checkpoint**: التأليف على كورس حيّ صار آمناً · صفر مسودّة تصل طالباً.

---

## Phase 6: US4 — المستندات والمرفقات (Priority: P4)

**Goal**: مذكّرة شرح تُعرَض ولا تُحمَّل، وورقة عمل تُحمَّل لتُحلّ بالقلم.

**Independent Test**: رفع مستند بكل قيمة من قيمتي المفتاح، والتحقق من ترويسة الاستجابة وأن
الرابط ينتهي.

- [ ] T068 [P] [US4] اكتب `backend/tests/Feature/Media/DocumentUploadTest.php`: PDF بامتداد `.mp4` يُقبل بمحتواه · zip بامتداد `.pdf` يُرفض · ملف يتجاوز الحد يُرفض **برسالة تذكر الحدّ الفعلي** (`FR-032` · `FR-039`)
- [ ] T069 [P] [US4] اكتب `backend/tests/Feature/Media/DocumentDispositionTest.php`: «عرض فقط» ⇒ `inline` · «يسمح بالتحميل» ⇒ `attachment` · طلب بلا منحة ⇒ رفض · صفر مسار عام دائم (`SC-010` · `SC-011`)
- [ ] T070 [US4] أعد تسمية `VideoProviderInterface` إلى `MediaProviderInterface` في `backend/app/Modules/Media/Contracts/` وحدّث كل مستعمليه ومزوّده المحلي واسم اختبار العقد (research §R5) — PHPStan يمسك ما فات
- [ ] T071 [US4] وسّع `backend/app/Modules/Media/Actions/RequestUploadTicket.php`: يستقبل `kind` و`role`، ويقابل الحدود بقائمة الصنف، و**يستبدل الأصل القائم فقط عند `role=primary`** — المرفقات كثيرة (research §R11)
- [ ] T072 [US4] وسّع `rejectionReason()` في `backend/app/Modules/Media/Actions/CompleteMediaUpload.php` ليقابل خريطة `kind` بدل القائمة الواحدة، ورسالة الرفض تسمّي الصنف لا «ليس ملف فيديو»
- [ ] T073 [US4] اجعل `stream()` في `backend/app/Modules/Media/Http/Controllers/PlaybackController.php` يقدّم المستند بـ`Content-Disposition` حسب `is_downloadable` — **القرار على الخادم**: إخفاء زرّ في الواجهة ليس منعاً (`FR-036`)
- [ ] T074 [US4] أضف `PUT /media/assets/{asset}/disposition` وActionها ([contracts/api.md](./contracts/api.md) §٢)
- [ ] T075 [US4] اجعل حذف العنصر وأرشفته يُتبعان أصوله ومرفقاته بلا ملف يتيم في `backend/app/Modules/Courses/Actions/DeleteLesson.php` و`ArchiveTreeNode.php` (`FR-038`)
- [ ] T076 [P] [US4] أنشئ `frontend/src/components/courses/editors/DocumentEditor.tsx` — الرفع، ومفتاح العرض/التحميل، **ونصّ صريح** أن حماية «يسمح بالتحميل» هي انتهاء الرابط لا منع النسخ (`FR-037`)
- [ ] T077 [P] [US4] أنشئ `frontend/src/components/courses/AttachmentsPanel.tsx` — مرفقات على أي عنصر مهما كان نوعه (`FR-019`)
- [ ] T078 [P] [US4] أنشئ `frontend/src/components/courses/editors/AudioEditor.tsx` — نفس خط الرفع بصنف `audio`

**Checkpoint**: نصف الأنواع الذي كان غير قابل للتأليف صار يعمل · صفر مسار دائم.

---

## Phase 7: US5 — عناصر الإحالة (Priority: P5)

**Goal**: اختبار الوحدة في مكانه من التسلسل ببوابة يختارها المدرّس؛ والواجب مردود برسالة
صريحة؛ والحصة القادمة تستقبل تسجيلها في موضعها.

**Independent Test**: وضع اختبار في منتصف كورس متسلسل بكل قيمة من قيمتي بوابته، والتحقق من
سلوك الفتح في الحالات الأربع.

- [ ] T079 [P] [US5] اكتب `backend/tests/Feature/Courses/ExamGateTest.php` — الحالات الأربع: (يكفي أن يُحاول × ناجح/راسب) و(يجب أن ينجح × ناجح/راسب)؛ وفي المحجوبة يُعرَض **سبب** الحجب وما يفكّه (`SC-012` · `FR-043`)
- [ ] T080 [P] [US5] اكتب `backend/tests/Feature/Courses/ReferenceIntegrityTest.php`: حذف عنصر الإحالة **لا** يمسّ الاختبار ولا محاولة واحدة عليه (`SC-013`)؛ وحذف الاختبار نفسه يُخفي عنصره من الشجرة بلا صفّ يشير إلى معدوم (`FR-045`)
- [ ] T081 [P] [US5] اكتب `backend/tests/Feature/Courses/AssignmentReservedTest.php`: `type = assignment` ⇒ **422** برسالة تسمّي سبيك 008؛ ويفشل الاختبار إن صار النوع يعمل بلا 008 (research §R12)
- [ ] T082 [US5] أضف `exam_gate` إلى بيانات عنصر الاختبار (يكفي أن يُحاول / يجب أن ينجح) وتحقّقه في `CreateLesson` و`UpdateLesson` (`FR-041`)
- [ ] T083 [US5] اجعل `canAccessLesson()` في `backend/app/Modules/Learning/Models/Enrollment.php` يقرأ بوابة عنصر الاختبار من محاولات الطالب (`FR-042`) — ⚠️ **مسار حرج**: تُشحن مع T079
- [ ] T084 [US5] أضف فلترة «الهدف معدوم» إلى نطاق الشجرة في `backend/app/Modules/Courses/Models/Lesson.php` — الحماية **عند القراءة** لا بمستمع حذف: مستمع يمكن ألا يُسجَّل، وسطر الفلترة يمرّ به كل قارئ بالضرورة (research §R9)
- [ ] T085 [US5] عدّل `backend/app/Modules/LiveSessions/Listeners/PublishRecordingAsLesson.php`: يبحث **أولاً** عن عنصر `live_session` بـ`reference_id` فيحوّله في مكانه (`type → video` · `class_session_id` يُضبط · `reference_id` يُفرَّغ · `chapter_id` و`order` و`uuid` كما هي)، وفي غيابه يبقى سلوك 005؛ و**يضبط `status = published` صراحةً** ([contracts/events.md](./contracts/events.md) §١)
- [ ] T086 [P] [US5] اكتب `backend/tests/Feature/LiveSessions/RecordingPlacementTest.php`: حصة لها عنصر ⇒ **عنصر واحد** في موضع العنصر لا في آخر الشجرة · إعادة الاستيعاب تحدّث الدرس نفسه · التسجيل يصل `published` (`SC-014` · `SC-020`)
- [ ] T087 [P] [US5] أنشئ `frontend/src/components/courses/editors/ExamPicker.tsx` — اختيار اختبار منشور من الكورس ومفتاح بوابته
- [ ] T088 [P] [US5] أنشئ `frontend/src/components/courses/editors/LiveSessionPicker.tsx` — الحصة القادمة، وحالة نهائية مفهومة لحصة مضى موعدها بلا تسجيل (`FR-048`)
- [ ] T089 [US5] أضف `assignment` إلى قائمة الأنواع في `frontend/src/components/courses/LessonEditor.tsx` **معطّلاً برسالته** — لا يظهر كخيار يعمل ثم يفشل صامتاً (`FR-046`)

**Checkpoint**: الاختبار صار عنصراً ببوابة · الواجب محجوز بلا وهم · التسجيل يحلّ في موضعه.

---

## Phase 8: US6 — تحرير كورس مأهول (Priority: P6)

**Goal**: المدرّس يرى أثر ما سينشره على ثلاثين طالباً قبل أن يضغط.

**Independent Test**: نشر دفعة تعديلات على كورس عليه تسجيلات، ومقارنة ما عُرِض قبل النشر بما
وقع فعلاً.

- [ ] T090 [P] [US6] اكتب `backend/tests/Feature/Courses/PublishImpactTest.php`: المعروض يطابق الواقع بعد النشر ١٠٠٪ (`SC-018`)
- [ ] T091 [P] [US6] اكتب `backend/tests/Feature/Courses/ConcurrentEditTest.php`: محرّران على الشجرة نفسها ⇒ **409** بالشجرة المحدَّثة في الردّ، بلا دهس صامت (`FR-009`)
- [ ] T092 [US6] أنشئ `backend/app/Modules/Courses/Actions/PreviewPublishImpact.php` — **نفس حساب النشر** لا تقدير ثانٍ ينحرف عنه (`FR-049`)
- [ ] T093 [US6] أضف `GET /courses/{course}/tree/publish-preview`
- [ ] T094 [US6] افرض فحص `structure_version` في `backend/app/Modules/Courses/Actions/ReorderTreeNodes.php` و`PublishTreeNodes.php` ⇒ 409 بحالة الشجرة الجديدة (`FR-009` · [contracts/api.md](./contracts/api.md) §٥)
- [ ] T095 [US6] أضف تحذيرات الحالات الخطرة في `backend/app/Modules/Courses/Actions/`: حذف درس تسجيل حصة (`FR-053`) · سحب كل عناصر كورس منشور إلى المسودّة (`FR-055`)
- [ ] T096 [US6] امنع تعديل `class_session_id` من أي مسار تأليف — في `backend/app/Modules/Courses/Actions/UpdateLesson.php` و`Http/Requests/UpdateLessonRequest.php` (`FR-054`) — الكاتب يبقى مستمع 005 وحده
- [ ] T097 [P] [US6] أنشئ `frontend/src/components/courses/PublishImpactDialog.tsx` — العناصر المضافة · الطلاب الذين تتغيّر نسبتهم · الدروس التي يتغيّر ترتيب فتحها
- [ ] T098 [US6] عالج 409 في `frontend/src/lib/courses.ts` برسالة عربية عبر `userMessage()` وإعادة بناء الشجرة من الردّ — **يُمنع** خطأ خام على الشاشة

**Checkpoint**: التحرير على شجرة مأهولة صار مرئي الأثر ومحميّاً من الدهس.

---

## Phase 9: Polish & Cross-Cutting

- [ ] T099 حدّث نصّ الحالة الفارغة في `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/page.tsx` من «سطح التأليف قيد الإعداد» إلى **دعوة تقود إلى السطح** (`FR-063`) — السطح لا يُعدّ مكتملاً بلا مدخل إليه
- [ ] T100 [P] أضف زرّ «محتوى الكورس» بجوار «تعديل الكورس» في `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/page.tsx` — مدخل ثانٍ ظاهر لا مخفيّ خلف حالة فارغة
- [ ] T101 [P] أضف `logActivity()` إلى Actions التأليف في `backend/app/Modules/Courses/Actions/` بمن نفّذ ووقته على العنصر (`FR-056`)
- [ ] T102 [P] وسّع `backend/app/Modules/Marketplace/Support/PublicFieldAllowlist.php` وحالة في `PublicExposureTest` — صفر حقل من عنصر مسودّة أو مؤرشف في أي حمولة عامة (`FR-062`)
- [ ] T103 [P] أضف `frontend/e2e/course-authoring.spec.ts`: من صفحة الكورس إلى عنصر منشور — يبدأ من المدخل وينتهي بما يراه الطالب (`SC-016`). يستعمل `useTeacherAccount()` من `e2e/teacher-account.ts`، و**يُمنع** استيراد ملف إعداد من ملف اختبار
- [ ] T104 [P] أضف `/manage/courses/[uuid]/content` إلى قوائم تدقيق RTL وaxe في `frontend/e2e/rtl.spec.ts` — بحساب المدرّس لا الطالب
- [ ] T105 [P] وسّع `backend/database/seeders/ScenarioSeeder.php` بكورس مُؤلَّف كاملاً: قسمان · أربعة فصول · عنصر من كل نوع مدعوم · مستند بكل قيمة من قيمتي المفتاح
- [ ] T106 حدّث `docs/README.md` (الوحدات · نقاط النهاية · الصلاحيات) و`docs/erd.md` (الأعمدة الستّة) — الوثيقة تتحرّك مع الكود
- [ ] T107 حدّث `CLAUDE.md` و`AGENTS.md` معاً بمزالق هذه المرحلة: الترتيب كتابةٌ على حقوق وصول · المقام يستثني التسجيل من بابيه · الترقيم قبل الفهرس · Markdown لا HTML
- [ ] T108 حدّث `docs/roadmap.md` بعلامة **مُنفَّذة** على 016 وتاريخها وجوهر ما نُفِّذ
- [ ] T109 شغّل البوابات الأربع وPlaywright على بناء إنتاج، وسجّل النتائج في [quickstart.md](./quickstart.md) — وأوقف خادم التطوير أولاً: الإعداد يبني للإنتاج و`.next` مشترك

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
