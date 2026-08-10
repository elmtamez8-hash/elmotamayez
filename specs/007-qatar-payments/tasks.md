# Tasks: بوابة الدفع وتحصيل الطالب (‏007 `qatar-payments`)

**Input**: `specs/007-qatar-payments/` — `plan.md` · `spec.md` · `research.md` · `data-model.md` · `contracts/` · `quickstart.md`

**Tests**: **مطلوبة صراحةً.** `NFR-003ب` يسمّي اختبارات وحدة وتكامل بالاسم، وتسعة من
خمسة عشر معيار نجاح تقول «**مُثبَتة باختبار**». فمهام الاختبار جزءٌ من كل قصّة لا زينة.

**Organization**: بالقصص، لتُسلَّم كلٌّ منها وتُختبر وحدها.

---

## الشكل: `[ID] [P?] [Story] الوصف + المسار`

- **[P]** — ملفٌّ مختلف وبلا تبعية على مهمّةٍ ناقصة.
- **[Story]** — في أطوار القصص وحدها.
- **⚠️ سلسلة الهجرات لا تحمل `[P]` أبداً**: ثلاثٌ منها تُسقط النشر إن سبقت تنظيفها
  (`data-model.md` §ط، درس ‎016‎ حرفياً).

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

**وستّة متطلبات منفَّذة سلفاً** (‏`research.md` §و): `FR-002` · `FR-019` · `FR-021` ·
`FR-025` · `FR-035/036` — **تُثبَّت باختبار ولا تُبنى**، ومهامها أدناه موسومة **«تثبيت»**.
و`FR-018` نصفُه وحده منفَّذ، فله عمودٌ وتعداد لا مسارٌ جديد.

---

## الطور ‎١‎: التهيئة

**الغرض:** حالةٌ مرجعية وإعدادات تشغيل. **الوحدة قائمة** — لا إنشاء بنية ولا تسجيل مزوّد.

- [ ] T001 تشغيل البوابات الأربع وتسجيل الحالة الخضراء المرجعية قبل أي تعديل: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` من `backend/`، و`npx tsc --noEmit` من `frontend/`
- [ ] T002 [P] إنشاء `backend/config/payments.php` — سجلّ المزوّدين، مهلة العملية المعلّقة (`FR-015`)، حدّ محاولات الإشعار المؤجَّل (`data-model.md` §ي)، ومفاتيح تُقرأ من البيئة بقيمٍ فارغة
- [ ] T003 [P] إضافة مفاتيح البيئة **بلا أي قيمة** إلى `backend/.env.example`: `PAYMENTS_WEBHOOK_ALLOWED_IPS` · `PAYMENTS_PENDING_TIMEOUT_MINUTES` — `FR-010` يوجب البيئة، والمستودع **يُمنع** أن يحمل سرّاً
- [ ] T004 [P] إضافة مشرف `supervisor-payments` على طابور `payments` داخل **`environments`** في `backend/config/horizon.php` — لا `defaults`، فهي لا تُشغّل مشرفاً (‏`plan.md` §المخاطر، الدرس المدفوع في الجولة ‎٦‎ من ‎006‎)

---

## الطور ‎٢‎: الأساس (حاجزٌ لكل القصص)

**⚠️ لا تبدأ أي قصّة قبل اكتماله.** ولا شيء هنا يُختبر بلا المزوّد الوهميّ (`NFR-011`).

### ٢أ — سلسلة الهجرات المرتَّبة (‏`data-model.md` §ط) — **بلا `[P]`، وبهذا الترتيب**

- [ ] T005 هجرة ‎١‎: إضافة `uuid` **قابلاً للإفراغ** إلى `payment_transactions` في `backend/app/Modules/Payments/Database/Migrations/2026_08_11_000100_add_uuid_to_payment_transactions.php`
- [ ] T006 هجرة ‎٢‎: ملء `uuid` بـ**`chunkById`** في `.../2026_08_11_000200_backfill_payment_transaction_uuids.php` — `chunk` يُرقّم بـOFFSET والمُسنِد (`uuid IS NULL`) ينكمش تحته فتُتخطّى صفوفٌ **ويُبلَّغ بالنجاح** (‏درس ‎016‎)
- [ ] T007 هجرة ‎٣‎: `unique(uuid)` + جعله غير قابل للإفراغ في `.../2026_08_11_000300_add_uuid_index_to_payment_transactions.php` — **بعد** الملء، و`->change()` يُعيد إعلان كل خاصيّة لأن Laravel ‎13‎ يُسقط ما لا يُعاد إعلانه
- [ ] T008 هجرة ‎٤‎: **إزالة تكرار `(provider, reference)` القائم** في `.../2026_08_11_000400_dedupe_payment_transaction_references.php` — أيّ قاعدة وقع فيها سباق `ApproveOrder` مرّة تحمل صفَّين متطابقين، فتُسقط الهجرة التالية على بيانات حيّة
- [ ] T009 هجرة ‎٥‎: `reference` غير قابل للإفراغ + `unique(provider, reference)` في `.../2026_08_11_000500_add_reference_unique_to_payment_transactions.php` — ⚠️ NULL **لا يتصادم** مع NULL في فهرسٍ فريد على MySQL وSQLite معاً (`data-model.md` §ح)
- [ ] T010 هجرة ‎٦‎: **إزالة تكرار `captured` لكل طلب** في `.../2026_08_11_000600_dedupe_captured_transactions.php` — السبب نفسه
- [ ] T011 هجرة ‎٧‎: فهرس «`captured` واحدة لكل طلب» في `.../2026_08_11_000700_add_single_capture_index.php` — **هذا هو ضمان عدم التكرار الحقيقي** على مستوى الأثر، لأن مفتاح `(provider, external_id)` مفتاحٌ على الإيصال وبوابةٌ تسكّ معرّفاً جديداً لكل إعادة إرسال تُبطله (‏`data-model.md` §ج)
- [ ] T012 هجرة ‎٩‎: `method` · `failure_reason` · `settled_at` على `payment_transactions` في `.../2026_08_11_000900_add_method_and_outcome_to_payment_transactions.php` — إضافاتٌ بلا قيد
- [ ] T013 هجرة ‎١٠‎: الفهارس المُعلَنة في `data-model.md` §ح — **`(created_at, status, method)`** (يبدأ بـ`created_at` لا بـ`workspace_id`) · `(status, created_at)` · **`credit_purchases(order_id)`** في `.../2026_08_11_001000_add_reporting_indexes.php`

### ٢ب — التعدادات والعقد والمزوّد

- [ ] T014 [P] إنشاء `PaymentStatus` بالقائمة المغلقة (`Initiated` · `Pending` · `Captured` · `Failed` · `Expired` · `Mismatch` · `Reversed`) ودالّة الانتقالات المسموحة في `backend/app/Modules/Payments/Enums/PaymentStatus.php` — ⚠️ `Captured` نهائيّة إلا عبر `Reversed`، ولا انتقال من `Failed` إليها بلا بشر (`FR-014`)
- [ ] T015 [P] إنشاء `PaymentMethod` (`BankTransfer` · `MobileWallet` · `Gateway`) في `backend/app/Modules/Payments/Enums/PaymentMethod.php` — يغلق نصف `FR-018` الناقص ويفتح بُعد «الطريقة» في `FR-031` (‏`data-model.md` §ز)
- [ ] T016 [P] إنشاء `ChargeIntent` في `backend/app/Modules/Payments/Data/ChargeIntent.php` — ⚠️ `final class ... extends DataTransferObject` بخصائص `readonly` **مُرقّاة**، لا `final readonly class` (خطأ PHP قاتل)، و`redirectUrl` **قابل للإفراغ** مع `instructions` لأن التحويل البنكي بلا صفحة دفع (`contracts/provider.md` §ج)
- [ ] T017 [P] إنشاء `CallbackEvent` في `backend/app/Modules/Payments/Data/CallbackEvent.php` — `amountMinor` صحيح، و`safePayload` **مُنقّى عند حدود العقد لا عند العرض**
- [ ] T018 توسيع `backend/app/Modules/Payments/Contracts/PaymentProviderInterface.php` بـ`verifySignature(string $rawBody, array $headers)` · `parseCallback(string $rawBody)` · `transactionsInWindow(CarbonImmutable $from, CarbonImmutable $to)`، وتغيير `createCharge()` إلى `ChargeIntent` — بأنواع قيمٍ في `@param`/`@return` لأن `array` عارية تسقط على Larastan ‎L8‎ (‏`contracts/provider.md` §ب/§ز)
- [ ] T019 تنفيذ الدوالّ الثلاث في `backend/app/Modules/Payments/Providers/ManualTransferProvider.php` — ⚠️ **`verifySignature()` تعيد `false` أبداً** بتعليقٍ يقول لماذا: مزوّدٌ بلا بوابة لا يرسل إشعارات، فكل ما يصل باسمه انتحال (‏`contracts/provider.md` §هـ)
- [ ] T020 إنشاء `backend/app/Modules/Payments/Providers/PaymentProviderRegistry.php` بوسم الحاوية على غرار `->tag('notification.channels')` — **المعرّف المجهول والمسجَّل بلا قدرة على استقبال إشعارات كلاهما ‎404‎، لا ‎403‎**
- [ ] T021 تحديث `backend/app/Modules/Payments/Models/PaymentTransaction.php`: `HasUuid` · قالب `PaymentStatus` و`PaymentMethod` · علاقة `providerCallbacks()`
- [ ] T022 إنشاء `backend/tests/Support/FakePaymentProvider.php` بالحالات **السبع**: نجاح · فشل · تكرار · توقيع غير صالح (`NFR-011`) · **نجاحٌ بلا إرسال إشعار** (مدخل `SC-004` الوحيد) · **مبلغٌ مخالف بتوقيعٍ صحيح** (§د) · **معرّف حدثٍ جديد لكل إعادة إرسال** — ⚠️ في `tests/` لا `app/`، على شكل `FakeBroadcastProvider`
- [ ] T023 [P] اختبار وحدة لحلّ المزوّد ورفض المجهول في `backend/tests/Unit/Payments/ProviderRegistryTest.php`

**نقطة تفتيش:** العقد والوهميّ والمخطَّط جاهزون — تبدأ القصص.

---

## الطور ‎٢‎ــج: توحيد الوحدات الصغرى — **قصّة أساس مستقلّة، وأوّل ما يُقتطع**

**الغرض:** `NFR-007`. **⚠️ أخطر كتلة في المرحلة، ونصفُها في وحدات لا تخصّها**
(`data-model.md` §هـ). لها بوابتها الخضراء وحدها، وإن قُلّص النطاق فهي التي تخرج.

- [ ] T024 هجرة ‎٨‎: `courses.price`/`courses.currency` · `products.price`/`products.currency` · `orders.amount → amount_minor` · `payment_transactions.amount → amount_minor` في `backend/app/Modules/Payments/Database/Migrations/2026_08_11_000800_convert_money_to_minor_units.php` — ⚠️ **الضرب ×‎١٠٠‎ يقع في PHP لا في المحرّك**: MySQL وSQLite لا يتّفقان على ما يصير `49.99` عند تغيير النوع
- [ ] T025 تمرير `price_minor` في `backend/app/Modules/Payments/Actions/CreateOrder.php:20` — بدونه يصير كورس ‎49.99‎ = **‎0.49‎ ريال**، وهو الصنف الذي **لا يعيد اختبارٌ محليّ إنتاجه** (`NFR-012`)
- [ ] T026 [P] حذف `minorToDecimal()` من `backend/app/Modules/Payments/Actions/PurchaseCredits.php:118,166` — وُجدت للتحويل العكسي، فتصير خطأً بمئة ضعف
- [ ] T027 [P] إسقاط قالب `decimal:2` من `Order::casts()` (`backend/app/Modules/Payments/Models/Order.php:52`) و`PaymentTransaction::casts()` (`.../Models/PaymentTransaction.php:32`)
- [ ] T028 [P] إسقاط `(float)` من `backend/app/Modules/Payments/Http/Resources/OrderResource.php:19`
- [ ] T029 نسخ المبلغ الصحيح في `backend/app/Modules/Payments/Actions/ApproveOrder.php`
- [ ] T030 اختبار وحدة لذهاب المال وإيابه صحيحاً بلا عددٍ عائم في `backend/tests/Unit/Payments/MinorUnitsTest.php`، وتشغيل `php vendor/bin/pest tests/Feature/Payments` كاملاً

**نقطة تفتيش:** المال عددٌ صحيح في كل جدولٍ تمسّه المرحلة.

---

## الطور ‎٣‎: US1 — الدفع الفوري (P1) 🎯 **الحدّ الأدنى القابل للشحن**

**الهدف:** الطالب يدفع، فيصل إشعارٌ موقَّع، فتُضاف الأرصدة ويُرفع الحجب خلال ثوانٍ بلا بشر.

**اختبارٌ مستقلّ:** مستحقٌّ + دفعةٌ عبر الوهميّ + إشعار نجاح موقَّع ⇒ الحجب مرفوع.

### اختبارات US1

> ⚠️ **ولا `Queue::fake()` عارية في أيٍّ منها.** مستمع الشحن مطبور، ففاكٌّ بلا وسائط
> يبتلعه فيصير التأكيد على جدولٍ فارغ **وينجح**. تُزيَّف وظائف الخطّ الزمني وحدها.

- [ ] T031 [P] [US1] المسار السعيد (‏`quickstart` ‎١‎ · `SC-001`) في `backend/tests/Feature/Payments/InstantPaymentTest.php` — ويؤكّد **وجود القالب** قبل تأكيد وصول الإشعار
- [ ] T032 [P] [US1] التوقيع الباطل (‏`SC-002`) في `backend/tests/Feature/Payments/WebhookSignatureTest.php` — صفر أثر **وصفٌّ مخزَّن** بـ`signature_valid = false`، واستجابةٌ لا تميّز الرفض عن القبول
- [ ] T033 [P] [US1] التكرار عشراً (‏`SC-003`) في `backend/tests/Feature/Payments/WebhookIdempotencyTest.php` — وحالةٌ ثانية بـ**معرّف حدثٍ جديد لكل إعادة إرسال**، فهي ما يُثبت أن الضمان على الأثر لا على الإيصال
- [ ] T034 [P] [US1] المبلغ خالف والتوقيع صحيح (‏`quickstart` ‎٩أ‎) في `backend/tests/Feature/Payments/AmountMismatchTest.php` — `result = mismatch`، لا شحن ولا أرصدة
- [ ] T035 [P] [US1] `manual` لا يقبل إشعاراً ومزوّدٌ مجهول ‎404‎ (‏`quickstart` ‎٩ب‎) في `backend/tests/Feature/Payments/WebhookProviderResolutionTest.php`
- [ ] T036 [P] [US1] `PaymentTransactionPolicy` — **مدرّسٌ من نفس المساحة يُردّ بـ‎403‎** في `backend/tests/Feature/Payments/PaymentVisibilityTest.php`، لأن `BelongsToWorkspace` وحده يمرّره
- [ ] T037 [P] [US1] حالة عزل المستأجرين في `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` تغطّي `provider_callbacks` **على المسار المتأخّر** (الإشعار الذي سبق كتابة العملية)، لا الحالة السعيدة وحدها (‏`SC-013`)
- [ ] T038 [P] [US1] اختبار وحدة للتحقّق من التوقيع والمنقّي في `backend/tests/Unit/Payments/CallbackSanitizerTest.php` (`NFR-003ب`)
- [ ] T039 [P] [US1] النزاع البنكي (‏`quickstart` ‎٩‎ · `د4`) في `backend/tests/Feature/Payments/ChargebackTest.php` — `Reversed` **بقيدٍ جديد لا بتعديل**، والحجب يُعاد تقييمه

### تنفيذ US1

- [ ] T040 [US1] هجرة `provider_callbacks` في `backend/app/Modules/Payments/Database/Migrations/2026_08_11_001100_create_provider_callbacks_table.php` — ⚠️ `workspace_id` **قابل للإفراغ** يُحلّ عند المعالجة، مع `attempts` وفهارس `unique(provider, external_id)` · `(payment_transaction_id)` · `(workspace_id)` · `(processed_at)` (‏`data-model.md` §ج/§ح)
- [ ] T041 [P] [US1] نموذج `backend/app/Modules/Payments/Models/ProviderCallback.php` ومصنعه في `backend/database/factories/Modules/Payments/ProviderCallbackFactory.php`
- [ ] T042 [P] [US1] منقّي الحمولة المرفوضة في `backend/app/Modules/Payments/Support/CallbackPayloadSanitizer.php` — ⚠️ **تنقيةٌ ثانية مستقلّة عن المزوّد**: المرفوض توقيعه لا يمرّ بـ`parseCallback` فحمولته هي جسد المهاجم الخام، وسلسلةٌ بشكل رقم بطاقة تهبط في العمود وتعيش في كل نسخة احتياطية (`FR-030` · `SC-002`)
- [ ] T043 [US1] `backend/app/Modules/Payments/Actions/InitiatePayment.php` — ينشئ العملية بمبلغها وعملتها ومرجعها ويعيد `ChargeIntent` (`FR-004`)
- [ ] T044 [US1] `backend/app/Modules/Payments/Policies/PaymentTransactionPolicy.php` — ⚠️ `view()` تشترط `$transaction->order->user_id === $user->id` **ولا شيء غيره**: لا صلاحية مساحة ولا عضوية (`contracts/api.md` §٢أ)
- [ ] T045 [US1] `StartPaymentRequest` في `backend/app/Modules/Payments/Http/Requests/` و`PaymentTransactionResource` في `.../Http/Resources/` — السلسلة `FormRequest → DTO → Action → Resource` التي يوجبها الدستور §II
- [ ] T046 [US1] `backend/app/Modules/Payments/Http/Controllers/PaymentController.php` — `POST /payments/{order}/charge` و`GET /payments/{transaction}` بالـuuid
- [ ] T047 [US1] تعريف محدِّد `throttle:webhook` في `backend/app/Providers/AppServiceProvider.php::registerRateLimiters()` — ⚠️ **مُفهرس بمعرّف المزوّد من المسار**: نسخُ شكل المحدِّدات المصادَقة (`by('user:'.$request->user()?->getKey())`) على مسارٍ بلا مستخدم ينهار إلى الثابت `'user:'` — دلوٌ واحد للكوكب. وبسعةٍ تحتمل دفقة إعادة إرسال، فـ‎429‎ عليها يُبقي من دفع محجوباً حتى المسح الليلي
- [ ] T048 [US1] قائمة العناوين المسموحة تُقرأ من البيئة في `backend/app/Modules/Payments/Http/Middleware/VerifyWebhookSource.php` — نصف `FR-011` الذي كان ساقطاً؛ ⚠️ وبلا `TRUSTED_PROXIES` مضبوطة تسمح للجميع أو لا أحد، **فإن تعذّرت قبل اختيار المزوّد يُعلَن التأجيل صراحةً بسببه** لا يُترك فراغاً يُقرأ كتغطية
- [ ] T049 [US1] `backend/app/Modules/Payments/Http/Controllers/WebhookController.php` — التوقيع **أوّلاً وقبل أي قراءة للحمولة**، ثم صفٌّ في `provider_callbacks`، ثم `202` **حتى للتوقيع الباطل** (ردٌّ مميَّز أوراكل يخبر المهاجم متى اقترب)
- [ ] T050 [US1] كتابة صفّ الإشعار في `backend/app/Modules/Payments/Http/Controllers/WebhookController.php` بـ`create()` في `try/catch` **ثم قراءةٌ عكسية بالمفتاح ورميٌ عند عدم الوجود** — `insertOrIgnore` لا يُقلع النموذج فلا تعمل `HasUuid`، و`catch` وحده يحوّل كل عطلٍ حقيقي (مفتاح خارجي، قيمة خارج المدى) إلى «مكرّر عولج سلفاً». الشكل المشحون في `CreditLedger:360-372`
- [ ] T051 [US1] `backend/app/Modules/Payments/Jobs/ProcessProviderCallbackJob.php` على طابور `payments` — يحلّ المستأجر من **الطلب المرجعيّ** ولا يقرؤه من الحمولة أبداً، ويستعمل `forWorkspace()` لا `WorkspaceContext::set()` (`NFR-009`)، وبعد الحدّ المعلَن يكتب `result = abandoned` ويدخل `unresolved_count` (`data-model.md` §ي)
- [ ] T052 [US1] `backend/app/Modules/Payments/Actions/HandleProviderCallback.php` — ⚠️ **مقارنة `amountMinor` و`currency` إلزامية**: التوقيع ليس تحقّقاً من المبلغ، وإشعارٌ موقَّع صحيحاً بـ‎١‎ ريال يغلق مطالبة ‎٥٠٠‎ (‏`contracts/provider.md` §د). والانتقال **تحديثٌ شرطيّ ذرّي** على الحالة لا `update()` عادي
- [ ] T053 [P] [US1] أحداث `PaymentCaptured` · `PaymentFailed` · `PaymentReversed` في `backend/app/Modules/Payments/Events/` — ⚠️ **تُطلَق بعد المعاملة لا داخلها**، على قاعدة `ChargeSessionSeats`
- [ ] T054 [US1] ربط `PaymentCaptured` بالمستمعَين **المشحونين** `CreditPurchaseOnApproval` و`CreateEnrollmentFromOrder` في `backend/app/Modules/Payments/PaymentsServiceProvider.php::boot()` — ⚠️ **بلا هذا السطر ينتهي المسار كلّه بعمليةٍ `captured` ورصيدٍ لم يتحرّك**، ولا مستمعَ ثانٍ يسكّ أرصدةً لأن مسارَين للسكّ عطلٌ بالبناء
- [ ] T055 [US1] `backend/app/Modules/Payments/Actions/ReversePayment.php` ومستمع `.../Listeners/ReevaluateOnReversal.php` — يعيد تقييم الحجب ويُنبّه المسؤول (`د4`)
- [ ] T056 [US1] إضافة الأنواع الخمسة إلى `backend/app/Modules/Notifications/Support/NotificationType.php`: `PaymentConfirmed` · `PaymentFailed` · `ReceiptApproved` · `ReceiptRejected` · `PaymentReversed` — جميعها `isMandatory() => true` و`guardianPermission() => GuardianPermission::Payments`
- [ ] T057 [US1] صفٌّ لكل نوعٍ منها في `backend/database/seeders/NotificationTemplateSeeder.php` — ⚠️ **إشعارٌ بلا قالب معتمَد يُسجَّل ويُسقَط بصمت**، فبدون هذه المهمّة تؤكّد اختبارات US1 على جدولٍ فارغ وتنجح
- [ ] T058 [US1] تسجيل المسارات في `backend/app/Modules/Payments/routes/api.php` — الطالبيّان بـ`auth:sanctum` + `throttle:billing`، والويب-هوك `POST /webhooks/payments/{provider}` بلا مصادقة و`throttle:webhook` + حارس المصدر
- [ ] T059 [P] [US1] صفحة بدء الدفع في `frontend/src/app/(app)/(shell)/billing/pay/page.tsx`
- [ ] T060 [P] [US1] صفحة العودة بحالاتها **الثلاث** (نجاح · فشل · ما زالت معلّقة) في `frontend/src/app/(app)/(shell)/billing/pay/return/page.tsx` — `FR-009`: الاعتماد لا يتوقّف على عودة المستخدم، فالصفحة تعرض ولا تقرّر
- [ ] T061 [P] [US1] `frontend/src/lib/payments.ts` — ولا تنسيق مال في الـAPI، `formatMinorMoney()` وحدها تُحوّله نصّاً
- [ ] T062 [P] [US1] مدخلات `attributes` لكل حقلٍ جديد في `backend/lang/ar/validation.php` — بدونها يُعرض `payment_method` خاماً للطالب
- [ ] T063 [US1] ⚠️ **قلب `BillingMode::PaymentGateway::isReady()` إلى `true`** في `backend/app/Modules/Payments/Enums/BillingMode.php` — **بعد** أن يخضرّ مسار US1 كاملاً لا قبله؛ وبدونه لا مساحة تُحوَّل إلى النمط الذي بنته المرحلة

**نقطة تفتيش:** US1 تعمل وتُشحن وحدها. **هذه هي الـMVP.**

---

## الطور ‎٤‎: US3 — الإيصال والاعتماد اليدوي (P3)

⚠️ **قبل US2 خلافاً لأولويّتَيهما المعلنتين**، لأن جرد `research.md` §و غيّر حجمهما:
هذه دلتا ضيّقة على مسارٍ يعمل، وتلك بناءٌ كامل يحتاج US1 قبله (`plan.md` §ترتيب التنفيذ).

**الهدف:** إغلاق فجوة `US3` الحقيقية: ثلاثة أحداث · عنوان الشبكة والجهاز · زمن المراجعة ·
سباق `ApproveOrder` **و`RejectOrder`** · محفظة الهاتف.

### اختبارات US3

- [ ] T064 [US3] ⚠️ **اختبارٌ يجب أن يفشل أوّلاً** — اعتمادان متزامنان لطلبٍ واحد في `backend/tests/Feature/Payments/ApprovalConcurrencyTest.php`: قيد أرصدة واحد ✅ اليوم، و**صفّا `payment_transactions`** ❌ يكسران `FR-032` و`SC-010`. ويمشي المسار الذي يمشيه التزامن الحقيقي على شكل `SeatConcurrencyTest`، **لا `lockForUpdate()`** فهي بلا أثر على SQLite
- [ ] T065 [US3] اعتمادٌ ورفضٌ متزامنان في `backend/tests/Feature/Payments/ApprovalConcurrencyTest.php` — الأثر أسوأ: طلبٌ **مرفوض** وقد سُكّت أرصدته وأُنشئ تسجيله، **بلا مسار تعويض** (`research.md` §هـ2)
- [ ] T066 [P] [US3] **تثبيت** `FR-019` في `backend/tests/Feature/Payments/ReceiptLinkTest.php` — الرابط موقّع ‎١٥‎ دقيقة وينتهي فيُردّ؛ **قائم ويُثبَّت لا يُبنى**
- [ ] T067 [P] [US3] **تثبيت** `FR-021` و`FR-002` في `backend/tests/Feature/Payments/ManualPathTest.php` — لا رفض بلا سبب (إلزاميّ بتوقيع `RejectOrder`)، والمسار اليدوي عاملٌ بجوار البوابة
- [ ] T068 [P] [US3] أحداث الإيصال الثلاثة وتسجيل القرار بعنوان الشبكة والجهاز (`FR-023` · `SC-008`) في `backend/tests/Feature/Payments/ReceiptLifecycleTest.php`
- [ ] T069 [P] [US3] **ثغرة مشحونة**: مدرّسٌ حاملٌ `ORDERS_VIEW_ALL` **لا يرى** طلبات `kind = credits` في `backend/tests/Feature/Payments/OrderKindVisibilityTest.php` — اليوم يقرأ الإجمالي `cost-plus` الذي دفعه طالبه، وحجمان يحلاّن ثوابت المنصّة (`FR-033`)

### تنفيذ US3

- [ ] T070 [US3] تحديثٌ شرطيّ ذرّي على الحالة (`WHERE status IN (pending, under_review)`) في `backend/app/Modules/Payments/Actions/ApproveOrder.php` **و**`backend/app/Modules/Payments/Actions/RejectOrder.php` — الاثنان معاً في مهمّةٍ واحدة لأن السباق واحد والقرار من يكسبه (`research.md` §هـ2)
- [ ] T071 [P] [US3] أحداث `ReceiptUploaded` · `ReceiptApproved` · `ReceiptRejected` في `backend/app/Modules/Payments/Events/` — ⚠️ **تُضاف ولا يُعاد تسمية القائم**: `PaymentApproved`/`PaymentRejected` عن الطلب لا الإيصال، ومستمعان مشحونان مربوطان بهما
- [ ] T072 [US3] إطلاقها من `backend/app/Modules/Payments/Actions/UploadPaymentReceipt.php` و`ApproveOrder.php` و`RejectOrder.php`
- [ ] T073 [US3] إضافة عنوان الشبكة والجهاز إلى نداءات `logActivity()` في `backend/app/Modules/Payments/Actions/ApproveOrder.php` و`RejectOrder.php` و`UploadPaymentReceipt.php` **وحدها** — ⚠️ **يُمنع تعديل سمة `LogsActivity` المشتركة**: ‎٢٣‎ Action في **سبع** وحدات تستعملها، وتغييرها يُلحق حقلين بلا معنى بكل سجلٍّ في المنتج (`research.md` §ج)
- [ ] T074 [P] [US3] قبول `PaymentMethod::MobileWallet` عند رفع الإيصال في `backend/app/Modules/Payments/Actions/UploadPaymentReceipt.php` وكتابة `method` على الطلب والعملية — نصف `FR-018` الناقص
- [ ] T075 [P] [US3] زمن المراجعة المتوقّع المعلن في `backend/app/Modules/Payments/Http/Resources/OrderResource.php` (`FR-024`)
- [ ] T076 [US3] تقييد `OrderController::index` والحمولة على `kind` في `backend/app/Modules/Payments/Http/Controllers/OrderController.php` — **لا على الصلاحية وحدها**: طلبات الكورس استثناءٌ مشروع لأن المدرّس يعتمدها، ومشتريات الأرصدة ليست كذلك
- [ ] T077 [P] [US3] عرض الحالة وزمن المراجعة وأحداث الإيصال في `frontend/src/app/(app)/(shell)/orders/page.tsx` — **الصفحة قائمة**، وهي مسار الإيصال الحقيقي

**نقطة تفتيش:** المسار اليدوي مغلق التزامن ومسجَّل بالكامل.

---

## الطور ‎٥‎: US2 — التسوية الدورية (P2)

**الهدف:** ما ضاع من الإشعارات يُلتقط، وما لم يُحسم يظهر لمسؤول.

### اختبارات US2

- [ ] T078 [P] [US2] `SC-004` — نجاحٌ لدى الوهميّ **بلا إشعار** ثم تسوية ⇒ يُعتمد ويُرفع الحجب، في `backend/tests/Feature/Payments/ReconciliationSweepTest.php`
- [ ] T079 [P] [US2] `SC-005` في `backend/tests/Feature/Payments/ReconciliationIdempotencyTest.php` — تشغيلان متتاليان ينتجان الحالة نفسها، **وتشغيلان متداخلان** يسكّان مرّةً واحدة (التحديث الشرطيّ الذرّي هو ما يجعله صحيحاً تحت التزامن)
- [ ] T080 [P] [US2] ⚠️ **الاتجاه هو الاختبار** في `backend/tests/Feature/Payments/ReconciliationDirectionTest.php`: `Pending → Captured` آليٌّ بحقّ، و`Captured → Failed` يحتاج بشراً (`FR-014`) — اختبارٌ لا يفرّق يمرّ على تنفيذٍ يسحب رصيداً دفعه صاحبه
- [ ] T081 [P] [US2] المعلّقة المتجاوزة للمهلة تُغلق بحالةٍ نهائية معلنة (`FR-015`) وغير المحسوم يظهر في التقرير (`FR-017`)، في `backend/tests/Feature/Payments/ReconciliationTimeoutTest.php`
- [ ] T082 [P] [US2] **الثابت الثالث** في `backend/tests/Feature/Payments/ReconciliationInvariantTest.php`: كل `captured` على طلب أرصدة يحمل قيداً في `credit_transactions` — ⚠️ المقارنة حالةً-بحالة **عمياء** عن العطل الذي سيقع فعلاً (المستمع المطبور مات: المزوّد يقول نجحت ونحن نقول نجحت، **صفر ملاحظات كل ليلة** والطالب محجوب)
- [ ] T083 [P] [US2] اختبار وحدة للمدى **نصف المفتوح `[from, to)`** في `backend/tests/Unit/Payments/ReconciliationWindowTest.php` (`NFR-003ب` · `contracts/provider.md` §و) — مغلقٌ على الطرفين يزور الثانية الحدّية مرّتين، ومفتوحٌ عليهما يُسقطها

### تنفيذ US2

- [ ] T084 [US2] هجرة `payment_reconciliation_runs` في `backend/app/Modules/Payments/Database/Migrations/2026_08_11_001200_create_payment_reconciliation_runs_table.php` — **مملوك للمنصّة صنف (ب)** بـ`uuid`، على شكل `create_credit_reconciliation_runs_table.php:22` المشحون
- [ ] T085 [P] [US2] نموذج `backend/app/Modules/Payments/Models/PaymentReconciliationRun.php` ومصنعه في `backend/database/factories/Modules/Payments/PaymentReconciliationRunFactory.php`
- [ ] T086 [US2] `backend/app/Modules/Payments/Actions/ReconcilePayments.php` — ⚠️ يبني على `transactionsInWindow()` **لا على `verify()`**: الثانية تسأل «ما حال ما أعرفه؟» فتبقى عمياء عن دفعةٍ ضاع إشعارها، وهي الحالة التي وُجدت التسوية لأجلها (`contracts/provider.md` §أ)
- [ ] T087 [US2] الثابت الثالث داخل `backend/app/Modules/Payments/Actions/ReconcilePayments.php` **بقراءتين مُجمَّعتين لا باستعلامٍ لكل صفّ**، و`findings` **عيّنة** بينما `unresolved_count` هو العدد الحقيقي دائماً — سقفٌ يبلّغ عن نفسه بوصفه «كل شيء» هو كيف يُقرأ نشرٌ مكسور كثلاث مشاكل بدل تسعة آلاف
- [ ] T088 [US2] `backend/app/Modules/Payments/Jobs/ReconcilePaymentsJob.php` على طابور **`maintenance`** مع `withoutOverlapping()` — و**كل المشيات `chunkById`**، و`forWorkspace()` لا `WorkspaceContext::set()` فالوظيفة تعبر مساحاتٍ على العامل نفسه (`NFR-009`)
- [ ] T089 [P] [US2] جدولتها في `backend/routes/console.php`
- [ ] T090 [US2] `GET /admin/payments/reconciliation` في `backend/app/Modules/Payments/Http/Controllers/Admin/PaymentReconciliationController.php` + Resource — ⚠️ **`GET` لأنه يقرأ لقطةً مخزَّنة**: ثلاثة `GROUP BY` بلا مرشِّح مستأجر على أسرع الجداول نموّاً تُشغَّل عند كل تحديث للصفحة
- [ ] T091 [P] [US2] صفحة التسوية في `frontend/src/app/(app)/(shell)/admin/payments/reconciliation/page.tsx`

**نقطة تفتيش:** ما ضاع يُلتقط، ولا تصحيح آليّ يضرّ الطالب.

---

## الطور ‎٦‎: US4 — سجلّ التدقيق المالي (P4)

**الهدف:** كل عملية مالية بمنفّذها ووقتها وعنوان شبكتها، ولا تُعدَّل.

### اختبارات US4

- [ ] T092 [P] [US4] خمس عمليات مالية ⇒ خمسة قيود بمنفّذها ووقتها وعنوانها (`SC-008`) في `backend/tests/Feature/Payments/BillingAuditTest.php`
- [ ] T093 [P] [US4] رفض التعديل والحذف (`SC-009`) — ⚠️ **ويُختبر الشكل الجُملي أيضاً**: `update()` على مُنشئ استعلام لا يستحضر نماذج فلا يمرّ بحارس النموذج، وهي الثغرة نفسها المكتوبة في `CLAUDE.md` عن دفتر التسوية
- [ ] T094 [P] [US4] **الاتجاهان معاً** في `backend/tests/Feature/Payments/AuditSubjectIsolationTest.php`: مدقّق مدفوعات الطلاب لا يرى قيداً من ‎014‎، ومدقّق أجور المدرّسين لا يرى قيداً من هنا
- [ ] T095 [P] [US4] `FR-029` في `backend/tests/Feature/Payments/BillingAuditAccessTest.php` — بلا `BILLING_AUDIT_VIEW` ‎403‎، **وحاملُ أعلى دورٍ مستأجر يُردّ كذلك** (الدستور §I يوجبها في نفس الـPR)
- [ ] T096 [P] [US4] `FR-028` — السلسلة الكاملة من الإنشاء إلى الإغلاق بلا N+1، في `backend/tests/Feature/Payments/AuditChainTest.php`

### تنفيذ US4

- [ ] T097 [P] [US4] ثابت `BILLING_AUDIT_VIEW` في `backend/app/Modules/Tenancy/Support/Permissions.php` وإسناده في `RolePermissionMatrix.php` — ⚠️ **`billing.*` لا `payments.*`**: الأخيرة عائلةٌ **مستأجرة** في هذا المستودع (`payments.approve` في مصفوفة المدرّس)، والمرآة الصحيحة لـ`settlement.audit.view` هي `billing.audit.view`
- [ ] T098 [US4] `backend/app/Modules/Payments/Support/BillingAuditSubjects.php` — `Order` · `PaymentTransaction` · `CreditTransaction` · `CreditPurchase` · `CreditBalance` · `TermsConsent` · **`ExamModeWindow`** (يكتب في السجلّ اليوم في `:63,87`، فقائمةٌ بدونه تترك قيوداً مالية خارج تدقيقها بالبناء)
- [ ] T099 [US4] نموذج نشاطٍ مُعاد ربطه بحارس عدم التعديل في `backend/app/Shared/Models/ActivityEntry.php` + مفتاح `activity_model` في `backend/config/activitylog.php` (**غير منشور اليوم** — يُنشأ في هذه المهمّة) — شكل `LedgerEntry::booted()` و`CreditTransaction::booted()` المشحونَين، فspatie لا يفرض `FR-027`
- [ ] T100 [US4] ⚠️ **تمرير المساحة والمنفّذ صراحةً** من داخل `forWorkspace()` في نداءات `logActivity()` داخل `backend/app/Modules/Payments/Jobs/` كلّها — `logActivity()` يقرأ `WorkspaceContext::id()` و`Auth::user()` وكلاهما `null` في وظيفةٍ مطبورة، **وأسوأ: المفردة تخزّن أول نتيجة فقد يحمل عاملٌ مساحةً بائدة**؛ و`activity_log` بلا عمود `workspace_id` فتلك القيمة هي التسمية الوحيدة. ويُسمّى المنفّذ «النظام» بمرجع الإشعار لا بمستخدمٍ وهميّ
- [ ] T101 [US4] `backend/app/Modules/Payments/Http/Controllers/Admin/PaymentAuditController.php` — القائمة والسلسلة، مع `->with(['subject','causer'])` منسوخاً مع المُرشِّح من `SettlementAuditController:49`: **نسخُ المُرشِّح وحده يشحن العطل الذي أُصلح هناك** (‏Resource تعمل مرّة لكل صفّ)
- [ ] T102 [P] [US4] صفحة التدقيق في `frontend/src/app/(app)/(shell)/admin/payments/audit/page.tsx`

**نقطة تفتيش:** السجلّ يحسم الخلاف ولا يُعدَّل.

---

## الطور ‎٧‎: US5 — سجلّ التحصيل للإدارة (P5)

**الهدف:** صورة التدفّق النقدي الداخل للمنصّة وحدها — ولا شيء منها يبلغ مدرّساً.

### اختبارات US5

- [ ] T103 [P] [US5] `SC-010` — ‎١٠٬٠٠٠‎ عملية والإجماليات تطابق المصدر بفارق صفر، في `backend/tests/Feature/Payments/CollectionReportTest.php`
- [ ] T104 [P] [US5] `SC-014` — ميزانية استعلامات ولا مسح كامل، في `backend/tests/Feature/Payments/CollectionQueryBudgetTest.php` على شكل `BalanceQueryBudgetTest` المشحون
- [ ] T105 [P] [US5] `FR-033` في `backend/tests/Feature/Payments/CollectionAccessTest.php` — المدرّس **ومالك المساحة** يُردّان بـ‎403‎ (`SC-011`)، والتصدير بنفس القيود (`FR-034`)
- [ ] T106 [P] [US5] `SC-012` — فحصٌ آليّ: صفر سرّ أو بيان وسيلة دفع في أي استجابة أو سجلّ تطبيق أو رسالة خطأ، في `backend/tests/Feature/Payments/PaymentExposureTest.php`
- [ ] T107 [P] [US5] `FR-035/036` — **تثبيت** في `backend/tests/Feature/Settlement/ContextIsolationTest.php`: يغطّي جداول ‎007‎ الجديدة على الاتجاهين (قوائم الجداول مشتقّة من نداءات `Schema::create` لكل طرف، فتُلتقط تلقائياً ويُؤكَّد ذلك)

### تنفيذ US5

- [ ] T108 [P] [US5] ثابت `BILLING_COLLECTION_VIEW` في `backend/app/Modules/Tenancy/Support/Permissions.php` — منصّيّ، لا يبلغ أي دورٍ مستأجر
- [ ] T109 [US5] `backend/app/Modules/Payments/Actions/BuildCollectionReport.php` — استعلامٌ **مُجمَّع** على الفهرس بلا جدول تجميع (`research.md` §ط)، و⚠️ **`withoutWorkspaceScope()` مُعلَنة صراحةً بتعليقٍ واختبار**: `WorkspaceContext::id()` يرتدّ إلى `users.last_workspace_id` **لكل مستخدم بمن فيهم السوبر أدمن**، فبدونها يعرض التقرير مال مدرّسٍ واحد على أنه إجمالي المنصّة **ويمرّ الاختبار على تجهيزةٍ بمساحة واحدة**
- [ ] T110 [US5] `backend/app/Modules/Payments/Http/Controllers/Admin/CollectionReportController.php` — والتصدير **يعيد استعمال نفس المُصفِّي والاستعلام**، لا استعلاماً ثانياً: هناك يُنسى شرطٌ واحد فيخرج الملفُّ حاملاً ما تمنعه الشاشة (`FR-034`)
- [ ] T111 [US5] `backend/app/Modules/Payments/Support/PaymentFieldAllowlist.php` على شكل `StudentBalanceAllowlist` — ⚠️ **وقيمة الحقل تُفحص كما يُفحص اسمه**: قائمةُ أسماءٍ لا ترى سرّاً داخل `payload`، وهو عمودٌ حرّ يكتبه الطرف الآخر
- [ ] T112 [P] [US5] صفحة التحصيل في `frontend/src/app/(app)/(shell)/admin/payments/collection/page.tsx`

**نقطة تفتيش:** كل القصص تعمل مستقلّةً.

---

## الطور ‎٨‎: الصقل والمشترك

- [ ] T113 [P] `frontend/e2e/payments.spec.ts` — ⚠️ ويحتاج `PHP_CLI_SERVER_WORKERS=8 php artisan serve`، وإلا سقط **البناء** قبل أن يعمل اختبارٌ واحد
- [ ] T114 [P] تحديث `docs/README.md` بالصلاحيتين المنصّيتين الجديدتين والمسارات والطابور، و`docs/erd.md` بالجدولين الجديدين وبتصحيح ملاحظة الوحدات الصغرى على `orders` التي بطلت بالطور ‎٢‎ــج
- [ ] T115 [P] إضافة مزالق هذه المرحلة إلى `CLAUDE.md` و`AGENTS.md`: التوقيع ليس تحقّقاً من المبلغ · `manual` لا يقبل إشعاراً · مفتاح التكرار على الأثر لا الإيصال · محدِّد الويب-هوك يُفهرس بالمزوّد
- [ ] T116 تشغيل سيناريوهات `quickstart.md` التسعة يدوياً وتأشير كلٍّ منها
- [ ] T117 البوابات الأربع خضراء (`SC-015` · `NFR-006`): `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` من `backend/`، و`npx tsc --noEmit` من `frontend/` — **بلا baseline جديد وبلا `@phpstan-ignore` وبلا `assert()`/`@var` مضمَّن**

---

## التبعيات وترتيب التنفيذ

### بين الأطوار

- **‏١ التهيئة** — بلا تبعية.
- **‏٢ الأساس** — يحجز كل القصص. سلسلة الهجرات `T005→T013` **متتابعة حتماً**.
- **‏٢ــج الوحدات الصغرى** — يعتمد على ‎٢أ‎، و**قابل للاقتطاع كاملاً** بقرار المالك: عندها تبقى المرحلة تعمل على `decimal` وتُسجَّل المخالفة لـ`NFR-007` صراحةً.
- **‏٣ US1** ← ‎٢‎.
- **‏٤ US3** ← ‎٢‎ (مستقلّ عن US1 بالتسليم).
- **‏٥ US2** ← ‎٣‎ — التسوية تحتاج مساراً تصحّحه.
- **‏٦ US4** ← ‎٣‎ و‎٤‎ — يسجّل ما تنتجه ما قبله.
- **‏٧ US5** ← كلّها — يعرض ولا ينتج.
- **‏٨ الصقل** ← ما شُحن منها.

### ⚠️ لماذا US3 قبل US2

خلافاً لأولويّتَيهما المعلنتين: جرد `research.md` §و غيّر حجمهما — `US3` صار دلتا ضيّقة على
مسارٍ يعمل، و`US2` بناءٌ كامل يحتاج `US1` قبله. **الأولوية تقيس القيمة، والترتيب يقيس
التبعية والحجم** (`plan.md` §ترتيب التنفيذ).

### داخل القصّة

الاختبار قبل التنفيذ · الهجرة قبل النموذج · النموذج قبل الـAction · الـAction قبل المتحكّم ·
المتحكّم قبل الواجهة.

⚠️ **و`T064` وحده يُكتب ليفشل أوّلاً** — العيب مشحون، فاختبارٌ أخضر من أول تشغيل يعني أنه
لا يقيس السباق.

### فرص التوازي

| الطور | المتوازي |
|---|---|
| ‎١‎ | `T002` `T003` `T004` |
| ‎٢ب‎ | `T014` `T015` `T016` `T017` معاً، ثم `T018` |
| ‎٢ــج‎ | `T026` `T027` `T028` |
| ‎٣‎ | تسعة اختبارات `T031`…`T039` معاً · الواجهة `T059`…`T062` |
| ‎٤‎ | `T066` `T067` `T068` `T069` |
| ‎٥‎ | `T078`…`T083` |
| ‎٦‎ | `T092`…`T096` |
| ‎٧‎ | `T103`…`T107` |

**⚠️ ولا `[P]` على `T005`…`T013` أبداً** — هجرةٌ تسبق تنظيفها تُسقط النشر على بيانات حيّة.

```bash
# اختبارات US1 دفعةً واحدة
Task: "المسار السعيد في tests/Feature/Payments/InstantPaymentTest.php"
Task: "التوقيع الباطل في tests/Feature/Payments/WebhookSignatureTest.php"
Task: "التكرار عشراً في tests/Feature/Payments/WebhookIdempotencyTest.php"
Task: "المبلغ المخالف في tests/Feature/Payments/AmountMismatchTest.php"
Task: "حلّ المزوّد في tests/Feature/Payments/WebhookProviderResolutionTest.php"
```

---

## استراتيجية التسليم

**الحدّ الأدنى:** ‎١‎ + ‎٢‎ + ‎٣‎ (‏`T001`…`T023` + `T031`…`T063`) = **التحصيل صار لحظياً**،
وهي المرحلة كلها في جملة.

**التسليم المتدرّج:** الأساس ⇐ US1 (شحن) ⇐ US3 (شحن) ⇐ US2 ⇐ US4 ⇐ US5.

**عند تقليص النطاق:** يخرج الطور **‎٢ــج** أوّلاً — سبعُ مهامّ في أربعة جداول ووحدتين لا
تخصّان المرحلة، وهي أخطر كتلةٍ فيها. وخروجه لا يعطّل قصّةً واحدة.

**قرارٌ مفتوح لا يحجب العمل:** آلية إسناد `finance-admin` (‏`model_has_roles.team_id` غير
قابل للإفراغ وجزءٌ من المفتاح الأساسي). الحارس اليوم السوبر أدمن عبر `Gate::before`،
والاعتماد يعمل.

---

## ملاحظات

- كل مهمّة تحمل مساراً دقيقاً وسنداً في وثيقة تصميم، فتُنفَّذ بلا سياقٍ إضافي.
- ⚠️ **ولا `Queue::fake()` عارية في أي اختبار يمسّ الشحن** — مستمع الشحن مطبور، فيبتلعه الفاكّ العاري ويصير التأكيد على جدولٍ فارغ. تُزيَّف وظائف الخطّ الزمني وحدها.
- الهجرات في `Database/Migrations` بحرف **M** كبير — خطؤها يُحمّل **صفر** هجرة على لينكس.
- `php artisan migrate` وحدها؛ **يُسأل المالك قبل أي `migrate:fresh`**.
- إيداعٌ بعد كل مهمّة أو مجموعةٍ منطقية، ووقوفٌ عند كل نقطة تفتيش للتحقّق من القصّة وحدها.
