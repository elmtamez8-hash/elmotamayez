# Implementation Plan: تعريب لوحة التطبيق وتوحيد نظام التصميم (Arabic RTL App Shell)

**Branch**: `002-arabic-rtl-app-shell` | **Date**: 2026-08-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-arabic-rtl-app-shell/spec.md`

---

## Summary

المنتج اليوم تطبيقان بصريّان في مستودع واحد: مجموعة `(public)` عربية `rtl` مبنية على رموز
`@theme`، ومجموعة `(app)` إنجليزية `ltr` مبنية على لوحة Tailwind الافتراضية. المرحلة تُلغي
الازدواج بأربع حركات مرتّبة:

1. **تخطيط جذري واحد** (`src/app/layout.tsx`) يحمل `lang="ar" dir="rtl"` وخط Cairo وسكربت
   السمة — فتصبح المجموعتان تخطيطين متداخلين لا جذرين متنافسين.
2. **رمز سطح مرتفع واحد** (`--color-surface-raised`) يستبدل ٥٧ موضعاً مكتوباً يدوياً
   بصيغة `bg-white dark:bg-transparent`، ثم استبدال ٥٣٠ استخدام لوحة افتراضية في `(app)`
   بالرموز القائمة.
3. **تعريب النصوص** في ٢٢ صفحة، ونقل رسائل التحقّق إلى العربية من مصدرها في Laravel
   (ملفات ترجمة فقط — بلا مساس بمنطق أو شكل الاستجابة).
4. **استخراج `components/ui/`** من الأنماط المكرّرة، وحذف النسخ القديمة لا تركها.

النهج المعماري: **لا حزمة جديدة، ولا طبقة تجريد جديدة**. كل ما تحتاجه المرحلة موجود —
Tailwind v4 يدعم الخصائص المنطقية، و`next/font` يستضيف Cairo ذاتياً، و`@axe-core/playwright`
ومصفوفة ٣ عروض × سمتين مضبوطة سلفاً في `playwright.config.ts`.

---

## Technical Context

**Language/Version**: TypeScript 5.7 · React 19 · Node 22

**Primary Dependencies**: Next.js 15 (App Router) · Tailwind CSS 4 (`@theme`، بلا
`tailwind.config`) · `next/font/google` (Cairo، مستضاف ذاتياً) · `@tabler/icons-react`
· `@playwright/test` + `@axe-core/playwright`. **لا حزمة جديدة** — راجع
[research.md](./research.md) قرار R7.

**Storage**: `localStorage` لمفتاحين اثنين فقط: `theme` و`auth_token`. لا تخزين جديد.

**Testing**: Playwright — ٦ مشاريع (٣٦٠/٧٦٨/١٤٤٠ × نهاري/ليلي) على بناء إنتاجي، و`axe`
بوسوم `wcag2a wcag2aa wcag21a wcag21aa`. البوابة النوعية `npx tsc --noEmit`.

**Target Platform**: متصفّحات حديثة تدعم الخصائص المنطقية للاتجاه (`inset-inline-start`،
`margin-inline`) و`:focus-visible`. لا دعم لـ IE ولا Safari < 15.

**Project Type**: تطبيق ويب — تعديل طبقة العرض في `frontend/` حصراً، عدا ملفَّي ترجمة
في `backend/lang/ar/`.

**Performance Goals**: صفر وميض سمة عند أول رسم (SC-007) — يتحقّق بسكربت متزامن في `<head>`
قبل أول طلاء. لا هدف أداء آخر تفرضه المرحلة.

**Constraints**:
- **يُمنع** تشغيل `npm run build` أثناء عمل `npm run dev` — كلاهما يكتب في `.next/`.
- **يُمنع** إدخال حزمة تدويل (FR-004). النصوص عربية مباشرةً في JSX.
- عقد الـ API لا يتغيّر: شكل استجابة الخطأ `{message, errors:{field:[…]}}` يبقى حرفياً
  كما هو؛ يتغيّر **نصّ** الرسالة فقط.

**Scale/Scope**:

| المقياس | العدد |
|---|---|
| صفحات تحت `(app)` | ٢٢ (١٨ تحت `(shell)` + ٤ خارجه) |
| صفحات تحت `(public)` (مرجع بصري، لا تُعاد كتابتها) | ١٤ + `not-found` |
| مكوّنات `components/marketplace/` | ٢٨ + ٣ حالات |
| استخدامات لوحة Tailwind الافتراضية في `(app)` | ٥٣٠ |
| المقابل في `(public)` + `components/` | ٠ |
| مواضع `bg-white dark:bg-transparent` | ١٩ في العام + ٣٨ في اللوحة = ٥٧ |
| تخطيطات جذرية اليوم | ٢ (متعارضتان) → ١ |

---

## Constitution Check

*GATE: يُفحص قبل المرحلة ٠ ويُعاد فحصه بعد المرحلة ١.*

| المبدأ | الانطباق | الحالة |
|---|---|---|
| **I — عزل المستأجرين** | لا نموذج جديد ولا استعلام جديد. لا كيان يُصنَّف في طبقة ملكية. | **غير منطبق** — مُعلَن صراحةً في [data-model.md](./data-model.md) |
| **II — المنطق في الـ Actions** | لا منطق أعمال في هذه المرحلة. | **غير منطبق** |
| **III — استقلال الوحدات** | لا وحدة جديدة، لا هجرة، لا مستمع أحداث. | **غير منطبق** |
| **IV — البوابات الآلية خضراء** | البوابات الأربع تسري. `APP_LOCALE=ar` يمسّ `pest` — راجع R2. | **منطبق — يُفحص** |
| **V — التفويض بالسياسات** | لا تغيير في التفويض. حارس `(shell)` يبقى كما هو. | **غير منطبق** |
| **VI — العقود الظاهرة** | لا مسار جديد، لا حمولة جديدة. شكل استجابة الخطأ ثابت. | **منطبق — يُحترم** |
| **قيود البيئة** | `npm run build` و`npm run dev` لا يعملان معاً. | **منطبق** |
| **سير العمل** | صفر علامة `[NEEDS CLARIFICATION]` في المواصفة، وقائمة التحقّق مُمرَّرة. | **مُستوفى** |

**نتيجة البوابة قبل المرحلة ٠**: ✅ مرور — مع مخالفة واحدة موثّقة في
[Complexity Tracking](#complexity-tracking).

**إعادة الفحص بعد المرحلة ١**: ✅ مرور — التصميم لم يُدخل نموذجاً ولا Action ولا وحدة،
ولم يوسّع سطح الـ API. المخالفة الوحيدة بقيت واحدة ولم تتوالد.

---

## Project Structure

### Documentation (this feature)

```text
specs/002-arabic-rtl-app-shell/
├── plan.md                      # هذا الملف
├── research.md                  # مخرج المرحلة ٠ — ٨ قرارات
├── data-model.md                # مخرج المرحلة ١ — لا كيانات بيانات؛ جرد رموز ومكوّنات
├── quickstart.md                # مخرج المرحلة ١ — دليل تحقّق قابل للتشغيل
├── contracts/
│   ├── design-tokens.md         # عقد الرموز: ما هو معرَّف وما يُمنع كتابته يدوياً
│   ├── ui-components.md         # عقد المكتبة المشتركة: واجهة كل مكوّن وسلوكه
│   └── error-messages.md        # عقد رسائل الخطأ: من يترجم ماذا وأين
├── checklists/requirements.md   # موجود
└── tasks.md                     # مخرج المرحلة ٢ — لا يُنشئه /speckit-plan
```

### Source Code (repository root)

```text
frontend/src/
├── app/
│   ├── layout.tsx                    # ➕ جديد — التخطيط الجذري الوحيد: lang=ar dir=rtl + Cairo + سكربت السمة
│   ├── globals.css                   # ✏️ +رمز --color-surface-raised
│   ├── (public)/
│   │   ├── layout.tsx                # ✏️ يفقد <html>/<body> ويحتفظ بـ SiteHeader/SiteFooter/FloatingActions
│   │   └── … ١٤ صفحة + not-found     # ✏️ استبدال bg-white dark:bg-transparent بالرمز (١٩ موضعاً)
│   └── (app)/
│       ├── layout.tsx                # ✏️ يفقد <html>/<body> ويحتفظ بـ AuthProvider
│       ├── login/ · register/ · invitations/ · certificates/verify/   # ٤ صفحات
│       └── (shell)/
│           ├── layout.tsx            # ✏️ تعريب التنقّل + رموز + شريط جانبي منطقي الاتجاه
│           └── … ١٨ صفحة             # ✏️ تعريب + رموز + استهلاك components/ui
├── components/
│   ├── ui/                           # ➕ المكتبة المشتركة (راجع contracts/ui-components.md)
│   ├── marketplace/                  # ✏️ تُحدَّث استيراداتها؛ ما انتقل يُحذَف من هنا
│   └── icons/
└── lib/
    ├── api.ts                        # ✏️ خريطة الحالة → رسالة عربية للأخطاء غير 422
    └── errors.ts                     # ➕ نصوص الخطأ العامة في مكان واحد

frontend/e2e/
├── auth.setup.ts                     # ➕ مشروع إعداد يُنتج storageState مصادَقاً عليه
├── accessibility.spec.ts             # ✏️ +صفحات اللوحة
├── rtl.spec.ts                       # ➕ فحص آلي: lang/dir/تمرير أفقي/نص إنجليزي
└── playwright.config.ts              # ✏️ +مشروع setup و dependencies

backend/lang/ar/
├── validation.php                    # ➕ رسائل التحقّق + مصفوفة attributes بالمسمّيات العربية
└── passwords.php                     # ➕
```

**Structure Decision**: بنية تطبيق ويب قائمة (`backend/` + `frontend/`) بلا تغيير. العمل
محصور في `frontend/src/` عدا مجلد `backend/lang/ar/` — وهو مجلد ترجمة صرف لا يحوي منطقاً.
لا مجلد جديد على مستوى الجذر، ولا حزمة، ولا مساحة عمل npm ثانية.

---

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| لمس `backend/` رغم `NFR-001` — إضافة `backend/lang/ar/{validation,passwords}.php` وضبط `APP_LOCALE=ar` | `FR-016` يوجب أن تسمّي رسالةُ التحقّق الحقلَ بمسمّاه العربي، و`FR-017` يمنع عرض نصّ تقني. رسائل ٤٢٢ تُولَّد في Laravel ولا تحمل رمز قاعدة يمكن للواجهة أن تترجمه | البديل هو مطابقة نصوص إنجليزية بتعابير نمطية في الواجهة — أكثر كوداً، وهشّ بشكل دائم، ويتعطّل صامتاً عند أي تحديث لنصوص Laravel. و`NFR-001` يمنع تغيير **المنطق والعقود**؛ ملف الترجمة ليس أياً منهما: شكل الاستجابة `{message, errors:{field:[…]}}` يبقى حرفياً كما هو |

**ما يقيّد هذه المخالفة**: `pest` بوابة إلزامية. أي اختبار قائم يؤكّد نصّاً إنجليزياً
للتحقّق سيسقط، ويُصلَح في نفس الـ PR — لا يُستثنى ولا يُسكَت.

---

## Phase Outputs

- **المرحلة ٠** — [research.md](./research.md): ٨ قرارات محسومة، صفر `NEEDS CLARIFICATION` متبقٍّ.
- **المرحلة ١** — [data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md).
- **المرحلة ٢** — `tasks.md` عبر `/speckit-tasks` (خارج نطاق هذا الأمر).
