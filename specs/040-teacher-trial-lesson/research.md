# Research: الحصة التجريبية لكل كورس

القرارات مبنية على الكود في 2026-10-09، وعلى مراجعة التصميم بأربعة وكلاء (الأمان، والتعارض، والاستعلامات، ثم إعادة الصياغة لكل كورس). ما راجعتُه بنفسي معلَّم ✔.

## R1 — أين تُحفظ العلامة

- **Decision**: عمود `courses.trial_lesson_id` nullable، FK إلى `lessons.id` مع `nullOnDelete`، باسم قيد صريح (≤ ٦٤ حرفاً). يُكتب بـ`forceFill` داخل الـ Action، **ولا يُضاف إلى `$fillable`**. هذا نمط عمود القرار في المستودع (`UpdateTeacherProfile.php:41-53`)، ويمنع أي كتابة جماعية مستقبلية من تخطّي القاعدة.
- **Rationale**:
  - «واحدة لكل كورس» تتحقّق بالبناء نفسه.
  - الكورس والدرس في **مكان العمل نفسه**، فلا جسر بين أماكن عمل. هذا يُسقط ثلاث ملاحظات من المراجعة كانت كلها في تصميم «لكل مدرّس»:
    - `TeacherProfile` عليه `BelongsToWorkspace` ✔.
    - الوصول إلى الملف من مكان عمل آخر.
    - الإشارة عبر أماكن العمل.
- **حذف الكورس**: `Course` عليه SoftDeletes ✔، فلا تسقط العلامة، بل يخفي القراءةُ الحصة لأن الكورس غير عام. وتعود إن استُرجع الكورس. لا join خام يتخطّى `deleted_at`.
- **حذف الدرس**: `Lesson` بلا SoftDeletes، فـ`nullOnDelete` يعمل فعلاً.
- **الهجرة**: `Schema::table('courses')` و`foreignId(...)->nullable()->constrained('lessons', indexName: …)->nullOnDelete()`. ولـ`down()` `dropConstrainedForeignId` في `Schema::table` منفصل، على سابقة `add_promo_video_to_courses.php:51-69`.

## R2 — من يكتب

- **Decision**: `PUT /courses/{course}/trial-lesson`، في وحدة Courses، مع سياسة جديدة `CoursePolicy::chooseTrialLesson()`.
- **السياسة**: تكرّر شكل `changePricing()`: `belongsToCurrentWorkspace` ثم `decidesCoursePricingIn(workspace)`.
- **Rationale**:
  - المساعد يُرفض (FR-006).
  - المدرّس الذي ترك المكان ليس عضواً، فلا يصل إلى المسار أصلاً. هذا يغلق ملاحظة المراجعة **H1**: `teacherUser()` يرجع إلى المُنشئ المغادر ✔، فلا يُستعمل هنا.
- **Body**: `{ "lesson": "<uuid>" }` للتعليم، و`{ "lesson": null, "replacing": "<uuid>" }` للإلغاء.
- **الإلغاء مشروط**: `UPDATE courses SET trial_lesson_id = NULL WHERE id = ? AND trial_lesson_id = ?`، حتى لا يمسح تبويب قديم اختياراً أحدث (FR-007، ملاحظة المراجعة ٥).
- **التعليم**: `UPDATE` بسيط. آخر من يكتب يربح، ولا قاعدة تنكسر، لأن القراءة تعيد الفحص.
- **التسلسل**: `FormRequest` (`lesson` uuid عبر `WorkspaceRules::exists('lessons','uuid')`) → DTO `SetTrialLessonData` → `Courses\Actions\SetCourseTrialLesson` → `CourseTrialLessonResource`.

## R3 — القاعدة الواحدة، بصيغة SQL

- **Decision**: `Courses\Support\TrialLessonRule` له وجهان، على **تهجئة واحدة** للشروط الثابتة:
  - `refusalFor(Course, Lesson): ?string`: عند التعليم، لسبب عربي.
  - `scopeEligible(Builder<Lesson>)`: قيد SQL يطبّقه كل من يقرأ الحصة. الشروط:
    - `type IN (embed, video)`.
    - `class_session_id IS NULL`.
    - `release_session_id IS NULL`.
    - `NOT EXISTS lesson_cohort_scopes`.
    - `is_high_value = false`.
    - `visibleToStudents()`.
    - للفيديو المرفوع: `whereHas('mediaAsset', kind = video, provider = bunny, status playable)`.
- **ملاحظة**: `refusalFor` يُبنى فوق `scopeEligible` بسؤال `exists()` لكل شرط، فلا نسختان. أو يسأل الأعمدة نفسها بثوابت مشتركة؛ والقرار في المهام، **بشرط اختبار** يثبت أن الوجهين يتفقان على كل حالة رفض.
- **Rationale**: ملاحظتا المراجعة ١ و٢ (الاستعلامات): القاعدة في PHP و`ReadPublicCourse` كانت تكلّف حوالي ٢٠ استعلاماً لكل طلب بثّ. أما القيد فاستعلام واحد بفهارس قائمة: `lesson_cohort_scopes_pair_unique`، و`media_assets_owner_role_index`.
- **Bunny فقط** (ملاحظة الأمان M2): المزوّد المحلي يخدم البايتات من PHP، وكل طلب Range يمرّ بالحدود ويستهلك عمّال PHP-FPM من زوار مجهولين. والأصول المحلية قديمة، فرفعها من جديد أهون من بناء خدمة بايتات عامة.

## R4 — باب الزائر

- **Decision**: مساران في Marketplace، بمفتاح **الكورس** فقط:
  - `GET /marketplace/courses/{courseKey}/trial`: يعيد الحصة نفسها (`uuid`، `title`، `kind`، `duration_seconds?`) مع واحد من اثنين:
    - `embed_url` للمضمَّن، وهذا يغلق ملاحظة التعارض **C1**: المضمَّن غير المعلَّم «مفتوح» كان سيكون رابطاً ميتاً على باب الدرس العام.
    - أو `playback` (وصف التشغيل) للمرفوع.
  - `GET /marketplace/courses/{courseKey}/trial/stream`: `302` إلى رابط Bunny موقَّع، مع `Cache-Control: no-store`.
- **الحلّ**: الكورس عبر `publiclyListed()` (خفيف، **لا** `ReadPublicCourse` الكامل). ثم الدرس عبر `whereKey(course.trial_lesson_id)->where('course_id', course.id)->tap(TrialLessonRule::scopeEligible)->with('mediaAsset')`. من ٢ إلى ٣ استعلامات.
- **كل رفض** ينتهي إلى `NotFoundHttpException('غير متاح')` واحد (L4)، بما فيه مزوّد مجهول.
- **التوقيع**: نضيف `?CarbonImmutable $expiresAt` اختيارياً إلى `PlaybackContext`، ويقرؤه Bunny قبل `grant->expires_at` (ملاحظة الأمان L2). لا `PlaybackGrant` غير محفوظ يتسرّب إلى مزوّد مستقبلي.
- **مدّة الرابط**: إعداد `media.trial_link_ttl_seconds` (افتراضياً ٦٠٠). و`reload_after_seconds` = ثلثا المدة، بحدّ أدنى ٣٠، على نمط `PlaybackGrantResource.php:117-119`.
- **Rationale**: لا معرّف درس في أي مسار، فلا تخمين (FR-010). ولا صفّ تصريح، فلا مساس بمسار الطلاب (FR-014).

## R5 — أين يشاهد الزائر

- **Decision**: صفحة جديدة `/courses/{slug}/trial`.
- **الروابط الواردة إليها**: زرّ صفحة الكورس، وشارة الكارت، وقائمة صفحة المدرّس. وصفحة الدرس العام تبقى كما هي لدروس «مفتوح» المضمَّنة (FR-018 السابق، لا تراجع).
- **الصفحة**: الإطار يُرسم على الخادم (العنوان والكورس)، و**وصف التشغيل يُجلب من المتصفّح** داخل `TrialPlayer`. هذا يغلق ملاحظتي **H1**/**M4**: لو جلبه خادم Next لتشارك كل الزوار مفتاح IP واحداً.
- **Rationale**: رابط واحد ثابت لكل كورس (FR-009). ولا حاجة لحمولة المدرّس في صفحة الدرس (ملاحظة الاستعلامات ٦، وملاحظة التعارض H2: حمولة الكورس لا تحمل الحقل).

## R6 — المشغّل

- **Decision**: نفصل `VideoPlayer` إلى جزأين:
  - `VideoPlayerCore`: المشغّل وhls.js وإعادة تحميل المصدر، **بلا** `Watermark`.
  - `VideoPlayer`: الحالي، يركّب `Core` مع `Watermark` كما اليوم.
  - و`TrialPlayer` يستعمل `Core` وحده.
- **Rationale**: ملاحظتا **C2**/**H2**. اليوم `VideoPlayer` يركّب `Watermark` دائماً ✔ (`:272`)، وهي التي تجدّد التصريح كل `renew_after_seconds` ✔، فالزائر يُوقَف. ولا نضيف «علامة اختيارية» لمسار الطلاب، لأن العلامة هي حارس التجديد. الطلاب يرون السلوك نفسه، ويُثبَت باختباراتهم القائمة دون تعديل.
- **اختبار vitest**: `TrialPlayer` لا يرسل أي طلب `renew` ولا يرسم علامة.

## R7 — الإعدادات والحدود

- **الإعدادات**: `media.trial_link_ttl_seconds` و`media.trial_requests_per_minute` في `PlatformSettings::KEYS` (`Modules/Tenancy/Support/PlatformSettings.php`)، مع قيم افتراضية في `config/media.php`، وحقلين في `ManagePlatformSettings`. لا صفوف seeder، فـ`get()` يرجع إلى config (ملاحظة M1).
- **الحدّ**: limiter `trial-playback` مفتاحه `ip:`، على المسارين، و**بلا** `throttle:api` الجماعي على نمط `/chat-media` ✔ (`Community/routes/api.php:302-313`). وفرع `isOwnServerRender` احتياطاً.
- **الحساب**: المشاهد الواحد يطلب الوصف مرة، والبثّ عند البدء، ثم مرة كل ثلثي المدة. فـ٣٠ في الدقيقة تتّسع لحوالي ١٥ مشاهداً يبدأون معاً خلف IP واحد.

## R8 — صفحة المدرّس والشارات

- **الحمولة**:
  - `coursesOf()` يعيد كورسات المدرّس، ويُضاف إلى كل كارت `has_trial: bool` محسوباً **باستعلام واحد** للقائمة كلها (`whereIn(trial_lesson_id)` + `scopeEligible`)، لا لكل صف.
  - وحمولة المدرّس تحمل `trial_lessons: [{course_slug, course_title, subject, grade_level, lesson_title, kind}]` من الاستعلام نفسه، بدل `trial_lesson` المفرد في #375.
  - وتحديث `PublicFieldAllowlist` و`PublicExposureTest`، وإعادة كتابة اختبارات #375 في `PublicPreviewLessonTest.php` التي تثبت الاختيار التلقائي.
- **قائمة السوق** (`/courses` و`/teachers`): الكارت يحمل `has_trial` بالاستعلام الواحد نفسه.
- **الفيديو التعريفي**: يُقرَّر بـ`videoEmbedUrl(intro_video_url) !== null`، لا بمجرد وجود الرابط. والرابط `?tab=about#intro-video` يعمل من أي تبويب (ملاحظة M5).

## R9 — المحرّر

- **الحمولة**: `LessonResource` في وحدة Courses يحمل `is_trial` (مقارنة واحدة مع `course.trial_lesson_id`)، و`trial_refusal` (سبب عدم الصلاحية أو null). و`CourseResource` يحمل `can_choose_trial` (السياسة) و`trial_status`. كلها على `make()` فقط، لا لكل صف في الشجرة (ملاحظة الاستعلامات «مناطق سليمة»).
- **الواجهة**: مفتاح في `LessonEditor` يظهر حين `can_choose_trial`، وتنبيه في صفحة الكورس حين لا حصة (FR-019).
