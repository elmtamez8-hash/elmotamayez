# Specification Quality Checklist: الطالبُ يقبلُ وصيَّه

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

### لماذا هذه المواصفةُ موجودةٌ أصلاً

استُخرِجَت من `031` لأنّ المراجعةَ المتوازيةَ كشفَت أنّ ذَراعَ وليِّ الأمرِ هناك **مبنيّةٌ على
شرطٍ لا يمكنُ أن يتحقّق**. ولم تُكتشَفْ بقراءةِ الشيفرة — بل بعدِّ الصفوفِ في قاعدةِ التطوير:
`active: 16` منها **١٥ بلا حساب**، فالعلاقاتُ النشطةُ التي تُسمّي حساباً **واحدة**، ومصدرُها
بذرةُ عرضٍ تكتبُ `'active'` نصّاً حرفيّاً.

### مواضعُ صُحِّحَت أثناءَ التحقّق

**١ · `SC-001` كانَ «يقبلُ الطالبُ فتصيرُ نشطة».** توكيدةٌ عن عمودٍ في جدول — تمرُّ خضراءَ
فوقَ بناءٍ لا يقرؤُها فيه أيُّ حارس. صارَ **«فيظهرُ في قائمةِ أبناءِ وصيِّه»**: القارئَ الذي
كانَ يُجيبُ صفراً.

**٢ · `SC-002` أُضيفَ**: المواصفةُ كلُّها موجودةٌ لتفتحَ `029` و`031`، ولم يكنْ فيها معيارٌ
يقيسُ ذلك. وقيدُه «**على قاعدةٍ لم تكتبْ حالةَ العلاقةِ بيدِها**» هو المعيارُ نفسُه — بدونِه
يُقاسُ على تركيبةٍ تُنشِئُ ما تدّعي أنّها تختبرُ إنشاءَه، وهي العلّةُ التي أخفَت العطبَ
مراحلَ كاملة.

**٣ · `US3` (الصلاحيّات) كانت خارجَ النطاق.** أُدخِلَت لأنّ القبولَ بدونِها **بلا معنى**:
يقبلُ الطالبُ «الحضورَ» ثمّ يمنحُ الوصيُّ نفسَه «المدفوعات» بطلبٍ واحد — الشرطُ الحقيقيُّ
يعودُ «علاقةٌ نشطة»، وهو ما جاءَ القبولُ ليُبطِلَه.

**٤ · `FR-011` و`SC-007` أُضيفا صراحةً**: الضغطُ الطبيعيُّ عندَ النشرِ أن تُردَمَ الروابطُ
المعلَّقةُ الـ«قائمةُ منذُ شهور» إلى نشطة. وذلك **يخترعُ موافقةً لم يُعطِها أحد** — ويُلغي
الميزةَ في الطريقِ إلى تشغيلِها.

**٥ · `Edge Case` لإعادةِ الطلبِ بعدَ رفض**: بدونَه يصيرُ الرفضُ زرّاً يُعادُ الضغطُ فوقَه
بلا حدّ — رفضٌ لا يُحتَرَم.

### الحدُّ الذي وُضِعَ عمداً

**حراسةُ القاصرِ ليست هذه المواصفة.** «من يتابعُ من» و«من يوافقُ على معالجةِ بيانات» سؤالانِ
مختلفان، ودمجُهما هو الاقترانُ الذي سجَّلَ `GuardianPermission` تصحيحَه من قبل.
