# Implementation Plan: صفحةُ المادّةِ منهجاً ومجموعات

**Branch**: `021-course-cohort-hub` | **Date**: 2026-08-27 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/021-course-cohort-hub/spec.md`

---

## Summary

`/enrollments/{course}` هي البابُ الوحيدُ إلى كورسٍ اشتراهُ الطالب، وهي اليومَ قائمةٌ مسطَّحةٌ تربطُ **كلَّ** درسٍ بلا استثناء — فالمنعُ يُكتشَفُ بعدَ الضغطِ لا قبلَه، والقرارُ نفسُه موجودٌ على الخادمِ منذ ٠١٦ وحدَه لا يسألُه أحد. تُحوَّلُ الصفحةُ إلى **منهجٍ يحملُ حالةَ كلِّ عنصرٍ وسببَ قفلِه**، فوقَه بنرٌ وتبويباتٌ تجمعُ ما يخصُّ المادّةَ من ستّةِ أماكنَ متفرّقة. ثمّ يُضافُ ما لم يكن موجوداً إطلاقاً: **المجموعة** — تشغيلةٌ مجدولةٌ لمادّةٍ واحدةٍ تملكُ حصصَها وأعضاءَها وخيطَ نقاشِها ونطاقَ تنبيهاتِها، ولا تملكُ محتوًى ولا مالاً.

**القرارُ التقنيُّ الحامل**: العضويّةُ **صفٌّ بجانبَ التسجيلِ لا حقلٌ فيه**، فالانتقالُ إغلاقُ صفٍّ وفتحُ آخَر — و«ولا يتم فقد اي شيئ» يصيرُ نتيجةَ بناءٍ لا وظيفةَ ترحيل. و`enrollments` **لا تتغيّرُ بعمودٍ واحد**.

**أصعبُ ما في المرحلة** ليس المجموعة، بل أنّ المنهجَ يجبُ أن يُجابَ بجملةٍ واحدةٍ **بغيرِ إملاءٍ ثانٍ لقرارِ فتحِ الدرس** — استخراجٌ من `Enrollment::accessTo()` يخدمُ الصيغتين، لا حسابٌ جماعيٌّ مكتوبٌ بجانبِه (R2).

---

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 (backend) · TypeScript 5 · Next.js 15 App Router (frontend)

**Primary Dependencies**: قائمةٌ كلُّها — spatie/permission (وضعُ الفِرَقِ بـ`team_id = workspace_id`) · Sanctum · Horizon · Laravel Reverb · Filament. **صفرُ رزمٍ جديدة، وصفرُ خدماتٍ خارجيّة.**

**Storage**: صفحةُ بياناتٍ واحدةٌ مقسَّمةٌ بـ`workspace_id`. SQLite محلّيّاً وفي الاختبار · MySQL + Redis إنتاجاً.

**Testing**: Pest مع `RefreshDatabase` و`WithWorkspace` · vitest + jsdom للمكوّنات · Playwright للطرفِ للطرفِ والوصول

**Target Platform**: تطبيقُ وِبٍّ عربيٌّ RTL، متصفّحاتُ سطحِ المكتبِ والهاتف

**Project Type**: Web — `backend/` وحدةٌ مونوليثيّةٌ معياريّةٌ ⇄ `frontend/` Next.js

**Performance Goals**: المنهجُ والتبويباتُ والقائمةُ **بعددِ استعلاماتٍ ثابتٍ لا ينمو مع الصفوف** (SC-004 · SC-013) · الرسالةُ اللحظيّةُ دونَ ثانيتين (SC-014)

**Constraints**: عربيٌّ فقط ومن اليمينِ لليسار · محدّداتٌ مُسمّاةٌ حصراً · Larastan L8 بلا خطِّ أساسٍ جديدٍ وبلا `@phpstan-ignore` · **لا `migrate:fresh`** · لا سرَّ في المستودع · **لا مالَ يمرُّ بالمجموعةِ في أيِّ اتّجاه**

**Scale/Scope**: ٥ كياناتٍ جديدة · ٣ جداولَ قائمةٍ تكتسبُ أعمدة · ~١٥ مساراً جديداً و٤ مرشِّحاتٍ على مساراتٍ قائمة · صفحةٌ واحدةٌ مُعادُ بناؤها بـ٨ تبويبات · مكوّنُ `Tabs` مشترك

**NEEDS CLARIFICATION**: **لا شيء.** الخمسةُ التي كانت حُسِمَتْ في `## Clarifications` بتاريخ 2026-08-27.

---

## Constitution Check

*GATE: يمرُّ قبلَ Phase 0 ويُعادُ بعدَ Phase 1.*

| المبدأ | كيف تمرُّ هذه المرحلة | حيثُ يظهرُ الدليل |
|---|---|---|
| **I · عزلُ المستأجرين** (غيرُ قابلٍ للتفاوض) | الجداولُ الخمسةُ كلُّها `BelongsToWorkspace`، والتحقّقُ بـ`WorkspaceRules::exists()` لا `exists:table,id`. ⚠️ **والحارسُ الحقيقيُّ هنا ليس النطاق**: الطالبُ عضوٌ في لا مساحة، فـ`WorkspaceScope` خاملٌ عليه تماماً — كلُّ قراءةِ طالبٍ تُرشَّحُ **بملكيّتِه أو عضويّتِه صراحةً**، ولا يُربَطُ مسارُ طالبٍ بكيانٍ ضمنيّاً. حالةٌ في `WorkspaceIsolationTest` في نفسِ الدفعة | data-model §١–٥ · research R17 |
| **II · المنطقُ في الـ Actions** | `FormRequest → DTO → Action → Resource`. القواعدُ المضبوطةُ على الكيانِ (السعةُ · عضويّةٌ واحدةٌ · طلبٌ واحدٌ · نوعُ الكورس) مفروضةٌ **داخلَ الفعل** — البذرةُ ولوحةُ Filament والمسارُ يمرّون منه | contracts §ج · §د |
| **III · استقلالُ الوحداتِ بالأحداث** | عقدٌ واحدٌ جديد `Shared\Contracts\CohortDirectory` تُنفّذُه `Learning`. `LiveSessions` و`Community` و`Assessments` تسألُه ولا تستوردُ نموذجاً. **لا وحدةَ جديدةً** ⇒ لا قيدَ `phpstan.neon` ولا مخاطرَ حرفِ `M` | research R1 · data-model §ثالثاً |
| **IV · البوّاباتُ خضراء** | `pest` · `pint --test` · `phpstan analyse` (L8، بلا خطِّ أساس) · `npx tsc --noEmit` · `npm test` | quickstart |
| **V · التفويضُ بالسياساتِ والثوابت** | `CohortPolicy` · `CohortTransferRequestPolicy`، والأسماءُ من `Tenancy\Support\Permissions`. تُعادُ الاستفادةُ من `SESSIONS_MANAGE` (الإسنادُ والإنشاء) و`CHAT_MODERATE` (الكتمُ والمنع) و`ATTENDANCE_VIEW` (ما تمنعُه القائمة). ⚠️ **والقائمةُ لا تسألُ سياسةَ الصفّ** — كلُّ شاشةِ قائمةٍ تحملُ قطعَها على الاستعلامِ نفسِه (درسُ `OrderResource`) | data-model · contracts |
| **VI · العقودُ الظاهرةُ مقصودة** | `HasUuid` وكشفُ الـuuid وحدَه · `declare(strict_types=1);` · DTO ترثُ `DataTransferObject` · المرشِّحُ يُطابَقُ عبرَ العلاقةِ بالـuuid فمعرّفٌ مجهولٌ يُطابِقُ لا شيء | contracts §ز |

**الحكم: يمرُّ — بلا مخالفةٍ تحتاجُ تبريراً.** `## Complexity Tracking` محذوفةٌ لذلك.

**إعادةُ التقييمِ بعدَ Phase 1**: ⚠️ بندٌ واحدٌ استحقَّ نظرةً ثانيةً — `cohort_memberships.course_id` **مُكرَّرٌ** مع `cohorts.course_id`. وهو تكرارٌ مقصودٌ لا سهو: الفهرسُ الفريدُ `(student_user_id, course_id, closed_slot)` هو الحارسُ الوحيدُ لـ FR-027، وفهرسٌ لا يستطيعُ حملَ العمودَ بلا انضمامٍ هو فهرسٌ غيرُ موجود. مسجَّلٌ في data-model §٢ بدلَ أن يُكتشَفَ لاحقاً على أنّه تطبيعٌ ناقص.

---

## Project Structure

### Documentation (this feature)

```text
specs/021-course-cohort-hub/
├── plan.md              # هذا الملفّ
├── spec.md              # ٧١ FR · ٢٠ SC · ٥ توضيحات
├── research.md          # Phase 0 — ١٨ قراراً بملفٍّ وسطر
├── data-model.md        # Phase 1
├── contracts/api.md     # Phase 1
├── quickstart.md        # Phase 1
└── checklists/requirements.md
```

### Source Code (repository root)

```text
backend/app/
├── Shared/Contracts/
│   └── CohortDirectory.php                    ← جديد · العقدُ الوحيدُ العابر
├── Modules/Learning/                          ← تملكُ المجموعةَ والعضويّةَ والسجلَّ والطلب
│   ├── Models/{Cohort,CohortMembership,CohortMembershipEvent,CohortTransferRequest}.php
│   ├── Support/
│   │   ├── LessonGate.php                     ← ⚠️ مُستخرَجٌ من Enrollment::accessTo() · الصيغتان
│   │   └── EloquentCohortDirectory.php
│   ├── Actions/{CreateCohort,ArchiveCohort,JoinCohort,RequestTransfer,
│   │            DecideTransferRequest,MoveMember,RemoveMember,
│   │            ReadCurriculum,ReadCohortRoster,ReadCourseAnnouncements}.php
│   ├── Policies/{CohortPolicy,CohortTransferRequestPolicy}.php
│   ├── Http/{Controllers,Requests,Resources}/
│   └── Database/Migrations/                   ← ⚠️ M كبيرة
├── Modules/LiveSessions/
│   ├── …/EloquentSessionAttendanceDirectory.php   ← previousCountableSessionIds() + cohort (R6)
│   ├── …/ClassSessionController.php               ← حجبُ غيرِ المُسنَدِ + مرشِّحُ المجموعة
│   └── Database/Migrations/                       ← cohort_id + الفهرسُ الرباعيّ
├── Modules/Community/
│   ├── Enums/ConversationKind.php                 ← + Cohort
│   ├── Policies/ConversationPolicy.php            ← فرعٌ ثالثٌ في publicRoom() + منعُ الكتابة
│   ├── Models/ConversationWriteBan.php            ← جديد
│   ├── Support/{AnnouncementAudience,WriteBanReader}.php
│   └── Database/Migrations/
├── Modules/Assessments/…                          ← مرشِّحُ course على exams و assignments
└── Modules/Certificates/…                         ← مرشِّحُ course

frontend/src/
├── components/ui/Tabs.tsx                     ← ⚠️ جديد · لا وجودَ له اليوم (R12)
├── components/courses/                        ← جديد
│   ├── CourseBanner.tsx                       ← ⚠️ <img> عاديّة، لا next/image (R11)
│   ├── CurriculumTree.tsx · LessonRow.tsx     ← الحالةُ والقفلُ وسببُه
│   ├── NextSessionHeader.tsx
│   ├── CohortPicker.tsx · CohortSwitcher.tsx
│   └── tabs/{Sessions,Exams,Assignments,Announcements,Certificate,Chat,Roster}Tab.tsx
├── app/(app)/(shell)/enrollments/[course]/page.tsx   ← يُعادُ بناؤها
├── app/(app)/(shell)/manage/courses/[course]/cohorts/  ← شاشةُ المدرّس
└── lib/{cohorts,curriculum}.ts
```

**Structure Decision**: توسعةٌ داخلَ الوحداتِ القائمة، **بلا وحدةٍ جديدة**. المجموعةُ تسكنُ `Learning` بجانبَ `Enrollment` لأنّ السبيكَ نفسَها تصفُ العضويّةَ بأنّها صفٌّ بجانبَه، ولأنّ أسخنَ قراءةٍ في المرحلة — شرطُ العضويّةِ في FR-028أ — تقعُ داخلَ `Enrollment::accessTo()`. بقيّةُ الوحداتِ تسألُ `CohortDirectory` على سابقةِ `EnrollmentDirectory` و`SessionAttendanceDirectory`، وهو ما يطلبُه `AnnouncementAudience` بنصِّه («asked of the DIRECTORIES»).

---

## ترتيبُ التنفيذ

| الموجة | يشحنُ | يعتمدُ على |
|---|---|---|
| **م١ · المنهج** (US1) | `LessonGate` مُستخرَجاً · `GET /courses/{c}/curriculum` · `cover_url` · البنرُ والشجرةُ والحالات · `Tabs` | لا كيانَ جديد |
| **م٢ · التبويبات** (US2) | ٤ مرشِّحاتٍ · الحصّةُ القادمةُ للمادّةِ · تنبيهاتُ الطالبِ · ٧ تبويبات | م١ |
| **م٣ · المجموعات** (US3) | ٤ كياناتٍ · العقدُ · الانضمامُ والطلبُ والموافقةُ والسجلُّ · حجبُ Q3 والإسنادُ الجماعيُّ · إصلاحُ R6 | م١ |
| **م٤ · الشات** (US4) | النوعُ الرابعُ · الفرعُ الثالثُ في السياسةِ · منعُ الكتابةِ · نطاقُ التنبيه | م٣ |
| **م٥ · الزملاء** (US5) | `ReadCohortRoster` + المرتبةُ والنياشين | م٣ |

⚠️ **م١ تشحنُ وحدَها بلا كيانٍ جديدٍ واحد**، وهي التي تُصلِحُ العيبَ القائمَ فعلاً اليوم. و**م٣ لا تُرحِّلُ صفّاً**: كورسٌ بلا مجموعاتٍ يبقى السلوكَ الافتراضيَّ المُختبَر (FR-036 · SC-009).

---

## المخاطرُ الثلاثةُ الوحيدةُ التي تستحقُّ الذكر

1. **⚠️ استخراجُ `LessonGate` هو المهمّةُ الأخطر.** يمسُّ الطريقَ الوحيدَ لفتحِ درسٍ في المنتَجِ كلِّه. الشبكةُ هي اختباراتُ `accessTo` القائمةُ: خضراءُ قبلَ الاستخراجِ وبعدَه بلا تعديلِ حرفٍ فيها، أو الاستخراجُ غيّرَ معنًى. **ولا يُكتَبُ حسابٌ جماعيٌّ بجانبَ الفرديِّ بحالٍ** — تلك هي بعينِها قيدُ السبيكِ المكتوب.
2. **⚠️ حجبُ Q3 يمرُّ فوقَ بياناتٍ حيّة.** الحمايةُ أنّ بابَي الرؤيةِ مفصولان أصلاً في الشجرة: الحجبُ يقعُ على الاكتشافِ (`ClassSessionController@index`) بينما «حصصي» مبنيَّةٌ على حجوزاتِ الطالبِ (`GetStudentSchedule`) وتُترَكُ بلا لمسة، والتسجيلُ مُستحَقٌّ بالمقعدِ لا بالمجموعة. تُحرَسُ بـ SC-011ب وبفحصِ quickstart §٤.
3. **⚠️ الفهرسُ الرباعيُّ يحلُّ محلَّ ثلاثيٍّ يعتمدُ عليه استعلامٌ ساخن.** الفهرسُ يُضافُ **قبلَ** أن يقرأَ أيُّ استعلامٍ العمودَ الجديد، وإسقاطُ عمودٍ مُفهرَسٍ يُسقِطُ فهرسَه في جملةٍ مستقلّةٍ أوّلاً — MySQL يتساهلُ وSQLite يرفض، وكلُّ اختبارٍ هنا يعملُ على SQLite.
