# Implementation Plan: شهادةٌ تُرى — قوالبُ مصمَّمةٌ ومواضعُ حقولٍ قابلةٌ للضبط

**Branch**: `028-certificate-design-templates` | **Date**: 2026-09-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/028-certificate-design-templates/spec.md`

---

## Summary

صفحةُ التحقّقِ العامّةُ تعرضُ اليومَ **بطاقةَ بيانات**، لا شهادة. تُستبدَلُ برسمِ الشهادةِ نفسِها
على قالبٍ مصمَّمٍ يحملُ ستَّ حقائق (الاسم · المادّة · المدرّس · التاريخ · الرقم · رمزُ استجابةٍ
سريعة)، ويختارُ المدرّسُ قالبَه من معرضٍ مرئيّ، ويضبطُ مواضعَ الحقولِ فوقَ الصورة، **وله أن يرفعَ
تصميمَه الخاصَّ** (قرارُ التوضيحِ ٢٠٢٦-٠٩-٠٦).

**النهجُ التقنيُّ في سطرَين**: القوالبُ المشحونةُ **سجلٌّ في الكودِ** لا جدولَ ولا بذرة؛ وما
يملكُه المدرّسُ (التبنّي، والمواضعُ، والصورةُ المرفوعة) **جدولٌ واحدٌ مملوكٌ لمساحةِ العمل**
يُقرَأُ **حيّاً** عندَ الرسم — بينما تُجمَّدُ الحقائقُ الستُّ عندَ الإصدار.

**والميزةُ تحذفُ أكثرَ ممّا تضيفُ في الخلفيّة**: جدولُ `certificate_templates` بكلِّ ما حولَه
وخمسةِ مساراتِه **بلا مستدعٍ واحدٍ في الواجهة**، وعمودُ `certificates.template_id` **بصفرِ
قرّاء**.

---

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript (Next.js 15 App Router)

**Primary Dependencies**: قائمةٌ كلُّها عدا واحدة — `spatie/image` (إعادةُ الترميز، بسائقِ `Gd`
صراحةً) · `@tabler/icons-react` عبرَ `components/icons` · Tailwind v4 برموزِ `@theme`.
**الجديدُ الوحيد**: مكتبةُ ترميزِ QR في الواجهةِ تُخرِجُ SVG (التبريرُ في «Complexity Tracking»).

**Storage**: MySQL في الإنتاج · SQLite في التطويرِ والاختبار. الصورُ المرفوعةُ على قرصِ `public`
(`storage/app/public/certificate-designs/`)؛ الصورُ المشحونةُ ملفّاتٌ ملتزَمةٌ في
`frontend/public/certificate-templates/`.

**Testing**: Pest (Feature أوّلاً) · Vitest + jsdom للمكوّنات · Playwright خارجَ حلقةِ التطوير.

**Target Platform**: متصفّحٌ عربيٌّ RTL، هاتفٌ وحاسوب، **وورقةٌ مطبوعة**.

**Project Type**: تطبيقُ ويب — `backend/` (أحاديّةٌ معياريّة) + `frontend/`.

**Performance Goals**: الشهادةُ المرسومةُ كاملةٌ في ≤ ثانيتَين على هاتفٍ متوسّط (SC-011) —
وهو ما يحكمُ تحويلَ صورتَي القالبِ إلى WebP قبلَ الالتزام (المرفقُ ١٫٥ ميغابايت PNG).

**Constraints**: صفحةُ العرضِ **عامّةٌ بلا استيثاق** — وهذا القيدُ يقودُ ثلاثةَ قراراتٍ: لا HTML
مخزَّنٌ يُصيَّر، وإعادةُ ترميزِ كلِّ صورةٍ مرفوعةٍ على الخادم، وحمولةٌ مغلقةُ الحقول.

**Scale/Scope**: وحدةٌ واحدةٌ (`Certificates`) · جدولٌ جديدٌ واحدٌ وجدولٌ محذوفٌ واحد · عمودانِ
مضافانِ وعمودٌ محذوف · أربعةُ مساراتٍ جديدةٍ وخمسةٌ محذوفة · ثلاثُ شاشاتٍ في الواجهة.

---

## Constitution Check

*GATE: يُعادُ الفحصُ بعدَ تصميمِ المرحلةِ الأولى. **مُعادٌ ونتيجتُه أدناه.***

| المبدأ | الحالة | كيف |
|---|---|---|
| **I · عزلُ المستأجرين** | ✅ | **التصنيفُ معلَنٌ في `data-model.md` و«Key Entities»**: القالبُ المشحونُ منصّيٌّ صنف (ب) ⇒ سجلٌّ في الكودِ بلا كاتب؛ `certificate_designs` مملوكٌ لمساحةِ العملِ بـ`BelongsToWorkspace` **وحالةٍ في `WorkspaceIsolationTest` في نفسِ الـPR**؛ `certificates` جسرٌ كما هو. وكلُّ تجاوزٍ للنطاقِ معلَّلٌ ومغطّىً باختبار (ق-١٠). |
| **II · المنطقُ في الـActions** | ✅ | `SelectCertificateDesign` · `UploadCertificateDesign` · `SaveFieldBoxes` · `DeleteCertificateDesign`. **حدودُ الصناديقِ وقاعدةُ «أحدُ العمودَينِ بالضبط» مفروضتانِ في الإجراءِ لا في الاستمارةِ وحدَها** — البذرةُ ولوحةُ Filament تصلانِ بلا استمارة. |
| **III · استقلالُ الوحدات** | ✅ | كلُّ شيءٍ داخلَ `Modules/Certificates`. **اسمُ المدرّسِ والمادّةِ يُقرآنِ داخلَ `IssueCertificate` من `Enrollment` الذي يستقبلُه أصلاً**، فلا استدعاءَ لإجراءِ وحدةٍ أخرى. لا وحدةَ جديدةَ ⇒ لا سطرَ في `phpstan.neon`. |
| **IV · البوّاباتُ خضراء** | ✅ | الأربعُ في `quickstart.md`. **ومسارانِ من الثمانيةِ الحرجةِ يُمَسّان: «إصدارُ الشهادة» و«التحقّقُ منها»** — فتُقاسُ تجهيزةُ الشهاداتِ القائمةُ قبلَ التغييرِ وبعدَه. |
| **V · التفويضُ بالسياسات** | ✅ | سياسةٌ في `Certificates/Policies/` على ثابتِ `Permissions::CERTIFICATES_REGENERATE`. **لا صلاحيّةَ جديدة** — والسببُ في ق-١٢: `SeedDefaultRoles` يعملُ مرّةً عندَ الإنشاء، فالجديدةُ تصلُ صفرَ مساحاتٍ قائمة. |
| **VI · العقودُ الظاهرة** | ⚠️ **حذفٌ في مكانِه، مبرَّرٌ** | تُحذَفُ خمسةُ مساراتٍ بلا إصدارٍ جديد. الشرطُ («تغييرٌ كاسرٌ لعميلٍ قائم») **منتفٍ بالقياس**: صفرُ مستدعين تحتَ `frontend/src`. موثَّقٌ في ق-١١ وفي وصفِ الـPR. وكلُّ الحمولاتِ عبرَ Resources، وكلُّ معرَّفٍ ظاهرٍ uuid. |
| **البيئة** | ✅ | لا إطارَ جديدَ ولا لغة. الأعمدةُ نصّيّةٌ وjson — **لا حسابَ عدديٍّ يخفيه SQLite**. `storage:link` مطلوبٌ ومذكورٌ في `quickstart.md`. |
| **سيرُ العمل** | ✅ | صفرُ علاماتِ NEEDS CLARIFICATION · القائمةُ ١٦/١٦ · التوثيقُ يتحرّكُ مع الكود (`docs/README.md` للمسارات، `docs/erd.md` للمخطّط). |

---

## Project Structure

### Documentation (this feature)

```text
specs/028-certificate-design-templates/
├── plan.md              # هذا الملفّ
├── spec.md
├── research.md          # ١٤ قراراً بأدلّتِها المقيسة
├── data-model.md        # التصنيفُ الدستوريُّ + الجداولُ + بنيةُ field_boxes
├── contracts/api.md     # العقدُ العامُّ والخاصُّ وما يُحذَفُ منه
├── quickstart.md        # مشيٌ يدويٌّ + البوّابات
├── checklists/requirements.md
├── assets/              # القالبانِ كما أرسلَهما صاحبُ الطلب (PNG أصليّ)
└── tasks.md             # لاحقاً · /speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/Certificates/
├── Support/
│   └── CertificateTemplateRegistry.php     # ✚ القالبانِ المشحونان — كودٌ لا جدول
├── Models/
│   ├── Certificate.php                     # ~ يفقدُ template()
│   ├── CertificateDesign.php               # ✚ BelongsToWorkspace · HasUuid
│   └── CertificateTemplate.php             # ✖ يُحذَف
├── Actions/
│   ├── IssueCertificate.php                # ~ يجمّدُ اسمَ المدرّسِ والمادّة
│   ├── RegenerateCertificate.php           # ~ يكفُّ عن تحريكِ issued_at
│   ├── SelectCertificateDesign.php         # ✚ التبنّي والاختيار (معاملةٌ واحدة)
│   ├── UploadCertificateDesign.php         # ✚ إعادةُ الترميز + الحدّ
│   ├── SaveFieldBoxes.php                  # ✚ فرضُ المديات
│   ├── DeleteCertificateDesign.php         # ✚
│   └── ResolveCertificateDesign.php        # ✚ الحلُّ الحيُّ — الإملاءُ الوحيد
├── Http/
│   ├── Controllers/
│   │   ├── CertificateController.php       # ~ verify يوسَّع · index يصلَّحُ شكلُه
│   │   ├── CertificateDesignController.php # ✚ أربعةُ مسارات
│   │   └── CertificateTemplateController.php  # ✖ يُحذَف
│   ├── Requests/{Store,Update}TemplateRequest.php  # ✖ يُحذَفان
│   ├── Requests/{UploadDesignRequest,SaveBoxesRequest}.php  # ✚
│   └── Resources/
│       ├── CertificateResource.php         # ~ ✚ teacher_name · subject_name · design
│       ├── CertificateDesignResource.php   # ✚
│       └── CertificateTemplateResource.php # ✖ يُحذَف
├── Policies/CertificateDesignPolicy.php    # ✚
└── Database/Migrations/
    ├── ..._create_certificate_designs.php          # ✚
    ├── ..._freeze_teacher_and_subject_on_certificates.php  # ✚ + تعبئةٌ بـchunkById
    └── ..._drop_certificate_templates.php          # ✚ down() يقولُ إنّه لا يستعيد

frontend/
├── public/certificate-templates/{classic,students}.webp   # ✚ ملتزَمة
└── src/
    ├── lib/certificate-design.ts                # ✚ الأنواعُ + هندسةُ الصناديقِ + مُلاءمةُ الخطّ (نقيّةٌ وقابلةٌ للاختبار)
    ├── components/certificates/
    │   ├── CertificateArtwork.tsx               # ✚ الرسّامُ الواحدُ — ثلاثةُ مواضعَ تستعملُه
    │   ├── TemplateGallery.tsx                  # ✚ المعرضُ والرفع
    │   └── FieldBoxEditor.tsx                   # ✚ السحبُ فوقَ الصورة
    └── app/(app)/
        ├── certificates/verify/[code]/page.tsx  # ~ يُعادُ تصميمُها
        └── (shell)/manage/certificates/
            └── design/page.tsx                  # ✚ شاشةُ المدرّس

backend/tests/Feature/Certificates/          # TemplateTest.php ✖ ← DesignTest · VerifyArtworkTest · FrozenFactsTest
frontend/src/**/*.test.{ts,tsx}              # certificate-design.test.ts · CertificateArtwork · FieldBoxEditor · verify page
```

**Structure Decision**: وحدةٌ قائمةٌ واحدة (`Modules/Certificates`) ومسارٌ عامٌّ قائمٌ واحد — لا
وحدةَ جديدة، ولا نمطَ جديد. الواجهةُ تتبعُ التقسيمَ القائمَ: منطقٌ نقيٌّ في `lib/` يُختبَرُ
بـVitest، ورسمٌ في `components/certificates/`، وشاشتانِ في مكانِهما من شجرةِ المسارات.

---

## ترتيبُ الشحنِ (ولماذا هو ملزِم)

| | القصّة | يعتمدُ على |
|---|---|---|
| ١ | صفحةُ التحقّقِ المُعادُ تصميمُها على القالبِ الافتراضيّ | السجلُّ + الحقائقُ المجمَّدةُ + تعبئتُها |
| ٢ | معرضُ القوالبِ والاختيار | الجدولُ الجديد |
| ٣ | محرّرُ المواضع | ٢ |
| ٤ | الطباعة | ١ |
| ٥ | **رفعُ تصميمٍ خاصّ** | **٣ — إلزاماً** |

⚠️ **الخامسةُ لا تشحنُ قبلَ الثالثة.** صورةٌ لا يعرفُ النظامُ أينَ مستطيلُ الاسمِ فيها ستطبعُ
الاسمَ فوقَ الزخرفة — **وأوّلُ من يرى ذلك هو الطالبُ على شهادتِه**، لا المدرّس.

---

## المزالقُ المعروفةُ التي تدخلُ التنفيذَ مكتوبةً

مأخوذةٌ من `research.md`؛ مكرّرةٌ هنا لأنّ كلَّ واحدةٍ منها **شُحِنَتْ فعلاً مرّةً في هذه الشجرة**.

1. ⚠️ **القراءةُ العامّةُ للتصميمِ**: `withoutWorkspaceScope()` **مع** `where('workspace_id', …)`.
   الضحيّةُ ليست الزائرَ بل **مدرّساً غريباً مسجَّلَ الدخول** — واختبارُ الزائرِ وحدَه ينجحُ على
   بناءٍ بلا تجاوزٍ إطلاقاً. (عائلةُ ٠٢٤.)
2. ⚠️ **`chunkById` في هجرةِ التعبئة**، لا `chunk`: المسنَدُ ينكمشُ تحتَ المشي فيقفزُ OFFSET
   فوقَ ما أُصلِح **ويُبلِّغُ بالنجاح**.
3. ⚠️ **`<img>` لا `next/image`** لأيِّ صورةٍ يرفعُها مستخدم — وإلّا أُحييَ تحذيرُ `sharp`
   وبَطَلَتْ ملاحظةُ `CLAUDE.md` كلُّها.
4. ⚠️ **رمزُ لونٍ غيرُ معرَّفٍ في `@theme` لا يرسمُ شيئاً بصمت** — شُحِنَ أربعَ مرّات.
   الحارسُ `src/lib/theme-tokens.test.ts`.
5. ⚠️ **`selected_for_workspace_id` فريدٌ قابلٌ للإفراغ**، لا `is_selected` منطقيّ: MySQL بلا
   فهارسَ جزئيّة.
6. ⚠️ **إعادةُ الترميزُ بسائقِ `Gd` صراحةً** — لا `imagick` على هذا الخادم، والحزمةُ تختارُ
   افتراضَها بنفسِها.
7. ⚠️ **اختبارُ المكوّنِ يقيسُ ما لا يقيسُه غيرُه**: مُلاءمةُ الخطِّ ورفعُ الصورةِ ومنعُ اعتمادِ
   تصميمٍ بلا مواضع — **لا تُرى من سلسلةِ الخلفيّةِ إطلاقاً**.
8. ⚠️ **`jsdom` لا يخطّط**: لا عرضَ ولا ارتفاعَ لعنصر. فمنطقُ المُلاءمةِ يعيشُ **دالّاتٍ نقيّةً
   في `lib/certificate-design.ts`** تأخذُ الأبعادَ وسيطاً — وهو الشكلُ الوحيدُ الذي يُقاسُ.
   (درسُ `avatar-crop.ts` من الأمس.)
9. ⚠️ **`use(params)` يُعلِّق**: اختبارُ صفحةِ التحقّقِ `await act(async () => render(<Suspense>…))`،
   لا `render` عارياً — وإلّا وقفتِ الشجرةُ وسقطتْ كلُّ حالةٍ برسالةٍ عن عنصرٍ مفقودٍ في صفحةٍ لم
   تُرسَمْ أصلاً.

---

## Complexity Tracking

> الدستور: أيُّ تبعيّةٍ جديدةٍ تُبرَّرُ بمشكلةٍ قائمةٍ الآنَ لا بحاجةٍ متوقَّعة.

| المخالفة | لماذا لزمت | البديلُ الأبسطُ ولماذا رُفِض |
|---|---|---|
| **تبعيّةُ ترميزِ QR في الواجهة** | `FR-028` يطلبُ رمزاً يُصوَّرُ من ورقةٍ مطبوعة، وهو مطلوبُ المستخدمِ الصريح | **الكتابةُ بأيدينا**: QR معيارٌ (ISO/IEC 18004) بتصحيحِ Reed–Solomon — كودٌ أكثرُ وخطرٌ أكبرُ من مكتبةٍ صغيرة، وهو نقيضُ «الأبسطُ الذي يعمل». **خدمةٌ خارجيّةٌ بصورةٍ من عنوانِها**: تُسرِّبُ رمزَ تحقّقِ كلِّ شهادةٍ إلى طرفٍ ثالثٍ وتجعلُ التحقّقَ معتمِداً على تشغيلِ غيرِنا. **توليدٌ على الخادم**: تبعيّةُ PHP جديدةٌ + تخزينٌ + إبطالُ ذاكرةٍ، مقابلَ SVG يُحسَبُ من عنوانِ الصفحةِ نفسِها. |

**ولا مخالفةَ أخرى.** وما يخفّفُ الميزانَ أنّ الميزةَ **تحذفُ** جدولاً ونموذجاً ومتحكّماً
وطلبَينِ ومَورِداً وخمسةَ مساراتٍ وعموداً — كلَّها بلا مستدعٍ أو بلا قارئٍ مقيس.
