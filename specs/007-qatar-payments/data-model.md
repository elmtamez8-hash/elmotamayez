# Data Model — 007 `qatar-payments`

**السند:** `research.md` §ي (التصنيف) · §ب (لا دفتر رابع) · §د (الوحدات الصغرى) · دستور v1.2.0 §I

> **جدولان جديدان فقط، وثلاث هجرات على القائم.** المرحلة تُوسّع ما شُحن ولا تبني موازياً له
> — وكل كيان هنا مصنَّف صراحةً في إحدى طبقات الدستور الثلاث قبل هجرته، كما يوجب §I.

---

## أ — نظرة عامة

```
Order ─────────────► PaymentTransaction ────► CreditPurchase ────► CreditTransaction
 (قائم، يُهاجَر)        (قائم، يُوسَّع)            (قائم ‎006‎)         (قائم ‎006‎ — الدفتر)
   │                        ▲                                            │
   │                        │                                            ▼
   │                 ProviderCallback ★                            CreditAllocation
   │                   (جديد)                                        (قائم ‎006‎)
   │                        ▲
   └──► media('receipt')    │
        (قائم، خاصّ)   PaymentReconciliationRun ★
                            (جديد)

                    activity_log ← BillingAuditSubjects ★ (قائمة قراءة، لا جدول)
```

★ = يُنشأ في هذه المرحلة. وما عداه قائم.

---

## ب — `payment_transactions` — الكيان المركزي (قائم، يُوسَّع)

**الطبقة: مملوك لمساحة العمل.** الدفعة مقابل خدمةِ مدرّسٍ بعينه. `BelongsToWorkspace` قائم ✅

| العمود | اليوم | بعد | لماذا |
|---|---|---|---|
| `uuid` | **غير موجود** | `uuid unique` | `NFR-005`: المسارات والحمولات تكشف الـuuid وحده — وهذه المرحلة أوّل من يكشف الجدول على مسار |
| `amount` | `decimal(12,2)` | `bigInteger` وحدات صغرى | `NFR-007` + `research.md` §د |
| `currency` | `char(3) default 'USD'` | `char(3) default 'QAR'` | سوق قطر (`PRODUCT.md`) — الافتراض الحالي عطلٌ صامت |
| `status` | `string` حرّ | `string` + قائمة `PaymentStatus` | حالةٌ حرّة لا تُغلق مساراً |
| `reference` | `string nullable` | + **`unique(provider, reference)`** | يُغلق سباق `ApproveOrder` (‏§هـ) |
| `payload` | `json nullable` | كما هو، **بلا بيانات وسيلة دفع** | `FR-003` · `FR-030` · `NFR-010` |
| `failure_reason` | — | `string nullable` | `FR-008`: سببٌ مفهوم يُعرَض للطالب |
| `settled_at` | — | `timestamp nullable` | متى أقرّ المزوّد نهائياً — مُدخل التسوية |

**حالات `PaymentStatus` (قائمة مغلقة):**

```
Initiated ──► Pending ──┬─► Captured ──► Reversed        (نزاع بنكي — د4)
                        ├─► Failed
                        └─► Expired                       (تجاوزت المهلة — FR-015)
```

⚠️ **`Captured` نهائيّةٌ إلا عبر `Reversed`.** ولا انتقال من `Failed` إلى `Captured` بلا
مراجعة بشرية: `FR-014` يمنع التصحيح الآلي **في اتجاه يضرّ الطالب**، والاتجاه المعاكس
(‏`Pending → Captured` حين تجده التسوية ناجحاً عند المزوّد) آليٌّ بحقّ — فهو يخدم الطالب.

---

## ج — `provider_callbacks` ★ (جديد)

**الطبقة: مملوك لمساحة العمل.** يُشتقّ مستأجره من الطلب المرجعيّ — لا من سياق الطلب
الشبكي، فالمسار عامّ و`WorkspaceScope` عديم الأثر عليه (‏§ز).
**حالة إلزامية في `WorkspaceIsolationTest` في نفس الـPR** (الدستور §I).

| العمود | النوع | ملاحظة |
|---|---|---|
| `uuid` | `uuid unique` | |
| `workspace_id` | `unsignedBigInteger` | مُشتقّ، لا مقروء من الطلب |
| `payment_transaction_id` | `nullable` | **nullable عمداً**: الإشعار قد يسبق كتابة العملية (حالة حافة معلَنة) |
| `provider` | `string` | |
| `external_id` | `string` | معرّف الحدث عند المزوّد |
| `signature_valid` | `boolean` | نتيجة التحقّق، مخزَّنة لا مستنتَجة |
| `payload` | `json` | ⚠️ **مُنقّى**: بلا بطاقة ولا سرّ (`FR-030`) |
| `received_at` · `processed_at` | `timestamp` | الثانية `nullable` |
| `result` | `string` | `accepted` · `rejected_signature` · `duplicate` · `deferred` |

**الفهرس الحاسم:** `unique(provider, external_id)` — **هو** ضمانُ `FR-006` و`SC-003`.

⚠️ **ويُكتب بـ`create()` داخل `try/catch`، لا بـ`insertOrIgnore`.** الشكل الثاني لا يُقلع
النموذج فلا تعمل `HasUuid`، والقاعدة كاملةً في `CLAUDE.md`. *(الدفتر في ‎006‎ يستعمل
`insertOrIgnore` بحقٍّ لأنه يمرّر `uuid` صراحةً ثم يقرأ عكسياً — وهو استثناءٌ بشرطه، لا
رخصة.)*

⚠️ **والإشعار المرفوض توقيعه يُخزَّن أيضاً.** `FR-005` يمنع أثره، ولا يمنع تسجيله —
و`SC-002` يطلب «الرفض **والتسجيل**». سجلٌّ لا يحفظ المحاولة الفاشلة يخفي الهجوم بالضبط.

---

## د — `payment_reconciliation_runs` ★ (جديد)

**الطبقة: بلا مفتاح مستأجر.** جولةٌ على مستوى المنصّة تعبر كل المساحات — الشكل نفسه الذي
شُحن في `credit_reconciliation_runs` (‏006)، وهو مقصود ومُوثَّق هناك.

| العمود | النوع |
|---|---|
| `ran_at` · `window_from` · `window_to` | `timestamp` |
| `checked_count` · `corrected_count` · `unresolved_count` | `unsignedInteger` |
| `findings` | `json` — عيّنة محدودة |

⚠️ **`findings` عيّنة، و`unresolved_count` هو العدد الحقيقي دائماً**، والتشغيل المبتور يقول
ذلك في سجلّه. سقفٌ يبلّغ عن نفسه بوصفه «كل شيء» هو كيف يُقرأ نشرٌ مكسور كثلاث مشاكل بدل
تسعة آلاف — الدرس نفسه المكتوب في `ReconcileCreditBalancesJob`.

⚠️ **والمشيات كلها `chunkById`، لا `chunk`.** `chunk` يُرقّم بـOFFSET فتمشي الصفحة `k` عبر
`k×500` صفّاً: ‎O(n²)‎ زيارة صفّ في كل جولة، على أسرع جداول المرحلة نمواً.

---

## هـ — `orders` (قائم، تُهاجَر)

| العمود | اليوم | بعد |
|---|---|---|
| `amount` | `decimal(12,2)` | `bigInteger` وحدات صغرى |
| `currency` | `char(3)` | افتراضٌ `QAR` |

**ولا تتغيّر:** `status` · `approved_by` · `approved_at` · `rejection_reason` · مجموعة
الوسائط `receipt`. **ولا يُنشأ `PaymentReceipt` ولا `PaymentApproval` ككيانين** — الأول
مجموعةُ وسائط على القرص الخاص برابطٍ موقّع ‎١٥‎ دقيقة، والثاني ثلاثةُ أعمدة على الطلب،
وكلاهما مشحون ويعمل (‏`research.md` §و). كيانٌ جديد لما هو قائم هو نسخةٌ ثانية تتباعد.

---

## و — `BillingAuditSubjects` ★ — قائمة قراءة، لا جدول

**لا هجرة.** `activity_log` جدولٌ واحد مشترك، والحارس هو **شكل الاستعلام**: صنفٌ نهائيّ
يعلن أنواع الموضوعات المسموحة، ولا فرع فيه يسأل عن أكثر.

`Order` · `PaymentTransaction` · `CreditTransaction` · `CreditPurchase` · `CreditBalance`
· `TermsConsent`

**الصورة المقابلة لـ`SettlementAuditSubjects`**، وبالسبب نفسه: مدقّق أجور المدرّسين لا يرى
ما دفعه طالب، ومدقّق مدفوعات الطلاب لا يرى أجر مدرّس — بالبناء، لا بمرشِّح يُنسى فرعه.

**والحقول المضافة على النداءات المالية وحدها** (‏`FR-023`): عنوان الشبكة والجهاز.
⚠️ **يُمنع تعديل سمة `LogsActivity` المشتركة** — عشرون Action في ستّ وحدات تستعملها،
وحقلان لا معنى لهما في سياق «نشر كورس» هما ضريبةٌ على كل المنتج لأجل صفحة واحدة.

---

## ز — ما لا يُنشأ، ومكتوبٌ لماذا

| الكيان في السبيك | القرار | السبب |
|---|---|---|
| `CollectionTransaction` | **يسقط** | دفترٌ رابع يقيّد ما يقيّده `credit_transactions`؛ ‎015‎ `Q1` (`research.md` §ب) |
| `PaymentReceipt` | **يسقط** | مجموعة وسائط قائمة برابط موقّع — `FR-019` منفَّذ كاملاً |
| `PaymentApproval` | **يسقط** | ثلاثة أعمدة على `orders` + قيدُ تدقيق |
| محفظة نقدية | **تسقط** | `Q4` ألغاها و`FR-025` يمنع رصيداً ثانياً |
| جدول تجميع لسجلّ التحصيل | **يؤجَّل** | استعلامٌ مُجمَّع على فهرس أوّلاً؛ الترقية بقياس لا بظنّ (§ط) |
| أي كيان تسوية مدرّس | **ممنوع** | ‎014‎ — و`ContextIsolationTest` يُسقط البناء |

---

## ح — الفهارس، مُعلَنةً في المواصفة (‏`NFR` قابلية التوسّع)

| الجدول | الفهرس | يخدم |
|---|---|---|
| `payment_transactions` | `unique(provider, reference)` | سباق الاعتماد · `SC-007` |
| `payment_transactions` | `(workspace_id, status, created_at)` | سجلّ التحصيل · `SC-014` |
| `payment_transactions` | `(status, settled_at)` | مسح التسوية |
| `provider_callbacks` | `unique(provider, external_id)` | `FR-006` · `SC-003` |
| `provider_callbacks` | `(processed_at)` | طابور المؤجَّل |

⚠️ **`whereDate()` ممنوعة على هذه الأعمدة**: دالّةٌ تلفّ العمود تُفقده فهرسه، والمقارنة
تكون بنصّ تاريخ. وحين يكون العمود طابعاً زمنياً والحدّ تاريخاً فالحدّ الأعلى **بداية اليوم
التالي** — `<= ends_on` يُسقط كل ما بعد منتصف ليل آخر يوم بصمت. الدرس نفسه كلّف ‎005‎
إصلاحاً و‎014‎ آخر.
