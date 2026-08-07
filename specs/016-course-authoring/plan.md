# Implementation Plan: سطح تأليف الكورسات (Course Authoring Surface)

**Branch**: `016-course-authoring` | **Date**: 2026-08-08 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/016-course-authoring/spec.md`

## Summary

عشر نقاط نهاية لبنية محتوى الكورس قائمة منذ الهجرة الأولى وبلا مستدعٍ واحد من الواجهة. هذا
السبيك يصلها، ويوسّع أنواع العناصر من أربعة إلى عشرة في أربع عائلات، ويفتح خط الرفع للمستندات
والصوت.

**والعمل الحقيقي ليس في الإنشاء بل في التحرير**: الشجرة يكتب فيها ثلاثة أطراف معاً — المدرّس،
و`PublishRecordingAsLesson` بعد كل حصة، وطلاب يقفون داخلها الآن تُشتقّ نسبتهم من عددها ويُشتقّ
ما يُفتح لهم من `order`. فإعادة الترتيب **كتابة على حقوق وصول**، وإضافة درس **إنقاصُ نسبة كل
طالب**، وحذف درس **إتلافُ تقدّم مُسجَّل**.

**صفر جدول جديد وصفر حدث جديد**: كل ما يلزم أعمدة على خمسة جداول قائمة، وتغييرٌ على مستمع
واحد. وأثقل ما في التنفيذ ليس الميزة بل **أربع مخالفات قائمة** يضاعفها السطح إن شُحن فوقها:
الأقسام والفصول بلا uuid، والمنطق في المتحكّمات، و`Rule::exists` خام على جدولين تابعين
للمستأجر، و`order` يتعادل على صفر.

**وعطل صامت يصلحه هذا السبيك بالضرورة**: درس تسجيل الحصة في مقام نسبة التقدّم بينما استحقاقه
بالمقعد — فطالب بلا مقعد لا يبلغ ١٠٠٪ ولا تصدر شهادته أبداً (`FR-026أ`).

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 (أحادية معيارية) · TypeScript / Next.js 15 App Router

**Primary Dependencies**: **لا تبعية جديدة، خلفيةً ولا واجهة.** النصّ المنسَّق Markdown
يُصيَّر بـ`league/commonmark` المثبّت أصلاً كتبعية للإطار (R7)، وإعادة الترتيب بأزرار لا
بمكتبة سحب (R8).

**Storage**: MySQL إنتاجاً · SQLite محلياً وفي الاختبارات. **أعمدة على جداول قائمة فقط**
(`data-model.md`) — وهجرتان منها تُعيدان ترقيم بيانات قائمة، فتُتحقَّقان بـ`migrate` فوق قاعدة
قائمة لا بـ`migrate:fresh`.

**Testing**: Pest — `tests/Feature/Courses/` شبكة الأمان، مع حالات في
`tests/Feature/Learning/` لأن التصحيح يمسّ مسارين حرجين. وPlaywright لمسار التأليف الكامل.

**Target Platform**: خادم Linux · متصفّح

**Project Type**: تطبيق ويب (خلفية + واجهة)

**Performance Goals**: عدد استعلامات جلب الشجرة **ثابت** لا يتحرّك بعدد الأقسام أو الفصول أو
الدروس (`FR-010` · `SC-015`). وإعادة الترتيب كتابة واحدة لكل مستوى لا كتابة لكل عنصر.

**Constraints**: صفر شقيقين بترتيب واحد · صفر عنصر مسودّة في حمولة يراها طالب · صفر مسار عام
دائم إلى أصل · صفر حذف نهائي لدرس عليه تقدّم · صفر حالة إتمام مسحوبة أو شهادة مُبطَلة.

**Scale/Scope**: توسعة وحدة `Courses` القائمة (~١١ Action · ٦ أعمدة جديدة على ٥ جداول · ٦
نقاط نهاية جديدة) + توسعة `Media` (`kind` · `role` · `is_downloadable`) + شاشة تأليف واحدة
في الواجهة و`lib/courses.ts`.

## Constitution Check

*بوابة: تُفحص قبل البحث، وتُعاد بعد التصميم.*

| المبدأ | الحالة | كيف |
|---|---|---|
| **I — عزل المستأجرين** | ✅ | كل الكيانات **مملوكة لمساحة العمل** ومُصنَّفة صراحةً في `spec.md › Q6` و`data-model.md`. ولا كيان مملوكاً للمنصة ولا جسراً، فحارس رؤية المدرّس للطالب لا موضع له. **ويصحّح مخالفة قائمة**: `Rule::exists` خام على `course_sections` و`course_chapters` يصير `WorkspaceRules::exists()` (R14) |
| **II — المنطق في Actions** | ⚠️→✅ | **مخالف اليوم**: `LessonController::store()` يكتب `$course->lessons()->create(...)` مباشرةً. يُنقل إلى Actions، وقواعد هذا السبيك (الترتيب الكثيف · منع الحذف · سريان النشر) تُفرض فيها لأنها المدخل المشترك مع البذور ولوحة Filament (R14) |
| **III — التكامل بالأحداث** | ✅ | صفر حدث جديد **مُبرَّراً** (`contracts/events.md`): الثلاث قراءات العابرة متزامنة، والحدث لا يجيب عن سؤال متزامن. ولا وحدة جديدة، فلا إضافة إلى `phpstan.neon` |
| **IV — البوابات الأربع** | ✅ | والمسارَان الحرجان اللذان يمسّهما السبيك مسمَّيان: **تقييد الدروس** و**إتمام الكورس** |
| **V — الصلاحيات من الثوابت** | ✅ | **صفر صلاحية جديدة**: `LESSONS_MANAGE` · `LESSONS_DELETE` · `COURSES_PUBLISH` قائمة وتكفي |
| **VI — العقود الظاهرة** | ⚠️→✅ | **مخالف اليوم**: `Section` و`Chapter` بلا `HasUuid`، والـAPI يستقبل `section_id`/`chapter_id` تسلسليَّين. يُصحَّح بهجرة uuid وربط بالمسار عليه (R1) |

**نتيجة إعادة الفحص بعد التصميم**: **لا مخالفة جديدة**. والمخالفتان أعلاه **قائمتان قبل هذا
السبيك** ويصحّحهما — لا يُدخلهما. `Complexity Tracking` فارغ.

**تجريد واحد جديد، وكلفته معلنة**: إعادة تسمية `VideoProviderInterface` إلى
`MediaProviderInterface` (R5). ليست تجميلاً: العقد سيخزّن مذكّرات PDF وتسجيلات صوتية، وعقد
اسمه Video يفعل ذلك هو نفس صنف العطل الذي جعل `ClassSession` لا تُسمّى `Session` — يُقرأ خطأً
مرة واحدة لكل قارئ. الكلفة ~١٠ ملفات داخل وحدة واحدة، وPHPStan يمسك ما فات.

**وثلاثة أشياء لم تُبنَ لأن لا مشكلة قائمة تستدعيها**: حدث `CourseStructureChanged` (بلا
مستمع)، وجدول `lesson_attachments` (عمود `role` يكفي)، ومُنقّي HTML (اختيار Markdown يلغي
الحاجة).

## Project Structure

### Documentation (this feature)

```text
specs/016-course-authoring/
├── plan.md              # هذا الملف
├── research.md          # ١٤ قراراً، كلها مُتحقَّق منها في الكود بسطر مرجعي
├── data-model.md        # صفر جدول جديد · ٦ أعمدة · ١٠ أنواع في ٤ عائلات · انتقالات الحالة
├── quickstart.md        # ١١ سيناريو تحقّق
├── contracts/
│   ├── api.md           # المسارات · قائمة الحقول المصرّح بها · رموز الردّ
│   └── events.md        # صفر حدث جديد، ولماذا
├── checklists/requirements.md
└── tasks.md             # ناتج /speckit-tasks — لا يُنشئه هذا الأمر
```

### Source Code (repository root)

```text
backend/app/Modules/Courses/
├── Actions/
│   ├── CreateSection.php · UpdateSection.php · DeleteSection.php
│   ├── CreateChapter.php · UpdateChapter.php · DeleteChapter.php
│   ├── CreateLesson.php  · UpdateLesson.php  · DeleteLesson.php   # الحذف يرفض ما عليه تقدّم
│   ├── ReorderTreeNodes.php        # قائمة uuid كاملة · معاملة واحدة · فحص النسخة
│   ├── PublishTreeNodes.php        # دفعة · سريان بالسلسلة
│   └── PreviewPublishImpact.php    # نفس حساب النشر — لا تقدير ثانٍ ينحرف
├── Database/Migrations/            # M كبيرة · ٥ هجرات (uuid · status · order · reference · version)
├── Enums/
│   ├── LessonType.php              # يتّسع من ٤ إلى ١٠ قيم
│   └── ContentStatus.php           # draft · published · archived
├── Http/{Controllers,Requests,Resources}/
│   └── Resources/CourseTreeResource.php   # شجرة المؤلّف — منفصلة عن الطلابية عمداً
├── Models/                         # Section · Chapter يكتسبان HasUuid
├── Policies/
└── Support/
    ├── LessonTypeRegistry.php      # العائلة · قابلية الإتمام · الحقول المطلوبة لكل نوع
    └── MarkdownRenderer.php        # commonmark بتجريد HTML الخام — لا تخزين لـHTML

backend/app/Modules/Media/
├── Contracts/MediaProviderInterface.php   # ← إعادة تسمية VideoProviderInterface
├── Enums/MediaKind.php · MediaRole.php
└── Actions/RequestUploadTicket.php        # يقبل kind و role — لا الفيديو وحده

backend/app/Modules/Learning/            # تصحيح على مسارين حرجين — مع اختباره
├── Actions/MarkLessonComplete.php        # المقام: المنشور القابل للإتمام غير التسجيلي
└── Models/Enrollment.php                 # التسلسل يتخطّى المسودّة والمؤرشف

backend/app/Modules/LiveSessions/Listeners/PublishRecordingAsLesson.php
                                          # يحلّ محلّ عنصر الحصة إن وُجد · يضبط status صراحةً

backend/tests/Feature/Courses/            # + حالات في Learning/ و Tenancy/
frontend/src/
├── app/(app)/(shell)/manage/courses/[uuid]/content/page.tsx   # سطح التأليف
├── components/courses/                   # TreeOutline · LessonEditor لكل عائلة · MoveControls
└── lib/courses.ts                        # الاستدعاءات المتناثرة تُجمَع هنا
```

**Structure Decision**: **توسعة `Courses` القائمة، لا وحدة جديدة.** الجداول والنماذج
والسياسات والمسارات كلها هناك، ووحدة `Authoring` منفصلة تعني نموذجين لشيء واحد أو وحدة بلا
نماذج تستدعي أخرى — وكلاهما يخالف المبدأ الثالث لحلّ مشكلة تنظيمية غير قائمة.

## Complexity Tracking

> لا مخالفة دستورية تستدعي تبريراً. المخالفتان المرصودتان (II و VI) **قائمتان في الكود قبل
> هذا السبيك**، وهو يصحّحهما — R1 و R14.

## ترتيب التنفيذ المقترح

الترتيب محكوم بالخطر لا بالحجم: **ما يمسّ الطلاب الحاليين أولاً وبأثر صفر، ثم ما يضيف**.

| # | الدفعة | لماذا هنا |
|---|---|---|
| ١ | uuid للأقسام والفصول · نقل المنطق إلى Actions · `WorkspaceRules` | تصحيح مخالفات قائمة **قبل** بناء سطح يضاعفها |
| ٢ | `status` + الترتيب الكثيف + هجرات الترحيل | البنية التي تجعل التأليف على كورس حيّ آمناً. **الترقيم قبل الفهرس الفريد وفي هجرة واحدة** — التعادل قائم اليوم فعلاً (`data-model.md`) |
| ٣ | تصحيح المقام والتسلسل: `FR-026أ` **و**`FR-027أ` | **مساران حرجان** — أصغر دفعة ممكنة ومع اختبارها. **شقّان لا شقّ واحد**: استبعاد التسجيل من المقام وحده يترك العطل نفسه عائداً من باب الترتيب |
| ٤ | شاشة الشجرة + الترتيب بالأزرار + المسودّة/النشر | US1 و US3 — أول قيمة يراها المدرّس |
| ٥ | محرّرات الأنواع المكتوبة والمرفوعة + `kind`/`role`/التصرّف | US2 و US4 |
| ٦ | عناصر الإحالة: الاختبار وبوابته · `assignment` المردود · الحصة القادمة | US5 |
| ٧ | معاينة الأثر + النسخة + التحذيرات + الرابط الداخل من صفحة الكورس | US6 و `FR-063` |

**الدفعة ٣ هي الأخطر**: تعديل على `MarkLessonComplete` و`canAccessLesson` يمسّ اثنين من
المسارات الحرجة الثمانية. تُشحن وحدها، ومع اختبارها، ولا تُدمج مع دفعة أخرى.
