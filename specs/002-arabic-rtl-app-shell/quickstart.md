# Quickstart — التحقّق من `002-arabic-rtl-app-shell`

**Feature**: `002-arabic-rtl-app-shell` · **Date**: 2026-08-05

دليل تشغيل يُثبت أن المرحلة سُلِّمت. كل قسم مرتبط بمعيار نجاح في
[spec.md](./spec.md#success-criteria-mandatory)، ويُنفَّذ كما هو مكتوب بلا تعديل.

> **قاعدة بيئية**: **يُمنع** تشغيل `npm run build` وخادم `npm run dev` معاً — كلاهما يكتب في
> `.next/` فيموت خادم التطوير بـ`Cannot find module './NNN.js'`. أوقف الأول قبل الثاني.

---

## ٠ — المتطلّبات

```bash
# الخلفية — نافذة أولى
cd D:\mteatch\backend
php artisan migrate:fresh --seed
php artisan serve                       # :8000

# الواجهة — نافذة ثانية
cd D:\mteatch\frontend
npm install
npm run dev                             # :3000
```

بيانات الدخول المزروعة: `teacher@example.com` و`student@example.com` — كلمة المرور
`password`.

**الخلفية إلزامية** لكل ما يلي، لا للـe2e وحدها: الصفحات العامة تجلب منها، وصفحات اللوحة
لا تُفتَح إلا بعد مصادقة (راجع [research.md](./research.md) قرار R5).

---

## ١ — الاتصال البصري بين المنطقتين (US1 · SC-001 · SC-007)

1. افتح `http://localhost:3000` — عربية، من اليمين لليسار، بخط Cairo.
2. بدّل إلى الوضع الليلي من الترويسة.
3. سجّل الدخول بـ`student@example.com`.
4. **المتوقّع**: تفتح `/dashboard` بنفس اللغة والاتجاه والخط والسمة — **بلا ومضة بيضاء**
   عند التحميل.
5. تنقّل بين الصفحات الست: `/dashboard` · `/enrollments` · `/exams` · `/certificates` ·
   `/orders` · `/settings`. لا انقطاع بصري في أيّ منها.
6. **الشريط الجانبي على يمين الشاشة** والمحتوى إلى يساره.

**فحص آلي**: في كل صفحة، من كونسول المتصفّح:

```js
document.documentElement.lang     // "ar"
document.documentElement.dir      // "rtl"
document.documentElement.dataset.theme  // "dark" — كما تُرك في الخطوة ٢
```

**فحص الومضة (SC-007)** — الفحص اليدوي لا يكفي، العين تفوّت إطاراً واحداً:

```bash
npx playwright test e2e/rtl.spec.ts --grep "flash" --project=desktop-dark
```

---

## ٢ — صفر نصّ إنجليزي (SC-002)

```bash
npx playwright test e2e/rtl.spec.ts --grep "latin"
```

**المتوقّع**: مرور. أي بقيّة لاتينية خارج `ALLOWED_LATIN` تُطبَع باسم الصفحة والمُحدِّد.

**فحص يدوي مكمّل**: افتح `/dashboard` وابحث بصرياً في الشريط الجانبي — «لوحة التحكم»،
«الكورسات»، «تعلّمي»، «الاختبارات»، «الشهادات»، «الطلبات»، «الإدارة»، «تسجيل الخروج».
لا `Dashboard` ولا `Sign out` ولا `Administration`.

---

## ٣ — صفر لون مكتوب يدوياً (SC-003)

```bash
cd D:\mteatch\frontend
grep -rnE '\b(bg|text|border|ring|from|to)-(gray|indigo|blue|red|green|yellow|amber|slate|zinc|neutral|emerald|purple|pink|orange)-[0-9]{2,3}\b' src/app src/components
grep -rnE '\b(bg-white|bg-black|text-black)\b' src/app src/components
grep -rnE '#[0-9a-fA-F]{3,8}\b|rgb\(|hsl\(' src/components src/app --include='*.tsx'
```

**المتوقّع**: صفر نتيجة من الثلاثة.

> **`text-white` ليس مخالفة** ولا يلتقطه أيٌّ من الأوامر أعلاه: هو المقدّمة المفروضة فوق
> `bg-primary` و`bg-secondary` و`bg-danger` في [عقد الرموز §٢٫١](./contracts/design-tokens.md)،
> ومستعمَل هكذا في الموقع العام المشحون. المخالفة هي `bg-white` — سطحٌ مكتوب يدوياً.

**خطّ الأساس قبل البدء**: ٥٣٠ نتيجة في `(app)` · ٠ في `(public)` و`components/` ·
٥٧ موضع `bg-white`. الرقم النهائي صفر — وهذا هو مقياس التقدّم أثناء التنفيذ.

**استثناء مسموح**: `globals.css` وحده (كتلتا `@theme` و`[data-theme="dark"]` وخلفيات SVG
المضمّنة). الأمران أعلاه لا يشملانه.

---

## ٤ — صفر اتجاه فيزيائي (FR-015)

```bash
grep -rnE '\b(ml|mr|pl|pr)-[0-9]|\b(left|right)-[0-9]|\btext-(left|right)\b|\bborder-(l|r)\b|\brounded-(l|r)-' src/app src/components
```

**المتوقّع**: صفر نتيجة. `inset-y-*` و`top-*` و`bottom-*` مسموحة ولا يلتقطها التعبير.

**خطّ الأساس**: ١٦ موضعاً في `(app)` و٣ في `(public)`.

**فحص بصري للأيقونات (FR-012 / FR-013)**: افتح `/manage/courses` وانتقل بين الصفحات —
سهم «التالي» يشير **يساراً** وسهم «السابق» **يميناً**. ثم افتح أي شاشة فيها بحث — عدسة
البحث **غير مقلوبة** ومقبضها في مكانه الطبيعي.

---

## ٥ — رسائل عربية (US2 · FR-016 · FR-017)

1. افتح `/login` وأرسل النموذج فارغاً.
   **المتوقّع**: «حقل البريد الإلكتروني مطلوب» — بالمسمّى العربي لا `email`.
2. أدخل بريداً صحيحاً وكلمة مرور خاطئة.
   **المتوقّع**: رسالة عربية مفهومة، لا `These credentials do not match our records.`
3. أوقف الخلفية (`Ctrl+C` في نافذة `php artisan serve`) وأعد المحاولة.
   **المتوقّع**: «تعذّر الاتصال. تحقّق من اتصالك بالإنترنت.» — لا `Failed to fetch`.
4. أعد تشغيل الخلفية، وسجّل الدخول بـ`student@example.com`، ثم افتح `/workspaces`.
   **المتوقّع**: رسالة صلاحيات عربية أو حالة فراغ عربية — لا نصّ استثناء تقني.

**فحص من الخلفية مباشرةً**:

```bash
cd D:\mteatch\backend
php artisan tinker --execute="app()->setLocale('ar'); dump(trans('validation.required', ['attribute' => trans('validation.attributes.phone_number')]));"
```

**المتوقّع**: نصّ عربي يحوي «رقم الهاتف».

---

## ٦ — الحالات الثلاث (SC-005)

لكل قائمة من الثماني — `dashboard` · `enrollments` · `exams` · `certificates` ·
`orders` · `members` · `workspaces` · `manage/courses`:

| الحالة | كيف تُستحضَر | المتوقّع |
|---|---|---|
| **تحميل** | خنق الشبكة إلى `Slow 3G` من DevTools وحدّث | هيكل تحميل عربي، لا شاشة بيضاء |
| **فراغ** | ادخل بحساب جديد بلا بيانات | عنوان عربي + الإجراء التالي (راجع [contracts/error-messages.md](./contracts/error-messages.md#٥--حالات-الفراغ-fr-018)) |
| **خطأ** | أوقف الخلفية وحدّث | رسالة عربية + **زر إعادة محاولة يعمل** |

**تمييز مُلزِم**: طبّق تصفية بلا نتائج — الرسالة **يجب** أن تكون «لا نتائج تطابق التصفية»
مع زر «امسح التصفية»، لا رسالة «لا بيانات» التي تدعو للإنشاء.

---

## ٧ — إمكانية الوصول (SC-006)

```bash
cd D:\mteatch\frontend
npx playwright test e2e/accessibility.spec.ts
```

٦ مشاريع (٣٦٠/٧٦٨/١٤٤٠ × نهاري/ليلي) × كل الصفحات العامة وصفحات اللوحة.

**المتوقّع**: صفر انتهاك. الفشل يُطبع بمعرّف القاعدة والمُحدِّد — لا عدداً مجرّداً.

**فحص لوحة المفاتيح يدوياً**: من `/dashboard`، اضغط `Tab` من أعلى الصفحة.
**المتوقّع**: أول توقّف هو «تخطَّ إلى المحتوى الرئيسي»، ثم مؤشّر تركيز **ظاهر** على كل
عنصر تفاعلي بعده، وترتيب التنقّل يتبع الترتيب البصري من اليمين لليسار.

---

## ٨ — بلا تمرير أفقي عند ٣٦٠ بكسل (SC-008)

```bash
npx playwright test e2e/rtl.spec.ts --grep "overflow" --project=mobile-light
```

**فحص يدوي**: DevTools ← عرض ٣٦٠ ← افتح `/manage/courses`.
**المتوقّع**: الجدول يُمرَّر أفقياً **داخل غلافه**؛ جسم الصفحة لا يتحرّك.

---

## ٩ — صفر تكرار في المكوّنات (SC-004)

```bash
cd D:\mteatch\frontend
ls src/components/ui/
grep -rn "from \"@/components/marketplace/\(Pagination\|SubmitButton\|PhoneInput\|ThemeToggle\|states\)" src/
```

**المتوقّع**: المجلد يحوي المكوّنات المنقولة، والبحث الثاني يعطي صفر نتيجة — لا استيراد
باقٍ من الموضع القديم، ولا ملف يُعيد التصدير منه (`FR-020`).

---

## ١٠ — البوابات (SC-009 · الدستور IV)

```bash
# الواجهة
cd D:\mteatch\frontend
npx tsc --noEmit
npm run test:e2e

# الخلفية — تسري لأن المرحلة تلمس lang/ و APP_LOCALE
cd D:\mteatch\backend
php vendor/bin/pest
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

**الأربع خضراء إلزاماً.** بوابة `pest` هي التي تكشف أي اختبار قائم يؤكّد نصّ رسالة تحقّق
إنجليزياً — يُصلَح، ولا يُستثنى ولا يُسكَت.

> **قبل `npm run test:e2e`**: أوقف `npm run dev`. الاختبارات تبني نسخة إنتاجية، والاثنان
> يكتبان في `.next/`.

---

## قائمة القبول

| # | المعيار | القسم |
|---|---|---|
| ١ | `lang="ar"` و`dir="rtl"` في ١٠٠٪ من صفحات اللوحة | §١ |
| ٢ | صفر نصّ إنجليزي خارج القائمة المعلَنة | §٢ |
| ٣ | صفر لون مكتوب يدوياً | §٣ |
| ٤ | صفر مكوّن مكرّر | §٩ |
| ٥ | الحالات الثلاث في كل قائمة | §٦ |
| ٦ | صفر انتهاك axe من فئة `serious`/`critical` | §٧ |
| ٧ | صفر ومضة سمة | §١ |
| ٨ | صفر تمرير أفقي عند ٣٦٠ بكسل | §٨ |
| ٩ | البوابات الأربع خضراء | §١٠ |
