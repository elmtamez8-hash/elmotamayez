# Specification Quality Checklist: لوحةٌ تعرفُ من يفتحُها

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-07
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

## Notes

- **جولةُ تحقّقٍ أولى (2026-09-07)**: رسبَت ثلاثةُ بنودٍ وأُصلِحَت في الملفِّ قبلَ الاعتماد:
  - «No implementation details» — كان جدولُ القياسِ يسمّي ملفّاتٍ ومساراتٍ وأصنافاً بأسمائِها
    (`page.tsx`، `EnrollmentController::index`، `/billing/children/balance`، `403`، `recharts`).
    أُعيدَت صياغتُه بلغةِ السلوكِ المقيسِ دونَ تسميةِ سطحٍ تقنيّ، وبقيَ الرقمُ والنتيجةُ كما قِيسا.
  - «Success criteria are technology-agnostic» — `SC-003` كان يقولُ «٤٠٣»، فصارَ «ردودُ ممنوع».
  - «Testable and unambiguous» — `FR-022` أُضيفَ لأنّ حراسةَ الوِلايةِ كانت مذكورةً في اتّجاهٍ
    واحدٍ فقط (المأذونُ يقرأ)، والاتّجاهُ المعكوسُ هو الذي تُكتَبُ له الاختبارات.

- **تحقّقٌ من الحدودِ التقنيّةِ مؤجَّلٌ إلى `/speckit-plan` عمداً**، ومنه: شكلُ القراءةِ الجديدةِ
  (Assumption 10)، وحدودُ الوحداتِ في تجميعِ حضورِ الابن، وسقفُ الاستعلاماتِ لكلِّ قراءة.

- **مراجعةُ الوكلاءِ بُعداً بُعدٍ تقعُ بينَ `/speckit-plan` و`/speckit-tasks`**، لا هنا.

- **جولةُ `/speckit-clarify` (2026-09-07)**: ثلاثةُ أسئلةٍ سُئِلَت وأُجِيبَت وأُدمِجَت. البنودُ
  الستّةَ عشرَ بقيَت كلُّها ناجحةً (16/16 → 16/16)، وثلاثُ مواضعَ صُحِّحَت أثناءَ الدمج:
  - **تناقضٌ صريحٌ أُزيل**: `FR-006` كان يطلبُ بطاقةَ «رصيدِ الحصص» — رقماً واحداً — بينما الرصيدُ
    في هذا المنتَجِ **لكلِّ كورسٍ على حدة ولا يُجمَع**، فلا رقمَ صحيحاً يُوضَعُ فيها. صارَ
    `FR-006أ` يمنعُ المجموعَ صراحةً ويُلزِمُ العرضَ لكلِّ كورس.
  - **غموضٌ أمنيٌّ أُغلِق**: «آخرُ إشعاراتِه» في قسمِ وليِّ الأمرِ كانت تحتملُ «إشعاراتِ الابن» —
    وهي تسريبٌ لتنبيهاتِ أمانِ الطفلِ نفسِه. صارَت «الإشعاراتُ الواردةُ إلى حسابِ وليِّ الأمرِ
    نفسِه» في الرواية وفي `FR-008`.
  - **لغةُ تنفيذٍ أُبعِدَت**: «بساعةِ المتصفّح» في `FR-011أ` صارَت «بمرورِ الوقتِ وحدَه بلا طلبٍ
    إضافيّ ولا إعادةِ تحميل» — شرطٌ يُقاسُ بلا تسميةِ آليّة.
