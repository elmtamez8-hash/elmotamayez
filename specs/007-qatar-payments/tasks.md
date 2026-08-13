# Tasks: بوابة الدفع وتحصيل الطالب (‏007 `qatar-payments`)

**Input**: `specs/007-qatar-payments/` — `plan.md` · `spec.md` · `research.md` · `data-model.md` · `contracts/` · `quickstart.md`

**Tests**: **مطلوبة صراحةً.** `NFR-003ب` يسمّي اختبارات وحدة وتكامل بالاسم، وتسعة من
خمسة عشر معيار نجاح تقول «**مُثبَتة باختبار**».

**Organization**: بالقصص، لتُسلَّم كلٌّ منها وتُختبر وحدها.

> **النسخة ‎٢‎ — بعد مراجعة ستّة وكلاء (‏2026-08-11).** النسخة الأولى حملت ‎١١٧‎ مهمّة
> و**خمس قاصمات**، ثلاثٌ منها ادّعاءُ وجودٍ لم أفتح ملفّه. التصحيحات موسومة ⚠️ **[‏م‏٢‏]**
> حيث تغيّر القرار، لا حيث زاد الشرح.

---

## الشكل: `[ID] [P?] [Story] الوصف + المسار`

- **[P]** — ملفٌّ مختلف وبلا تبعية على مهمّةٍ ناقصة. **وتُفحَص القاعدة داخل الطور وعبره**.
- **[Story]** — في أطوار القصص وحدها.
- **⚠️ سلسلة الهجرات لا تحمل `[P]` أبداً**: ثلاثٌ منها تُسقط النشر إن سبقت تنظيفها.

---

## ⚠️ ما لا يُبنى، مكتوباً هنا كي لا يُبنى سهواً

| ما يذكره السبيك | القرار | السند |
|---|---|---|
| `CollectionTransaction` (‏`Key Entities` · `US3` سيناريو ‎٨‎) | **لا مهمّة له** — دفترٌ رابع | `Clarifications 2026-08-10` · `research.md` §ب · ‎015‎ `Q1` |
| `PaymentReceipt` · `PaymentApproval` ككيانين | **لا مهمّة لهما** — وسائط وأعمدة قائمة | `data-model.md` §هـ |
| محفظة نقدية | **لا مهمّة لها** | `Q4` · `FR-025` |
| `refund()` على العقد أو استدعاؤها | **لا مهمّة لها — وغيابُها هو المنع** | `research.md` §د3 |
| «مدرّس عليه رصيد سالب» | **خارج النطاق** — يناقض `FR-035` و`Q6` | `spec.md` §Clarifications |
| محوّل بوابةٍ حقيقية | **مؤجَّل بقرار المالك** | `Q8` |
| **`NFR-013`** كشف حساب مدرّس بـ‎١٠٬٠٠٠‎ عملية | ⚠️ **[‏م‏٢‏]** **متقادم وخارج النطاق** — `Q6` نقل كشف المدرّس إلى ‎014‎ و`FR-035` يمنعه هنا. نظيره داخل النطاق `SC-014` ومغطّى. **[‏تحليل] وسُحب من `spec.md` نفسه**، لا من هذا الجدول وحده | `spec.md` §NFR |
| **`NFR-001ب`** اختبار كيانٍ من الصنف (أ) | ⚠️ **[‏تحليل]** **غير منطبق ومُعلَن** — لا كيان من الصنف (أ) في المرحلة: حساب الأرصدة وموافقات الشروط مملوكان للمنصّة وشُحنا في ‎006‎ ولا يُلمسان. **والجديدان (ب) ومساحة عمل**، فحارسهما `T134` و`T046` | `research.md` §ي · الدستور §I |

**وخمسةُ متطلبات منفَّذة سلفاً** تُثبَّت باختبار ولا تُبنى: `FR-002` · `FR-019` ·
`FR-021` · `FR-035` · `FR-036` — ومهامها موسومة **«تثبيت»**.

> ⚠️ **[‏م‏٢‏] وسادسٌ خرج من القائمة: `FR-025`.** كتبتُ أنه «منفَّذ كاملاً» ثم لم أعطه
> مهمّةً من أيّ نوع. **ولم يكن منفَّذاً**: `AdjustCredits:62-70` لا يمرّر `enforceFloor`،
> وقيمته الافتراضية `false` (`CreditMovement:38`)، فاسترداد اليوم يهبط بالرصيد تحت الصفر —
> نقيض `research.md` §د3. له الآن أربع مهامّ في الطور ‎٤‎. **و`FR-018` كذلك نصفُه وحده
> منفَّذ**، فله عمودٌ وتعداد.

---

## الطور ‎١‎: التهيئة

- [x] T001 تشغيل البوابات الأربع وتسجيل الحالة الخضراء المرجعية قبل أي تعديل: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` من `backend/`، و`npx tsc --noEmit` من `frontend/`
- [x] T002 [P] إنشاء `backend/config/payments.php` — سجلّ المزوّدين · مهلة العملية المعلّقة (`FR-015`) · **حدّ محاولات الإشعار المؤجَّل بقيمةٍ صريحة ‎١٢‎ محاولة وتراجعٍ أُسّي مُعلَن** (‏⚠️ **[‏م‏٢‏]** كان «حدّاً معلَناً» بلا رقم، ووظيفةٌ بلا رقمٍ تعيد المحاولة أبداً) · قائمة العناوين المسموحة تُقرأ من البيئة بقيمةٍ فارغة
- [x] T003 [P] إضافة مفاتيح البيئة **بلا أي قيمة** إلى `backend/.env.example`: `PAYMENTS_WEBHOOK_ALLOWED_IPS` · `PAYMENTS_PENDING_TIMEOUT_MINUTES` · `PAYMENTS_CALLBACK_MAX_ATTEMPTS` — `FR-010` يوجب البيئة، والمستودع **يُمنع** أن يحمل سرّاً. ولا مفتاح توقيعٍ بعد، لأن المزوّد الحقيقي مؤجَّل (`Q8`) — **وهذا مكتوبٌ في الملف كتعليق**، لا فراغٌ يُقرأ كتغطية
- [x] T004 ⚠️ **[‏م‏٢‏]** إضافة `supervisor-payments` إلى `backend/config/horizon.php` **بكتلةٍ كاملة في `defaults`** (‏`connection` · `queue => ['payments']` · `maxProcesses` · `tries` · `timeout` قصير) **وإدراجٍ في `environments` تحت `production` و`local` معاً** — على شكل `supervisor-maintenance` المشحون بالضبط. ⚠️ **ونسختي الأولى قالت «في `environments` لا `defaults`» فقلبت الدرس**: `defaults` يوفّر القيم و`environments` يقرّر من يعمل، **والاثنان لازمان**؛ وكتلةٌ في `environments` وحدها بلا `connection` تُسقط تزويد **كل** المشرفين. والتعليق المشحون فوق `supervisor-maintenance` يقول ذلك حرفياً
- [x] T005 [P] ⚠️ **[‏م‏٢‏]** إضافة `redis:payments` إلى `waits` في `backend/config/horizon.php` — بدونها لا يُطلق الطابور الجديد `LongWaitDetected` أبداً، فطابورٌ متكدّس لا يقول ذلك لأحد و`202` كان قد أخبر المزوّد أنّ كل شيء بخير

---

## الطور ‎٢‎: الأساس (حاجزٌ لكل القصص)

### ٢أ — سلسلة الهجرات المرتَّبة (‏`data-model.md` §ط) — **بلا `[P]`، وبهذا الترتيب**

- [x] T006 هجرة ‎١‎: `uuid` **قابلاً للإفراغ** على `payment_transactions` في `backend/app/Modules/Payments/Database/Migrations/2026_08_11_000100_add_uuid_to_payment_transactions.php`
- [x] T007 هجرة ‎٢‎: ملء `uuid` بـ**`chunkById`** في `.../2026_08_11_000200_backfill_payment_transaction_uuids.php` — `chunk` يُرقّم بـOFFSET والمُسنِد (`uuid IS NULL`) ينكمش تحته فتُتخطّى صفوفٌ **ويُبلَّغ بالنجاح**
- [x] T008 هجرة ‎٣‎: `unique(uuid)` + غير قابل للإفراغ في `.../2026_08_11_000300_add_uuid_index_to_payment_transactions.php` — و`->change()` يُعيد إعلان **كل** خاصيّة لأن Laravel ‎13‎ يُسقط ما لا يُعاد إعلانه
- [x] T009 هجرة ‎٤‎: إزالة تكرار `(provider, reference)` في `.../2026_08_11_000400_dedupe_payment_transaction_references.php` — ⚠️ **[‏م‏٢‏] والناجي بقاعدةٍ معلنة: أقدمهما بـ`id`، والخاسر يُعاد ترقيم مرجعه بلاحقةٍ مسجَّلة ولا يُحذف.** حذفُ صفٍّ ماليّ في الوحدة نفسها التي يمنع `FR-027` تعديل سجلّها تناقضٌ في الاتجاهين
- [x] T010 هجرة ‎٥‎: `reference` غير قابل للإفراغ + `unique(provider, reference)` في `.../2026_08_11_000500_add_reference_unique_to_payment_transactions.php` — ⚠️ NULL **لا يتصادم** مع NULL في فهرسٍ فريد على المحرّكين معاً، وتُفحص الصفوف الفارغة قبل القيد
- [x] T011 هجرة ‎٦‎: إزالة تكرار `captured` لكل طلب في `.../2026_08_11_000600_dedupe_captured_transactions.php` — **الناجي أقدمهما بـ`id`، والخاسر يُنقل إلى `Mismatch` بسببٍ مسجَّل، لا يُحذف**
- [x] T012 هجرة ‎٧‎: ⚠️ **[‏م‏٢‏] عمود `captured_order_id` قابل للإفراغ + `unique(captured_order_id)`** ومَلؤه من الصفوف `captured` الباقية، في `.../2026_08_11_000700_add_captured_order_id.php` — **قرار المالك ‎2026-08-11‎، ولا فهرسٌ جزئيّ**: `unique(order_id) WHERE status = captured` **لا وجود له في MySQL ‎8‎** ويعمل على SQLite، فيخضرّ كل تشغيلٍ محليّ ويسقط النشر؛ و`unique(order_id)` عارية **تكسر إعادة المحاولة**. والعمود يعمل لأن **NULL لا يتصادم مع NULL** (`data-model.md` §ح)
- [x] T013 هجرة ‎٩‎: `method` · `failure_reason` · `settled_at` في `.../2026_08_11_000900_add_method_and_outcome_to_payment_transactions.php`
- [x] T014 هجرة ‎١٠‎: الفهارس المُعلَنة في `data-model.md` §ح — **`(created_at, status, method)`** · `(status, created_at)` · **`credit_purchases(order_id)`** في `.../2026_08_11_001000_add_reporting_indexes.php`. ⚠️ **[‏م‏٢‏] و`(workspace_id, status, created_at)` غير موجود على هذا الجدول إطلاقاً** — المركّب المشحون على **`orders`**، جدولٌ آخر؛ فهذه إضافةٌ لا استكمال

### ٢ب — التعدادات والعقد والمزوّد

- [x] T015 [P] `PaymentStatus` بالقائمة المغلقة (`Initiated` · `Pending` · `Captured` · `Failed` · `Expired` · `Mismatch` · `Reversed`) ودالّة الانتقالات المسموحة في `backend/app/Modules/Payments/Enums/PaymentStatus.php` — ⚠️ `Captured` نهائيّة إلا عبر `Reversed`، ولا `Failed → Captured` بلا بشر (`FR-014`)، **و[‏م‏٢‏] لا `Reversed → Captured` إطلاقاً**: بوابةٌ تسكّ معرّفاً جديداً لكل إعادة إرسال تمرّ بكل الفهارس وتعيد رفع الحجب بعد نزاعٍ بنكيّ
- [x] T016 [P] `PaymentMethod` (`BankTransfer` · `MobileWallet` · `Gateway`) في `backend/app/Modules/Payments/Enums/PaymentMethod.php` — يغلق نصف `FR-018` الناقص ويفتح بُعد «الطريقة» في `FR-031`
- [x] T017 [P] `ChargeIntent` في `backend/app/Modules/Payments/Data/ChargeIntent.php` — ⚠️ `final class ... extends DataTransferObject` بخصائص `readonly` **مُرقّاة**، لا `final readonly class` (خطأ PHP قاتل لأن الأساس `abstract class` غير `readonly`)، و`redirectUrl` **قابل للإفراغ** مع `instructions` لأن التحويل البنكي بلا صفحة دفع
- [x] T018 [P] `CallbackEvent` في `backend/app/Modules/Payments/Data/CallbackEvent.php` — ⚠️ **[‏م‏٢‏] نفس تحذير `readonly` المُرقّاة حرفياً** (كان في `T017` وحده، والمهمّتان متوازيتان فمنفّذٌ واحد يراه) · `amountMinor` صحيح · `safePayload` مُنقّى عند حدود العقد
- [x] T019 [P] ⚠️ **[‏م‏٢‏]** إضافة `fromArray()` صراحةً إلى `backend/app/Modules/Payments/Data/ChargeIntent.php` و`.../Data/CallbackEvent.php` — `DataTransferObject` **لا يوفّرها**؛ الأصناف الستة عشر التي تملكها تُعلنها بنفسها (`BillingSettingsData:30`)، و`contracts/provider.md:130` يقول عكس ذلك
- [x] T020 توسيع `backend/app/Modules/Payments/Contracts/PaymentProviderInterface.php` بـ`verifySignature(string $rawBody, array $headers)` · `parseCallback(string $rawBody)` · `transactionsInWindow(CarbonImmutable $from, CarbonImmutable $to)`، **وتغيير `createCharge()` إلى `ChargeIntent`** — بأنواع قيمٍ في `@param`/`@return` لأن `array` عارية تسقط على Larastan ‎L8‎
- [x] T021 تنفيذ الدوالّ الثلاث **وتغيير `createCharge()`** في `backend/app/Modules/Payments/Providers/ManualTransferProvider.php` — ⚠️ **`verifySignature()` تعيد `false` أبداً** بتعليقٍ يقول لماذا: مزوّدٌ بلا بوابة لا يرسل إشعارات، فكل ما يصل باسمه انتحال. ⚠️ **[‏م‏٢‏] و`createCharge()` تعيد `array` اليوم (`:24-33`) فتغييرها إلزاميّ لا اختياريّ**، وإلا فـPHP يسقط. والتوقيع يُقارَن بـ`hash_equals` لا `===`
- [x] T022 `backend/app/Modules/Payments/Providers/PaymentProviderRegistry.php` بوسم الحاوية على غرار `->tag('notification.channels')` — المعرّف المجهول والمسجَّل بلا قدرة على استقبال إشعارات كلاهما **‎404‎ لا ‎403‎**
- [x] T023 تحديث `backend/app/Modules/Payments/Models/PaymentTransaction.php`: `HasUuid` · قالب `PaymentStatus` و`PaymentMethod` · `captured_order_id` في `$fillable`. ⚠️ **[‏م‏٢‏] ولا علاقة `providerCallbacks()` هنا** — `ProviderCallback` يُنشئه `T057` في الطور ‎٣‎، وعلاقةٌ إلى صنفٍ غير موجود **تُسقط Larastan L8 عند نقطة تفتيش الطور ‎٢‎**
- [x] T024 `backend/tests/Support/FakePaymentProvider.php` بالحالات **السبع**: نجاح · فشل · تكرار · توقيع غير صالح (`NFR-011`) · **نجاحٌ بلا إرسال إشعار** (مدخل `SC-004` الوحيد) · **مبلغٌ مخالف بتوقيعٍ صحيح** · **معرّف حدثٍ جديد لكل إعادة إرسال** — ⚠️ في `tests/` لا `app/`، على شكل `FakeBroadcastProvider`
- [x] T025 [P] اختبار وحدة لحلّ المزوّد ورفض المجهول في `backend/tests/Unit/Payments/ProviderRegistryTest.php`
- [x] T026 [P] ⚠️ **[‏م‏٢‏]** اختبار القبول المعماريّ الذي يوجبه `NFR-003` نصّاً («اختبار قبول معماري صريح») في `backend/tests/Feature/Payments/ProviderExtensibilityTest.php` — تسجيل مزوّدٍ ثانٍ في الحاوية يعمل **بلا تعديل سطرٍ واحد** في `Actions/` أو `Models/`؛ اختبارُ حلٍّ في السجلّ (`T025`) لا يقيس ذلك

**نقطة تفتيش:** العقد والوهميّ والمخطَّط جاهزون. ⚠️ **والبوابات الأربع تُشغَّل هنا** — `T023` هو الموضع الذي كانت تسقط فيه L8 في النسخة الأولى.

---

## الطور ‎٢‎ــج: توحيد الوحدات الصغرى — ⚠️ **[‏م‏٢‏] صار حاجزاً لـUS1، ولم يعد مجّاني الاقتطاع**

> **لماذا تغيّر تصنيفه:** `T088` (`HandleProviderCallback`) يقارن `$event->amountMinor`
> — وهو `int` — بـ`$transaction->amount_minor`. **وبدون هذا الطور العمود اسمه `amount`
> ونوعه `decimal:2`، وقالب Laravel يعيده نصّاً**، فالمقارنة `int !== string` صادقةٌ أبداً:
> إمّا لا يمرّ المسار السعيد فيرقّع المنفّذ المقارنة بعددٍ عائم ×‎١٠٠‎ **عند أحرج سطرٍ في
> المرحلة**، وإمّا تُكتب فضفاضةً فتكفّ عن الحراسة. والحدّ الأدنى المعلَن في نسختي الأولى
> كان يستثنيه بينما `T094` يقلب البوابة إلى `isReady()` داخل نفس الحدّ الأدنى.
>
> **فإن أراد المالك اقتطاعه فالثمن مُسمّى**: تُكتب المقارنة على `decimal` بتحويلٍ صريح
> موثَّق في مكانٍ واحد، ويُسجَّل خرق `NFR-007` صراحةً.

- [x] T027 هجرة ‎٨‎: `courses.price`/`currency` · `products.price`/`currency` · `orders.amount → amount_minor` · `payment_transactions.amount → amount_minor` في `.../2026_08_11_001100_convert_money_to_minor_units.php` — ⚠️ **الضرب ×‎١٠٠‎ في PHP لا في المحرّك** (‏MySQL وSQLite لا يتّفقان على ما يصير `49.99`)، **وبـ`chunkById` لا `chunk`** [‏م‏٢‏]: المُسنِد `amount_minor IS NULL` ينكمش تحت OFFSET فتُتخطّى صفوف — وصفٌّ متخطّى هنا **سعرٌ مقسومٌ على مئة بصمت**
- [x] T028 تمرير `price_minor` في `backend/app/Modules/Payments/Actions/CreateOrder.php:20`
- [x] T029 [P] حذف `minorToDecimal()` من `backend/app/Modules/Payments/Actions/PurchaseCredits.php:118,166`
- [x] T030 [P] إسقاط `decimal:2` من `backend/app/Modules/Payments/Models/Order.php:51` و`backend/app/Modules/Payments/Models/PaymentTransaction.php:34` — ⚠️ **[‏م‏٢‏] الرقمان مُصحَّحان**: كانا ‎:52‎ و‎:32‎، والسطران هناك `'kind'` وقوس `casts()`
- [x] T031 [P] إسقاط `(float)` من `backend/app/Modules/Payments/Http/Resources/OrderResource.php:20` — ⚠️ **[‏م‏٢‏]** كان ‎:19‎، وهو `'uuid'`
- [x] T032 نسخ المبلغ الصحيح في `backend/app/Modules/Payments/Actions/ApproveOrder.php`
- [x] T033 ⚠️ **[‏م‏٢‏]** قرّاء `courses.price` في `backend/app/Modules/Courses/Models/Course.php`: القالبان `:99,100` · `isFree()` `:182` (‏`(float) $this->price === 0.0`) · **فهرس Scout `:199`** — والجرد كان ناقصاً للمرّة الثالثة
- [x] T034 [P] ⚠️ **[‏م‏٢‏]** `backend/app/Modules/Courses/Http/Resources/CourseResource.php:22` — **سطحٌ عامّ**: `price` مُدرَج في `Marketplace/Support/PublicFieldAllowlist.php:101`، فعرضٌ ×‎١٠٠‎ هنا يظهر لكل زائر
- [x] T035 [P] ⚠️ **[‏م‏٢‏]** `backend/app/Modules/Payments/Models/Product.php:30` والقالب `decimal:2`
- [x] T036 [P] ⚠️ **[‏م‏٢‏]** لوحة Filament: `backend/app/Filament/Resources/OrderResource.php:34,54` (`TextInput::make('amount')` · `TextColumn::make('amount')->money(...)`) — **لم تذكرها مهمّةٌ واحدة في النسخة الأولى**
- [x] T037 [P] ⚠️ **[‏م‏٢‏]** `frontend/src/app/(app)/(shell)/orders/page.tsx` — يستورد `formatMoney` (وحدات كبرى)؛ يتحوّل إلى `formatMinorMoney`
- [x] T038 اختبار وحدة لذهاب المال وإيابه صحيحاً بلا عددٍ عائم في `backend/tests/Unit/Payments/MinorUnitsTest.php`، وتشغيل `php vendor/bin/pest` كاملاً

> ⚠️ **[‏م‏٢‏] وحجّةٌ إضافية للاقتطاع كشفها الكود:** `Course.php:57` يحمل تعليقاً مشحوناً —
> «`price` and `currency` are **FROZEN, not extended**» — فهذا الطور يعيد كتابة نوع
> حقلين مجمّدين بقرارٍ موثَّق. لا يمنع التنفيذ، لكنه يُقرأ قبله.

**نقطة تفتيش:** المال عددٌ صحيح في كل جدولٍ وكل قارئٍ يمسّه المنتج.

---

## الطور ‎٣‎: US1 — الدفع الفوري (P1) 🎯 **الحدّ الأدنى القابل للشحن**

**الهدف:** الطالب يدفع، فيصل إشعارٌ موقَّع، فتُضاف الأرصدة ويُرفع الحجب خلال ثوانٍ بلا بشر.

### اختبارات US1

> ⚠️ **ولا `Queue::fake()` عارية في أيٍّ منها.** مستمع الشحن مطبور، ففاكٌّ بلا وسائط يبتلعه
> فيصير التأكيد على جدولٍ فارغ **وينجح**. تُزيَّف وظائف الخطّ الزمني وحدها.

- [x] T039 [P] [US1] المسار السعيد (‏`SC-001`) في `backend/tests/Feature/Payments/InstantPaymentTest.php` — ويؤكّد **وجود القالب** قبل تأكيد وصول الإشعار
- [x] T040 [P] [US1] ⚠️ **[‏م‏٢‏] الدفعة الفاشلة** (‏`FR-008` · سيناريو القبول الخامس) في `backend/tests/Feature/Payments/FailedPaymentTest.php` — المستحق باقٍ والحجب ساري و`failure_reason` مفهوم يصل الطالب. **لم يكن لها اختبارٌ واحد بين تسعة**، وحالة «فشل» إحدى أربعٍ يسمّيها `NFR-011`
- [x] T041 [P] [US1] التوقيع الباطل (‏`SC-002`) في `backend/tests/Feature/Payments/WebhookSignatureTest.php` — صفر أثر **وصفٌّ مخزَّن** بـ`signature_valid = false`، واستجابةٌ لا تميّز الرفض عن القبول. **و[‏م‏٢‏] حالةٌ ثانية: طلبان مرفوضان متتاليان يُنتجان صفَّين لا صفّاً** (راجع `T060`)
- [x] T042 [P] [US1] التكرار عشراً (‏`SC-003`) في `backend/tests/Feature/Payments/WebhookIdempotencyTest.php` — وحالةٌ بـ**معرّف حدثٍ جديد لكل إعادة إرسال** تُثبت أن الضمان على الأثر لا على الإيصال
- [x] T043 [P] [US1] المبلغ خالف والتوقيع صحيح في `backend/tests/Feature/Payments/AmountMismatchTest.php` — `result = mismatch`، لا شحن ولا أرصدة
- [x] T044 [P] [US1] `manual` لا يقبل إشعاراً ومزوّدٌ مجهول ‎404‎ في `backend/tests/Feature/Payments/WebhookProviderResolutionTest.php`. **و[‏م‏٢‏] حالةٌ ثالثة: إشعارٌ موقّع بسرّ مزوّدٍ يسمّي مرجع مزوّدٍ آخر يُرفض** (راجع `T087`)
- [x] T045 [P] [US1] `PaymentTransactionPolicy` — **مدرّسٌ من نفس المساحة يُردّ بـ‎403‎** في `backend/tests/Feature/Payments/PaymentVisibilityTest.php`، لأن `BelongsToWorkspace` وحده يمرّره
- [x] T046 [P] [US1] حالة عزل في `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` تغطّي `provider_callbacks` **على المسار المتأخّر**، **و[‏م‏٢‏] تؤكّد اتجاهَي السمة معاً** (`must use` / `must NOT use`) كما يفعل الملف في `:305-327` — فقرار `BelongsToWorkspace` على نموذجٍ بمستأجرٍ قابل للإفراغ يقرّر أيضاً هل يرى العاملُ المؤجَّل صفَّه أصلاً
- [x] T047 [P] [US1] اختبار وحدة للمنقّيَين في `backend/tests/Unit/Payments/CallbackSanitizerTest.php` — ⚠️ **[‏م‏٢‏] ولا يُقال إنه يغطّي `hash_equals`**: لا اختبار يقيس زمناً ثابتاً، والقاعدة تُحرَس بالمراجعة لا بالتأكيد
- [x] T048 [P] [US1] النزاع البنكي (‏`د4`) في `backend/tests/Feature/Payments/ChargebackTest.php` — `Reversed` **بقيدٍ جديد لا بتعديل** · الحجب يُعاد تقييمه · **و[‏م‏٢‏] `captured_order_id` يُمسح، والطالب يستطيع الدفع لذلك الطلب ثانيةً** — بدون هذا التأكيد يصير العطل صامتاً ودائماً
- [x] T049 [P] [US1] ⚠️ **[‏م‏٢‏] سباق الويب-هوك ضدّ التسوية** على الصفّ نفسه في `backend/tests/Feature/Payments/CaptureConcurrencyTest.php` — خاسر التحديث الشرطيّ **يتخطّى الأثر**: حدثٌ واحد وسكّةٌ واحدة. لم يكن مغطّى، والحارس الذي ينقذه اليوم هو بالضبط الذي تقول الوثائق إنه ليس الضمان

### تنفيذ US1

- [x] T050 [US1] هجرة `provider_callbacks` في `.../2026_08_11_001200_create_provider_callbacks_table.php` — `workspace_id` **قابل للإفراغ** يُحلّ عند المعالجة · `attempts` · الفهارس الأربعة. ⚠️ **[‏م‏٢‏] و`attempts` `unsignedSmallInteger` لا `TinyInteger`**: حدٌّ فوق ‎٢٥٥‎ يفيض على MySQL ويقبله SQLite صامتاً — عائلة `CAST(... AS SIGNED)` نفسها
- [x] T051 [P] [US1] نموذج `backend/app/Modules/Payments/Models/ProviderCallback.php` **بـ`HasUuid`** [‏م‏٢‏] ومصنعه في `backend/database/factories/Modules/Payments/ProviderCallbackFactory.php`
- [x] T052 [US1] علاقة `providerCallbacks()` على `backend/app/Modules/Payments/Models/PaymentTransaction.php` — ⚠️ **[‏م‏٢‏] هنا لا في `T023`**: الصنف يوجد الآن
- [x] T053 [P] [US1] منقّي الحمولة المرفوضة في `backend/app/Modules/Payments/Support/CallbackPayloadSanitizer.php` — **تنقيةٌ ثانية مستقلّة عن المزوّد**: المرفوض توقيعه لا يمرّ بـ`parseCallback` فحمولته جسد المهاجم الخام، وسلسلةٌ بشكل رقم بطاقة تهبط في العمود وتعيش في كل نسخة احتياطية
- [x] T054 [US1] `backend/app/Modules/Payments/Actions/InitiatePayment.php` — ينشئ العملية بمبلغها وعملتها ومرجعها ويعيد `ChargeIntent` (`FR-004`)
- [x] T055 [US1] `backend/app/Modules/Payments/Policies/PaymentTransactionPolicy.php` — ⚠️ `view()` تشترط `$transaction->order->user_id === $user->id` **ولا شيء غيره**. **و[‏م‏٢‏] `OrderPolicy::pay()` للطلب** يشترط الشيء نفسه، لأن `contracts/api.md:53` يوجبه ولا مهمّة كانت تغطّيه
- [x] T056 [US1] `StartPaymentRequest` في `.../Http/Requests/` و`PaymentTransactionResource` في `.../Http/Resources/`
- [x] T057 [US1] `backend/app/Modules/Payments/Http/Controllers/PaymentController.php` — `POST /payments/{order}/charge` و`GET /payments/{transaction}` بالـuuid
- [x] T058 [US1] محدِّد `throttle:webhook` في `backend/app/Providers/AppServiceProvider.php::registerRateLimiters()` — ⚠️ **[‏م‏٢‏] بمفتاحين لا بواحد**: المزوّد من المسار **زائد عنوان الشبكة**، على شكل `auth` (`ip + email`) و`billing` (`user + ip`) المشحونَين. مفتاحٌ بالمزوّد وحده **دلوٌ واحد للكوكب لقيمتين اثنتين**: مهاجمٌ يستنزفه فيُردّ المزوّد الحقيقي بـ‎429‎ ويبقى من دفع محجوباً حتى المسح الليلي — وهو الضرر الذي تسمّيه المهمّة نفسها. ونسخُ `by('user:'.$request->user()?->getKey())` هنا ينهار إلى الثابت `'user:'`
- [x] T059 [US1] حارس المصدر في `backend/app/Modules/Payments/Http/Middleware/VerifyWebhookSource.php` — ⚠️ **[‏م‏٢‏] ويُرتَّب قبل المحدِّد في `T071`**: تحديدٌ قبل قائمةٍ يجعل قمامة عنوانٍ مرفوض تستهلك دلو المزوّد. و`TrustProxies` **مشحونٌ ويعمل** (`AppServiceProvider:75-81`، افتراضُ منعٍ ولا `'*'`)، **فحجّة التأجيل التي كتبتُها لا وجود لها** — الباقي قيمةُ نشرٍ لا كودٌ ناقص
- [x] T060 [US1] `backend/app/Modules/Payments/Http/Controllers/WebhookController.php` — التوقيع **أوّلاً وقبل أي قراءة للحمولة**، ثم الصفّ، ثم `202` **حتى للتوقيع الباطل**. ⚠️ **[‏م‏٢‏] و`external_id` للصفّ مرفوض التوقيع يُكتب `NULL`**، لا من الجسد (فتكون الحمولة قد قُرئت قبل التحقّق، ويختار المهاجم مفتاح التكرار) ولا ثابتاً (فطلبُ قمامةٍ واحد يجعل كل رفضٍ لاحق «مكرّراً» ويُسكِت السجلّ الذي يوجبه `SC-002`). و**NULL لا يتصادم** — نفس الخاصيّة المستعملة في `T012`
- [x] T061 [US1] كتابة صفّ الإشعار في نفس المتحكّم بـ⚠️ **[‏م‏٢‏] `insertOrIgnore` بمصفوفةٍ تحمل `uuid` و`created_at` صراحةً، ثم قراءةٌ عكسية بالمفتاح، ثم `throw` عند عدم الوجود** — والنسخة الأولى قالت `create()` في `try/catch` وهو **مرفوضٌ في الجولة ‎٧‎ من ‎006‎** لأنه لا يميّز عطلاً حقيقياً من مكرّر، **واستشهدت بـ`CreditLedger:346-372` الذي يفعل العكس**. الجذر صُحّح في `data-model.md` §ج
- [x] T062 [US1] كتابة سبب الانحراف **في `WebhookController` نفسه** بتعليقٍ فوق المتحكّم: بلا `FormRequest` لأن التحقّق من التوقيع يسبق أي فكٍّ للجسد، و`FormRequest` يفكّ قبل أن يعمل المتحكّم فيُبطل `FR-005`؛ والتوقيع على البايتات كما وصلت. ⚠️ **[‏تحليل] وقد أُعلن في `plan.md` §Complexity Tracking بندَين ‎٤‎ و‎٥‎** — فهذه المهمّة صارت التعليق في موضع القراءة، لا الإعلان
- [x] T063 [US1] `backend/app/Modules/Payments/Jobs/ProcessProviderCallbackJob.php` على طابور `payments` — يحلّ المستأجر من **الطلب المرجعيّ** ولا يقرؤه من الحمولة · `forWorkspace()` لا `WorkspaceContext::set()` · **و[‏م‏٢‏] يحمل مُعرِّف صفّ الإشعار لا حمولته**: وظيفةٌ تُسلسِل الجسد الخام تُنزله في `failed_jobs` — مصبٌّ لا يراه أيٌّ من المنقّيَين
- [x] T064 [US1] ⚠️ **[‏م‏٢‏]** حدّ المحاولات من `config/payments.php` (`T002`) مع `tries`/`backoff` مُعلَنَين على الوظيفة، ثم `result = abandoned` — وظيفةٌ بلا `tries` على مشرفٍ بلا `tries` تعيد المحاولة **بلا نهاية** على مرجعٍ لن يوجد، وقد أُرسل `202` فلا أحد ينتظر جواباً
- [x] T065 [US1] `backend/app/Modules/Payments/Actions/HandleProviderCallback.php` — ⚠️ **مقارنة `amountMinor` و`currency` إلزامية** (التوقيع ليس تحقّقاً من المبلغ) · والانتقال **تحديثٌ شرطيّ ذرّي** يكتب `captured_order_id` **في نفس الجملة** [‏م‏٢‏] لا في نداءٍ ثانٍ، وإلا فبينهما نافذة · **وخاسر التحديث يتخطّى الأثر كلّه**
- [x] T066 [US1] ⚠️ **[‏م‏٢‏]** استحضار العملية في `backend/app/Modules/Payments/Actions/HandleProviderCallback.php` بـ**`(provider, reference)` معاً** لا بالمرجع وحده — الفهرس الفريد على الزوج، والحصر عليه هو ما يمنع إشعاراً موقّعاً بسرّ مزوّدٍ من المطالبة بعملية مزوّدٍ آخر. نظريّةٌ بمزوّدٍ واحد، **و`T022` يبني سجلّاً كي لا يبقى واحداً**
- [x] T067 [P] [US1] أحداث `PaymentCaptured` · `PaymentFailed` · `PaymentReversed` في `backend/app/Modules/Payments/Events/` — ⚠️ **[‏م‏٢‏] و`PaymentCaptured` يحمل `Order` صراحةً**، لأن `T069` يعتمد عليه. وتُطلَق **بعد المعاملة لا داخلها**
- [x] T068 [US1] ⚠️ **[‏م‏٢‏] القاصمة الأولى:** توسيع تلميح النوع في `backend/app/Modules/Payments/Listeners/CreditPurchaseOnApproval.php:34` و`.../CreateEnrollmentFromOrder.php:37` ليقبلا `PaymentCaptured` كما يقبلان `PaymentApproved` — بعقدٍ مشترك يحمل `Order`. **المستمعان مُقيَّدان بالنوع على `PaymentApproved` اليوم، فربطهما بحدثٍ آخر يرمي `TypeError` عند أوّل دفعة ناجحة**، والملفّان لم تكن تلمسهما مهمّة. ⚠️ **[‏تحليل] و`CreateEnrollmentFromOrder` هو المسار الحرج الثامن** («اعتماد الدفع ← إنشاء التسجيل») من الثمانية التي يوقف كسرُها الدمج (الدستور §IV) — **فهذا أخطر ملفٍّ تمسّه المرحلة**، ويُشغَّل مساره كاملاً قبل الإيداع لا مع بقيّة البوابات
- [x] T069 [US1] ربط `PaymentCaptured` بالمستمعَين المشحونَين في `backend/app/Modules/Payments/PaymentsServiceProvider.php::boot()` مع **إبقاء ربطَي `PaymentApproved` القائمَين** (‏`:69-70`) — بدون هذا السطر ينتهي المسار كلّه بعمليةٍ `captured` ورصيدٍ لم يتحرّك، ولا مستمعَ ثانٍ يسكّ أرصدةً
- [x] T070 [US1] `backend/app/Modules/Payments/Actions/ReversePayment.php` ومستمع `.../Listeners/ReevaluateOnReversal.php` — يعيد تقييم الحجب ويُنبّه المسؤول **ويمسح `captured_order_id`** [‏م‏٢‏]
- [x] T071 [US1] ⚠️ **[‏م‏٢‏]** ربط `PaymentReversed` بمستمعه في `PaymentsServiceProvider::boot()` — `T070` كان يُنشئ المستمع ولا يربطه، وهو عطل `T069` نفسه بوجهٍ آخر
- [x] T072 [US1] الأنواع الخمسة في `backend/app/Modules/Notifications/Support/NotificationType.php`: `PaymentConfirmed` · `PaymentFailed` · `ReceiptApproved` · `ReceiptRejected` · `PaymentReversed` — `isMandatory() => true` و`guardianPermission() => GuardianPermission::Payments`، على شكل `PaymentReminder` المشحون
- [x] T073 [US1] صفٌّ لكل نوعٍ في `backend/database/seeders/NotificationTemplateSeeder.php` — **إشعارٌ بلا قالب معتمَد يُسجَّل ويُسقَط بصمت**، فبدونها تؤكّد اختبارات US1 على جدولٍ فارغ وتنجح
- [x] T074 [US1] المسارات في `backend/app/Modules/Payments/routes/api.php` — الطالبيّان بـ`auth:sanctum` + `throttle:billing`، والويب-هوك بلا مصادقة بترتيب **حارس المصدر ثم `throttle:webhook`**
- [x] T075 [P] [US1] صفحة بدء الدفع في `frontend/src/app/(app)/(shell)/billing/pay/page.tsx` — الألوان من `@theme` وحده، خصائص منطقية (`ms-*`/`start-*`)، ونصوص عربية من `src/lib/labels.ts`، والأخطاء عبر `userMessage()`/`fieldErrors()` [‏م‏٢‏]
- [x] T076 [P] [US1] صفحة العودة بحالاتها **الثلاث** في `frontend/src/app/(app)/(shell)/billing/pay/return/page.tsx` — `FR-009`: الصفحة تعرض ولا تقرّر. نفس قيود التصميم
- [x] T077 [P] [US1] `frontend/src/lib/payments.ts` — و`formatMinorMoney()` وحدها تُحوّل المال نصّاً
- [x] T078 [P] [US1] مدخلات `attributes` لكل حقلٍ جديد في `backend/lang/ar/validation.php`
- [x] T079 [US1] ⚠️ **[‏تحليل]** دفعةٌ تنجح على مستحقٍّ **أُلغي أو صُحِّح بينما الدفع جارٍ** — في `backend/app/Modules/Payments/Actions/HandleProviderCallback.php` واختبارها في `backend/tests/Feature/Payments/CapturedOnCancelledOrderTest.php`. **والسبيك يجيب صراحةً: «تُقيَّد رصيداً و`يُمنع` أن تُرفَض بعد نجاح الخصم»** — فالمال خرج من حساب الطالب فعلاً، ورفضُ العملية يعني خصماً بلا مقابل. تُحوَّل إلى رصيد أرصدةٍ بسببٍ مسجَّل عبر `AdjustCredits`، ولا تُغلِق مستحقاً لم يعد قائماً. **حالة حافّة معلَنة في السبيك بلا مهمّة**
- [x] T080 [US1] ⚠️ **[‏تحليل]** ترتيب توزيع دفعةِ وليّ أمرٍ يسدّد **عن عدّة أبناء** — يُعلَن في `backend/config/payments.php` ويُفرَض في `backend/app/Modules/Payments/Actions/InitiatePayment.php`، وتُضاف حالته إلى `backend/tests/Feature/Payments/InstantPaymentTest.php`. «ترتيبٌ معلن» يعني **مكتوباً في موضعٍ واحد**، لا ناتجَ ترتيبِ استعلامٍ يتغيّر بتغيّر فهرس
- [x] T081 [P] [US1] ⚠️ **[‏تحليل]** المزوّد متوقّف ⇒ **المسار اليدوي يظهر بديلاً** في `frontend/src/app/(app)/(shell)/billing/pay/page.tsx` — `FR-002` يوجب بقاء اليدويّ عاملاً، والحافّة تطلب **ظهوره** لا مجرّد بقائه: صفحةٌ ترمي خطأً حين تسقط البوابة توقف التحصيل الذي وُجد اليدويّ كي لا يتوقّف. والخطأ عبر `userMessage()` لا خاماً
- [x] T082 [US1] ⚠️ قلب `BillingMode::PaymentGateway::isReady()` في `backend/app/Modules/Payments/Enums/BillingMode.php:51-54` — **بعد** أن يخضرّ مسار US1 كاملاً. ⚠️ **[‏م‏٢‏] والتنفيذ ليس «إعادة `true`»**: السطر `return $this !== self::PaymentGateway;` وقلبُه الساذج يجعل الدالّة ميتة لكل الحالات؛ المطلوب إزالة الاستثناء وحده

**نقطة تفتيش:** US1 تعمل وتُشحن. **الحدّ الأدنى = الأطوار ‎١‎ و‎٢‎ و‎٢ــج‎ و‎٣‎.**

---

## الطور ‎٤‎: US3 — الإيصال والاعتماد اليدوي (P3)

⚠️ قبل US2 خلافاً لأولويّتَيهما: الأولوية تقيس القيمة، والترتيب يقيس التبعية والحجم.

### اختبارات US3

- [x] T083 [US3] ⚠️ **اختبارٌ يجب أن يفشل أوّلاً** — اعتمادان متزامنان في `backend/tests/Feature/Payments/ApprovalConcurrencyTest.php`. ⚠️ **[‏م‏٢‏] ويُستحضَر نموذجا `Order` مستقلّان قبل أوّل اعتماد**: `update()` يُغيّر النسخة في الذاكرة، و`refresh()` يعيد `approved` — فاختبارٌ على نسخةٍ واحدة يخضرّ من أوّل تشغيل **ولا يقيس السباق**، وهو ما تعلنه المهمّة نفسها دليلَ فشل
- [x] T084 [US3] اعتمادٌ ورفضٌ متزامنان في الملف نفسه — طلبٌ **مرفوض** وقد سُكّت أرصدته وأُنشئ تسجيله، **بلا مسار تعويض**
- [x] T085 [P] [US3] **تثبيت** `FR-019` في `backend/tests/Feature/Payments/ReceiptLinkTest.php` — موقّع ‎١٥‎ دقيقة وينتهي فيُردّ
- [x] T086 [P] [US3] **تثبيت** `FR-021` و`FR-002` في `backend/tests/Feature/Payments/ManualPathTest.php` — ومعهما **[‏تحليل]** نصفُ `SC-006` الموجب («صفر إيصال معتمد **بلا إضافة أرصدة**»، وكان موزّعاً على اختبار التزامن) **وتثبيت حالة الحافّة «تغيّر رسم التشغيل `يُمنع` أن يعيد تسعير شراء تمّ»** — مضمونةٌ باللقطة الرباعية في `credit_purchases`، وضمانٌ بلا اختبارٍ يسقط عند أوّل إعادة هيكلة
- [x] T087 [P] [US3] أحداث الإيصال الثلاثة **وإنشاء** الأثر التدقيقيّ بعنوان الشبكة والجهاز في `backend/tests/Feature/Payments/ReceiptLifecycleTest.php` — ⚠️ **[‏تحليل] ومسار الحالات المعلَن نفسه** (`FR-020`: مرفوع ← قيد المراجعة ← معتمد **أو** مرفوض): الانتقالات المسموحة **والممنوعة**، فمتطلّبٌ يقول «مسارٌ معلن» ولا يُختبر إلا بأحداثه هو مسارٌ غير مُعلَن
- [x] T088 [P] [US3] ⚠️ **[‏م‏٢‏] ثغرة `ORDERS_VIEW_ALL` على مسارَيها** في `backend/tests/Feature/Payments/OrderKindVisibilityTest.php` — القائمة **و`show`**: مدرّسٌ حاملٌ الصلاحية لا يرى طلب `kind = credits` بأيٍّ منهما. **ولا يرفضه**
- [x] T089 [P] [US3] ⚠️ **[‏م‏٢‏] أرضية الاسترداد** في `backend/tests/Feature/Payments/RefundFloorTest.php` — سيناريو `quickstart` ‎٩ج‎: رصيدٌ ‎٦‎ وطلبُ ‎٨‎ ⇒ **يُستردّ ‎٦‎** · سببٌ إلزاميّ · `RefundIssued` يُطلَق · ودفتر المدرّس لا يتحرّك. **كان السيناريو الوحيد بلا مهمّة، والمتطلّب الوحيد الذي أعلنتُه منفَّذاً وليس كذلك**
- [x] T090 [P] [US3] ⚠️ **[‏م‏٢‏] الفائض النقدي** (`FR-025أ` · حالة الحافّة «دفع مرتين») في `backend/tests/Feature/Payments/SurplusCreditedTest.php` — يُقيَّد رصيداً بسياسةٍ معلنة و**يُمنع أن يضيع**

### تنفيذ US3

- [x] T091 [US3] تحديثٌ شرطيّ ذرّي على الحالة (`WHERE status IN (pending, under_review)`) في `backend/app/Modules/Payments/Actions/ApproveOrder.php` **و**`RejectOrder.php` — الاثنان في مهمّةٍ واحدة لأن السباق واحد والقرار من يكسبه؛ والاعتماد يكتب `captured_order_id` في نفس الجملة
- [x] T092 [US3] ⚠️ **[‏م‏٢‏]** تمرير `enforceFloor: true` من `backend/app/Modules/Payments/Actions/AdjustCredits.php:62-70` حين يكون النوع `Refund` — **الفجوة سطرٌ واحد**، وبدونه يهبط الاسترداد بالرصيد تحت الصفر. والتسليم يبقى `false` (دَينٌ وقع)، والاسترداد `true` (إخراجُ مال): **هذا هو الموضع الوحيد الذي تُفرَض فيه الأرضية في المرحلة**
- [x] T093 [US3] ⚠️ **[‏م‏٢‏]** قيد الفائض النقدي عن سعر الحزمة في `backend/app/Modules/Payments/Actions/PurchaseCredits.php` عبر `AdjustCredits` بسياسةٍ معلنة — `FR-025أ`، و**يُمنع أن يضيع**
- [x] T094 [P] [US3] أحداث `ReceiptUploaded` · `ReceiptApproved` · `ReceiptRejected` في `.../Events/` — **تُضاف ولا يُعاد تسمية القائم**
- [x] T095 [US3] إطلاقها من `backend/app/Modules/Payments/Actions/UploadPaymentReceipt.php` و`.../ApproveOrder.php` و`.../RejectOrder.php`
- [x] T096 [US3] ⚠️ **[‏م‏٢‏] إنشاء** الأثر التدقيقيّ لا تعديله: `backend/app/Modules/Payments/Actions/ApproveOrder.php:46` يُضاف إليه عنوان الشبكة والجهاز، **أمّا `.../RejectOrder.php` و`.../UploadPaymentReceipt.php` فلا تستعملان `LogsActivity` إطلاقاً** — فتُضاف السمة وأوّل نداء لكلٍّ منهما. النسخة الأولى قالت «إضافة حقلين إلى نداءات» وثلثاها لا نداء له. **ويُمنع تعديل السمة المشتركة** (‏٢٣ Action في سبع وحدات)
- [x] T097 [US3] قبول `PaymentMethod::MobileWallet` وكتابة `method` في `backend/app/Modules/Payments/Actions/UploadPaymentReceipt.php` — ⚠️ **[‏م‏٢‏] بلا `[P]`**: `T095` و`T096` يعدّلان الملف نفسه
- [x] T098 [P] [US3] زمن المراجعة المتوقّع في `backend/app/Modules/Payments/Http/Resources/OrderResource.php` — والقيمة تُقرأ من `platform_settings` لا تُحسب في الـResource
- [x] T099 [US3] ⚠️ **[‏م‏٢‏]** فرعٌ على `kind` في `backend/app/Modules/Payments/Policies/OrderPolicy.php` — **في `view():25` و`reject():86` معاً**، على شكل `approve():69` المشحون. النسخة الأولى أغلقت `index` وحده، **و`show` يبقى مفتوحاً**: المدرّس يقرأ الإجمالي uuid بعد uuid ومعه رابط الإيصال البنكي (`OrderResource:36-42`)، **ويرفض شراء أرصدةٍ منصّياً** — الدافعُ يُنقض عليه بيعُ المنصّة
- [x] T100 [US3] تقييد `OrderController::index` والحمولة على `kind` في `.../Http/Controllers/OrderController.php`
- [x] T101 [US3] ⚠️ **[‏تحليل]** الاسترداد **الجزئي** لدفعةٍ أغلقت مستحقاً — في `backend/app/Modules/Payments/Actions/AdjustCredits.php` وحالةٌ في `backend/tests/Feature/Payments/RefundFloorTest.php`: **المستحق يُعاد جزئياً والحجب يُعاد تقييمه**. و`WithholdingReader` مشتقٌّ من خمسة مدخلات فالتقييم لحظيّ بلا جدول — **لكن لا شيء يعيد فتح المستحق إن لم تُكتب هذه المهمّة**، فيبقى الطالب مفتوحاً بعد استردادٍ سحب ثمن ما فُتح له. حالة حافّة معلَنة بلا مهمّة
- [x] T102 [P] [US3] عرض الحالة وزمن المراجعة في `frontend/src/app/(app)/(shell)/orders/page.tsx` — **الصفحة قائمة**

> ⚠️ **[‏م‏٣‏] ثلاثة انحرافات عن نصّ المهامّ، مكتوبة هنا لا مسكوتٌ عنها:**
>
> 1. **`T093` نُفِّذ في `HandleProviderCallback` و`RecordCreditPurchase`، لا في
>    `PurchaseCredits`.** الأخير **لا يستلم مالاً أبداً** (‏`FR-018`: لا رصيد يُسكّ عند
>    النيّة)، فلا فائض يمرّ به. الفائض يقع في موضعين، كلاهما في الإشعار: دفعةٌ ثانية على
>    الطلب نفسه، ودفعةٌ نجحت بعد الإلغاء. والسياسة المعلنة (تحويلٌ بلقطة سعر الشراء
>    وتقريبٌ **لأعلى**) في `RecordCreditPurchase::recordSurplus()`، حيث يُكتب كل رصيد
>    مقابل عوض — تحويلٌ ثانٍ في المتحكّم بالإشعار هو النسخة التي تنحرف.
> 2. **و«دفع مرتين» كان `500` لا فائضاً.** `captured_order_id` فريد، والالتقاط الثاني على
>    طلبٍ ما زال مفتوحاً كان يكتبه فيرتطم بالفهرس — فسؤال «هل يحمل الطلبَ التقاطٌ سابق؟»
>    هو ما حوّل الانهيار إلى الجواب الذي يقوله السبيك.
> 3. **و`T101` لا كود له، وهذا هو الجواب.** الحجب مشتقٌّ من خمسة مدخلات ومخزَّنٌ بلا
>    واحد منها، فالحجز التالي يجيب جواباً مختلفاً بلا شيء يُعاد تقييمه. ما احتاج كتابةً
>    هو **الإخبار** — وهو ما يفعله `BalanceAnnouncer` داخل `AdjustCredits`، وما تثبّته
>    حالة `RefundFloorTest` الأخيرة.
>
> **ورابعٌ لم تسمّه أيّ مهمّة:** `OrderController::index` كان يلفّ المجموعة بـ
> `response()->json()`، فتُسلسَل **مصفوفةً عارية** بلا غلاف `data` — و`orders/page.tsx`
> يقرأ `res.data`. كل قائمة طلبات في المنتج كانت تعود `undefined` وتعرض «لا طلبات في
> سجلّك» لمن له طلبات. والصفحة كانت ترسل `rejection_reason` حيث ينتظر الـAPI `reason`،
> فكلّ رفضٍ من الشاشة يعود `422` بلا أثر.

**نقطة تفتيش:** المسار اليدوي مغلق التزامن ومسجَّل، والاسترداد بأرضيته.

---

## الطور ‎٥‎: US2 — التسوية الدورية (P2)

### اختبارات US2

- [ ] T103 [P] [US2] `SC-004` — نجاحٌ بلا إشعار ثم تسوية، في `backend/tests/Feature/Payments/ReconciliationSweepTest.php`
- [ ] T104 [P] [US2] `SC-005` في `backend/tests/Feature/Payments/ReconciliationIdempotencyTest.php` — تشغيلان متتاليان **وتشغيلان متداخلان**
- [ ] T105 [P] [US2] **الاتجاه هو الاختبار** في `.../ReconciliationDirectionTest.php`: `Pending → Captured` آليٌّ، و`Captured → Failed` يحتاج بشراً
- [ ] T106 [P] [US2] المهلة والحالة النهائية وغير المحسوم في `.../ReconciliationTimeoutTest.php`. ⚠️ **[‏م‏٢‏] وحالةٌ لـ`abandoned`**: إشعارٌ استنفد محاولاته **يظهر في التقرير** — كان يُكتب ولا يقرؤه أحد فلا يبلغ `FR-017` أبداً
- [ ] T107 [P] [US2] **الثابت الثالث** في `.../ReconciliationInvariantTest.php`: كل `captured` على طلب أرصدة يحمل قيداً — المقارنة حالةً-بحالة **عمياء** عن المستمع المطبور الذي مات
- [ ] T108 [P] [US2] اختبار وحدة للمدى **نصف المفتوح `[from, to)`** في `backend/tests/Unit/Payments/ReconciliationWindowTest.php`

### تنفيذ US2

- [ ] T109 [US2] هجرة `payment_reconciliation_runs` في `.../2026_08_11_001300_create_payment_reconciliation_runs_table.php` — **مملوك للمنصّة صنف (ب)** بـ`uuid`
- [ ] T110 [P] [US2] نموذج `.../Models/PaymentReconciliationRun.php` **بـ`HasUuid`** [‏م‏٢‏] ومصنعه
- [ ] T111 [US2] `backend/app/Modules/Payments/Actions/ReconcilePayments.php` — يبني على `transactionsInWindow()` **لا `verify()`**. ⚠️ **[‏م‏٢‏] والمشية الرئيسية بقاموسٍ مُجمَّع**: مطابقةُ كل عملية يبلّغها المزوّد باستعلامٍ لكلٍّ هي N استعلاماً ليلياً ينمو مع الحجم؛ الشكل هو `->pluck()` ثم مقارنةٌ في الذاكرة كما في `ReconcileCreditBalancesJob:100-103`
- [ ] T112 [US2] الثابت الثالث داخل نفس الـAction بقراءتين مُجمَّعتين، **وقراءة `provider_callbacks WHERE result = 'abandoned'`** [‏م‏٢‏] لتدخل `unresolved_count`؛ و`findings` **عيّنة** بينما `unresolved_count` هو العدد الحقيقي دائماً
- [ ] T113 [US2] `backend/app/Modules/Payments/Jobs/ReconcilePaymentsJob.php` — **كل المشيات `chunkById`** · `forWorkspace()` لا `WorkspaceContext::set()`. ⚠️ **[‏م‏٢‏] و`withoutOverlapping()` على الجدولة لا كوسيط وظيفة**: قفل الجدولة ينتهي تلقائياً بعد ‎١٤٤٠‎ دقيقة، وقفل الوسيط بلا انتهاء — فعاملٌ يُقتل عند مهلة ‎٩٠٠‎ ثانية يترك قفلاً دائماً **ولا تعمل التسوية ثانيةً أبداً، بصمت**
- [ ] T114 [P] [US2] الجدولة في `backend/routes/console.php` على شكل `Schedule::job(new X, 'maintenance')->withoutOverlapping()` المشحون
- [ ] T115 [US2] ثابت `BILLING_COLLECTION_VIEW` في `backend/app/Modules/Tenancy/Support/Permissions.php` **وإدراجه في `Permissions::all()`** — ⚠️ **[‏م‏٢‏] نُقل من الطور ‎٧‎**: `T117` يستند إليه، وكان يُنشأ بعد طورين. ⚠️ **ولا إسناد في `RolePermissionMatrix`**: `$all` تصل السوبر أدمن وحدها (`:16`, `:137`)، والمصفوفات الأخرى مستأجرة وهو ما يمنعه اختبار الـ‎403‎. وثابتٌ خارج `all()` **لا يُبذَر فلا يعمل لأحد**
- [ ] T116 [US2] `backend/app/Modules/Payments/Http/Controllers/Admin/PaymentReconciliationController.php` **+ Resource** — `GET` لأنه يقرأ لقطةً مخزَّنة، والمسح **وظيفة**
- [ ] T117 [US2] ⚠️ **[‏م‏٢‏]** تسجيل مسارات الإدارة في `backend/app/Modules/Payments/routes/api.php` — **ملف المسارات الوحيد للوحدة**، ولم تكن تلمسه إلا مهمّة الطور ‎٣‎
- [ ] T118 [P] [US2] صفحة التسوية في `frontend/src/app/(app)/(shell)/manage/payments/reconciliation/page.tsx` — ⚠️ **[‏م‏٢‏] `manage/` لا `admin/`**: لا وجود لمقطع `admin/` في الواجهة، وكل شاشةٍ إدارية شُحنت تحت `manage/`
- [ ] T119 [US2] ⚠️ **[‏م‏٢‏]** مدخلٌ في مصفوفة `NavItem[]` داخل `frontend/src/app/(app)/(shell)/layout.tsx:35-74` — **سطحٌ بلا رابطٍ واصل غير مُسلَّم**، والخطر مكتوبٌ في `plan.md` ولم تغطّه مهمّة

**نقطة تفتيش:** ما ضاع يُلتقط، وما لم يُحسم يُرى.

---

## الطور ‎٦‎: US4 — سجلّ التدقيق المالي (P4)

### اختبارات US4

- [ ] T120 [P] [US4] خمس عمليات ⇒ خمسة قيود بمنفّذها ووقتها وعنوانها (`SC-008`) في `backend/tests/Feature/Payments/BillingAuditTest.php`
- [ ] T121 [P] [US4] رفض التعديل والحذف (`SC-009`) في `backend/tests/Feature/Payments/ImmutableAuditTest.php` — **ويُختبر الشكل الجُملي**: `update()` على مُنشئ استعلام لا يستحضر نماذج فلا يمرّ بحارس النموذج
- [ ] T122 [P] [US4] **الاتجاهان معاً** في `.../AuditSubjectIsolationTest.php`
- [ ] T123 [P] [US4] `FR-029` في `.../BillingAuditAccessTest.php` — بلا الصلاحية ‎403‎، **وحاملُ أعلى دورٍ مستأجر يُردّ كذلك**
- [ ] T124 [P] [US4] `FR-028` — السلسلة الكاملة **بعدد استعلاماتٍ ثابت مع نموّ العيّنة** [‏م‏٢‏] في `.../AuditChainTest.php`: سقفٌ ثابت على عيّنةٍ صغيرة يمرّ فوق N+1، والمساواة وحدها تفشل للسبب الصحيح — الحجّة مكتوبة في ترويسة `BalanceQueryBudgetTest:14-18`

### تنفيذ US4

- [ ] T125 [P] [US4] ثابت `BILLING_AUDIT_VIEW` في `backend/app/Modules/Tenancy/Support/Permissions.php` **وفي `Permissions::all()`** — ⚠️ **[‏م‏٢‏] ولا إسناد في `RolePermissionMatrix`**: النسخة الأولى أمرت بإسنادٍ لا وجود له، و`$all` تصل السوبر أدمن تلقائياً بينما المصفوفات الباقية مستأجرة. **و`billing.*` لا `payments.*`** لأن الأخيرة عائلةٌ مستأجرة هنا
- [ ] T126 [US4] `backend/app/Modules/Payments/Support/BillingAuditSubjects.php` بالأنواع السبعة — ⚠️ **[‏م‏٢‏] وثلاثةٌ منها وحدها لها كاتب اليوم** (`Order:46` · `ExamModeWindow:63,87` · `CreditBalance:103`)؛ القائمة صحيحةٌ **كعقد** لأنها شكل الاستعلام لا جرد الموجود، **لكنّ تأكيداً على الأربعة الباقية يقيس جدولاً فارغاً وينجح** — فيُقصر التأكيد على ما يُكتب فعلاً، ويُضاف الباقي مع كاتبه
- [ ] T127 [US4] نموذج نشاطٍ مُعاد ربطه بحارس عدم التعديل في `backend/app/Shared/Models/ActivityEntry.php` + مفتاح `activity_model` في `backend/config/activitylog.php` (**غير منشور اليوم**) — شكل `LedgerEntry::booted()` المشحون، فspatie لا يفرض `FR-027`. ⚠️ **[‏م‏٢‏] وهذا تغييرٌ عبر المنتج كلّه**: يسري على ‎٢٣‎ Action في سبع وحدات ومنها قارئ تدقيق ‎014‎ — **نفس المدى الذي مُنع لأجله تعديل `LogsActivity`**، فيُعلَن ويُختبر أثره على `SettlementAuditController`
- [ ] T128 [US4] ⚠️ **[‏م‏٢‏]** تمرير المساحة والمنفّذ صراحةً من داخل `forWorkspace()` في القيود التي **تكتبها وظائف هذه المرحلة** (`T063` · `T113`) — النسخة الأولى قالت «في نداءات `Jobs/` كلّها»، **و لا نداء `logActivity()` في أيّ `Jobs/` في المستودع**. الحجّة صحيحة (المفردة تخزّن أول نتيجة، و`activity_log` بلا `workspace_id`) والهدف سابقٌ لأوانه، فيُصاغ على ما سيُكتب
- [ ] T129 [US4] ⚠️ **[‏م‏٢‏]** علاقات سلسلة `FR-028` في `backend/app/Modules/Payments/Models/` — `CreditPurchase::creditTransaction()` و`CreditTransaction::allocations()` **غير موجودتين**، والوصلة `source_type`/`source_id` بلا فهرسٍ يقودها. **فالمسند يحمل `credit_balance_id` معها** ليستعمل `credit_tx_idempotency`، وإلا فكل قراءة سلسلةٍ مسحٌ كامل للدفتر
- [ ] T130 [US4] `backend/app/Modules/Payments/Http/Controllers/Admin/PaymentAuditController.php` **+ Resource** [‏م‏٢‏] — ⚠️ **[‏تحليل] وبلا Action ولا FormRequest عمداً، على سابقة `SettlementAuditController` المشحونة**؛ الدستور §II يوجب السلسلة، والانحراف هنا **يُعلَن في `plan.md` §Complexity Tracking** لا يُترك ضمناً: المُرشِّح كلّه `BillingAuditSubjects` وهو صنفٌ نهائيّ بلا فرع. مع `->with(['subject','causer'])` منسوخاً **مع** المُرشِّح من `SettlementAuditController:49`، وتحميلٍ مسبق لهَوْبات السلسلة
- [ ] T131 [P] [US4] صفحة التدقيق في `frontend/src/app/(app)/(shell)/manage/payments/audit/page.tsx` + مدخل التنقّل في `layout.tsx`

**نقطة تفتيش:** السجلّ يحسم الخلاف ولا يُعدَّل.

---

## الطور ‎٧‎: US5 — سجلّ التحصيل للإدارة (P5)

### اختبارات US5

- [ ] T132 [P] [US5] `SC-010` — ‎١٠٬٠٠٠‎ عملية والإجماليات تطابق المصدر بفارق صفر، في `backend/tests/Feature/Payments/CollectionReportTest.php`
- [ ] T133 [P] [US5] ⚠️ **[‏م‏٢‏]** `SC-014` في `.../CollectionQueryBudgetTest.php` — **بمساواةٍ لا بسقف، وبعيّنةٍ تنمو**: النسخة الأولى قالت «ولا مسح كامل» و**المسح الكامل استعلامٌ واحد**، فالعدّاد لا يتغيّر بوجود الفهرس. ما يقيسه هذا الاختبار فعلاً هو **اختفاء الوصلة صفّاً-بصفّ**، وأمّا استعمال الفهرس فلا يُثبته اختبارٌ على SQLite ويُحرَس بالمراجعة — **وهذا مكتوبٌ في الاختبار نفسه**
- [ ] T134 [P] [US5] `FR-033` في `backend/tests/Feature/Payments/CollectionAccessTest.php` — المدرّس **ومالك المساحة** ‎403‎، والتصدير بنفس القيود. ⚠️ **[‏تحليل] وحالةٌ ثالثة على `GET /admin/payments/reconciliation`**: `PaymentReconciliationRun` كيانٌ **من الصنف (ب)**، والدستور §I يوجب في نفس الـPR اختباراً يؤكّد ردَّ **حاملِ أعلى دورٍ مستأجر** بـ‎403‎ — وكان المسار الوحيد من الثلاثة بلا تلك الحالة. ⚠️ **ويُعلَن أنّ حارس الكتابة فارغٌ عمداً**: الجدول تكتبه وظيفةٌ لا مستخدم، فلا سطح كتابةٍ يُحرَس — و«غير منطبق» قرارٌ يُكتب لا فراغٌ يُترك
- [ ] T135 [P] [US5] `SC-012` في `.../PaymentExposureTest.php` — ⚠️ **[‏م‏٢‏] بقائمة حمولاتٍ مُعدَّدة** يمشيها الفحص، على شكل `PublicExposureTest`؛ «أيّ استجابة» بلا قائمةٍ تُمشى **ليس فحصاً**
- [ ] T136 [P] [US5] `FR-035/036` — **تثبيت** في `backend/tests/Feature/Settlement/ContextIsolationTest.php`: القوائم مشتقّة من `Schema::create` فتُلتقط الجداول الجديدة تلقائياً، **ويُمدَّد تأكيد السلامة في `:101-106` ليسمّيها** [‏م‏٢‏]

### تنفيذ US5

- [ ] T137 [US5] `backend/app/Modules/Payments/Actions/BuildCollectionReport.php` — استعلامٌ **مُجمَّع** بلا جدول تجميع، و**`withoutWorkspaceScope()` مُعلَنة صراحةً بتعليقٍ واختبار** لأن `WorkspaceContext::id()` يرتدّ إلى `users.last_workspace_id` **لكل مستخدم بمن فيهم السوبر أدمن**
- [ ] T138 [US5] ⚠️ **[‏م‏٢‏]** نصف `FR-031` الثاني في `backend/app/Modules/Payments/Actions/BuildCollectionReport.php` — «تفصيل كل عملية **بلقطة مكوّنات سعرها**» يعبر `orders → credit_purchases`، **فيُبنى بوصلةٍ واحدة أو تحميلٍ مسبق لا بصفٍّ صفّاً**: عشرة آلاف صفٍّ × استعلامين تحت سقف الثانية. والفهرس `(created_at, status, method)` يخدم المدى ولا يفعل شيئاً للوصلة
- [ ] T139 [US5] ⚠️ **[‏م‏٢‏]** `CollectionReportRequest` في `.../Http/Requests/` و`CollectionRowResource` في `.../Http/Resources/` — الدستور §II يوجب السلسلة، والمُصفِّيات مدخلاتُ مستخدم. ⚠️ **و`whereDate()` ممنوعة**: دالّةٌ تلفّ العمود تُفقده فهرسه، والحدّ الأعلى **بداية اليوم التالي** لأن العمود طابعٌ زمنيّ والحدّ تاريخ
- [ ] T140 [US5] `.../Http/Controllers/Admin/CollectionReportController.php` — والتصدير **يعيد استعمال نفس المُصفِّي والاستعلام** لا استعلاماً ثانياً، **وبـ`chunkById`/cursor** [‏م‏٢‏] لأنّ حمولته عشرة آلاف صفّ لا ملخّصٌ مُجمَّع
- [ ] T141 [US5] `backend/app/Modules/Payments/Support/PaymentFieldAllowlist.php` — **وقيمة الحقل تُفحص كما يُفحص اسمه**، لأن `payload` عمودٌ حرّ يكتبه الطرف الآخر. ⚠️ **[‏م‏٢‏] ويُكتب في ترويسته ما يحرسه بالضبط وما لا يحرسه** — `StudentBalanceAllowlist:16-33` يفعل ذلك ويسحب ادّعاءَ مسحٍ سابقاً بنصّ «‏worse than none, because it is read as covered»
- [ ] T142 [P] [US5] صفحة التحصيل في `frontend/src/app/(app)/(shell)/manage/payments/collection/page.tsx` + مدخل التنقّل في `layout.tsx`

**نقطة تفتيش:** كل القصص تعمل مستقلّةً.

---

## الطور ‎٨‎: الصقل والمشترك

- [ ] T143 [P] `frontend/e2e/payments.spec.ts` — يحتاج `PHP_CLI_SERVER_WORKERS=8 php artisan serve`. ⚠️ **[‏م‏٢‏] واختبار الويب-هوك يُعلن `test.use({ storageState: { cookies: [], origins: [] } })`**: مشاريع Playwright تحمل حالةً مصادَقة، فمسارٌ «بلا مصادقة» يُختبر مسجَّلَ الدخول بصمت
- [ ] T144 [P] ⚠️ **[‏م‏٢‏]** فتح Horizon للتشغيل: `Gate::define('viewHorizon', ...)` في `backend/app/Providers/HorizonServiceProvider.php:29-33` يقارن بقائمةٍ **فارغة** فلا يفتحها أحد، ومسارات التنبيه الثلاثة **مُعلَّقة** (‏`:18-20`) — فطابورٌ متكدّس لا يبلغ أحداً ولا يُرى
- [ ] T145 [P] تحديث `docs/README.md` بالصلاحيتين والمسارات والطابور، و`docs/erd.md` بالجدولين وبتصحيح ملاحظة الوحدات الصغرى على `orders`
- [ ] T146 [P] إضافة مزالق المرحلة إلى `CLAUDE.md` و`AGENTS.md`: التوقيع ليس تحقّقاً من المبلغ · `manual` لا يقبل إشعاراً · **الضمان عمودٌ فريد لا فهرسٌ جزئيّ (MySQL بلا فهارس جزئية)** · محدِّد الويب-هوك بمفتاحين · `defaults` **و**`environments` معاً في Horizon
- [ ] T147 تشغيل سيناريوهات `quickstart.md` **الاثني عشر** (‏١–٩ و‎٩أ‎ و‎٩ب‎ و‎٩ج‎) وتأشير كلٍّ منها — ⚠️ **[‏م‏٢‏]** كانت «التسعة»
- [ ] T148 البوابات الأربع خضراء (`SC-015` · `NFR-006`): `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` من `backend/`، و`npx tsc --noEmit` من `frontend/` — **بلا baseline جديد وبلا `@phpstan-ignore` وبلا `assert()`/`@var` مضمَّن**

---

## التبعيات وترتيب التنفيذ

| الطور | يعتمد على | ملاحظة |
|---|---|---|
| ‎١‎ التهيئة | — | |
| ‎٢أ‎ الهجرات | ‎١‎ | **متتابعة حتماً** `T006→T014` |
| ‎٢ب‎ العقد | ‎٢أ‎ | نقطة تفتيش ببواباتٍ خضراء |
| **‎٢ــج‎ الوحدات الصغرى** | ‎٢أ‎ | ⚠️ **[‏م‏٢‏] صار حاجزاً لـUS1** — `T065` يقارن `int` بعمودٍ لا يوجد بدونه |
| ‎٣‎ US1 | ‎٢ب‎ · **‎٢ــج‎** | |
| ‎٤‎ US3 | ‎٢‎ | مستقلّ عن US1 بالتسليم |
| ‎٥‎ US2 | ‎٣‎ | التسوية تحتاج مساراً تصحّحه |
| ‎٦‎ US4 | ‎٣‎ · ‎٤‎ | يسجّل ما تنتجه ما قبله |
| ‎٧‎ US5 | كلّها | يعرض ولا ينتج |
| ‎٨‎ الصقل | ما شُحن | |

### داخل القصّة

الاختبار قبل التنفيذ · الهجرة قبل النموذج · النموذج قبل الـAction · الـAction قبل المتحكّم ·
المتحكّم قبل الواجهة · **والواجهة قبل رابطها الواصل**.

⚠️ **و`T083` وحده يُكتب ليفشل أوّلاً** — بنموذجَي `Order` مستقلّين، وإلا خضرّ من أوّل تشغيل.

### فرص التوازي

| الطور | المتوازي |
|---|---|
| ‎١‎ | `T002` `T003` `T005` |
| ‎٢ب‎ | `T015`…`T019` معاً، ثم `T020` |
| ‎٢ــج‎ | `T029` `T030` `T031` · `T034` `T035` `T036` `T037` |
| ‎٣‎ | أحد عشر اختباراً `T039`…`T049` · الواجهة `T075`…`T078` و`T081` |
| ‎٤‎ | `T085`…`T090` |
| ‎٥‎ | `T103`…`T108` |
| ‎٦‎ | `T120`…`T124` |
| ‎٧‎ | `T132`…`T136` |

**⚠️ ولا `[P]` على `T006`…`T014`** — هجرةٌ تسبق تنظيفها تُسقط النشر على بيانات حيّة.

⚠️ **[‏م‏٢‏] وثلاث تصادماتٍ أُزيلت**: `T097` فقد `[P]` (يشارك `UploadPaymentReceipt.php` مع
`T095`/`T096`) · `T115` و`T125` في طورين مختلفين على `Permissions.php` · `T031` و`T098`
في طورين مختلفين على `OrderResource.php`.

---

## استراتيجية التسليم

**الحدّ الأدنى:** ‎١‎ + ‎٢أ‎ + ‎٢ب‎ + **‎٢ــج‎** + ‎٣‎ — ⚠️ **[‏م‏٢‏] والوحدات الصغرى داخله
الآن**، لأن حارس المبلغ يقف عليها.

**التسليم المتدرّج:** الأساس ⇐ US1 (شحن) ⇐ US3 (شحن) ⇐ US2 ⇐ US4 ⇐ US5.

**عند تقليص النطاق:** لم يعد الطور ‎٢ــج‎ اقتطاعاً مجّانياً. **ثمنه مُسمّى** — مقارنةُ مبلغٍ
على `decimal` بتحويلٍ صريح في موضعٍ واحد موثَّق، وخرقُ `NFR-007` مُسجَّل. وحجّةٌ ثانية تسنده:
`Course.php:57` يعلن `price` و`currency` **مجمَّدين بقرار**.

**قرارٌ مفتوح لا يحجب العمل:** آلية إسناد `finance-admin`. الحارس اليوم السوبر أدمن عبر
`Gate::before`، والاعتماد يعمل.

---

## ملاحظات

- كل مهمّة تحمل مساراً دقيقاً وسنداً في وثيقة تصميم، فتُنفَّذ بلا سياقٍ إضافي.
- ⚠️ **ولا `Queue::fake()` عارية في أي اختبار يمسّ الشحن** — تُزيَّف وظائف الخطّ الزمني وحدها.
- الهجرات في `Database/Migrations` بحرف **M** كبير — خطؤها يُحمّل **صفر** هجرة على لينكس.
- `php artisan migrate` وحدها؛ **يُسأل المالك قبل أي `migrate:fresh`**.
- إيداعٌ بعد كل مهمّة أو مجموعةٍ منطقية، ووقوفٌ عند كل نقطة تفتيش.
