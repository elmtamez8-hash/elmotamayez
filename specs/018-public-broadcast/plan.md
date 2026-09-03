# Implementation Plan: الفيديو الترويجي للكورس (Course Promo Video)

**Branch**: `018-public-broadcast` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/018-public-broadcast/spec.md`

## Summary

كورسٌ منشورٌ يحمل **مُعرِّف فيديو** على قناة مدرّسه، معتمَداً بمراجعةٍ منصّية. وصفحةُ الكورس
العامّة تعرض زرّاً يكشف إطارَ التضمين **عند الضغط لا قبله**، ثمّ يعود الزائر إلى مدخل الحجز
الذي وضعه ٠٢٣.

**الشكل التقني في جملة**: أربعة أعمدة على `courses`، ومستخرِجُ مُعرِّفٍ في الخلفية، ومفتاحٌ
واحد في `PublicCourseDetailResource` وأخوه في `PublicFieldAllowlist`، ومكوّنُ عميلٍ صغير في
صفحةٍ خادميّة قائمة، وإجراءُ مراجعةٍ على `CourseResource` في `/admin`، ومستمعٌ واحد لحدثِ
مغادرة المدرّس القائم.

**لا وحدة جديدة، ولا نموذج، ولا سياسة، ولا جدول، ولا اعتماد خارجيّ، ولا بايت يمرّ بخوادمنا.**

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript (Next.js 15 App Router)

**Primary Dependencies**: لا تبعية جديدة. لا حزمة، لا SDK، لا واجهة برمجية خارجية.

**Storage**: أربعة أعمدة تُضاف إلى `courses` (MySQL في الإنتاج · SQLite محلياً وفي الاختبارات)

**Testing**: Pest (Feature أساساً) · vitest + jsdom لمكوّن الكشف · Playwright لمسار الزائر

**Target Platform**: خادم Linux + متصفّح — والصفحة العامّة تُعرَض لزائرٍ غير مصادَق عليه

**Project Type**: ويب (backend/ + frontend/) — امتدادٌ داخل وحدتين قائمتين، لا وحدة جديدة

**Performance Goals**: صفر طلبٍ إضافيّ على صفحة الكورس (الأعمدة تأتي مع الصفّ المقروء أصلاً)،
وصفر بايت من طرفٍ ثالث قبل ضغط الزرّ

**Constraints**: المسار العامّ بلا عزل مستأجر — `publiclyListed()` هو الحارس الوحيد ·
`PublicFieldAllowlist` يحرس كلّ مفتاح · **يُمنع** وصول نصٍّ من مستخدم إلى `iframe src`

**Scale/Scope**: فيديو واحد لكلّ كورس · ملفّان جديدان في الخلفية وواحدٌ في الواجهة، والباقي
تعديلاتٌ على ملفّات قائمة

## Constitution Check

*GATE: قبل Phase 0، ويُعاد بعد Phase 1.*

| المبدأ | الحكم | كيف يُستوفى |
|---|---|---|
| **I — عزل المستأجرين** | ✅ **لا كيان جديد يُصنَّف** | البيانات أعمدةٌ على `Course`، وهو **مملوك لمساحة العمل** ويستخدم `BelongsToWorkspace` منذ ٠٠٧. فلا طبقةَ ملكيّةٍ جديدة تُعلَن ولا `WorkspaceIsolationTest` جديد — الحالة قائمة. والمسار العامّ يمرّ بـ`publiclyListed()` القائم على `Course` نفسه (‏`publicListingConstraints()` سطر ١٩١)، لا بحارسٍ ثانٍ. |
| **II — المنطق في الـ Actions** | ✅ | `SetCoursePromoVideo` و`ReviewCoursePromoVideo` — إجراءان، كلٌّ بـ`handle()` واحدة. الاستخراج والتحقّق داخل الإجراء **وأيضاً** في `FormRequest`، لأن Filament والبذور تصل الإجراء بلا نموذجِ طلب. |
| **III — استقلال الوحدات** | ✅ | محو الرابط عند مغادرة المدرّس يقع في **مستمعٍ داخل `Courses`** لحدث `Compliance\Events\TeacherOffboardingCompleted` — بجانب `Marketplace\Listeners\UnlistDepartedTeacher` القائم على الحدث نفسه. صفر تعديل في `Compliance`. |
| **IV — البوابات الأربع** | ✅ | لا هجرةَ جدول، فلا مدخلَ جديد في `phpstan.neon`. `PublicExposureTest` و`ContextIsolationTest` يمرّان على السطح الجديد بلا تعديلٍ فيهما. |
| **V — التفويض بالسياسات والثوابت** | ⚠️ **إذنٌ منصّيٌّ جديد** | `Permissions::MARKETPLACE_PROMO_REVIEW`. الكتابةُ من المدرّس تحرسها `CoursePolicy::update()` القائمة (‏يملك الكورس)؛ والاعتمادُ قرارٌ منصّيّ. انظر Complexity Tracking. |
| **VI — العقود الظاهرة** | ✅ | مفتاحٌ واحد في `PublicCourseDetailResource` مكتوبٌ باليد (‏لا `parent::toArray()`)، وتوأمُه في `PublicFieldAllowlist::COURSE_DETAIL`. **يُنشَر مُعرِّفُ الفيديو لا الرابط الملصوق** — والمعرِّف عامٌّ على قناةٍ عامّة، فليس سرّاً ولا معرّفاً تسلسلياً عندنا. |

**نتيجة البوابة: تمرّ.** مخالفةٌ واحدة مبرَّرة أدناه.

## Project Structure

### Documentation (this feature)

```text
specs/018-public-broadcast/
├── plan.md              # هذا الملف
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/
│   └── promo-video.md   # Phase 1 — عقد الحقل العامّ ونقطتا النهاية
└── tasks.md             # /speckit-tasks — لا يُنشئه هذا الأمر
```

### Source Code (repository root)

```text
backend/
├── app/Modules/Courses/
│   ├── Actions/
│   │   ├── SetCoursePromoVideo.php          # جديد — يستخرج المعرّف ويكتبه ويصفّر المراجعة
│   │   └── ReviewCoursePromoVideo.php       # جديد — قبول/رفض بإذنٍ منصّيّ
│   ├── Support/
│   │   └── PromoVideoUrl.php                # جديد — المستخرِج، التهجئة الوحيدة
│   ├── Listeners/
│   │   └── ClearPromoVideoOnOffboarding.php # جديد — مستمعٌ لحدث المغادرة   
│   ├── Http/Requests/UpdateCourseRequest.php        # تعديل — حقل الرابط
│   ├── Http/Controllers/CourseController.php        # تعديل — نقطتا النهاية
│   ├── CoursesServiceProvider.php                   # تعديل — Event::listen سطرٌ واحد
│   ├── Database/Migrations/…add_promo_video_to_courses.php   # جديد
│   └── Models/Course.php                            # تعديل — $fillable و$casts
├── app/Modules/Marketplace/
│   ├── Http/Resources/PublicCourseDetailResource.php # تعديل — مفتاح واحد
│   └── Support/PublicFieldAllowlist.php              # تعديل — مفتاح واحد
├── app/Modules/Tenancy/Support/Permissions.php       # تعديل — ثابتٌ واحد
└── app/Filament/Resources/CourseResource*            # تعديل — عمودٌ وإجراءُ صفّ

frontend/
├── src/components/courses/PromoVideoButton.tsx       # جديد — "use client"، الزرّ والكشف
├── src/components/courses/PromoVideoButton.test.tsx  # جديد — vitest
├── src/app/(public)/courses/[uuid]/page.tsx          # تعديل — سطرُ تركيب
├── src/app/(app)/(shell)/manage/courses/[uuid]/edit/page.tsx  # تعديل — حقل الرابط
└── src/lib/courses.ts                                # تعديل — نوعٌ واحد
```

**Structure Decision**: امتدادٌ داخل `Courses` و`Marketplace` القائمتين. لا وحدة جديدة —
الفيديو **بيانٌ على الكورس** لا كيانٌ يقف بنفسه، ووحدةٌ جديدة تعني مزوّداً ومجلّدَ هجراتٍ
ومدخلاً في `phpstan.neon` مقابل أربعة أعمدة.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| إذنٌ منصّيٌّ جديد `marketplace.promo.review` | الاعتماد قرارٌ يقرّر ما يظهر على صفحةٍ عامّة تحت اسمٍ نزكّيه؛ ولا يجوز لمالك مساحة العمل أن يعتمد فيديو نفسه — وإلّا فالمراجعة اسمٌ بلا مضمون | **إعادة استعمال `MARKETPLACE_TEACHERS_APPROVE`**: يعمل بلا سطرٍ جديد، لكنّ الاسم يكذب — إذنٌ عن اعتماد المدرّسين يحرس اعتماد الفيديوهات، فأوّل من يقرأ قائمة الأذونات يمنح أحدَهما ظانّاً أنه منح الآخر. والإذن مصنَّف منصّياً **بالاشتقاق** (`all()` ناقص ما تحمله الأدوار)، فكلفته ثابتٌ وسطرٌ في اختبار |
| مكوّن عميلٍ في صفحةٍ خادميّة | الزرّ يحتاج حالةً (‏مكشوف/مخفيّ)، والصفحة `async` خادميّة | لا بديل: الكشف بلا حالةٍ يعني تحميل الإطار مع الصفحة، وهو ما يُبطل قرار «صفر بايت من طرفٍ ثالث قبل الضغط» (‏research §R3) |
