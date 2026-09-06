# Specification Quality Checklist: شهادةٌ تُرى — قوالبُ مصمَّمةٌ ومواضعُ حقولٍ قابلةٌ للضبط

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-06
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

- **صفرُ علاماتِ NEEDS CLARIFICATION**، والافتراضاتُ العشرةُ **حُسِمَتْ كلُّها** بجلسةِ
  `/speckit-clarify` يومَ ٢٠٢٦-٠٩-٠٦ (مسجَّلةٌ في «Clarifications»): تسعةٌ أُقِرَّتْ كما كُتِبَت،
  **والسادسُ وُسِّع**.
- **التوسعةُ هي التغييرُ الوحيدُ في النطاق**: القالبانِ المشحونانِ يبقيانِ الطبيعيَّ والافتراضيّ،
  **ويجوزُ للمدرّسِ فوقَهما أن يرفعَ تصميمَه الخاصّ**. جلبتْ معَها قصّةً خامسةً (P5) وثمانيةَ
  متطلَّباتٍ (FR-040…FR-047) وأربعةَ معاييرِ نجاحٍ جديدة.
- ⚠️ **وترتيبُ الشحنِ جزءٌ من القرار**: الرفعُ **بعدَ** محرّرِ المواضعِ لا قبلَه — صورةٌ لا يعرفُ
  النظامُ أينَ مستطيلُ الاسمِ فيها ستطبعُ الاسمَ فوقَ الزخرفة، وأوّلُ من يرى ذلك هو الطالب.
- ⚠️ **وإعادةُ معالجةِ الصورةِ على الخادمِ (FR-042) ليست تحسيناً**: صفحةُ عرضِ الشهادةِ عامّةٌ بلا
  استيثاق، فصورةٌ تُقبَلُ كما وصلتْ تُخدَمُ من عنوانِ المنصّةِ لكلِّ زائر.
- **قسمُ «ما قِيسَ في الشجرة» سياقٌ لا متطلَّبات**: يسمّي ملفّاتٍ وأعمدةً لأنّ كلَّ سطرٍ فيه قياسٌ
  أُجرِيَ يومَ ٢٠٢٦-٠٩-٠٦، لا تصميمٌ مقترَح. المتطلَّباتُ نفسُها (FR) لا تسمّي تقنيّةً ولا ملفّاً.
- **تبعيّةٌ حادّة**: شهاداتُ **إتمامِ الكورس** لم تكنْ تصدرُ إطلاقاً حتّى `5bdd25d` (٢٠٢٦-٠٩-٠٦) —
  لم يكنْ في الواجهةِ ما يُتِمُّ درساً، فلم يبلغْ كورسٌ ١٠٠٪ ولم يُطلَقْ `CourseCompleted` قطّ.
  **وشهاداتُ اجتيازِ الاختبارِ لم يمسَّها ذلك** وتصدرُ منذُ زمن — ورابطُ التحقّقِ في طلبِ المستخدمِ
  دليلٌ على وجودِ واحدةٍ على الأقلّ. فالتجهيزةُ الصالحةُ لقياسِ هذه الميزةِ تحتاجُ سببَي إصدارٍ
  معاً، لا سبباً واحداً.
