# Contract: المكتبة المشتركة (`components/ui/`)

**Feature**: `002-arabic-rtl-app-shell` · **الموقع**: `frontend/src/components/ui/`

عقد بين المكتبة ومستهلكيها — `(app)` و`(public)` ومراحل ٠٠٣–٠١٥ القادمة. الغرض أن يجد
كاتب شاشة جديدة عنصراً جاهزاً معرّباً متّسقاً، فلا يخترع نمطاً بصرياً ثالثاً (`FR-019`).

---

## ٠ — القواعد الحاكمة

1. **بلا `className` حرّ.** المكوّن لا يقبل `className` من الخارج. المظهر يُتحكَّم فيه
   بـ`variant`/`size` معدودة. **السبب**: `className` الحرّ يجعل كل مكوّن قابلاً للانحراف
   موضعياً، فيُفرَغ `SC-004` من معناه ويعود التعدّد من الباب الخلفي — كما يُلغي الحاجة إلى
   `tailwind-merge` التي منعها `NFR-005`.
   **الاستثناء الوحيد**: خاصية `layout` معدودة (`"block" | "inline"`) عند الحاجة الحقيقية.
2. **بلا حالة داخلية إلا لما هو بصري بحت** (فتح/إغلاق قائمة). البيانات والتحقّق ملك
   المستهلك.
3. **كل نصّ ظاهر يأتي prop** — لا نصّ عربي مثبّت داخل مكوّن عام عدا ما لا معنى لتغييره
   («جارٍ التحميل…»).
4. **يُمنع** `right`/`left` في أي مكوّن — منطقية حصراً.
5. **يُمنع** بقاء نسخة ثانية بعد النقل (`FR-020`). لا ملف يُعيد التصدير.

---

## ١ — `Button`

```ts
type ButtonProps = {
  children: ReactNode
  variant?: "primary" | "secondary" | "ghost" | "danger"   // default: "primary"
  size?: "sm" | "md" | "lg"                                 // default: "md"
  type?: "button" | "submit"                                // default: "button"
  disabled?: boolean
  loading?: boolean          // يعطّل + يعرض مؤشّراً + aria-busy
  iconStart?: ReactNode      // بداية السطر — يمين في RTL
  iconEnd?: ReactNode
  onClick?: () => void
  fullWidth?: boolean
}
```

**الالتزامات**: `loading` يعطّل الزر ويمنع النقر المزدوج · مؤشّر التركيز الموحّد · ارتفاع
الهدف اللمسي ≥ ٤٤px عند `md` و`lg` · `danger` يستعمل `bg-danger` بمقدّمة بيضاء.

**المصدر**: يُبنى من `SubmitButton.tsx` القائم (سلوك `loading` مُنفَّذ فيه سلفاً).

---

## ٢ — `Field` · `Input` · `Textarea` · `Select`

`Field` هو الغلاف الذي يحمل التسمية والوصف ورسالة الخطأ ويربطها بـ`aria` صحيح. المدخلات
الثلاثة تُستخدم داخله.

```ts
type FieldProps = {
  label: string            // عربي — يظهر ويُربط بـ htmlFor
  htmlFor: string
  error?: string           // عربي — يظهر ويُربط بـ aria-describedby ويضبط aria-invalid
  hint?: string
  required?: boolean       // يعرض علامة + aria-required
  children: ReactNode
}

type InputProps = {
  id: string
  name: string
  type?: "text" | "email" | "password" | "number" | "date" | "time" | "search"
  value: string
  onChange: (value: string) => void
  placeholder?: string
  disabled?: boolean
  invalid?: boolean
  autoComplete?: string
  inputMode?: "text" | "numeric" | "tel" | "email"
}

type SelectProps = {
  id: string
  name: string
  value: string
  onChange: (value: string) => void
  options: Array<{ value: string; label: string }>   // label عربي
  placeholder?: string      // خيار فارغ في الرأس
  disabled?: boolean
  invalid?: boolean
}
```

**الالتزامات**:
- الخطأ يُربط بـ`aria-describedby` **و**`aria-invalid` معاً — أحدهما وحده لا يكفي لقارئ الشاشة.
- الرقمي يحمل `inputMode="numeric"` — لوحة مفاتيح الجوال، لا شكل الحقل.
- الحقل الذي يُعرض فيه رقم دولي (`+974…`) يُلفّ محتواه بـ`<bdi>` (`FR-005`).
- **يُمنع** الاعتماد على `placeholder` كتسمية.

---

## ٣ — `Table`

```ts
type Column<T> = {
  key: string
  header: string                       // عربي
  render: (row: T) => ReactNode
  align?: "start" | "end"              // منطقي — لا left/right
  numeric?: boolean                    // يلفّ القيمة بـ <bdi> ويحاذيها end
}

type TableProps<T> = {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string
  caption: string                      // عربي — مطلوب، sr-only إن لم تُعرض
  state?: "loading" | "empty" | "error"
  emptyMessage?: string
  onRetry?: () => void                 // مطلوب عند state === "error"
}
```

**الالتزامات**:
- ترتيب الأعمدة من **البداية** — أول عنصر في `columns` هو أقصى اليمين في RTL. لا عكس يدوي
  للمصفوفة (`FR-014`).
- التمرير الأفقي داخل غلاف `overflow-x-auto` — **يُمنع** أن يتحرّك جسم الصفحة أفقياً (`SC-008`).
- `caption` إلزامية — جدول بلا وصف لا يُقرأ بقارئ الشاشة.
- `state` يعرض `LoadingSkeleton`/`EmptyState`/`ErrorState` مكان الصفوف — **حالة واحدة
  معرّفة، لا ثلاثة تنفيذات مختلفة في ثلاث شاشات** (`FR-018`).

---

## ٤ — الحالات الثلاث

```ts
type LoadingSkeletonProps = { rows?: number; variant?: "list" | "card" | "table" }
type EmptyStateProps      = { title: string; description?: string; action?: ReactNode }
type ErrorStateProps      = { title?: string; description?: string; onRetry: () => void }
```

**تنتقل كما هي** من `marketplace/states/` — تحقّق `FR-018` سلفاً وتستهلك الرموز.
`onRetry` **إلزامية** في `ErrorState`: خطأ بلا مخرج ليس حالة خطأ، بل طريق مسدود (`FR-018`).

---

## ٥ — `Card` · `Badge` · `Alert`

```ts
type CardProps  = { children: ReactNode; padding?: "none" | "sm" | "md"; as?: "div" | "article" | "section" }
type BadgeProps = { children: ReactNode; tone?: "neutral" | "success" | "warning" | "danger" | "info" }
type AlertProps = { tone: "info" | "success" | "warning" | "danger"; title: string; children?: ReactNode; onDismiss?: () => void }
```

**الالتزامات**: `Card` = `bg-surface-raised` + `border-line` + `rounded-2xl` — بلا ظلّ،
اتّباعاً لنمط `(public)` القائم · `Alert` يحمل `role="status"` للمعلوماتي و`role="alert"`
للخطر · **يُمنع** أن يكون اللون وحده حامل المعنى: كل `tone` يقترن بأيقونة ونصّ.

---

## ٦ — `Pagination` · `ThemeToggle` · `PhoneInput` · `Modal`

| المكوّن | الحالة | ملاحظة |
|---|---|---|
| `Pagination` | ينتقل كما هو | أسهمه من القائمة الاتجاهية — تنعكس في RTL |
| `ThemeToggle` | ينتقل كما هو | يكتب `localStorage['theme']` — مفتاح واحد للمنتج |
| `PhoneInput` | ينتقل كما هو | يحمل `<bdi>` سلفاً |
| `Modal` | **جديد** | تركيز محبوس · `Esc` يغلق · `aria-modal` · يعيد التركيز إلى المُطلِق عند الإغلاق |

---

## ٧ — عقد الاختبار

كل مكوّن في `components/ui/` **يجب** أن يُستهلَك في شاشة حقيقية قبل الدمج. **يُمنع** إضافة
مكوّن لحاجة متوقّعة (الدستور: «تبرير التعقيد… بمشكلة قائمة الآن، لا بحاجة متوقّعة»).

الفحوص المُلزِمة:

| # | الفحص | المعيار |
|---|---|---|
| ١ | صفر ملف مكرّر بين `marketplace/` و`ui/` | `SC-004` |
| ٢ | صفر قيمة لونية حرفية في `ui/` | `SC-003` |
| ٣ | صفر `right`/`left` فيزيائي في `ui/` | `FR-015` |
| ٤ | كل قائمة في اللوحة تعرض الحالات الثلاث | `SC-005` |
| ٥ | صفر انتهاك axe من فئة `serious`/`critical` | `SC-006` |
| ٦ | `npx tsc --noEmit` بلا `any` جديد | `NFR-002` |
