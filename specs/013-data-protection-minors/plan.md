# Implementation Plan: حماية بيانات القُصّر وحقوق البيانات

**Branch**: `013-data-protection-minors` | **Date**: 2026-08-19 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/013-data-protection-minors/spec.md`

> ⚠️ **اقرأ [`review-findings.md`](./review-findings.md) قبل `/speckit-tasks`.** خمسةُ وكلاءَ
> راجعوا هذه الخطة على خمسة أبعاد، وأعادوا **سبعَ مسائلَ حرجةٍ** تُغيّر شكلَها — منها أن
> «`FR-005` حرفياً» **خطأٌ** (‏لا عمودَ أصنافٍ في `terms_consents`)، وأن الكنسةَ **بلا مسارِ
> استعلامٍ إطلاقاً**، وأنه **لا مفهومَ تفعيلٍ في المنتج** ليُبنى عليه `FR-003`. القراراتُ
> المُدمَجةُ في هذا الملفّ مُعلَّمةٌ أدناه؛ وما لم يُدمَج بعدُ مُعدَّدٌ هناك بحكمِ **يُدمَج · يُقطَع ·
> يُؤجَّل**. والخلاصةُ التي اتّفق عليها الخمسة: **الخطةُ ناقصةُ الهندسة لا زائدتُها.**

---

## Summary

المرحلة تُضيف **وحدةً واحدةً جديدة** (`Compliance`) و**عقداً تنفّذه كلُّ وحدةٍ تملك بياناً
شخصياً**، ولا تبني نظامَ موافقاتٍ إطلاقاً — لأنه **مبنيٌّ وشغّالٌ منذ 006**.

القراءةُ التي حكمت هذه الخطة: `terms_consents` و`ConsentRegistry` و`ConsentDocument` قائمةٌ في
`Modules/Payments/`، والحالةُ `ConsentDocument::DataProcessing` **مكتوبةٌ فيها بالفعل** بتعليقٍ
يقول «ما يقوله النصّ وقواعدُ محوِه تخصّ سبيك 013»، وهجرةُ الجدول نفسِها تقول «هذا سجلٌّ
لالتزامٍ قانونيٍّ ومستثنًى من محو 013». فـ`FR-005` (‏وقتٌ وIP وuser-agent ونسخةٌ ومُوقِّعٌ
منفصلٌ عن المعنيّ) و`FR-006` (‏النسخةُ جزءٌ من السؤال) و`FR-008` (‏لا تُغني موافقةٌ عن أخرى —
ومحروسٌ بأن الوثيقةَ **وسيطٌ بلا قيمةٍ افتراضية**) **مُنفَّذةٌ ومختبَرة**. ما تحتاجه 013 من
الموافقة هو: صفُّ النسخة في `platform_settings`، وكتالوجُ الأصناف، والشاشةُ، وبوّابةُ التفعيل.

وما يُبنى فعلاً أربعةٌ: **كتالوجُ أصناف** يُقارَن بالمخطّط، و**طلبُ حقوقٍ** واحدٌ يمشي على كلّ
الوحدات بعقد، و**كنسةٌ ليليةٌ واحدة** تقرأ جدولَ مدد، و**خروجُ مدرّس**. وتاريخُ الميلاد.

**المبدأ الحاكم للحجم** (‏من طلب المستخدم الصريح «لا أريد أوفر إنجنير»): كنسةٌ ليليةٌ واحدةٌ لا
إطارُ احتفاظ · جدولُ مددٍ صغيرٌ لا محرّكُ سياسات · لا شاشةَ إدارةٍ لنسخِ السياسة أكثرَ من صفٍّ
في `platform_settings` · ولا كيانَ موافقةٍ ثانٍ.

---

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 (خلفية) · TypeScript · Next.js 15 App Router (واجهة)

**Primary Dependencies**: قائمةٌ كلُّها، **صفر تبعيةٍ جديدة**. `spatie/laravel-activitylog`
(مثبَّتٌ ومستخدَمٌ منذ 007) لسجلّ التدقيق · `spatie/laravel-permission` في وضع الفرق ·
Horizon + Redis للطوابير · `ZipArchive` من PHP نفسِه لملفّ التصدير.

**Storage**: MySQL في الإنتاج · SQLite في الاختبار والتطوير. أصولُ الوسائط عند مزوّدَي 019
(Bunny‏) و004 (‏القرص المحلّي) · ملفّاتُ التصدير على قرصٍ خاصٍّ مؤقّت.

**Testing**: Pest (‏Feature أساساً) · Vitest للشاشات · Playwright لمسارٍ واحدٍ من طرفٍ إلى طرف.

**Target Platform**: خادم Linux · متصفّحٌ حديث.

**Project Type**: أحاديّةٌ معياريّةٌ + واجهةٌ منفصلة (‏web).

**Performance Goals**: طلبُ تصديرٍ لطالبٍ له ٥٠٬٠٠٠ صفٍّ يكتمل في الخلفية بلا قفلِ جدولٍ حيّ
(‏`SC-014`) · الكنسةُ الليليةُ على دفعاتٍ بحجمٍ ثابت · صفر استعلامٍ داخل Resource.

**Constraints**: صفر تبعيةٍ جديدة · صفر سرٍّ في المستودع · كلُّ مهلةٍ ومدّةٍ صفٌّ في
`platform_settings` · الوحدةُ الجديدة تُدرَج في `phpstan.neon` · الهجراتُ في
`Database/Migrations` بحرفٍ كبير.

**Scale/Scope**: وحدةٌ واحدةٌ جديدة · **٦ جداولَ جديدةٍ + ٣ تعديلاتٍ على جداولَ قائمة** · عقدٌ
ينفّذه **١٣** وحدةً باستثناءٍ واحد · **٥٠ متطلَّباً** (`FR-001`…`FR-043` ومعها التسعُ الفرعية) ·
**٢٢ معيارَ نجاح** · ٦ قصص.

> الأرقامُ صُحِّحت في المراجعة: كانت «٤٣ متطلَّباً · ١٧ معياراً · ١١ منفّذاً · ٧ جداول» — والفارقُ
> في المتطلَّبات هو **بالضبط** ما أضافته جلسةُ التوضيح، أي الرقمُ الذي سيستعمله فحصُ النطاق.

**لا `NEEDS CLARIFICATION` واحدة**: الخمسةُ أُغلقت في جلسة `/speckit-clarify` بتاريخ 2026-08-19،
والمؤجَّلان (‏قاعدةُ الحسم بين أوصياءَ مختلفين · صيغةُ ملفّ التصدير) حُسما في
[`research.md`](./research.md) §R6 و§R4 لأنهما قرارا تصميمٍ لا قرارا عمل.

---

## Constitution Check

*GATE: يُفحص قبل Phase 0 ويُعاد فحصه بعد Phase 1.* المرجع: `.specify/memory/constitution.md`
**v1.2.0**.

### المبدأ I — عزل المستأجرين · تصنيفُ كلّ كيانٍ جديد

الدستور يطلب التصنيفَ **قبل كتابة الهجرة** ويرفض كياناً بلا تصنيفٍ معلَن. وهذه المرحلة هي
أكثرُ المراحل خطراً على هذا المبدأ، لأن كلَّ كياناتها تقريباً **مملوكةٌ للمنصة** — أي بلا نطاقٍ
عالميٍّ يحرسها إطلاقاً.

| الكيان | الطبقة | الحارس |
|---|---|---|
| `DataRequest` | **منصّة — أ** | ملكيةُ الصفّ: صاحبُ البيان أو وليُّه أو حاملُ صلاحيةٍ منصّية. **يُمنع** `BelongsToWorkspace`: طلبٌ واحدٌ يشمل بياناتِ الطالب عند كلّ مدرّسيه، والتصديرُ الجزئيُّ ليس حقاً مُنفَّذاً |
| `LegalHold` | **منصّة — أ** | يتبع الطلبَ والشخصَ؛ الكتابةُ بصلاحيةٍ منصّية |
| `TermsConsent` (‏قائم) | **منصّة — أ** | كما هو. لا تعديل |
| `DataCategory` | **منصّة — ب** | بيانٌ مرجعيّ: لا مالكَ فرداً له، فالحارسُ **صلاحيةُ كتابةٍ منصّية** واختبارٌ يردّ أعلى دورِ مستأجرٍ بـ403 |
| `RetentionRule` | **منصّة — ب** | كما فوق |
| `DataProcessor` | **منصّة — ب** | كما فوق |
| `TeacherOffboarding` | **جسر** | يحمل `workspace_id` للسياق ويشير إلى المستخدم العام. مساحةُ العمل هي الشيءُ الذي يُطوى، فالطلبُ عنها |

**حارسُ رؤية المدرّس للطالب** (‏`NFR-001أ`): الخطرُ هنا معاكسُ المعتاد — طلبٌ يُنشئه وليُّ أمرٍ
**باسم طالبٍ ليس ابنَه**. وهو نفسُ ما تحرسه هجرةُ `terms_consents` بعمودَي `user_id` و
`student_user_id` المنفصلَين، وبنفس السبب المكتوب فيها: بلا إثباتِ الرابط، أيُّ مستخدمٍ يوقّع
وثيقةً باسم غيره والردُّ يؤكّد أن المعرّف لشخصٍ حقيقيّ.

⚠️ **والحارسُ ليس `ParentStudentRelationPolicy` — وقولُ ذلك كان خطأً في نسخةٍ أولى من هذه
الخطة.** ثلاثةُ أسبابٍ مقروءةٌ من الملفّ نفسِه:

1. **يستقبل صفَّ علاقةٍ لا (وليّاً · طالباً).** `isParty()` هو
   `$id === $relation->guardian_user_id`، أي «هل يجوز لك رؤيةُ هذا الصفّ» لا «هل هذا الطالبُ
   ابنُك». ولتستعمله عليك أن تجد صفّاً أولاً — فإن وجدتَه فالجوابُ نعم سلفاً. سؤالٌ دائريّ.
2. **لا يفحص `status` إطلاقاً.** و`LinkGuardian` يُنشئ الصفَّ بـ`Pending` لطالبٍ له حساب،
   و`hasActiveParent()` مشروطٌ بـ`RelationType::Parent` وحده — فـ`relation_type: guardian`
   يمرّ. أي أن أيَّ مستخدمٍ مصادَقٍ يستطيع أن يُنشئ صفَّ «وصايةٍ» معلَّقاً على أيّ معرّف طالبٍ
   يملكه. **و`Revoked` يمرّ كذلك**: وليُّ أمرٍ نزعته الأسرةُ بعد نزاعِ حضانةٍ يبقى «طرفاً».
3. **فرعُه الثالث `teacherMaySee()` مبنيٌّ للمدرّسين**، ويكفيه `RELATIONS_VIEW_STUDENT` + تسجيلٌ
   نشطٌ في مساحة عمله — والصلاحيةُ في `$assistantTeacher` فما فوقه، **أي كلُّ مدرّسٍ ومساعدٍ
   يحملها**. فتفويضُ التصدير به يمنح كلَّ مدرّسٍ تصديراً **عابراً لمساحات العمل** لطالبٍ مسجَّلٍ
   عنده: درجاتُه وحضورُه وأولياءُ أمره ومدفوعاتُه عند كلّ مدرّسٍ آخر. أوسعُ خرقٍ ممكنٍ
   لـ`NFR-001أ`، وكانت نسخةُ الخطةِ الأولى تسجّله في فحصِ الدستور **ناجحاً**.

**الحارسُ الصحيح**: استعلامٌ صريحٌ داخل `CreateDataRequest` —
`ParentStudentRelation::where('guardian_user_id', $caller)->where('student_user_id', $subject)->active()->exists()`
(‏و`scopeActive` قائمٌ في النموذج). **ويُمنع** أن يفتح `RELATIONS_VIEW_STUDENT` طلبَ حقوقٍ
إطلاقاً، ويُكتب اختبارٌ يردّ **مدرّساً له طالبٌ مسجَّلٌ نشطاً** بـ403 على تصدير ذلك الطالب.

**وحدُّ ما يملكه الوليُّ يقيّد المحتوى لا الوصولَ وحده**: `ParentStudentRelation.permissions`
(‏`GuardianPermission`: الحضورُ · المدفوعاتُ · الجدولُ · النتائجُ · الإنذارات) تُقرأ بـ`allows()`
القائمة، فوليٌّ مُنِح «الحضورَ» وحده **يُمنع** أن يستقبل النتائجَ والمدفوعاتَ والتسجيلات. صاحبُ
البيان نفسُه يستقبل الكلّ.

**وما لم يُخترَق في المُنفَّذ**: صفُّ `Pending` لا يمنح قراءةَ بيانٍ اليوم — `EloquentGuardianDirectory`
يسأل `->active()` في مواضعه الثلاثة، وحقولُ الصفّ (‏الاسمُ والعمرُ والمرحلة) **يكتبها مُنشئه** لا
تُقرأ من الطالب. فالثغرةُ ثغرةُ **هذه الخطة** لو استعملت السياسةَ، لا ثغرةٌ حيّةٌ في 003. ويبقى
في 003 أمرٌ أصغرُ يستحقّ تذكرةً منفصلة: `update()` و`delete()` يمرّان بـ`isParty()` بلا فحصِ
حالةٍ أيضاً، فمُنشئُ صفٍّ معلَّقٍ — أو وليٌّ منزوعٌ — يستطيع تعديلَ صلاحياتِ ذلك الصفّ.

**والخطرُ الثالث خاصٌّ بهذه المرحلة وحدها**: التصديرُ يجمع من **كلّ** مساحات العمل عن قصد،
فهو أكبرُ تجاوزٍ للنطاق في المنتج. كلُّ `withoutWorkspaceScope()` فيه يحمل تعليقاً ويغطّيه
اختبارٌ، والاختبارُ **يحتاج مساحتَي عملٍ على الأقلّ** أو لا يُثبت شيئاً — بنصّ القاعدة القائمة
في `CLAUDE.md` عن التقارير المنصّية. و`SC-005` (‏صفر بياناتِ شخصٍ آخرَ في ملفّ) هو الوجهُ
الآخرُ للاختبار نفسِه.

**الحكم**: ✅ يمرّ. ⚠️ **وثلاثُ حالاتٍ لا حالتان** — والنسخةُ الأولى قالت «صفر نموذجٍ مملوكٍ لمساحة
عملٍ يُضاف فلا حالةَ عزلٍ جديدة»، وهو خطأٌ ناتجٌ عن **عدم إعلان** تصنيفِ `teacher_offboardings`:

1. الصنف (أ) لـ`DataRequest` — مدرّسٌ لا يراه · والطالبُ يرى طلبَه **الواحدَ** عبر كلّ مدرّسيه.
2. الصنف (ب) للمرجعية (‏`data_categories` · `data_processors` · `retention_sweep_runs` ·
   `breach_reports`) — أعلى دورِ مستأجرٍ يُردّ بـ403.
3. **`teacher_offboardings` يستخدم `BelongsToWorkspace`** كالجسرَين المشحونَين (`Enrollment` و
   `SessionBooking`)، **فتُضاف حالةٌ في `WorkspaceIsolationTest`**.

### المبدأ II — المنطق في الـ Actions

✅ يمرّ. كلُّ قاعدةٍ تُفرَض في الـ Action: بوّابةُ التفعيل (`FR-003`، `FR-009ب`)، وتعليقُ الحذف
(`FR-030`)، وحسمُ المستحقات قبل الخروج (`FR-032`)، ونطاقُ التصدير (`FR-017`). **والسبب هنا أثقلُ
من المعتاد**: `SeedCommand` يشغّل كلَّ الـSeeders داخل `Model::unguarded()`، ولوحةُ `/admin`
تكتب بلا `FormRequest` واحد — فقاعدةٌ في التحقّق وحده تُلتَف عليها من بابين معروفَين.

### المبدأ III — استقلال الوحدات والتكامل بالأحداث

هذا هو المبدأ الذي تشتبك معه المرحلةُ فعلاً، ومن جهتين:

**١ — الموافقةُ في `Payments` والامتثالُ يحتاجها.** الحلُّ عقدٌ في `App\Shared\Contracts\` —
`ConsentDirectory` — يُربَط بـ**`Payments\Support\EloquentConsentDirectory`** يفوّض القراءةَ إلى
`ConsentRegistry` والكتابةَ إلى الـAction `RecordTermsConsent`، مطابقاً `EloquentEnrollmentDirectory`
و`EloquentGuardianDirectory` المشحونَين. **صفر نقلِ ملفّ**: نقلُ `TermsConsent` إلى الوحدة الجديدة
يقلب المخالفةَ ولا يزيلها ويعيد كتابةَ `WithholdingReader` بلا مقابل.

⚠️ **لكن صفر تعديلٍ في Payments غيرُ صحيح، وقولُه كان خطأً.** `ConsentRegistry` **بلا `record()`**،
والجدولُ **بلا عمودِ أصناف** — فتُضاف هجرةُ عمودَي `categories` و`decision` والمفتاحُ الفريد،
و`consentedCategories()`، و`EloquentConsentDirectory`، ووسيطان على الـAction. أربعةُ تعديلاتٍ
مُعلَنةٌ في [`contracts/consent-directory.md`](./contracts/consent-directory.md).

**٢ — عقدُ التصدير والمحو والانقضاء تنفّذه كلُّ وحدة.** وهو **ليس** خرقاً للمبدأ بل تطبيقُه:
`Compliance` لا تعرف جداولَ أحد؛ تنادي واجهةً تُسجَّل بوسمٍ واحد — نفسُ نمطِ
`->tag('notification.channels')` من 003. ولا حدثَ، لأن التصديرَ **يحتاج جواباً** والأحداثُ لا تُرجع
قيماً.

⚠️ **والواجهةُ في `App\Shared\Contracts\` لا في `Compliance\Contracts\`.** الخطةُ كانت تُطبّق
القاعدةَ الصحيحةَ على `ConsentDirectory` وتُخالفها في عقدها الأكبر. سبعُ واجهاتٍ عابرةٍ للوحدات
تعيش في `Shared/Contracts/`، وواجهاتُ `{Module}/Contracts/` تُنفَّذ داخل وحدتها — إلا اثنَين، وهما
مُستهلِكان يبلغان واجهةَ **مُهايئِ مزوّد**. **و`ErasureMode` معها** إلى `Shared\Support\`، بسابقةِ
`GuardianPermission` وتعليقِها: «مفرداتٌ مشتركةٌ بين وحدتَين».

**٣ — خروجُ المدرّس أحداثٌ لا Action يعرف خمسةَ سياقات.** `TeacherOffboardingRequested` و
`Completed` تشترك فيهما Marketplace وTenancy وIdentity وMedia وCourses وNotifications، وجسرُ الحسم
عقدٌ **مُسمًّى** — [`contracts/settlement-clearance.md`](./contracts/settlement-clearance.md).

✅ يمرّ. `Compliance` تُضاف إلى `phpstan.neon`، ومجلدُ هجراتها `Database/Migrations`، ومزوّدُها
يُكتشَف تلقائياً. ⚠️ **و`ContextIsolationTest` يُوسَّع مسحُه لتشملها** — يمسح `Settlement/` و
`Payments/` وحدهما اليوم، فالحارسُ المُستشهَدُ به لا يحرس هذه المرحلة قبل التوسيع.

### المبدأ IV — البوابات الأربع خضراء

✅ يمرّ. ولا `@phpstan-ignore` ولا baseline جديد. والمساراتُ الثمانيةُ الحرجةُ تبقى خضراء —
**وواحدٌ منها يمسّه المحو مباشرة**: «إصدارُ الشهادة وعدم تكرارها». محوُ طالبٍ يمرّ على شهاداته،
وشهادةٌ تُتحقَّق علناً برقمها.

### المبدأ V — التفويض بالسياسات والثوابت

✅ يمرّ. صلاحياتٌ جديدةٌ **منصّيّةٌ** في `Permissions`: `compliance.requests.execute` ·
`compliance.registry.manage` · `compliance.holds.manage` · `compliance.offboarding.execute`.
منصّيةٌ لا مستأجرة، **والحارسُ على النموذج** لا على الشاشة: `Tenancy\Models\Role` يرفض
`givePermissionTo()` لصلاحيةٍ منصّيةٍ على دورٍ يحمل `team_id`، والمجموعةُ المنصّيةُ **مُشتقّة**
(‏الكلُّ ناقصَ ما تحمله أدوارُ المستأجر) — فصلاحيةٌ تُضاف اليوم منصّيةٌ حتى يضعها أحدٌ في دورِ
مستأجرٍ عن قصد. أي أن الأربعةَ محميّةٌ بالبناء بمجرّد تعريفها.

### المبدأ VI — العقود الظاهرة

✅ يمرّ. `HasUuid` وكشفُ الـuuid وحده · `declare(strict_types=1)` · DTOs ترث
`DataTransferObject` · كلُّ استجابةٍ عبر Resource · وقائمةُ حقولٍ مغلقةٌ لملفّ التصدير
(`ExportFieldAllowlist`) على غرار `PublicFieldAllowlist` و`TeacherFieldAllowlist` و
`StudentBalanceAllowlist` القائمة.

### قيود البيئة

⚠️ **بندٌ واحدٌ يستحقّ ذكراً**: SQLite يخفي أخطاءَ عرض الأعمدة، والكنسةُ تكتب أعداداً
(‏«كم صفّاً عالجتُ») — تُراجَع الهجرةُ لتقبلها MySQL الصارم. وفوقه بندٌ أخطر ليس في الدستور
لكنه في `CLAUDE.md`: `->delay()` يعمل فوراً على `sync`، وكلُّ اختبارٍ يمشي على خطٍّ زمنيٍّ
لمدّةِ احتفاظٍ يحتاج `Queue::fake()` بقائمةِ وظائفَ محدّدةٍ لا فارغة.

---

## Project Structure

### Documentation (this feature)

```text
specs/013-data-protection-minors/
├── plan.md              # هذا الملف
├── research.md          # Phase 0 — سبعة قرارات
├── data-model.md        # Phase 1 — الكيانات وطبقاتها
├── quickstart.md        # Phase 1 — سيناريوهات تحقّقٍ قابلةٌ للتشغيل
├── contracts/
│   ├── personal-data-owner.md    # العقد الذي تنفّذه كل وحدة
│   ├── consent-directory.md      # العقد نحو الموافقة القائمة في Payments
│   └── api.md                    # نقاط النهاية
├── checklists/requirements.md    # ١٨/١٨ ناجحة
└── tasks.md             # Phase 2 — لا يُنشئه /speckit-plan
```

### Source Code (repository root)

```text
backend/app/Shared/                          # ⚠️ المفرداتُ المشتركة — لا مساحةُ Compliance
├── Contracts/PersonalDataOwner.php           # تنفّذه ١٣ وحدة
├── Contracts/ConsentDirectory.php            # نحو الموافقة القائمة في Payments
├── Contracts/SettlementClearance.php         # نحو دفتر 014 — مُسمًّى الآن
├── Support/ErasureMode.php                   # وسيطُ عقدٍ عابرٍ، بسابقة GuardianPermission
├── Support/GuardianPermission.php            # + حالةٌ سادسة: DataRights
└── Data/DataSubject.php                      # user · workspaceIds · enrollmentIds · grantedScope

backend/app/Modules/Compliance/              # الوحدة الوحيدة الجديدة
├── ComplianceServiceProvider.php
├── Actions/
│   ├── CreateDataRequest.php                 # يسأل GuardianDirectory لا سياسةَ صفّ
│   ├── ExecuteDataExport.php                 # يمرّ على منفّذي العقد، مولِّداً مولِّداً
│   ├── ExecuteDataErasure.php                # محدودٌ · مستأنِفٌ · يُعيد قراءةَ التعليق كلَّ دفعة
│   ├── PlaceLegalHold.php · ReleaseLegalHold.php
│   ├── SaveDataCategory.php · SaveDataProcessor.php
│   ├── ReportBreach.php · AdvanceBreachReport.php          # FR-040
│   └── RequestTeacherOffboarding.php · ExecuteTeacherOffboarding.php
├── Support/
│   ├── PersonalDataRegistry.php · ExportFieldAllowlist.php
│   ├── ComplianceSettings.php · Anonymiser.php · ComplianceAuditSubjects.php
├── Jobs/
│   ├── RunRetentionSweepJob.php                            # الليليةُ الواحدة · ساعةٌ خاصّة
│   ├── FulfilDataRequestJob.php                            # طابور compliance · مهلةٌ صريحة
│   ├── RetryStalledDataRequestsJob.php                     # يكنس processing العالق
│   ├── TransferDataOwnershipJob.php                        # كان ExpireDataOwnershipJob
│   └── PruneExpiredExportsJob.php                          # ملفّاتُ التصدير المنتهية
├── Models/ Enums/ Policies/ Http/ Database/Migrations/     # ⚠️ M كبيرة
└── routes/api.php

# وحداتٌ قائمة — والتغييرُ ليس «تنفيذَ العقد وحده»
backend/app/Modules/Identity/
├── Support/IdentityPersonalData.php
├── Support/UserStatus.php                    # ⚠️ حالةُ التفعيل تُنشأ هنا
├── Actions/{RegisterStudent,ActivateStudentAccount,StartAuthSession}.php   # تتغيّر
└── Database/Migrations/                      # DOB · dob_is_estimated · ownership_transferred_at

backend/app/Modules/Payments/
├── Support/{PaymentsPersonalData,ConsentRegistry,EloquentConsentDirectory}.php
├── Actions/RecordTermsConsent.php             # + categories + decision
└── Database/Migrations/                       # عمودان ومفتاحٌ فريدٌ على terms_consents

backend/app/Modules/{Learning,Assessments,Certificates,LiveSessions,Media,
  Notifications,Marketplace,Settlement,CMS,Courses,Tenancy}/
├── Support/*PersonalData.php                  # ⚠️ Courses وTenancy مُضافتان في المراجعة
└── Database/Migrations/                       # فهرسُ (created_at) لما تكنسه expire()

backend/app/Modules/Notifications/Support/NotificationType.php   # ٦ حالاتٍ جديدة
backend/database/seeders/NotificationTemplateSeeder.php          # ٦ قوالبَ مُعتمَدة
backend/app/Modules/Certificates/                                # اسمُ عرضٍ مُجمَّد · throttle:public
backend/config/{horizon.php,scout.php,compliance.php}            # waits · SCOUT_QUEUE · احتياطيّات
backend/routes/console.php                                       # ٤ أسطرِ جدولةٍ · حذفُ ٠٣:٣٠
backend/phpstan.neon · backend/composer.json                     # Compliance · ext-zip

frontend/src/
├── app/(public)/privacy/page.tsx             # ⚠️ قائمةٌ فعلاً بـPolicyPlaceholder — تُستبدَل
├── app/(public)/signup/**                    # حقلُ تاريخِ الميلاد + اتّصالُ الوليّ
├── app/(app)/(shell)/family/                 # شاشةُ موافقةِ الوليّ — قائمةٌ فعلاً
├── app/(app)/(shell)/privacy/                # طلباتي · أصنافي · سحبُ صنف
├── app/(app)/(shell)/teaching/offboarding/   # US6 — طلبُ المدرّس
├── app/(app)/(shell)/manage/compliance/      # لوحةُ موظّف المنصة
├── components/compliance/*.test.tsx           # vitest لـSC-003 (نصُّ الشاشة)
└── lib/compliance.ts · lib/labels.ts
```

⚠️ **والواجهةُ ليست أربعةَ أسطر**: `(public)/privacy` **قائمةٌ اليوم** بـ`PolicyPlaceholder`
ومربوطةٌ من كلّ تذييل — فوضعُ السياسة الحقيقية خلف مصادقةٍ يترك الزائرَ يقرأ «لم يُكتب النصُّ
بعد»، **ويُصادِم مسارَين باسم `privacy`**. والسياسةُ تبقى عامّةً حيث هي، و`(app)/privacy` لطلباتي
وأصنافي. و`(app)/(shell)/family` قائمةٌ وهي موضعُ شاشةِ موافقةِ الوليّ. **وقاعدةُ التصميم تسري
كاملةً**: ألوانٌ من `@theme` وحده · خصائصُ منطقية (`ms-*`/`start-*`) · `components/ui/` بلا
`className` حرّ · نصوصٌ من `labels.ts` · أخطاءٌ عبر `userMessage()`/`fieldErrors()` · وكلُّ حقلٍ
جديدٍ له مُدخلٌ في `backend/lang/ar/`.

**Structure Decision**: **وحدةٌ واحدةٌ جديدة** (`Compliance`) لا توزيعٌ على القائمة. السبب من
`Q1` في السبيك نفسِها: المتطلَّبُ عابرٌ لكلّ الوحدات، وتوزيعُه يُنتج تطبيقاً جزئياً — **وحقُّ
الحذف الجزئيّ ليس حقَّ حذف**. والوحدةُ لا تعرف جداولَ أحد: تنادي عقداً، فيبقى المبدأ III قائماً
والامتثالُ كاملاً في مكانٍ واحد. واسمُ الوحدة `Compliance` لا `DataProtection` لأنها تحمل خروجَ
المدرّس أيضاً، وهو ليس حقَّ بياناتٍ بالمعنى الضيّق.

⚠️ **وحدٌّ صريحٌ على ما تملكه**: `Compliance` تملك الكتالوجَ والطلباتَ والتعليقاتَ والكنسةَ
والبلاغات. **ولا تملك دورةَ حياةِ الحساب.** `ActivateStudentAccount` تعيش في **`Identity`** — و
Action في Compliance يكتب `users.status` هو بعينه خرقُ المبدأ III الذي وُجد العقدُ لتجنّبه.
Compliance تسأل `ConsentDirectory` وتُطلق حدثاً، وIdentity تكتب.

⚠️ **ولا مفهومَ «تفعيلٍ» في المنتج ليُبنى عليه**: `users.status` قيمتُه الافتراضيةُ `'active'`،
**ولا شيءَ في Identity يكتبه ولا شيءَ يحرسه**، و`RegisterStudent` يُنشئ حساباً صالحاً في حفظةٍ
واحدةٍ بلا عمرٍ ولا وليّ. فـ`FR-003` و`FR-009ب` و`SC-017` كانت تفترض انتقالاً لا وجودَ له، **و٠١٣
تُنشئ الحالةَ نفسَها** (`FR-009د`) — هجرةٌ في Identity وتغييرٌ في مسار المصادقة.

**فادّعاءُ «صفر تغييرٍ في منطق الوحدات» يسقط صراحةً**: يتغيّر `RegisterStudent` و
`RegisterStudentData` وطلبُه و`StartAuthSession` و`backend/lang/ar/` وشاشتا التسجيل.

**ودعوةُ الوليّ صفُّ العلاقة نفسُه** (`FR-009هـ`)، لا آليةُ رموزٍ جديدة: التسجيلُ الذاتيُّ يُنشئ
`ParentStudentRelation` بحالة `Pending` ويُشعِر الوليَّ. و`Pending` **لا يمنح شيئاً اليوم**
(`EloquentGuardianDirectory` يسأل `->active()` في مواضعه الثلاثة)، فالصفُّ دعوةٌ بلا صلاحيةٍ **بحكم
البناء** لا بحكم فحصٍ يُنسى.

---

## Constitution Re-Check — بعد تصميم Phase 1

أُعيد الفحصُ على الملفّات المُنتَجة، لا على النيّة. **✅ يمرّ**، وثلاثةُ بنودٍ تغيّر تقييمُها
أثناء التصميم:

| البند | ما ظهر في التصميم |
|---|---|
| **I — الطبقات** | ظهر كيانٌ لم يكن في السبيك: `student_profiles.dob_is_estimated`. تعديلٌ على جدولٍ **قائمٍ** مملوكٍ للمنصة (‏أ) فلا تصنيفَ جديد — لكنه أضاف **هجرتَين بترتيبٍ ملزِم**، وهو قيدُ نشرٍ لا قيدُ ملكية |
| **I — التجاوز المتعمَّد** | التصديرُ صار **أكبرَ تجاوزٍ للنطاق في المنتج كلّه**: يجمع من كلّ مساحات العمل عن قصد. فكلُّ `withoutWorkspaceScope()` بتعليقٍ واختبار، **والتجاوزُ لكلّ نموذجٍ على حِدة** — `->with('subject')` يشغّل النطاقَ داخل استعلامِ العلاقة. مكتوبٌ في [`contracts/api.md`](./contracts/api.md) |
| **III — الوحدات** | تأكّد أن العقدَ **ليس** خرقاً بل تطبيقاً: `Compliance` لا تسمّي جدولاً واحداً لغيرها. وظهر أن ثلاثةَ وحداتٍ **لا** تنفّذه، وهو قرارٌ كُتب في [`contracts/personal-data-owner.md`](./contracts/personal-data-owner.md) بدل أن يُترك للصمت |

**وبندٌ صار أقوى**: المبدأ V. الصلاحياتُ الأربعُ محميّةٌ **بمجرّد تعريفها** — المجموعةُ المنصّيةُ
مُشتقّةٌ (‏الكلُّ ناقصَ ما تحمله أدوارُ المستأجر)، و`Tenancy\Models\Role` يرفض منحَ صلاحيةٍ منصّيةٍ
لدورٍ يحمل `team_id`. لا سطرَ حراسةٍ يُكتب.

**ولا بندَ رسب، ولا استثناءً يُطلَب.**

## Complexity Tracking

> يُملأ فقط إن كان في فحص الدستور مخالفاتٌ تحتاج تبريراً.

**لا مخالفة. ولا بندَ مؤجَّلٌ في هذه المرحلة** — الثلاثةُ التي كانت مفتوحةً بعد المراجعة (‏عمودُ
الأصناف · حالةُ التفعيل · `FR-040`) **نُفِّذت كلُّها في التصميم**، وليس في الخطة دلوُ تأجيل.

وأُسجّل هنا ثلاثةَ قراراتٍ رفضتُ فيها الحلَّ الأكبر، لأن «الحلُّ الأبسط الذي يعمل هو الحلُّ
الصحيح» بندٌ في الدستور يُراجَع، لا شعار:

| ما لم يُبنَ | لماذا رُفض | ما بُني بدلاً منه |
|---|---|---|
| كيانُ `ProcessingConsent` وجدولُه | `terms_consents` قائمٌ منذ 006 بكلّ ما يطلبه `FR-005`، و`ConsentDocument::DataProcessing` **مكتوبةٌ فيه بالفعل**، وهجرتُه تقول صراحةً إنه مستثنًى من محو 013. جدولٌ ثانٍ = نظامان للموافقة وسؤالٌ له جوابان | صفُّ نسخةٍ في `platform_settings` + عقدُ `ConsentDirectory` |
| محرّكُ سياساتِ احتفاظٍ (‏قواعدُ قابلةٌ للتركيب، شروطٌ، استثناءات) | لا حاجةَ قائمةً له: كلُّ صنفٍ يحتاج رقماً واحداً وسلوكاً واحداً من ثلاثة | جدولٌ بعمودَين + وظيفةٌ ليليةٌ واحدة |
| شاشةُ إدارةٍ لتحرير نصوص السياسة ونسخِها | فقرةُ الافتراضات: الطرفُ القانونيُّ يوفّر النصوص والنظامُ ينفّذها ولا يجتهد فيها. ومحرّرُ نصوصٍ قانونيةٍ لا يستعمله إلا شخصٌ واحدٌ مرّتين في السنة | صفٌّ في `platform_settings` للنسخة، والنصُّ ملفُّ Markdown يمرّ بـ`MarkdownRenderer` القائم |

**وجدولٌ رابعٌ قُطع في المراجعة**: `retention_rules`. فريدٌ ١:١ مع `data_categories` — أي عمودان
يلبسان جدولاً — **وعمودُ مدّةٍ بجانب صفِّ `platform_settings` جوابان لسؤالٍ واحد**، وهو ما يمنعه
`data-model.md` نفسُه عن `is_active` بعد أسطر. العمودان ينتقلان إلى `data_categories`، **وبقطعِه
يسقط استعلامٌ لكلّ صنفٍ في `GET /privacy/categories`** — أي أن التبسيطَ أصلح N+1 مجّاناً.

**وقراراتٌ عكسيّةٌ تزيد العمل عن قصد**:

| ما زِيد | لماذا |
|---|---|
| `ExportFieldAllowlist` | `SC-005` يقول صفر بياناتِ شخصٍ آخر، و`->toArray()` يُصدِّر كلَّ عمودٍ يُضاف بعد سنةٍ بلا مراجعة. **وستُّ قوائمَ من العائلة تحرس البناءَ اليوم** (‏لا ثلاثٌ كما قالت النسخةُ الأولى)، **أربعٌ منها تملكها الوحدات** — فالمركزيةُ تحمل ما لا تملكه وحدةٌ وحده، و`export()` يُركّب قائمةَ وحدته |
| `expire()` دالّةً خامسة | بلا ها **لا كنسةَ إطلاقاً**: مُسنَدُ النسخة الأولى عمودٌ لا وجودَ له، و`erase()` يستقبل شخصاً لا حدَّ عمرٍ. والمكسبُ الثاني أهمّ: **الوحدةُ تملك المُسنَدَ فتكتب فهرسَه** |
| `retention_sweep_runs` | `FR-031` يطلب سجلَّ كلّ تنفيذٍ، ولم يكن له جدولٌ — والخطةُ تحذّر من عرضِ عمودٍ لا وجودَ له |
| `breach_reports` + نقطةٌ عامّة | `FR-040` كان **بلا موضعٍ في أيّ ملفّ** والخطةُ تشحن مهلتَه. **ونُفِّذ ولم يُؤجَّل** بطلبٍ صريح |
| `open_key` عموداً فريداً | «طلبٌ مفتوحٌ واحد» قراءةٌ ثمّ إدراج، **ولا فهارسَ جزئيةً في MySQL** — فالشكلُ هو `captured_order_id` |
| `RetryStalledDataRequestsJob` | الطلبُ يُرسَل مرّةً بـ`tries: 1` ولا شيءَ يكنس `processing`؛ عائلةُ `recording_status = 'ingesting'` بعينها |
| `DataSubject` DTO | `erase(User)` يجعل ثلاثَ عشرةَ وحدةً تُشغّل استعلامَ مساحاتِ العمل بنفسها، **وعلى كلٍّ منها أن تُصيب `forWorkspace()` وحدها** — ثلاثةَ عشرةَ موضعاً لخطأٍ واحد |
