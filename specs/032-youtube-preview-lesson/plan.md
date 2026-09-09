# Implementation Plan: الحصّةُ التعريفيّةُ المجّانيّةُ مُستضافةٌ عندَ يوتيوب

**Branch**: `032-youtube-preview-lesson` | **Date**: 2026-09-08 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/032-youtube-preview-lesson/spec.md`

## Summary

نوعُ درسٍ جديدٌ `embed` يحملُ **عنوانَ إطارٍ بنَتْه المنصّةُ بنفسِها** من رابطٍ لصقَه المدرّس،
ويُقصَرُ بنيويّاً على الدرسِ المفتوح (`is_preview` أو `is_free`). ومعَه **بابٌ عامٌّ بلا رمز**
يفتحُ ذلك الدرسَ لزائرٍ لا حسابَ له، لأنّ القياسَ في الشيفرةِ أثبتَ أنّ الحصّةَ التعريفيّةَ
اليومَ لا يراها إلّا من اشترى.

**والمقاربةُ التقنيّةُ تُلخَّصُ في ثلاثِ إعاداتِ استعمالٍ لا ثلاثةِ ابتكارات:**

1. `Courses\Support\PromoVideoUrl` — مُستخرِجُ معرِّفِ يوتيوبَ **القائمُ في الخادم** منذُ ٠١٨.
   الجديدُ `EmbeddedVideoUrl` يستدعيه لِيوتيوبَ ويُضيفُ ذراعَ فيميو، ويُخرِجُ **عنوانَ إطارٍ
   كاملاً**. فلا هجاءَ ثانٍ لِيوتيوبَ **على الخادم** — و`frontend/src/lib/video-embed.ts` هجاءٌ
   ثانٍ قائمٌ سلفاً للفيديو التعريفيِّ للمدرّس، **ويختلفُ فعلاً** (`/live/` مقبولٌ هنا مرفوضٌ
   هناك). مسارُ الدرسِ لا ينادي الواجهةَ إطلاقاً، فالاختلافُ لا يُصيبُه.
2. `Courses\Support\PublishReadiness::assertPublishable()` — يمرُّ منه **النشرُ ومعاينةُ أثرِه**،
   فشرطُ «المفتوح» فيه يحرسُ البابَين بسطرٍ واحد. ⚠️ وليسَ «بابَ كلِّ نشر»: `PublishRecordingAsLesson`
   يكتبُ `status = Published` مباشرةً (لا يُنتِجُ `embed`، فلا خطر)، والـSeeders تكتبُ الصفوفَ
   خامّةً — و`SC-003` تُقاسُ بعدٍّ، فبذرةٌ تُضيفُ درساً مُضمَّناً مقفَلاً تُسقِطُها.
3. `Marketplace` — الحارسُ العامُّ (`publiclyListed()`) وقائمةُ الحقولِ المُصرَّحِ بها
   (`PublicFieldAllowlist`) ومحدِّدُ المعدّلِ (`throttle:public`) كلُّها قائمة. `US2` تُضيفُ
   مساراً واحداً وقراءةً واحدةً داخلَ هذه الحدودِ ولا تكتبُ حارساً ثانياً.

**ولا جدولَ جديداً في هذه الميزة.** عمودٌ واحدٌ على `lessons` وقيمةُ enum واحدة.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13، أحاديّةٌ معياريّة) · TypeScript (Next.js 15 App Router)

**Primary Dependencies**: لا تبعيّةَ جديدة — لا SDK للمستضيفِ ولا مكتبةَ مشغِّل. الإطارُ وسمُ
`<iframe>` عارٍ، وهو قرارُ `FR-016` لا اختصار.

**Storage**: MySQL في الإنتاج · SQLite في الاختبارِ والتطويرِ المحلّي. **هجرتان**: عمودٌ واحدٌ
nullable على `lessons` (`link_reported_at`) بلا فهرسٍ وبلا `->after()`، وهجرةُ backfill تنادي
`seedMissing()` لقالبِ التنبيه. وتمييزُ المُبلِّغينَ في الذاكرةِ المؤقّتةِ لا في عمود — بصمةُ
عنوانٍ مخزَّنةٌ بياناتٌ شخصيّةٌ بعقدٍ كامل. ولا عمودَ جديدَ للرابطِ —
`lessons.external_url` القائمُ يحملُ عنوانَ الإطارِ المبنيَّ عندَنا.

**Testing**: Pest (Feature هي شبكةُ الأمان) · vitest + jsdom للواجهة · لا Playwright في هذه
الميزة: كلُّ ما تُضيفُه مُقاسٌ في الطبقتَين الأرخص.

**Target Platform**: خادمٌ لينكس خلفَ nginx · متصفّحاتُ سطحِ المكتبِ والجوّال

**Project Type**: تطبيقُ وِب — `backend/` + `frontend/`

**Performance Goals**: **صفرُ** استعلاماتٍ إضافيّةٍ على المسارِ الساخن. حقلا `uuid` و
`is_open` في الشجرةِ العامّةِ يُقرآنِ من علاقةٍ مُحمَّلةٍ سلفاً (`curriculumLessons`)، فلا
`N+1` يُخلَق. والبابُ العامُّ للدرسِ قراءةُ صفٍّ واحدٍ بشرطِ انضمامٍ إلى الكورسِ والمدرّس.

**Constraints**: لا نداءَ شبكيَّ إلى المستضيفِ من الخادمِ ولا من المتصفّحِ خارجَ وسمِ الإطار
(`FR-016` · `SC-007`) · لا مساسَ بمسارِ المحتوى المدفوعِ (`FR-015` · `SC-008`) · الحمولةُ
العامّةُ لا تحملُ حقلاً خارجَ `PublicFieldAllowlist`.

**Scale/Scope**: درسٌ مفتوحٌ واحدٌ أو اثنانِ في الكورسِ الواحد؛ الشجرةُ العامّةُ عشراتُ
العناصر. الحملُ المتوقَّعُ على البابِ العامِّ حملُ صفحةِ كورسٍ عامّةٍ نفسِه.

## Constitution Check

*GATE: مرَّت قبلَ Phase 0، وأُعيدَ فحصُها بعدَ Phase 1.*

| المبدأ | الحالة | الدليل |
|---|---|---|
| **I — عزلُ المستأجرين** | ✅ | **لا كيانَ جديدَ يُصنَّف.** التغييرُ عمودٌ واحدٌ على `lessons`، وهي **مملوكةٌ لمساحةِ العمل** وتحملُ `BelongsToWorkspace` منذُ ٠١٦. والبابُ العامُّ قراءةُ زائر، فـ`WorkspaceScope` عديمُ الأثرِ عليه بالتعريف — الحارسُ `publiclyListed()` صراحةً، ويُغطّيه اختبارٌ من مساحتَين. |
| **II — المنطقُ في الـActions** | ✅ | ثلاثةُ Actions جديدة، ولا شرطَ في متحكّم. وشرطُ «المفتوح» في `PublishReadiness` لأنّه يحرسُ **النشرَ ومعاينتَه** بسطرٍ واحد. ⚠️ **لا** لأنّ «Filament والـSeeders يقرؤُونه» — قِيسَ فكانَ خطأً: لا مورِدَ لِدرسٍ في Filament، والـSeeders تكتبُ الصفوفَ مباشرةً. والقنونةُ في الـAction هي ما يُغطّيهما. |
| **III — استقلالُ الوحداتِ بالأحداث** | ⚠️ مُبرَّرٌ للقراءةِ وحدَها | `Marketplace` تقرأُ `Courses\Models\Lesson` منذُ ٠٢٣ في `curriculumShape()` — سابقةٌ لِـ**قراءةٍ داخلَ مورِد**. ⛔ لكنّ `ReportBrokenEmbed` **كتابةٌ** على عمودِ Courses يُمسَحُ من Actionَين في Courses — عمودٌ واحدٌ بكاتبَينِ في وحدتَين. فالـAction تنتقلُ إلى `Courses\Actions\ReportBrokenEmbed`، ويبقى المسارُ العامُّ في `Marketplace` (المسارُ عنوانٌ لا مالك). |
| **IV — البوّاباتُ خضراء** | ✅ | pest · pint · phpstan level 8 · tsc. ولا `@phpstan-ignore` ولا baseline. |
| **V — التفويضُ بالسياساتِ والثوابت** | ✅ | مستقبِلو التنبيهِ يُحدَّدونَ بـ`Permissions::COURSES_UPDATE` ثابتاً لا نصّاً. والبابُ العامُّ بلا صلاحيّةٍ عمداً — وهو ما يجعلُ `FR-009` (جوابٌ واحدٌ لا يُميّز) هو الحارسَ كلَّه. |
| **VI — العقودُ الظاهرةُ مقصودة** | ✅ | `uuid` وحدَه في الحمولات · كلُّ استجابةٍ عبرَ API Resource · `declare(strict_types=1)`. وتوسيعُ `CURRICULUM_ITEM` بحقلَين **قرارُ نشرٍ صريحٌ** يُفشِلُ `PublicExposureTest` حتّى يُكتَب. |

**بندٌ دستوريٌّ يخصُّ سيرَ العمل**: صفرُ `[NEEDS CLARIFICATION]` وصفرُ بنودٍ راسبةٍ في
`checklists/requirements.md` (١٦/١٦) — فالانتقالُ إلى الخطّةِ مسموحٌ به.

## Project Structure

### Documentation (this feature)

```text
specs/032-youtube-preview-lesson/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── public-preview-lesson.md
│   └── embedded-lesson-type.md
├── checklists/requirements.md
└── tasks.md            # ← /speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/
├── Courses/
│   ├── Enums/LessonType.php                      # + case Embed
│   ├── Support/LessonTypeRegistry.php            # + صفُّ embed
│   ├── Support/EmbeddedVideoUrl.php              # جديد — الباني الوحيد
│   ├── Support/PublishReadiness.php              # + شرطُ «المفتوح»
│   ├── Actions/ManageLessons.php                 # قنونةُ الرابط + رفضُ نزعِ العلامة
│   ├── Actions/ChangeLessonType.php              # مسحُ الرابط / رفضُ المدفوع
│   ├── Http/Requests/{Store,Update}LessonRequest.php
│   └── Database/Migrations/…_add_link_reported_at_to_lessons.php
│   ├── Actions/ReportBrokenEmbed.php             # جديد — Courses تملكُ العمود
├── Marketplace/
│   ├── Actions/ReadPublicPreviewLesson.php       # جديد
│   ├── Http/Controllers/PublicMarketplaceController.php
│   ├── Http/Resources/PublicPreviewLessonResource.php   # جديد
│   ├── Http/Resources/PublicCourseDetailResource.php    # + uuid/is_open
│   ├── Support/PublicFieldAllowlist.php          # + PREVIEW_LESSON(_COURSE)، CURRICULUM_ITEM
│   └── routes/api.php                            # مساران داخلَ throttle:public
└── Notifications/Support/NotificationType.php     # + LessonLinkReported

backend/database/seeders/NotificationTemplateSeeder.php  # + قالبٌ + هجرةُ backfill

backend/tests/Feature/
├── Courses/EmbeddedLessonTest.php
├── Marketplace/PublicPreviewLessonTest.php
├── Marketplace/BrokenEmbedReportTest.php
└── (تعديل) Marketplace/{PublicExposureTest,PublicCourseDetailTest,PublicCourseBudgetTest}.php
     ⚠️ تجهيزاتُها الثلاثُ دروسٌ مقفَلة، فالفرعُ الجديدُ لا يُدخَلُ ولا تحمرُّ واحدةٌ منها

frontend/src/
├── components/courses/editors/EmbedEditor.tsx     # جديد — رابطٌ + مدّةٌ + تنبيه
├── components/courses/LessonEditor.tsx            # + الفرعُ الجديد + TYPE_OPTIONS
├── components/marketplace/CourseCurriculum.tsx    # ⛔ الموضعُ الوحيدُ الذي يُصنَعُ فيه الرابط
├── components/player/EmbeddedVideo.tsx            # جديد — بجانبِ VideoPlayer؛ لا مجلّدَ media
├── app/(app)/(shell)/learn/[lesson]/page.tsx      # ⛔ فرعُ embed — بدونِه بطاقةٌ فارغةٌ للطالب
├── app/(public)/courses/[slug]/lessons/[lesson]/page.tsx   # جديد — [slug] لا [uuid]
└── lib/{courses,public-api,labels}.ts             # لا يوجدُ lib/marketplace.ts
```

**Structure Decision**: `backend/` + `frontend/` كما هو المستودَعُ اليومَ. لا وحدةَ جديدة —
النوعُ يخصُّ `Courses` والبابُ العامُّ يخصُّ `Marketplace`، وكلاهما قائم.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|--------------------------------------|
| `Marketplace` تقرأُ نموذجَ `Courses` | البابُ العامُّ للدرسِ توأمُ البابِ العامِّ للكورس، وهو يقرؤُه هكذا منذُ ٠٢٣ | حدثٌ أو واجهةٌ بينَ الوحدتَينِ لقراءةٍ واحدة **تجريدٌ لحاجةٍ متوقَّعةٍ لا قائمة** — وهو ما يمنعُه بندُ «تبريرِ التعقيد» نصّاً |
| `EmbeddedVideoUrl` صنفٌ ثانٍ بجانبِ `PromoVideoUrl` | الأوّلُ يُخرِجُ **معرِّفاً** لِيوتيوبَ وحدَه؛ الجديدُ يُخرِجُ **عنوانَ إطارٍ** لمضيفَين | تعديلُ `PromoVideoUrl` مكانَه يمسُّ مسارَ ٠١٨ المشحونَ وتخزينَه؛ والصنفُ الجديدُ **يستدعيه** فلا يتكرّرُ هجاءُ يوتيوب. ⚠️ وبمضيفٍ واحدٍ يسقطُ الصنفُ كلُّه — **فيميو طلبٌ صريحٌ من المالك، وثمنُه معلَن** |
| توسيعُ الحمولةِ العامّةِ بمعرِّفِ درس | `US2` مستحيلةٌ بدونِه — لا سبيلَ لزائرٍ أن يُشيرَ إلى حصّة | **تعديلٌ معلَنٌ لِـ٠٢٣ · FR-005/SC-004**، مقصورٌ على المُضمَّنِ المفتوحِ وحدَه: لا أصلَ وسائطَ له فلا بايتَ يفتحُه معرِّفُه على نقطةِ التشغيل |
