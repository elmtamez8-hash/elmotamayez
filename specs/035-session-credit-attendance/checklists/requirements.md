# Specification Quality Checklist: الرصيدُ بالحصص

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-11
**Feature**: [spec.md](../spec.md)

## Content Quality

- [X] No implementation details (languages, frameworks, APIs)
- [X] Focused on user value and business needs
- [X] Written for non-technical stakeholders
- [X] All mandatory sections completed

## Requirement Completeness

- [X] No [NEEDS CLARIFICATION] markers remain — حُسِمَ الاثنانِ بقرارِ المالكِ 2026-09-11 (`FR-008أ` · `FR-013أ`)
- [X] Requirements are testable and unambiguous
- [X] Success criteria are measurable
- [X] Success criteria are technology-agnostic
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

**مراجعةُ خمسةِ وكلاءَ مستقلّين — 2026-09-12.** خمسةُ أبعاد: التناقضات · المعمار · تكلفةُ الاستعلام · الأمانُ والعزل · التزامنُ والدفتر. ~٥٠ نتيجة، منها **١١ حرجة**، **وأربعٌ وجدَها أكثرُ من مراجعٍ لا يرى عملَ الآخر** — وهي أقواها دليلاً. وأُضيفَ إلى المواصفةِ FR-023…FR-036 وSC-010…SC-013، وأُثبِتَت أربعةُ قراراتٍ للمالك.

⚠️ **أخطرُ ما كشفَته**: المواصفةُ في نسختِها الأولى **لم تذكرِ ٠٢٧ ولا مرّةً واحدة** (صفرُ ذكرٍ، مقيسٌ بالبحث)، وكانَ أثرُ FR-001 فيها حذفَ منتَجِ «اشتراكِ المجموعةِ بالمدّة» كاملاً وقفلَ محتوى كلِّ مشترِكٍ غابَ خلفَ رصيدٍ لا يملكُه. **والدرسُ يُكتَبُ**: جدولُ الأثرِ يُبنى بالبحثِ في المواصفاتِ كلِّها، لا بما يحضرُ الذاكرةَ منها.

⚠️ **وأربعةُ ادّعاءاتٍ حرجةٍ تحقّقَ منها المؤلّفُ بنفسِه في الشجرةِ** قبلَ كتابتِها: فهرسُ عدمِ التكرارِ ومحجوزيّةُ تركيبتِه · «جارية» ليست حالةً منتهيةً فالمدّةُ قابلةٌ للتعديلِ أثناءَ الحصّة · توجيهُ حركاتِ الدفترِ إلى عدّادِ «المُشترى» · تجهيزةُ الحضورِ الافتراضيّةُ «غائبٌ · صفرُ ثوانٍ».

⚠️ **ثلاثةُ بنودٍ تحملُ خطرَ عطبٍ مسجَّلٍ في هذا المستودع، وكُتِبَت شروطُها في المواصفةِ لا في المراجعة:**

1. **FR-016 (محتوى الحصّةِ في مقامِ النسبة)** — عائلةُ «عنصرٌ في المقامِ لا يمكنُ إتمامُه» التي وقعَت ستَّ مرّاتٍ من ستّةِ أبواب. لا يُشحَنُ إلّا مع FR-017 · FR-018 · FR-019.
2. **FR-021 (شحنُ الخصمِ والقفلِ معاً)** — نصفُ التغييرِ عطبٌ في أيِّ اتّجاهٍ شُحِنَ به.
3. **FR-014 (محاسبةُ المدرّسِ على الحاضر)** — الغيابُ صارَ بلا تكلفةٍ على أحد. القرارُ مُثبَّتٌ من المالك، وأثرُه على المدرّسِ مكتوبٌ في FR-015أ ويجبُ أن يبلغَه قبلَ التعاقدِ لا بعدَه.
