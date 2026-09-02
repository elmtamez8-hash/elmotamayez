# Implementation Plan: صفحةُ الكورسِ ومجموعاتُه وطلبُ الحصّةِ الخاصّة

**Branch**: `023-course-detail-booking` | **Date**: 2026-09-02 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/023-course-detail-booking/spec.md`

## Summary

ثلاثُ طبقاتٍ فوقَ بعضِها: **منفذٌ عامٌّ لتفاصيلِ الكورس** يقرأُ من حارسِ الإدراجِ العامِّ القائمِ ويُصفّى بقائمةِ الحقولِ المسموحة؛ **قسمُ المجموعاتِ** داخلَ الحمولةِ نفسِها لا في منفذٍ ثانٍ؛ و**دورةُ حياةِ طلبِ حصّةٍ خاصّة** — كيانٌ جديدٌ في طبقةِ الجسر، ينتهي بحجزِ مقعدٍ في حصّةٍ فرديّةٍ داخلَ مجموعةٍ ذاتِ مقعدٍ واحد.

⚠️ **وأهمُّ قرارٍ في هذه الخطّةِ هو ما لم يُبنَ**: الوثيقةُ تقولُ «يُخصَمُ الرصيدُ عندَ القبول»، وقياسُ الشيفرةِ يقولُ إنّ المنصّةَ **لا تخصمُ عندَ الحجزِ أبداً** — `BookSeat` لا يمسُّ رصيداً، والخصمُ يقعُ على `SessionDelivered` عبرَ `ChargeSeatsOnDelivery`. فبناءُ خصمٍ عندَ القبولِ مسارُ مالٍ **ثانٍ** يحتاجُ ردَّه عندَ الإلغاء، ويجعلُ للحصّةِ الواحدةِ مصدرَي قيدٍ يتباعدانِ عندَ أوّلِ عطل. القبولُ يحجزُ المقعدَ، والخصمُ يبقى حيثُ هو لكلِّ حصّةٍ في المنتَج. التفصيلُ في [research.md](./research.md) · قرار ٤.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript 5 (Next.js 15 App Router)

**Primary Dependencies**: لا تبعيّةَ جديدة. المستهلَكُ قائمٌ كلُّه: `IsPubliclyListed` · `PublicFieldAllowlist` · `MarketplaceCache` · `EnrollmentDirectory` · `BookSeat` · `DispatchNotification` · `CreditLedger`

**Storage**: MySQL 8 (الإنتاج) · SQLite في الذاكرة (الاختبارات)

**Testing**: Pest (Feature أوّلاً) · vitest + jsdom للمكوّنات · Playwright للمسارِ الكامل

**Target Platform**: ويب — متصفّحُ الهاتفِ أوّلاً، عربيٌّ RTL حصراً

**Project Type**: Web (backend مُوَحّدٌ نمطيّ + frontend منفصل)

**Performance Goals**: صفحةُ الكورسِ تُصيَّرُ على الخادمِ بميزانيّةِ استعلاماتٍ ثابتةٍ لا تنمو بعددِ الدروسِ ولا المجموعات

**Constraints**: المسارُ العامُّ **بلا عزلِ مستأجرٍ إطلاقاً** — `WorkspaceScope` خاملٌ بلا مستخدمٍ مصادَق. كلُّ قراءةٍ تبدأُ من `publiclyListed()`. ولا مبلغَ ماليٌّ في أيِّ حمولةٍ تصلُ طالباً أو مدرّساً

**Scale/Scope**: ثلاثُ قصصٍ · جدولٌ جديدٌ واحد · عمودانِ على جدولَينِ قائمَين · ٤ منافذَ عامّةٍ/مصادَقة · صفحتانِ في الواجهة

## Constitution Check

*GATE: يُقيَّمُ قبلَ البحثِ ويُعادُ بعدَ التصميم.*

### المبدأ الأوّل — عزلُ المستأجرين

**تصنيفُ كلِّ كيانٍ جديدٍ قرارٌ مُلزِمٌ يُوثَّقُ في المواصفة.** هذه هي التصنيفات:

| الكيان | الطبقة | الحارس |
|---|---|---|
| `private_session_requests` (جديد) | **جسر** | يحملُ `workspace_id` للسياقِ ويشيرُ إلى المستخدمِ العامّ — كـ`Enrollment` والحجزِ والحضورِ تماماً. الحارسُ: ملكيّةُ الصفِّ للطالبِ **أو** كونُ القارئِ مدرّسَ المساحة |
| `cohorts.individual_for_user_id` (عمودٌ جديد) | مملوكٌ لمساحةِ العمل (الجدولُ قائمٌ ويحملُ `BelongsToWorkspace`) | بلا تغيير |
| `courses.private_session_minutes` (عمودٌ جديد) | مملوكٌ لمساحةِ العمل | بلا تغيير |

⚠️ **والجسرُ ليس تصنيفاً مريحاً بين اثنَين**: الطلبُ يُقرأُ من طرفَين — الطالبُ يرى طلباتِه عبرَ كلِّ مدرّسيه، والمدرّسُ يرى الواردَ إليه في مساحتِه. لو صُنِّفَ «مملوكاً للمنصّة» لَما وُجِدَ نطاقٌ يمنعُ مدرّسَ الفيزياءِ من قراءةِ طلبٍ مُرسَلٍ إلى مدرّسِ الرياضيات. ولو صُنِّفَ «مملوكاً لمساحةِ العمل» وحدَه لَما استطاعَ الطالبُ — وهو عضوٌ في **لا مساحةَ إطلاقاً** — أن يقرأَ طلبَه، لأنّ `WorkspaceContext::id()` تُجيبُ `null` له فيُقارَنُ `workspace_id` حقيقيٌّ بـnull.

**اختباراتٌ إلزاميّةٌ في نفسِ الـPR**: حالةٌ في `tests/Feature/Tenancy/WorkspaceIsolationTest.php`، وحالةٌ تُثبِتُ أنّ الطالبَ يقرأُ طلبَه **مع `last_workspace_id = NULL` وسياقٍ مُصفَّر** — وإلّا كان الاختبارُ يقيسُ شخصاً لا وجودَ له في الإنتاج.

**حارسُ رؤيةِ المدرّسِ للطالب**: الطلبُ يُنشَأُ من تسجيلٍ نشطٍ في كورسِ المساحةِ نفسِها (`FR-015`)، فالحارسُ مُستوفىً بالتعريف. ويُسألُ `EnrollmentDirectory` صراحةً في الـAction لا يُستنتَجُ.

**التجاوزُ المتعمّدُ للنطاق**: القراءةُ العامّةُ لا تتجاوزُ نطاقاً — هي تعملُ حيثُ لا نطاق. الحارسُ البديلُ هو `publiclyListed()` وحدَه، ويُغطّيه `PublicExposureTest`.

### المبدأ الثاني — المنطقُ في الـActions

خمسُ Actions جديدة، لا سطرَ منطقٍ في متحكّم. تسلسلُ `FormRequest ← DTO ← Action ← Resource` كاملاً. وقواعدُ العملِ (حدُّ الطلباتِ · المهلة · وقوعُ المدّةِ داخلَ الفترة) **تُفرَضُ في الـAction** لا في التحقّقِ وحدَه — البذورُ ولوحةُ Filament تصلانِ الـAction بلا نموذج.

### المبدأ الثالث — استقلالُ الوحدات

الفعلُ يعبرُ ثلاثَ وحدات: `Marketplace` (القراءةُ العامّة) · `Learning` (الكورسُ والمجموعاتُ والتسجيل) · `LiveSessions` (الحصّةُ والمقعد) · `Payments` (الرصيد). ولا وحدةٌ تستدعي Action وحدةٍ أخرى:

- القراءةُ عبرَ **عقودِ الدليل** القائمةِ (`EnrollmentDirectory`) وعقدٍ جديدٍ تنشرُه `Learning` هو `CohortDirectory` — وهو النمطُ نفسُه الذي تتبعُه `SessionAttendanceDirectory` و`TeacherOffboardingDirectory` اليوم.
- الأثرُ الجانبيُّ عبرَ **حدثٍ ومستمع**: `PrivateSessionRequested` · `PrivateSessionAccepted` · `PrivateSessionRejected`، والإشعاراتُ تُشتَقُّ منها في `Notifications`.
- **لا وحدةَ جديدة**، فلا سطرَ في `phpstan.neon`. والهجراتُ في `Database/Migrations` بحرفٍ كبير.

### المبدأ الرابع — البوّاباتُ خضراء

`pest` · `pint --test` · `phpstan` (level 8) · `tsc --noEmit`. والمساراتُ الحرجةُ الثمانيةُ لا يمسُّها هذا العمل إلّا في واحد: **التسجيلُ وتقييدُ الدروس** — لأنّ `FR-019ب` يُدخِلُ تسجيلَ حصّةٍ خاصّةٍ في شجرةِ الكورس. `ProgressDenominatorTest` القائمُ هو البوّابة.

### المبدأ الخامس — التفويضُ بالسياسات

`PrivateSessionRequestPolicy` جديدة. الأذونُ من ثوابتِ `Permissions` — والقبولُ/الرفضُ يقعانِ تحتَ `SESSIONS_MANAGE` القائم، فلا إذنَ جديدَ ما لم تُثبِتِ المراجعةُ الحاجة. ⚠️ **ولا يكفي أن تُكتَبَ السياسة**: طريقةٌ لا يستدعيها سطحُ HTTP طريقةٌ لم تُختَبَرْ قطّ، والاختبارُ يمشي **اتّجاهَ السماحِ** كما يمشي اتّجاهَ المنع — فمُخمِّنُ لارافيل يفشلُ **مفتوحاً** حين لا تُسجَّلُ السياسة.

### المبدأ السادس — العقودُ الظاهرة

`uuid` وحدَه في كلِّ مسارٍ وكلِّ حمولة. كلُّ ردٍّ عبرَ API Resource. `declare(strict_types=1)` في كلِّ ملفّ. DTOs بخصائصَ `readonly`.

**النتيجة: البوّابةُ تمرُّ.** لا مخالفةَ تحتاجُ تبريراً — انظرْ «Complexity Tracking» أدناه: فارغٌ عن قصد.

## Project Structure

### Documentation (this feature)

```text
specs/023-course-detail-booking/
├── plan.md              # هذا الملفّ
├── research.md          # المرحلة ٠ — سبعةُ قرارات
├── data-model.md        # المرحلة ١ — الكياناتُ والانتقالات
├── quickstart.md        # المرحلة ١ — كيف يُثبَتُ أنّها تعمل
├── contracts/           # المرحلة ١ — عقودُ المنافذ
│   ├── public-course.md
│   └── private-session-requests.md
├── checklists/
│   └── requirements.md
└── tasks.md             # لاحقاً — /speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/
├── Marketplace/
│   ├── Actions/Public/ReadPublicCourse.php          # جديد
│   ├── Http/Controllers/PublicMarketplaceController.php   # + course()
│   ├── Http/Resources/PublicCourseResource.php      # جديد
│   ├── Http/Resources/PublicCohortResource.php      # جديد
│   ├── Support/PublicFieldAllowlist.php             # + COURSE_DETAIL · COHORT
│   └── routes/api.php                               # + GET /marketplace/courses/{uuid}  ← uuid لا slug
├── Learning/                                        # المجموعاتُ تسكنُ هنا
│   ├── Contracts/CohortDirectory.php                # جديد — عقدُ الدليل
│   ├── Support/EloquentCohortDirectory.php          # جديد
│   ├── Models/Cohort.php                            # + individual_for_user_id
│   └── Database/Migrations/…_add_individual_cohort.php
├── Courses/                                         # ⚠️ الكورسُ يسكنُ هنا لا في Learning
│   └── Models/Course.php                            # + private_session_minutes
└── LiveSessions/
    ├── Actions/RequestPrivateSession.php            # جديد
    ├── Actions/DecidePrivateSessionRequest.php      # جديد
    ├── Actions/WithdrawPrivateSessionRequest.php    # جديد
    ├── Jobs/ExpirePrivateSessionRequestsJob.php     # جديد
    ├── Models/PrivateSessionRequest.php             # جديد
    ├── Policies/PrivateSessionRequestPolicy.php     # جديد
    ├── Events/PrivateSession{Requested,Accepted,Rejected}.php
    └── Database/Migrations/…_create_private_session_requests.php

frontend/src/
├── app/(public)/courses/[slug]/page.tsx             # جديد
├── components/marketplace/CourseCard.tsx            # الرابطُ يتغيّر
├── components/marketplace/CohortList.tsx            # جديد
├── components/marketplace/CourseCurriculum.tsx      # جديد
└── components/courses/PrivateSessionRequestForm.tsx # جديد
```

**Structure Decision**: البنيةُ القائمةُ بلا وحدةٍ جديدة. القراءةُ العامّةُ تسكنُ `Marketplace` حيثُ يسكنُ كلُّ ما يُنشَرُ للزائر، والطلبُ يسكنُ `LiveSessions` لأنّ ناتجَه حصّةٌ ومقعد. `Learning` تنشرُ عقدَ دليلٍ للمجموعاتِ ولا تُستدعى أفعالُها من الخارج.

## Complexity Tracking

> فارغٌ عن قصد: البوّابةُ الدستوريّةُ تمرُّ بلا مخالفةٍ تحتاجُ تبريراً.

القرارُ الوحيدُ الذي **يبدو** مخالفةً وليس منها هو `cohorts.individual_for_user_id` — عمودٌ قابلٌ لـNULL يحملُ فهرساً فريداً مركّباً. وهذا هو المطلوبُ بالضبط: `NULL ≠ NULL` على المحرّكَين، فمجموعاتُ الكورسِ الجماعيّةُ تتعايشُ بحرّيّةٍ بينما لا يملكُ طالبٌ مجموعتَينِ فرديّتَينِ في كورسٍ واحد. هو نفسُ اصطلاحِ `captured_order_id`، ويُقرَأُ عكسَ قاعدةِ «فهرسٌ فريدٌ يحملُ عموداً قابلاً لـNULL لا يعضّ» — تلك القاعدةُ عن فهرسٍ **أُريدَ** له أن يعضَّ على الصفوفِ الشائعة، وهذا عكسُه.
