# Specification Quality Checklist: وليُّ الأمرِ يشحنُ رصيدَ ابنِه

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-08
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

### مواضعُ صُحِّحَت أثناءَ التحقّق

**١ · «ما قِيس» كانَ يحملُ أسماءَ أصنافٍ ودوالّ.** جدولُ القياسِ يصفُ **الشجرةَ كما هي**،
وهو الشكلُ الذي تتبعُه مواصفاتُ هذا المستودعِ منذُ ٠٢٩ — فبقيَ. أمّا **المتطلّباتُ
(`FR-*`) ومعاييرُ النجاحِ (`SC-*`) فخَلَتْ منها تماماً**: تقرأُ «شرطُ طرفيّةِ الابنِ في
الكورس» لا اسمَ الدالّةِ التي تُجيبُه، و«من أنشأَ الطلبَ نيابةً» لا اسمَ العمود. الحدُّ هو
أنّ القياسَ **مقروءٌ من الشيفرةِ** والمتطلّبَ **يُكتَبُ إليها**.

**٢ · `SC-002` كانَ «يُرفَضُ الشراءُ».** وهو يمرُّ خضراءَ فوقَ بناءٍ **يُسعِّرُ** ثمّ يرفضُ
عندَ الإرسال — والسعرُ هو الشيءُ المطلوبُ إخفاؤُه. صارَ **«صفرُ أسعارٍ وصفرُ طلبات»،
مقيسةً على البابَين معاً**.

**٣ · `SC-003` كانَ «المنتقي لا يعرضُ ما يرفضُه الخادم».** اتّجاهٌ واحدٌ يمرُّ فوقَ منتقٍ
**فارغٍ دائماً** — وهو العطبُ المُبلَّغُ عنه بعينِه. صارَ تطابقاً في الاتّجاهَين.

**٤ · `US2` كانَ مرتّباً `P2`.** وهو نصفُ الميزةِ لا حاشيتُها: بدونِه تكونُ المرحلةُ قد
منحَت العميلَ رخصةَ الموظّف. رُفِعَ إلى `P1`، وقصّتانِ بالأولويّةِ نفسِها مقصودتان — هذه
مرحلةٌ لا تُشحَنُ أنصافُها.

**٥ · `SC-004` أُضيفَ بعدَ الأولى**: لم يكنْ في المواصفةِ معيارٌ يقولُ إنّ **مسارَ الموظّفِ
لم يُكسَر**. وتغييرُ شرطٍ يتشاركُه بابانِ بلا معيارٍ يحرسُ البابَ الآخرَ هو كيفَ يُصلَحُ عطبٌ
بخلقِ آخر — وهي القاعدةُ التي كتبَها `US6` في ٠١٣ حينَ نجحَت تسعُ حالاتٍ بالشرطِ الخطأ.

### ما بقيَ خارجَ المواصفةِ عمداً

- **ابنٌ بلا حسابٍ على المنصّة** — مسارُ فتحِ الحسابِ قائمٌ ومستقلّ.
- **الاشتراكاتُ** — شُحِنَت في `029` بالشكلِ نفسِه؛ هذه الأرصدةُ وحدَها.
- **شاشةُ منحٍ جديدةٌ في `/admin`** — القائمةُ تكفي ولا تتغيّر.
