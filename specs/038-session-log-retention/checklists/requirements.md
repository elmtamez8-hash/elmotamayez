# Specification Quality Checklist: سجلُّ الجلساتِ والأجهزة — يُجهَّلُ ويُسقَف

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-22
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

**ما قِيسَ في التحقّقِ، لا ما يُفترَض:**

⛔ **وأثقلُ ما وجدَتْهُ المراجعةُ: الفجوةُ ثلاثيّةٌ لا واحدة.** المسودّةُ الأولى كانت تقولُ «لا يُنظَّفان» وتقف. وفحصُ `IdentityPersonalData` أظهرَ أنَّ الجدولَينِ **خارجَ التصديرِ وخارجَ المحوِ أيضاً** — صفرُ ذكرٍ لهما في الملفّ. وليسَ توسيعاً اختياريّاً: `ExportCompletenessTest` يشترطُ ملفّاً لكلِّ فئةٍ بما فيها الفارغة، فإعلانُ الفئتَينِ بلا مسارَيْ تصديرٍ ومحوٍ **يُسقِطُ البناء**. أُضيفَت FR-012 وSC-008 وقسمٌ في «لماذا الآن».

⚠️ **أسماءُ الأعمدةِ ترِدُ مرّةً واحدةً في المواصفة**، وهي في قسمِ «لماذا الآن» حيثُ القياسُ المؤرَّخ — لا في متطلَّبٍ واحد. ومتطلَّباتُ التجهيلِ مكتوبةٌ بما تعنيه («ما يدلُّ على عنوانٍ أو جهازٍ بعينِه») لأنّ اسمَ العمودِ قرارُ خطّةٍ لا قرارُ مواصفة، وتسميتُه هنا تُغلِقُ بابَ تصميمٍ قبلَ أن يُفتَح. أسماءُ الجدولَينِ تبقى لأنّهما موضوعُ المواصفةِ لا تفصيلاً فيها.

⚠️ **ولا يُسمّى مفتاحُ إعدادٍ واحدٌ في المتطلَّبات**: FR-005 تشترطُ أن يكونَ الرقمانِ قابلَينِ للضبطِ بلا شحنِ شيفرةٍ وأن يكونَ لكلٍّ منهما افتراضٌ مشحون، وتتركُ المفتاحَ والافتراضَ لِما تكتبُه الخطّة.

⛔ **وبندٌ يستحقُّ انتباهَ من يقرأُ بعدَنا، وهو مكتوبٌ في «الافتراضات» لا مخفيّ**: الرقمانِ يسكنانِ **مكانَين** — المدّةُ في صفِّ الفئةِ، والسقفُ في إعداداتِ المنصّة. وهذا يُخالِفُ ظاهرَ طلبِ المالك («الرقم من إعدادات المنصّة») ويُوافِقُ مقصدَه (قابلٌ للضبطِ بلا شحنِ شيفرة، وله افتراض). السببُ أنّ عمودَ المدّةِ قائمٌ في الكتالوجِ وتقرؤُه المكنسةُ العامّةُ أصلاً، فوضعُ الرقمِ في مكانٍ ثانٍ يُنتِجُ جوابَينِ لسؤالٍ واحد — وهو العطبُ الذي يُسجِّلُه هذا المستودَعُ مراراً. **إن أرادَ المالكُ الرقمَينِ في مكانٍ واحدٍ فهذا قرارُه، ويُغيَّرُ البند.**

⚠️ **وما لم يُدَّعَ**: المواصفةُ تُغلِقُ حالتَي `auth_sessions` و`devices`، **ولا تُغلِقُ الصنفَ** — حارسُ التغطيةِ يبقى لكلِّ وحدة، وجدولٌ جديدٌ داخلَ وحدةٍ مسجَّلةٍ يبقى غيرَ مرئيٍّ له. قولُ ذلك صراحةً أصدقُ من نصفِ إصلاحٍ يُقرَأُ إصلاحاً كاملاً.
