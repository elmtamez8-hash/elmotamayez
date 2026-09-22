# Specification Quality Checklist: سجلُّ الجلساتِ والأجهزةِ — يُجهَّلُ بالعمر، ويُسقَفُ بالعدد

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-22 · **Revised**: 2026-09-22 بعدَ مراجعةِ ستِّ وكلاء
**Feature**: [spec.md](../spec.md) · [review-findings.md](../review-findings.md)

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

**ما قِيسَ، لا ما يُفترَض. وما سقطَ في المراجعةِ مكتوبٌ هنا بدلَ أن يُزال.**

⛔ **المسودّةُ الأولى مرَّت على هذه القائمةِ كلِّها خضراءَ، ثمّ أسقطَت مراجعةُ الوكلاءِ منها سبعةَ ادّعاءاتٍ عن الشجرةِ وقراراً معماريّاً كاملاً.** فالقائمةُ الخضراءُ ليست دليلَ صحّة — والتفصيلُ في [review-findings.md](../review-findings.md). أثقلُ ما صُحِّحَ في نصِّ المواصفة:

| ما كانَ مكتوباً | ما قِيس |
|---|---|
| «كلُّ فئاتِ الكتالوجِ اليومَ بلا مدّةِ احتفاظ» (FR-011) | **١٩ من ٣٥ لها مدّة**. التعليمةُ بقيَت، والتبريرُ سقط |
| «`ExportCompletenessTest` يُسقِطُ البناءَ على فئةٍ بلا مسارِ تصدير» | يُثبِّتُ مفاتيحَ **بالاسم**. الحارسانِ الحقيقيّانِ `PersonalDataContractCoverageTest` و`CategoryRegistryCoverageTest` |
| US1: «موضعُ التفاصيلِ يحملُ جملةً صريحةً لا فراغاً» | الشاشةُ **لا تعرِضُ العمودَينِ أصلاً**، فلا خانةَ تفرغ. صارَ القبولُ «تُقرَأُ كما كانت» |
| SC-002: «عددُ سجلّاتِ الجلساتِ لأيِّ حساب» | ناقضَت FR-004 وحالةَ الحافّة. صارَت عن **المنتهيةِ المُجهَّلة** |
| «ثالثُ مرّةٍ يُسجَّلُ فيها هذا الحدّ» | **ثانية** |

⛔ **وثلاثةُ متطلَّباتٍ جديدةٍ وُلِدَت من المراجعةِ لا من المسودّة**، وكلُّ واحدٍ منها يسدُّ ثقباً كانَ سيُشحَنُ صامتاً:

- **FR-013 (المقبرة)**: FR-007 تمنعُ تجهيلَ جهازٍ ما زالَ يُستعمَل، فكانَ «التجهيلُ» يترُكُ كلَّ جلسةٍ قديمةٍ على متصفّحٍ حيٍّ مُشيرةً إلى بصمةٍ كاملة — أي **يفشلُ عندَ أكثرِ الناسِ لا أقلِّهم**.
- **FR-014 (المحوُ يصلُ مَن مُحِيَ قبلَ اليوم)**: مسارُ المحوِ يتوقّفُ مبكّراً لحسابٍ يحملُ علامةَ التجهيل، فكانَ كلُّ حسابٍ مُحِيَ سابقاً سيحتفظُ بعنوانِه إلى الأبد.
- **FR-015 (`session_id`)**: عمودٌ أُضيفَ ٢٠٢٦-٠٩-١٧، مُعرِّفٌ لكلِّ متصفّح، **غائبٌ عن كلِّ جردٍ كُتِبَ للجدول**.

⛔ **وأرضيّةُ عمرِ السقفِ قرارُ مالكٍ اتُّخِذَ بعدَ المراجعة** (FR-004): `auth_sessions` سجلُّ الدخولِ الوحيدُ في المنتَج، وسقفٌ بالعددِ وحدَه يُبلَغُ من لوحةِ مفاتيحٍ حيّةٍ في دقائق. فصارَ الرقمُ ثلاثةً لا اثنَين.

⚠️ **وقرارُ مكانِ الضبطِ حُسِمَ ثمّ قِيسَ ما يمنعُه**: المالكُ اختارَ الأرقامَ الثلاثةَ في `platform_settings`. ونقلُ **المدّةِ** إلى هناك وتركُ عمودِها فارغاً يجعلُ شاشةَ «خصوصيّتي» تطبعُ «يُحفظ ما دام الحساب قائماً» — **إشعارُ خصوصيّةٍ يكذِب**. فالمشغِّلُ يكتبُ الثلاثةَ من شاشةٍ واحدةٍ كما طلب، والمدّةُ تُخزَّنُ في عمودِها. **وإن أرادَ المالكُ غيرَ ذلك فهو قرارُه ويُغيَّرُ البند.**

⚠️ **وما لم يُدَّعَ، وذِكرُه جزءٌ من الصدق**:
- المواصفةُ تُغلِقُ حالتَي `auth_sessions` و`devices` **ولا تُغلِقُ الصنف** — حارسُ التغطيةِ يبقى لكلِّ وحدة.
- **٦٥٪ من الجدولِ جلساتٌ نشطةٌ لا تبلغُها هذه الميزةُ إطلاقاً** (FR-003)، والرمزُ لا ينتهي، وحدُّ الأجهزةِ مضبوطٌ للطالبِ وحدَه — فالمدرّسُ يُراكِمُ صفّاً نشطاً دائماً بعنوانٍ دائم.
- **جدولُ `sessions` مخزَنُ بياناتٍ شخصيّةٍ رابعٌ غيرُ مُعلَن** (`ip_address` صريحاً)، مسجَّلٌ في «خارجَ النطاق» كي لا يكونَ الاكتشافَ السابعَ لهذا الصنف.
