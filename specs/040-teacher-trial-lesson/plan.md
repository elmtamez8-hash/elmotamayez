# Implementation Plan: الحصة التجريبية للمدرّس

**Branch**: `040-teacher-trial-lesson` | **Date**: 2026-10-09 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/040-teacher-trial-lesson/spec.md`

## Summary

المدرّس يعلّم درساً واحداً من دروسه «حصتي التجريبية». نحفظ هذا الاختيار **عموداً واحداً على ملفه العام** (`teacher_profiles.trial_lesson_id`). هذا العمود يجعل «حصة واحدة لكل مدرّس» قيداً في البناء نفسه لا في الفحص، فلا يحتاج قفلاً ولا عدّاً.

صلاحية الحصة تُقاس بقاعدة واحدة اسمها `TrialLessonRule`. صفحة المدرّس العامة تسألها قبل عرض الزرّ، والباب يسألها قبل التشغيل. وهي تحلّ محلّ الاختيار التلقائي الذي أضافه #375.

للفيديو المرفوع **باب خاص للزوار** بمساران تحت `/marketplace/teachers/{key}/trial/...`، لا يقبلان معرّف درس أصلاً:
- **وصف التشغيل**: يعطي ما يحتاجه المشغّل، بلا علامة مائية ولا تجديد.
- **البثّ**: على Bunny يحوّل إلى رابط موقَّع صلاحيته ١٠ دقائق. وعلى المزوّد المحلي (التطوير) يخدم البايتات بنفسه.

البابان لا يكتبان صفّ تصريح، ولا يلمسان `PlaybackGrant` ولا `IssuePlaybackGrant` ولا مسار الطلاب. ولهما حدّ طلبات خاص بهما حسب مصدر الطلب (IP).

الواجهة:
- **محرّر الدرس**: مفتاح «اجعل هذا الدرس حصتي التجريبية» يظهر للمدرّس صاحب الملف وحده.
- **صفحة المدرّس**: تختار بين ثلاث حالات: الحصة التجريبية، ثم الفيديو التعريفي، ثم الكورسات.
- **صفحة الدرس العامة**: تشغّل الفيديو المرفوع بمشغّل الطلاب الموجود (`VideoPlayer`)، بوصف تشغيل لا علامة مائية فيه.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13)، TypeScript (Next.js 15 App Router)

**Primary Dependencies**:
- وحدة Marketplace: ملف المدرّس والسوق العام.
- وحدة Courses: الدروس، وقاعدة الجمهور.
- وحدة Media: `MediaProviderResolver` ومزوّدا Bunny والمحلي.

**Storage**: MySQL في الإنتاج وSQLite محلياً. عمود جديد واحد نوعه nullable FK.

**Testing**: Pest (Feature) للخادم، وvitest للواجهة.

**Target Platform**: Linux (Docker)، ومتصفّحات حديثة.

**Project Type**: web (backend + frontend)

**Performance Goals**:
- صفحة المدرّس: استعلام واحد إضافي على الأكثر لقراءة الحصة.
- بدء التشغيل للزائر: أقل من ٣ ثوانٍ (SC-001).

**Constraints**:
- صفر تغيير في مسار تشغيل الطلاب (SC-004).
- الباب لا يخدم إلا الحصة التجريبية الحالية (SC-003).

**Scale/Scope**:
- عشرات المدرّسين اليوم.
- الباب عام ومعرَّض للزحف، ولهذا عليه حدّ طلبات خاص.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| المبدأ | الحكم | كيف |
|---|---|---|
| I. عزل المستأجرين | ✅ | **التصنيف**: `trial_lesson_id` عمود على `teacher_profiles` المملوك للمنصة (ملف السوق العابر لأماكن العمل)، يشير إلى درس مملوك لمكان عمل. هو **جسر قراءة**: يُقرأ في السوق العام فقط، بعد التحقّق أن كورس الدرس عامّ وأن صاحبه هو هذا المدرّس. كل قراءة للدرس من السوق تعلن `withoutWorkspaceScope()` بتعليق واختبار، كما يفعل `ReadPublicPreviewLesson`. لا كيان جديد مملوك لمكان عمل، فلا حالة في `WorkspaceIsolationTest`. وتضاف حالة تؤكّد أن مدرّساً لا يستطيع تعليم درس من مكان عمل لا يدرّس فيه (FR-004) |
| II. المنطق في الـ Actions | ✅ | `SetTrialLesson` فيه كل قواعد FR-002 وFR-004 وFR-006. المتحكّم يتحقّق من الطلب ثم ينادي الـ Action. القاعدة `TrialLessonRule` يستعملها الـ Action وقراءة السوق معاً |
| III. استقلال الوحدات | ⚠️ مُبرَّر | Marketplace تقرأ نماذج Courses وتنادي Media. هذا قائم أصلاً: `ReadPublicPreviewLesson` يقرأ `Lesson`، و`PlaybackController` يستعمل `MediaProviderResolver`. لا Action من وحدة أخرى يُنادى؛ نقرأ نماذج ونستعمل العقد العام `MediaProviderInterface::manifest()` |
| IV. البوابات خضراء | ✅ | pint وphpstan level 8 وtsc وvitest ومجموعة Pest على CI |
| V. التفويض بالسياسات | ✅ | من يعلّم الحصة يقرّره `TeacherProfilePolicy` (أو قاعدة في الـ Action تعيد 403): صاحب الملف وحده (`teacher_profiles.user_id === actor`). لا صلاحية مستأجر تكفي، والمساعد يُرفض (FR-006) |
| VI. العقود الظاهرة مقصودة | ✅ | حقلان جديدان على حمولة عامة (`trial_lesson` يتغيّر شكله، و`trial_playback`)، يُضافان إلى `PublicFieldAllowlist`، و`PublicExposureTest` يحرسهما. ومساران عامّان جديدان يوثَّقان في contracts/ |

## Project Structure

### Documentation (this feature)

```text
specs/040-teacher-trial-lesson/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── api.md
└── tasks.md            # /speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/Marketplace/
├── Database/Migrations/2026_10_09_000100_add_trial_lesson_to_teacher_profiles.php   (جديد)
├── Support/TrialLessonRule.php            (جديد: قاعدة الصلاحية الواحدة)
├── Actions/SetTrialLesson.php             (جديد)
├── Actions/Public/ShowPublicTeacher.php   (trialLessonOf يقرأ العمود عبر القاعدة)
├── Actions/Public/ReadTrialPlayback.php   (جديد: يحلّ الحصة الصالحة + أصلها)
├── Http/Controllers/TrialPlaybackController.php   (جديد: وصف + بثّ)
├── Http/Controllers/TeacherProfileController.php  (+ updateTrialLesson)
├── Http/Requests/UpdateTrialLessonRequest.php      (جديد)
├── Support/PublicFieldAllowlist.php       (+ TRIAL_LESSON بشكله الجديد)
└── routes/api.php                         (+ PUT /teacher/trial-lesson، + مسارا الباب)
backend/app/Modules/Marketplace/Models/TeacherProfile.php  (+ trialLesson relation، fillable)
backend/app/Providers/AppServiceProvider.php               (+ limiter 'trial-playback')
backend/app/Shared/Support/PlatformSettings + seeder       (+ media.trial_link_ttl_seconds، limiter)
backend/tests/Feature/Marketplace/TrialLessonTest.php      (جديد)

frontend/src/
├── lib/public-api.ts                      (TrialLesson بشكله الجديد، trialPlayback)
├── lib/teacher-profile.ts | lib/profile.ts  (setTrialLesson)
├── components/marketplace/TrialCta.tsx    (+ فرع الفيديو التعريفي)
├── components/marketplace/TrialPlayer.tsx (جديد: VideoPlayer بوصف تجريبي)
├── components/courses/editors/LessonEditor.tsx  (+ مفتاح الحصة التجريبية)
└── app/(public)/courses/[slug]/lessons/[lesson]/page.tsx  (يقبل الفيديو المرفوع حين يكون هو الحصة التجريبية)
   أو صفحة جديدة: app/(public)/teachers/[slug]/trial/page.tsx  — يُحسم في research R5
```

**Structure Decision**: نعمل في الوحدات الموجودة نفسها، ولا ننشئ وحدة جديدة. منطق الحصة التجريبية كله في Marketplace، لأن صاحبها ملف السوق.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Marketplace تقرأ `Lesson`/`MediaAsset` وتستعمل `MediaProviderResolver` | الباب العام يحتاج أن يحلّ الدرس وأصله ويوقّع رابطه | حدث + مستمع لا يناسب قراءة متزامنة في طلب HTTP. والسابقة موجودة (`ReadPublicPreviewLesson`، `PublicCourseDetailResource`) |
| مسار بثّ ثانٍ بجانب `/playback/{grant}/stream` | مسار الطلاب يفترض صفّ تصريح مربوطاً بحساب وجلسة (أعمدة NOT NULL، وحارس يقرأ `session->status`) | جعل `user_id`/`auth_session_id` nullable يُضعف ضمان «انتهت الجلسة ⇒ مات التصريح» لكل الطلاب، وهو ما يمنعه FR-014 |
