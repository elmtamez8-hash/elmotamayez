# Specification Quality Checklist: مفرداتُ التسجيلِ القَطَريّة

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-30
**Feature**: [spec.md](../spec.md)

## Content Quality

- [X] No implementation details (languages, frameworks, APIs)
- [X] Focused on user value and business needs
- [X] Written for non-technical stakeholders
- [X] All mandatory sections completed

## Requirement Completeness

- [X] No [NEEDS CLARIFICATION] markers remain
- [X] Requirements are testable and unambiguous
- [X] Success criteria are measurable
- [X] Success criteria are technology-agnostic (no implementation details)
- [X] All acceptance scenarios are defined
- [X] Edge cases are identified
- [X] Scope is clearly bounded
- [X] Dependencies and assumptions identified

## Feature Readiness

- [X] All functional requirements have clear acceptance criteria
- [X] User scenarios cover primary flows
- [X] Feature meets measurable outcomes defined in Success Criteria
- [X] No implementation details leak into specification

## Notes

- **السؤالُ الوحيدُ حُسِمَ** (Session 2026-08-30): تفصيلُ المرحلة ⇐ **الاثنانِ معاً** —
  مفردةٌ عريضةٌ للمدرّسِ وللتصفيةِ ولوحاتِ الصدارة، ومفردةُ صفوفٍ مفردةٍ للطالب، وانتماءُ
  كلِّ صفٍّ لمرحلة. القرارُ أضافَ `FR-001أ…د` و`FR-011أ` و`SC-009` و`SC-010`، وثمنُه
  (جدولٌ ثانٍ وشاشةٌ ثانيةٌ وعلاقةٌ تُصان) مكتوبٌ في «الافتراضات» ولم يُخفَ.
- **ما جعلَ الحسمَ رخيصاً**: قِيسَ أنّ جدولَي الموادِّ والمراحلِ **فارغانِ على الموقعِ
  الحيّ**، فالمفرداتُ الجديدةُ صفوفٌ جديدةٌ لا إعادةُ تسميةٍ لمفتاحِ ربطٍ يستعملُه أحد.
- **المُعرِّفاتُ الأربعةُ القائمةُ لا تُمَسّ** — يشيرُ إليها الكورسُ وملفُّ المدرّسِ ومفتاحُ
  لوحةِ الصدارةِ نصّاً بلا مفتاحٍ أجنبيٍّ يدافعُ عنه المخزن.
- `FR-016` مطلبُ **تدقيقٍ** عن قصد: مخرجُه توثيقُ تصنيفِ كلِّ قائمةِ خياراتٍ في التسجيل،
  ويُقاسُ بغيابِ قائمةٍ غيرِ مصنَّفة.
- بندٌ واحدٌ يستحقُّ الانتباهَ في التخطيط، وليس عيباً في المواصفة: `FR-001ج` (اشتقاقُ المرحلةِ
  من الصفّ) يمسُّ ما يُخزَّنُ على ملفِّ الطالبِ اليومَ — و`Edge Cases` تُلزِمُ ببقاءِ شاشاتِ
  مَن سُجِّلَ قبلَ ذلك سليمة.
