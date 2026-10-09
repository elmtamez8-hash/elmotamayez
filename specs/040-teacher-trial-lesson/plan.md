# Implementation Plan: الحصة التجريبية لكل كورس

**Branch**: `040-teacher-trial-lesson` | **Date**: 2026-10-09 (revised after 4-agent review) | **Spec**: [spec.md](./spec.md)

## Summary

- **التخزين**: عمود `courses.trial_lesson_id` يحفظ حصة تجريبية واحدة لكل كورس.
- **من يكتبه**: من يقرّر سعر الكورس (`CoursePolicy::chooseTrialLesson`)، عبر `SetCourseTrialLesson`.
- **قاعدة الصلاحية**: واحدة اسمها `TrialLessonRule`، وتُطبَّق قيدَ SQL.
- **السوق**: يقرأ القاعدة باستعلام واحد للقائمة كلها، فيعرض شارة على الكارت وزرّاً في صفحة الكورس، وفي صفحة المدرّس زرّاً أو قائمة.
- **باب الزائر**: مسار بمفتاح الكورس فقط. يعيد رابط التضمين، أو وصف تشغيل للفيديو المرفوع (Bunny وحده)، وبثّه 302 إلى رابط موقَّع قصير العمر.
- **صفحة المشاهدة**: صفحة عامة جديدة `/courses/{slug}/trial` تشغّل الفيديو بـ`TrialPlayer`، وهو نواة مشغّل الطلاب بلا العلامة المائية.
- **مسار الطلاب**: لا يتغيّر.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13)، TypeScript (Next.js 15)

**Primary Dependencies**:
- **Courses**: الكورس والدرس والسياسة والمحرّر.
- **Marketplace**: الحمولات العامة والباب.
- **Media**: `MediaProviderResolver` و`PlaybackContext` و`BunnyMediaProvider`.

**Storage**: عمود nullable FK على `courses`.

**Testing**: Pest (Feature)، وvitest.

**Project Type**: web.

**Performance Goals**:
- لا N+1 في القوائم: استعلام واحد لـ`has_trial` لكل صفحة قائمة.
- الباب: ٢ إلى ٣ استعلامات بالمفتاح.

**Constraints**:
- SC-003: الباب لا يخدم إلا الحصة الحالية.
- SC-004: اختبارات الطلاب لا تتغيّر.

**Scale/Scope**: الباب عام ومعرَّض للزحف، وله حدّه الخاص.

## Constitution Check

| المبدأ | الحكم | كيف |
|---|---|---|
| I. عزل المستأجرين | ✅ | عمود على `courses` المملوك لمكان العمل، يشير إلى درس من الكورس نفسه، فلا جسر عبر أماكن العمل. السوق يقرأ الدرس بـ`withoutWorkspaceScope()` معلَّلاً ومختبَراً، كما يفعل الباب العام اليوم. يُضاف اختبار رفض لدرس من كورس آخر ولو في مكان عمل آخر. لا كيان جديد |
| II. المنطق في الـ Actions | ✅ | `FormRequest` (`WorkspaceRules::exists`) → DTO `SetTrialLessonData` → `SetCourseTrialLesson` → `CourseTrialLessonResource`. وحلّ الباب في Action `ReadCourseTrial`، وموارده `PublicCourseTrialResource` |
| III. استقلال الوحدات | ⚠️ مُبرَّر | Marketplace تقرأ نماذج Courses وتنادي `MediaProviderInterface::manifest()`. السابقة قائمة (`ReadPublicPreviewLesson` و`PublicCourseDetailResource`). القاعدة في Courses، والسوق يستعملها قراءةً فقط |
| IV. البوابات | ✅ | pint، وphpstan 8، وtsc، وvitest، وPest على CI |
| V. التفويض | ✅ | `CoursePolicy::chooseTrialLesson` على نمط `changePricing`، ولا صلاحية جديدة. المساعد يُرفض بالسياسة، والمغادر ليس عضواً |
| VI. العقود الظاهرة | ✅ | `has_trial`، و`trial`، و`trial_lessons`، وحمولة الباب، كلها في `PublicFieldAllowlist`، و`PublicExposureTest` يحرسها |

## Project Structure

```text
backend/app/Modules/Courses/
├── Database/Migrations/2026_10_09_000100_add_trial_lesson_to_courses.php
├── Support/TrialLessonRule.php
├── Data/SetTrialLessonData.php
├── Actions/SetCourseTrialLesson.php
├── Http/Requests/SetCourseTrialLessonRequest.php
├── Http/Controllers/CourseTrialLessonController.php
├── Http/Resources/CourseTrialLessonResource.php
├── Http/Resources/{CourseResource,LessonResource}.php      (+ الحقول)
├── Policies/CoursePolicy.php                                (+ chooseTrialLesson)
├── Models/Course.php                                        (+ trialLesson)
└── routes/api.php                                           (+ PUT)
backend/app/Modules/Marketplace/
├── Actions/Public/ReadCourseTrial.php
├── Actions/Public/ShowPublicTeacher.php     (trial_lessons + has_trial؛ يُحذف الاختيار التلقائي)
├── Http/Controllers/PublicCourseTrialController.php
├── Http/Resources/{PublicCourseTrialResource,PublicCourseCardResource,PublicCourseDetailResource}.php
├── Support/PublicFieldAllowlist.php
└── routes/api.php                           (+ المساران، بلا throttle:api)
backend/app/Modules/Media/Data/PlaybackContext.php           (+ ?expiresAt)
backend/app/Modules/Media/Providers/BunnyMediaProvider.php   (يقرأ expiresAt إن وُجد)
backend/app/Modules/Tenancy/Support/PlatformSettings.php     (+ مفتاحان)
backend/config/media.php                                     (+ قيم افتراضية)
backend/app/Providers/AppServiceProvider.php                 (+ trial-playback)
backend/app/Filament/.../ManagePlatformSettings.php          (+ حقلان)
backend/tests/Feature/Courses/CourseTrialLessonTest.php
backend/tests/Feature/Marketplace/CourseTrialDoorTest.php
backend/tests/Feature/Marketplace/PublicPreviewLessonTest.php  (إعادة كتابة اختبارات #375)

frontend/src/
├── components/player/VideoPlayerCore.tsx   (جديد: ما في VideoPlayer بلا Watermark)
├── components/player/VideoPlayer.tsx       (يركّب Core + Watermark؛ السلوك كما هو)
├── components/marketplace/TrialPlayer.tsx  (جديد)
├── components/marketplace/TrialCta.tsx     (حصة/قائمة/فيديو تعريفي/كورسات)
├── components/marketplace/CourseCard.tsx   (+ شارة)
├── app/(public)/courses/[slug]/trial/page.tsx  (جديد)
├── app/(public)/courses/[slug]/page.tsx    (+ زرّ)
├── app/(public)/teachers/[slug]/page.tsx   (+ id="intro-video"، trial_lessons)
├── components/courses/editors/LessonEditor.tsx  (+ المفتاح)
├── app/(app)/(shell)/manage/courses/[uuid]/page.tsx  (+ تنبيه FR-019)
└── lib/{public-api,courses}.ts
```

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Marketplace تقرأ `Lesson`/`MediaAsset` وتنادي المزوّد | الباب يحلّ الدرس ويوقّع رابطه في طلب متزامن | لا حدث يناسب قراءة HTTP، والسابقة قائمة |
| مسار بثّ ثانٍ | مسار الطلاب يفترض تصريحاً بحساب وجلسة | جعل الأعمدة nullable يُضعف ضمانات كل الطلاب (FR-014) |
| فصل `VideoPlayerCore` | الزائر لا يملك تصريحاً يجدّده | علامة مائية اختيارية تُضعف حارس التجديد في مسار الطلاب |
