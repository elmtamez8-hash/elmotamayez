# Phase 1 — Data Model: تعريب لوحة التطبيق وتوحيد نظام التصميم

**Feature**: `002-arabic-rtl-app-shell` · **Date**: 2026-08-05

---

## تصنيف الملكية (إلزامي بالدستور I)

> «كل كيان جديد **يجب** أن يُصنَّف صراحةً في واحدة من ثلاث طبقات قبل كتابة هجرته.
> كيان بلا تصنيف معلَن يُرفَض في المراجعة.»

**هذه المرحلة لا تُنشئ أي كيان.** لا جدول، لا هجرة، لا نموذج Eloquent، لا عمود. الطبقات
الثلاث لا تنطبق، وحارس رؤية المدرّس للطالب لا ينطبق، ولا حالة تُضاف إلى
`tests/Feature/Tenancy/WorkspaceIsolationTest.php`.

هذا الإعلان موجود ليُقرأ في المراجعة كإجابة صريحة على السؤال، لا كصمت يُفسَّر إهمالاً.

**ما تلمسه المرحلة من الخلفية**: ملفّا ترجمة تحت `backend/lang/ar/` وضبط `APP_LOCALE`.
لا جدول ولا استعلام ولا نموذج.

---

## الحالة الوحيدة التي تحملها المرحلة

| الاسم | التخزين | المفتاح | القيم | المستهلك |
|---|---|---|---|---|
| **تفضيل السمة** | `localStorage` | `theme` | `light` \| `dark` | سكربت الجذر (قبل أول رسم) · `ThemeToggle` |

قائمة أصلاً ومستخدَمة في `(public)`. المرحلة **لا تغيّر المفتاح ولا القيم** — ترفع قراءتها
من تخطيط المجموعة إلى التخطيط الجذري فقط، فتشمل اللوحة (`FR-009`, `FR-010`).

`auth_token` في `localStorage` قائم أيضاً ولا تمسّه هذه المرحلة.

---

## جرد رموز التصميم

المصدر الوحيد: كتلة `@theme` في `frontend/src/app/globals.css`. لا مصدر ثانٍ، ولا
`tailwind.config` (Tailwind v4).

### قائمة اليوم — ٢٣ رمزاً، تُستهلك كما هي

| المجموعة | الرموز |
|---|---|
| العلامة | `primary` · `primary-soft` · `secondary` · `accent` · `danger` |
| مقدّمات مقترنة | `accent-foreground` · `trust-medium-foreground` |
| هُويات نصّية (معايَرة على ٤٫٥:١ في السمتين) | `primary-ink` · `secondary-ink` · `danger-ink` · `trust-high-ink` · `trust-medium-ink` · `trust-low-ink` |
| نصّ وأسطح | `ink` · `ink-muted` · `surface` · `line` |
| نطاقات درجة الثقة | `trust-high` · `trust-medium` · `trust-low` |
| الخط | `--font-sans` ← `--font-cairo` |

### الإضافة — رمز واحد

| الرمز | نهاري | ليلي | يستبدل |
|---|---|---|---|
| `--color-surface-raised` | `#ffffff` | `#1f2937` | ٥٧ موضعاً بصيغة `bg-white dark:bg-transparent` |

**قاعدة مُلزِمة** (`FR-008`, `SC-003`): بعد هذه المرحلة **يُمنع** ظهور أي قيمة لونية حرفية
— لا سداسية، ولا `rgb()`، ولا صنف من لوحة Tailwind الافتراضية (`gray-*`، `indigo-*`، …) —
في أي ملف تحت `src/app/` أو `src/components/`. الاستثناء الوحيد: كتلة `@theme` وكتلة
`[data-theme="dark"]` في `globals.css` نفسه، وقيم `stroke`/`fill` داخل خلفيات SVG المضمّنة
هناك.

**نطاق الاستبدال المقيس**: ٥٣٠ استخداماً للوحة الافتراضية في `(app)` · ٠ في
`(public)` و`components/` · ٥٧ موضع `bg-white`.

---

## جرد المكوّنات

### المصدر — `components/marketplace/` (٢٨ + ٣ حالات)

**ينتقل إلى `components/ui/`** — عام بطبيعته، يستهلكه الطرفان:

| المكوّن | الحالة |
|---|---|
| `Pagination.tsx` | ينتقل كما هو |
| `SubmitButton.tsx` | ينتقل ويصير أساس `Button` |
| `PhoneInput.tsx` | ينتقل كما هو |
| `states/LoadingSkeleton.tsx` · `states/EmptyState.tsx` · `states/ErrorState.tsx` | تنتقل كما هي — تحقّق `FR-018` سلفاً |
| `ThemeToggle.tsx` | ينتقل — صار مطلوباً في اللوحة (`FR-009`) |

**يبقى في `marketplace/`** — خاص بالسوق العام: `TeacherCard` · `CourseCard` ·
`TrustScoreBadge` · `TrustScoreBreakdown` · `StarRating` · `SubjectsGrid` ·
`TestimonialsCarousel` · `FaqAccordion` · `ReviewForm` · `ReviewsTab` · `ProfileTabs` ·
`AvailabilityCalendar` · `SiteHeader` · `SiteFooter` · `FloatingActions` ·
`PolicyPlaceholder` · نماذج التسجيل الأربعة · `TeacherFilters` · `CourseFilters` ·
`NotificationPreferences` · `AddChildForm`.

### الإضافة — يُستخرَج من الأنماط المكرّرة في `(app)`

`Button` · `Input` · `Select` · `Textarea` · `Field` (تسمية + رسالة خطأ + وصف) ·
`Table` · `Card` · `Badge` · `Alert` · `Modal`.

واجهات هذه المكوّنات وسلوكها في [contracts/ui-components.md](./contracts/ui-components.md).

**قاعدة مُلزِمة** (`FR-020`, `SC-004`): المنقول **يُحذَف** من موضعه القديم وتُحدَّث
استيراداته. **يُمنع** إبقاء ملف يُعيد التصدير من الموضع الجديد — التوافق الخلفي مع مستهلك
داخل نفس المستودع تعقيد بلا مشكلة.

---

## جرد الصفحات

| المجموعة | العدد | الإجراء |
|---|---|---|
| `(app)/(shell)/*` | ١٨ | تعريب كامل · رموز · مكتبة مشتركة · الحالات الثلاث لكل قائمة |
| `(app)/{login,register,invitations,certificates/verify}` | ٤ | تعريب كامل · رموز |
| `(public)/*` | ١٤ + `not-found` | لا تعريب (عربية سلفاً) · استبدال ١٩ موضع `bg-white` · تصحيح ٣ أصناف فيزيائية |

**الصفحات ذات القوائم** الملزَمة بالحالات الثلاث (`FR-018`, `SC-005`): `dashboard` ·
`enrollments` · `exams` · `certificates` · `orders` · `members` · `workspaces` ·
`manage/courses`.

---

## تحوّلات الحالة

لا آلة حالات في هذه المرحلة. الانتقال الوحيد هو تبديل السمة:

```
light ⇄ dark      المُطلِق: ThemeToggle
                  الأثر: كتابة localStorage['theme'] + ضبط documentElement.dataset.theme
                  القيد: تُقرأ متزامنةً في <head> قبل أول رسم — بلا وميض (SC-007)
```
