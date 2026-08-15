# Implementation Plan: بنك الأسئلة والتقييم التحليلي

**Branch**: `008-question-bank-analytics` | **Date**: 2026-08-15 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/008-question-bank-analytics/spec.md`

---

## Summary

**تحويل الاختبارات من أداة تقييم إلى أداة تشخيص، بلا لمس محاولةٍ واحدة سابقة.**

السؤال اليوم يُخلق داخل اختبار ويموت معه (`questions.exam_id` غير قابل للإفراغ)، والتصحيح
آليّ للاختياري وحده، ولا يعرف النظام **في أي فكرة** رسب الطالب. المرحلة تضيف: بنك أسئلة
موسوماً بأربعة محاور · تحليلاً مُجمَّعاً لنِسَب الخطأ · دفتر أخطاء · اختباراً ذاتياً ·
تصحيحاً مقالياً بمعايير · كيان واجبٍ وتسليمه · شرطَ فتحٍ للحصة التالية · وتسهيلات تقييم.

**والقرار المعماري الذي تدور حوله المرحلة كلها:** الجدول `questions` **يُعاد توجيهه، ولا
يُستنسَخ**. `exam_answers.question_id` يشير إليه اليوم في كل محاولة سابقة؛ فبناءُ جدول بنكٍ
جديد ونقلُ الصفوف إليه يعني إمّا كسر ذلك المفتاح أو إعادة كتابة كل صفّ إجابة على المنصّة.
بإعادة التوجيه — `exam_id` يُفرَّغ بعد ملء `exam_items` منه، وتُضاف الوسوم — **تبقى كل
محاولة سليمة بلا نقل صفٍّ واحد**، وهو ما يطلبه `SC-015` مباشرةً.

---

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 · TypeScript 5 / Next.js 15 (App Router)

**Primary Dependencies**: قائمة ومُعاد استعمالها — `spatie/laravel-permission` (وضع الفرق)
· `laravel/scout` (‏Meilisearch، مستعمَل اليوم في `Course` وحده) · `laravel/horizon` ·
`spatie/laravel-activitylog` · Filament 5. **ولا مكتبة جداول بيانات مثبّتة**، وهو ما يحكم
قرار صيغ الاستيراد (‏`research.md` §ط).

**Storage**: MySQL في الإنتاج · SQLite للتطوير والاختبارات. **وSQLite يخفي عرض الأعمدة**،
فأي عمود درجات أو عدّاد يُراجَع في الهجرة لا في الاختبار المحلي.

**Testing**: Pest (‏`RefreshDatabase`) · Playwright للواجهة · `SCOUT_DRIVER=null` في
`phpunit.xml` — **وهذا فخّ مُعلَن**: `Model::search()` يعيد لا شيء في الاختبارات، فاختبارٌ
يؤكّد نتيجة بحثٍ ينجح فارغاً (‏`research.md` §ي).

**Target Platform**: خادم Linux خلف موازن حِمل · متصفّح حديث · عربية RTL حصراً.

**Project Type**: Web — `backend/` وحدات لارافل، `frontend/` Next.js.

**Performance Goals**: `SC-013` — بنك ‎١٠٬٠٠٠‎ سؤال أو لوحة تصحيح ‎٥٠٠‎ محاولة دون ‎٨٠٠‎ مللي
ثانية (p95) **وبعدد استعلامات ثابت لا يتجاوز ‎١٥‎**. `SC-004` — استيراد ‎١٬٠٠٠‎ سؤال بلا
تجاوز مهلة الطلب.

**Constraints**: `FR-014` يمنع مسح كل المحاولات عند كل عرض · `NFR-010` يمنع نموّ عدد
الاستعلامات مع عدد الأسئلة أو المحاولات · `FR-034` يمنع أي تصحيح آليّ للمقالي ·
`FR-041` يوجب فحص شرط الفتح **عند كل طلب** لا مرّةً تُخزَّن.

**Scale/Scope**: آلاف الأسئلة لكل مدرّس (‏لا ملايين) · محاولات بعشرات الآلاف على مستوى
المنصّة · ‎٧‎ قصص مستخدم · ‎٥٦‎ متطلّباً وظيفياً · ‎١٢‎ كياناً.

---

## Constitution Check

*بوابة: تمرّ قبل البحث، وتُعاد بعد التصميم.* الدستور v1.2.0.

| المبدأ | الحكم | كيف يُستوفى في هذه المرحلة |
|---|---|---|
| **I — عزل المستأجرين** | ✅ يمرّ | كل كيانٍ ينتجه المدرّس (‏`concepts` · البنك · `exam_items` · `assignments` · الرُّبريك · التجميعات) يحمل `BelongsToWorkspace`. **والطبقات الثلاث مُصنَّفة صراحةً في `data-model.md` §أ** — والقرار الحسّاس أن `submissions` و`attempt_items` و«دفتر الأخطاء» **جسورٌ** تحمل `workspace_id` وتشير إلى الطالب العام، لأن الطالب واحدٌ عبر مدرّسيه والتسليم واقعٌ داخل مساحة عمل. `WorkspaceRules::exists()` لكل تحقّق، وحالة في `WorkspaceIsolationTest` في نفس الـPR |
| **I‑أ — رؤية المدرّس تمرّ بالتسجيل** | ✅ يمرّ | لوحة التصحيح ودفتر أخطاء الطالب يُقرآن عبر `EnrollmentDirectory` القائم، لا بـ`exists:users,uuid`. `FR-015` و`FR-020` يصيران اختبارَين لا جملتَين |
| **II — المنطق في Actions** | ✅ يمرّ | قواعد المحاولة والمهلة وشرط الفتح وسياسة التأخير تُفرَض في الـAction. **وانحرافٌ واحد مُعلَن** في §Complexity Tracking |
| **III — استقلال الوحدات بالأحداث** | ✅ يمرّ | `ExamPassed` القائم يبقى العقد مع الشهادات، **ويُؤجَّل إطلاقه** حتى تكتمل الدرجة النهائية للاختبار ذي المقاليّ. وقراءة الحضور من ‎005‎ تمرّ بعقدٍ في `Shared/Contracts` على شاكلة `SessionAttendanceDirectory` المشحون — **لا استدعاء لنموذج ‎005‎** |
| **IV — البوابات خضراء** | ✅ يمرّ | `NFR-006` · بلا baseline جديد ولا `@phpstan-ignore` |
| **V — التفويض بالسياسات والثوابت** | ✅ يمرّ | ثوابت جديدة في `Permissions` (‏البنك · التصحيح · التحليل · الواجبات · التسهيلات)، وسياسة لكل كيان. **وثلاث منها تنضمّ إلى `RolePermissionMatrix::tenantPermissions()`** فتظهر في شاشة الأدوار تلقائياً — وهي الآن قناة التسليم لمساعد المدرّس (‏`FR-031`) |
| **VI — العقود الظاهرة مقصودة** | ✅ يمرّ | `HasUuid` وكشف الـuuid وحده · DTOs ترث `DataTransferObject` · وقائمة حقولٍ مسموحة للحمولات التي تلمس بيانات طالبٍ آخر |

**الحكم: تمرّ بلا مخالفة تحتاج تبريراً، عدا واحدةً مُعلَنة أدناه.**

---

## Project Structure

### Documentation (this feature)

```text
specs/008-question-bank-analytics/
├── plan.md              # هذا الملف
├── research.md          # القرارات وبدائلها المرفوضة
├── data-model.md        # الجداول والفهارس وسلسلة الهجرات المرتَّبة
├── contracts/
│   ├── api.md           # المسارات والصلاحيات والحمولات الممنوعة
│   └── events.md        # ما يُطلَق وما يُستهلَك، ومن يستهلكه لاحقاً
├── quickstart.md        # سيناريوهات التحقّق من الطرف إلى الطرف
└── checklists/
    └── requirements.md  # قائمة الجودة (قائمة سلفاً)
```

### Source Code (repository root)

```text
backend/app/Modules/Assessments/          ← تتوسّع، ولا تُستبدَل
├── Actions/
│   ├── SaveQuestion.php                  ⚠️ تتوسّع: الوسوم الأربعة إلزامية
│   ├── StartAttempt.php                  ⚠️ تتوسّع: تكتب `attempt_items` (اللقطة)
│   ├── GradeAttempt.php                  ⚠️ تنقسم: آليّ فوراً، ومقاليّ ينتظر
│   ├── GradeEssayAnswer.php              ★ التصحيح بمعايير، بحارس تعارض
│   ├── ReviseGrade.php                   ★ تعديل درجة مصحَّحة بسبب مسجَّل
│   ├── FinalizeAttempt.php               ★ المجموع + الإشعار + إعادة تقييم الشهادة
│   ├── BuildSelfExam.php                 ★ الاختبار الذاتي ومن الأخطاء
│   ├── ImportQuestions.php               ★ يُنادى من الوظيفة، لا من الطلب
│   ├── SaveAssignment.php · SubmitAssignment.php · GradeSubmission.php   ★
│   └── GrantAccommodation.php            ★
├── Jobs/
│   ├── ImportQuestionsJob.php            ★ (‏`forWorkspace()` لا `set()`)
│   ├── RollUpQuestionStatsJob.php        ★ التجميع الدوري
│   └── MarkMissedSubmissionsJob.php      ★ `FR-051`
├── Models/  Question · QuestionOption · Exam · ExamItem ★ · Attempt · AttemptItem ★
│           · Answer · RubricCriterion ★ · GradingRecord ★ · Concept ★
│           · Assignment ★ · Submission ★ · Accommodation ★ · QuestionImport ★
│           · QuestionStat ★ · ConceptStat ★
├── Support/  BankSearch ★ · MistakeNotebook ★ · UnlockRule ★ · LatePolicy ★
├── Policies/ QuestionPolicy ★ · AssignmentPolicy ★ · SubmissionPolicy ★
│           · GradingPolicy ★ · AccommodationPolicy ★ (‏+ ExamPolicy · AttemptPolicy القائمتان)
└── Database/Migrations/                  ⚠️ سلسلة مرتَّبة — `data-model.md` §ط

backend/app/Shared/Contracts/
└── SessionAttendanceDirectory.php        ⚠️ يتوسّع بسؤالٍ واحد: هل حضر الحصة؟

frontend/src/app/(app)/(shell)/
├── manage/bank/                          ★ البنك: تصفّح · وسم · استيراد
├── manage/grading/                       ★ لوحة التصحيح
├── manage/analytics/questions/           ★ نِسَب الخطأ بالفكرة والدرس
├── manage/assignments/                   ★ الواجبات وتسليماتها
├── practice/                             ★ الاختبار الذاتي و«اختبرني في أخطائي»
└── mistakes/                             ★ دفتر الأخطاء
```

**Structure Decision**: **وحدة `Assessments` القائمة تتوسّع.** لا وحدة جديدة: الواجب
والتسليم والتصحيح والتحليل كلّها تقرأ نفس المحاولة ونفس السؤال، ووحدةٌ ثانية تعني مفتاحاً
خارجياً عبر الوحدات أو حدثاً لكل قراءة — وكلاهما يخالف §III لا يخدمه. الواجهة تتبع
`(shell)` القائم بمكتبة `components/ui/` الموحّدة (‏تبعية ‎002‎).

---

## Complexity Tracking

| المخالفة | لماذا لزمت | البديل الأبسط ولماذا رُفض |
|---|---|---|
| **`student_user_id` مُكرَّر على `exam_answers`** بينما هو مشتقّ عبر `attempt_id` | دفتر الأخطاء (‏`FR-016`–`FR-019`) واختبار «اختبرني في أخطائي» يسألان «كل إجابات هذا الطالب الخاطئة» — وهو استعلامٌ على عمودٍ غير موجود اليوم، فيصير وصلةً إلى `exam_attempts` عند كل قراءة وفهرساً لا يبدأ بالطالب. | **الاشتقاق بوصلة**: مرفوض لأن `NFR-010` يمنع نموّ التكلفة مع عدد المحاولات، ودفتر الأخطاء أطول ما يقرؤه الطالب. **جدول `mistake_entries`**: مرفوض لسببٍ أقوى — نسخةٌ ثانية من واقعةٍ مسجَّلة سلفاً تنحرف عن أصلها، و«الخطأ المُصلَح» يصير عموداً يُحدَّث بدل أن يكون سؤالاً يُسأل. العمود المُكرَّر يُملأ **مرّةً واحدة عند إنشاء الصفّ** ولا يتغيّر بعدها، فلا مصدر انحراف. |

> ولا انحراف آخر. تحديداً: لا `Repository`، ولا طبقة خدمات، ولا كيان «نتيجة» رابع —
> الدرجة تبقى على المحاولة والتسليم كما هي اليوم.

---

## Phase 0 — Research

مكتمل في [`research.md`](./research.md): عشرة قرارات، أخطرها الأول (‏إعادة توجيه `questions`
بدل استنساخه) وأدقّها الثالث (‏دفتر الأخطاء مشتقّ لا مُخزَّن).

## Phase 1 — Design & Contracts

مكتمل: [`data-model.md`](./data-model.md) · [`contracts/api.md`](./contracts/api.md) ·
[`contracts/events.md`](./contracts/events.md) · [`quickstart.md`](./quickstart.md).

**إعادة فحص الدستور بعد التصميم:** لا مخالفة جديدة. الجداول الجديدة كلها مصنَّفة في
`data-model.md` §أ، والوحيدة التي بلا `workspace_id` هي **لا شيء** — وهو بذاته قرارٌ
مكتوب: `accommodations` كان مرشّحاً لملكية المنصّة ورُفض بحجّةٍ في `research.md` §و.
