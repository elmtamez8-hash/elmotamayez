# Specification Quality Checklist: بوابة الدفع وتحصيل الطالب

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-04
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

- [x] المبدأ الأول (عزل المستأجرين) مُعالَج صراحةً في قسم Non-Functional Requirements
- [x] المبدأ الثاني (المنطق في Actions) مذكور كقيد لا كتفصيل تنفيذ
- [x] المبدأ الثالث (التكامل بالأحداث) مذكور مع تسمية الأحداث العابرة للوحدات
- [x] المبدأ الرابع (البوابات الخضراء) مُدرَج كمعيار نجاح
- [x] المبدأ الخامس (الصلاحيات من الثوابت) مذكور صراحةً
- [x] المبدأ السادس (العقود الظاهرة: uuid، strict_types، DTO) مذكور صراحةً

## Notes

**الحالة: ناجحة — ‎٢٢‎/‎٢٢‎.** *(محدَّثة ‎2026-08-11‎)*

**سجلّ البند الأخير.** كُتب هذا الملف في ‎٤‎ أغسطس وحالته «راسبة على بند واحد»: ثلاثة أسئلة
مفتوحة تمنع `/speckit-plan`. **وأُغلقت الثلاثة في جلسة ‎2026-08-10‎** وسُجِّلت في
`spec.md` §Clarifications:

| السؤال | الجواب | كيف حُسم |
|---|---|---|
| `Q7` نموذج العمولة | `cost-plus` | **قرأه الكود المشحون** — `config/billing.php` و`BillingSettings::operatingFeeMinor()` و`credit_purchases` تخزّن اللقطة الرباعية. السؤال كان مُجاباً منذ ‎006‎ ولم يُسجَّل |
| `Q8` اختيار البوابة | **لا تُختار الآن، ويُبنى كل ما عداها** | قرار المالك — والأسئلة الثلاثة التي تحكم الاختيار تخصّ ‎011‎ و‎012‎ و‎014‎، فلا يحجب سطراً هندسياً هنا |
| `Q9` الاسترداد | **مُسجَّل لا آليّ** | قرار المالك، مُنقَّح ‎2026-08-11‎: الحدّ بين «آليّ» و«مُسجَّل» لا بين «نقد» و«أرصدة» |

**فلم يُشغَّل `/speckit-clarify` بمعناه الأداتيّ، وأُغلقت الأسئلة بجلسة أسئلةٍ مباشرة
وقراءةٍ في الكود** — واثنان من الثلاثة كان الكود قد أجابهما قبل أن يُسأل. والنتيجة نفسها:
**صفر علامة `[NEEDS CLARIFICATION]` في الوثائق السبع**، وهو ما يوجبه الدستور قبل التخطيط.

⚠️ **والذكران المتبقّيان في هذا الملف نصُّ البند نفسه وهذه الفقرة**، لا علامتين في مواصفة —
وفحصٌ نصّيّ لا يفرّق بينهما، فمكتوبٌ هنا كي لا يُقرأ الملف على أنه راسب.

بقية البنود ناجحة: النطاق محدّد، والمتطلبات قابلة للاختبار، ومعايير النجاح قابلة للقياس
ومحايدة تقنياً، والحالات الحديّة والتبعيات موثّقة.
