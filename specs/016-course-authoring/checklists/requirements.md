# Specification Quality Checklist: سطح تأليف الكورسات

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-08
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Constitution Alignment

> `.specify/memory/constitution.md` v1.1.0 — يُفحص عند `/speckit-plan` ويُعاد فحصه بعد التصميم.

- [x] المبدأ الأول (عزل المستأجرين) مُعالَج صراحةً — **وتصنيف الطبقة معلَن في Q6**: كل كيانات
      السبيك مملوكة لمساحة العمل، بلا كيان مملوك للمنصة وبلا جسر
- [x] المبدأ الثاني (المنطق في Actions) مذكور كقيد — وقواعد الترتيب والنشر والحذف مفروضة في
      الـ Action لا في التحقق وحده
- [x] المبدأ الثالث (التكامل بالأحداث) مذكور مع تسمية ما يُؤخذ من `Media` و`Assessments`
      و`LiveSessions`
- [x] المبدأ الرابع (البوابات الخضراء) مُدرَج، مع تسمية المسارين الحرجين اللذين يمسّهما
      السبيك: تقييد الدروس وإتمام الكورس
- [x] المبدأ الخامس (الصلاحيات من الثوابت) مذكور صراحةً بأسماء الأذونات القائمة
      (`LESSONS_MANAGE` · `COURSES_PUBLISH`)
- [x] المبدأ السادس (العقود الظاهرة: uuid، strict_types، DTO) مذكور صراحةً

## Boundary Checks

بنود خاصة بهذا السبيك — كلها مصدر ازدواج محتمل مع سبيكات أخرى:

- [x] كيان الواجب **لم** يُعرَّف هنا؛ 016 يحجز النوع و008 (`FR-043`…`FR-052`) يملكه — Q1
- [x] «التنويه» مفصول صراحةً عن «الإعلانات» في 010: الأول عنصر في الشجرة، الثاني رسالة
      واحد-إلى-كثير تمرّ بمركز الإشعارات
- [x] بنك الأسئلة وتحليل الفقرات وتصحيح المقالي **خارج النطاق** بنصّ صريح في القيود
- [x] `class_session_id` معلَن كتابةً لـ005 وحدها (`FR-054`) — لا يكتبه هذا السبيك
- [x] لا تبعية للأمام: كل ما يعتمد عليه السبيك أرقامه أدنى منه (002 · 004 · 005 · القائم)

## Notes

**الحالة: كل البنود ناجحة — جاهزة لـ `/speckit-plan`.**

ثلاثة بنود يُنتبه إليها عند التخطيط لأنها تمسّ كوداً قائماً يعمل:

1. **تصحيح مقام نسبة التقدّم** (`FR-026` و`FR-026أ`) يعدّل `MarkLessonComplete::recomputeProgress()` —
   وهو على مسار حرج. و`FR-026أ` ليس تحسيناً بل إصلاح عطل قائم: درس تسجيل حصة في المقام يمنع
   كل طالب بلا مقعد فيها من بلوغ ١٠٠٪ ومن أن تصدر شهادته، إلى الأبد. يُشحن مع اختباره
   (`SC-019`) في نفس الـ PR.
2. **تخطّي المسودّة في تسلسل الفتح** (`FR-027`) يعدّل `Enrollment::canAccessLesson()` — وهو
   على مسار حرج ثانٍ، ويُقرأ في كل فتح درس.
3. **النشر الآلي للتسجيل** (`FR-030`) يوجب أن يضبط `PublishRecordingAsLesson` حالة النشر
   الجديدة صراحةً؛ إغفاله يجعل كل تسجيل حصة يهبط مسودّةً لا يراها أحد — عطل صامت في ميزة
   شُحنت وتعمل.
