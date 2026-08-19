---
description: "Task list — قناة واتساب (٠٢٠)"
---

# Tasks: قناة واتساب (WhatsApp Notification Channel)

**Input**: [spec.md](./spec.md) · [plan.md](./plan.md)

**Tests**: مطلوبةٌ صراحةً — ‏٦ من ١٢ معياراً تصف عيباً **لا يظهر إلّا باختبارٍ مصاغٍ بشكلٍ
معيّن** (`SC-002` · `SC-003` · `SC-006` · `SC-009` · `SC-010` · `SC-012`).

## Format: `[ID] [P?] [Story] Description`

---

## ⚠️ اقرأ هذا قبل `T001`

**أربعةُ أشياءَ يمرّ خطؤها أخضر**، وكلُّها مقروءةٌ من الكود لا مُفترَضة:

1. **القناةُ وحدَها تُسلّم صفرَ رسالة** (`T015`). `defaultChannels()` يُرجع `[InApp]` لكلّ نوع،
   فبلا `T015` لا يُنشأ صفُّ تسليمِ واتساب أصلاً — **ولا شيءَ يفشل، لأن لا شيءَ يجري**.
2. **قالبٌ مفقودٌ إسقاطٌ صامت** (`T018`). `TemplateRenderer` يرفض، و`DispatchNotification`
   **يُسجّل ولا يُفشل**. وكلُّ تأكيدٍ على رسالةٍ بلا قالبٍ ينجح **فراغاً**.
3. **`canReach()` يمنع رمزَ التحقّق من الخروج** (`T024`). فبلا مسارٍ يتجاوز المُرسِل، لا
   يتحقّق رقمٌ واحدٌ أبداً — وكلُّ تسليمٍ «مُتخطّى» إلى الأبد، **بلا خطأٍ في أيّ سجلّ**.
4. **`Http::fake()` يُلحِق المجموعات وأوّلُ نمطٍ مطابقٍ يفوز** (`T012`). فاختبارٌ يُزيّف جواباً
   ثمّ يُعيد التزييف بجوابٍ آخر **يظلّ يأخذ الأول**. مغلقةٌ واحدةٌ تقودها حالةٌ يغيّرها
   الاختبار — نفسُ ما فرضته `BunnyIngestTest`.

---

## Phase 1: Setup — الإعدادات والأسماء

- [X] T001 أضف كتلةَ `whatsapp` إلى `backend/config/notifications.php`: `enabled` · `base_url` · `auth_header` · `api_key` · `default_country_code` · `language` — **والترويسةُ اسمٌ كاملٌ من البيئة لا فرعٌ في الكود** (‏الانتقال إلى Meta مفتاحان)
- [X] T002 [P] أضف المفاتيحَ الخمسةَ إلى `backend/.env.example` بقيمٍ فارغةٍ وتعليقٍ يقول إنّ الفراغ يعني «القناةُ مُتخطّاة لا فاشلة» — **ولا قيمةَ حقيقيةَ في المستودع**
- [X] T003 [P] أضف `redis:notifications` و`redis:notifications-high` إلى `waits` في `backend/config/horizon.php` — زوجٌ غائبٌ **لا يُراقَب بحدٍّ افتراضيٍّ بل لا يُراقَب**، وما يتأخّر هنا رسالةٌ إلى وليّ أمر
- [X] T004 [P] أضف `360dialog` و`d360` إلى قائمة الممنوعات في `backend/tests/Feature/Notifications/ProviderAgnosticTest.php` — **حارسٌ لا يعرف الاسمَ الجديد لا يحرسه**، وهذا أوّلُ مزوّدٍ فعليٍّ في هذه الوحدة
- [X] T005 [P] أضف مُدخلَي `contact_value` و`code` إلى `attributes` في `backend/lang/ar/validation.php` — بلا مُدخلٍ يُصيَّر الاسمُ إنجليزياً على شاشةٍ عربيةٍ فقط

---

## Phase 2: Foundational — القناة (تحجب كلَّ القصص)

- [X] T006 أنشئ `backend/app/Modules/Notifications/Support/PhoneNumber.php` بدالّة `toE164(string, string $defaultCountryCode): ?string` — تُنظّف المسافاتِ والشُّرَط، و`00` ⇒ `+`، و`0` بادئةً تسقط ويُضاف مفتاحُ الدولة، وما لا يُوحَّد يُرجع `null`. ⚠️ **`ponytail:` بشكلِ قطر** ومسارُ الترقية بجواره: بلدٌ ثانٍ ⇒ `libphonenumber`
- [X] T007 [P] أنشئ `backend/tests/Unit/Notifications/PhoneNumberTest.php` بحالاتِ `+974…` · `00974…` · `0…` · `974…` · نصٍّ باطل · رقمٍ قصيرٍ جداً
- [X] T008 [P] أنشئ `backend/app/Modules/Notifications/Contracts/SendsVerificationCodes.php` بدالّةٍ واحدة `sendVerificationCode(string $toE164, string $code): void` — **إعلانُ قدرة** بصيغة `ProviderCapabilities` في ٠١٩، والبريدُ والرسالةُ النصّية سيُنفّذانه
- [X] T009 أضف `verifiedValueFor(User, NotificationChannel): ?string` إلى `backend/app/Modules/Notifications/Models/ContactVerification.php` — `verified_at IS NOT NULL` وحدَه
- [X] T010 أنشئ `backend/app/Modules/Notifications/Channels/WhatsAppChannel.php` يُنفّذ `NotificationChannelInterface` و`SendsVerificationCodes` — `isEnabled()` من الإعداد، `canReach()` من `T009`، `send()` نداءُ `Http` واحد
- [X] T011 في `WhatsAppChannel`: ابنِ الحمولةَ من صفِّ القالب — الاسمُ `type`، اللغةُ `ar`، والمُعامِلاتُ من `variables` **بترتيبها** مقروءةً من `payload`. ⚠️ **اكتب بجوارها أنّ `body_ar` توثيقٌ لا نصّ** (`FR-008`) — عكسُه ما سيفترضه أوّلُ من يُصلح فاصلة
- [X] T012 في `WhatsAppChannel`: صنِّف الخطأ — `4xx` وليست `429` وبلا `is_transient=true` ⇒ `PermanentDeliveryException`؛ ما عداه ⇒ إعادةُ الرمي. ⚠️ **مقروءٌ من جواب المزوّد لا من قائمةِ رموزٍ عندنا**: قائمةٌ منسوخةٌ بالحدس تشيخ عند أوّل إصدار
- [X] T013 في `WhatsAppChannel`: **يُمنع** ظهورُ الرقم كاملاً في أيّ `Log::` — يُقنَّع إلى آخر أربعةِ أرقام (`FR-022`)
- [X] T014 أضف سطرَ الوسم في `backend/app/Modules/Notifications/NotificationsServiceProvider.php` — **سطرٌ واحد**، وهو ما يُثبت `SC-001` في ٠٠٣

**Checkpoint**: القناةُ قائمةٌ ومُعلَنة. ولا رسالةَ تخرج بعدُ — وهذا صحيح.

---

## Phase 3: US1 — تقريرُ الحصّة يصل الهاتف (P1) 🎯 MVP

- [X] T015 [US1] عدِّل `defaultChannels()` في `backend/app/Modules/Notifications/Support/NotificationType.php` ليُضيف واتساب حين `targetsGuardians()` — ⚠️ **يقرأ المُسنَدَ الموجودَ لا قائمةً ثانيةً بسبعةَ عشرَ اسماً**: قائمتان لنفس السؤال تفترقان عند أوّل نوعٍ يُضاف
- [X] T016 [US1] احذف التعليقَ القديم في نفس الدالّة («يومَ تصل قناةٌ ثانية…») واستبدله بما صار صحيحاً
- [X] T017 [US1] [P] أنشئ `backend/tests/Feature/Notifications/WhatsAppDefaultsTest.php` — **`SC-006`**: العددُ **سبعةَ عشرَ** بالضبط، ونوعٌ خارجَها (`security_alert`) لا يحمل القناة
- [X] T018 [US1] أضف **١٨** صفَّ قالبِ واتساب إلى `backend/database/seeders/NotificationTemplateSeeder.php` (‏السبعةَ عشرَ + `contact_verification`) بحالة `APPROVAL_PENDING` — ⚠️ **لا `APPROVED`**: ادّعاءُ اعتمادٍ لم يحدث يجعل أوّلَ إرسالٍ خطأً من المزوّد بدل رسالةٍ مقروءةٍ عندنا
- [X] T019 [US1] [P] تغطيةُ القوالب — **مُنفَّذةٌ داخل `WhatsAppDefaultsTest.php` لا في ملفٍّ ثانٍ**: الحالتان تقرآن نفسَ المُسنَد (`defaultChannels()`)، وملفّان يقرآنه هما موضعان يفترقان. يفشل على نوعٍ افتراضُه واتساب وبلا صفِّ قالب، وعلى صفٍّ مزروعٍ `approved`
- [X] T020 [US1] أنشئ `backend/tests/Feature/Notifications/WhatsAppChannelTest.php` — **`SC-002`** (‏بلا اعتماد: الكلُّ `skipped` وصفرُ `failed`) · **`SC-003`** (‏بلا رقمٍ متحقَّق: `skipped` لا `failed`) · **`SC-007`** (`500` يُعيد، `400` لا)
- [X] T021 [US1] في نفس الملفّ: الطريقُ السعيد — قالبٌ معتمَدٌ ورقمٌ متحقَّقٌ ⇒ **نداءٌ واحد** بجسمٍ فيه اسمُ القالب والمُعامِلاتُ بترتيبها، والتسليمُ `delivered`

---

## Phase 4: US2 — التحقّق من الرقم (P1)

- [X] T022 [US2] عدِّل `backend/app/Modules/Notifications/Actions/RequestContactVerification.php` ليُوحّد القيمةَ بـ`PhoneNumber::toE164()` قبل الحفظ، ويرمي عند الفشل — **التوحيدُ عند الكتابة** فلا تتعايش صيغتان في العمود
- [X] T023 [US2] عدِّل `backend/app/Modules/Notifications/Http/Controllers/ContactVerificationController.php` ليُرسل الرمزَ عبر القناة **إن كانت تُعلن `SendsVerificationCodes`** — وإلّا يبقى السلوكُ كما هو
- [X] T024 [US2] في `WhatsAppChannel::sendVerificationCode()`: أرسِلْ إلى الرقم **مباشرةً** بقالب `contact_verification.whatsapp`. ⚠️ **يُمنع** المرورُ بـ`DispatchNotification` — ينشئ صفَّ إشعارٍ في سجلٍّ لا يخصّه، ويسأل `canReach()` عن رقمٍ لم يُتحقَّق منه بعد **فيُتخطّى دائماً وأبداً**
- [X] T025 [US2] [P] أنشئ `backend/tests/Feature/Notifications/ContactVerificationSendTest.php` — **`SC-009`**: خرجت رسالةٌ **وصفرُ صفٍّ جديدٍ في `notifications`**؛ والرمزُ غائبٌ عن جسم الاستجابة
- [X] T026 [US2] [P] أضف `requestWhatsAppVerification()` و`confirmWhatsAppVerification()` إلى `frontend/src/lib/notifications.ts`
- [X] T027 [US2] أنشئ `frontend/src/components/settings/WhatsAppVerification.tsx` — مدخلُ رقمٍ ثمّ مدخلُ رمز، بحالاتِ التحميل والخطأ، و`422` عبر `fieldErrors()` وما عداه عبر `userMessage()`
- [X] T028 [US2] اربط المكوّنَ في `frontend/src/app/(app)/(shell)/settings/notifications/page.tsx` — **سطحٌ لا يصله المستخدم ليس مُسلَّماً**
- [X] T029 [US2] [P] أنشئ `frontend/src/components/settings/WhatsAppVerification.test.tsx` — **`SC-011`**: الرقمُ يُرسَل، ثمّ الرمز، ورسالةُ الخطأ تحت حقلها

---

## Phase 5: US3 — ساعاتُ الهدوء (P2)

- [X] T030 [US3] أنشئ `backend/tests/Feature/Notifications/WhatsAppDeliveryPathTest.php` (‏سُمّي على ما يفعله: يقود الصنفَ الحقيقيَّ عبر `DispatchNotification` كاملاً، لا ساعاتِ الهدوء وحدها) — **أوّلُ تشغيلٍ فعليٍّ لـ`QuietHours`**: نوعٌ اختياريٌّ مؤجَّلٌ وإلزاميٌّ خارجٌ فوراً. ⚠️ `Queue::fake()` **بأسماءٍ محدّدة** لا فارغاً، و`->delay()` يعمل فوراً على `sync`
- [X] T031 [US3] [P] أضف حالةَ التجميع إلى نفس الملفّ — **`SC-012`**: عشرون تنبيهاً ⇒ رسالةُ واتساب **واحدة** وعشرون صفّاً في الجرس

---

## Phase 6: US4 — قالبٌ لم يُعتمَد بعد (P2)

- [X] T032 [US4] أضف حالةَ `SC-004` إلى `WhatsAppChannelTest` — صفٌّ `pending` ⇒ `failed` **بمفتاح القالب في السبب**، والعمليةُ المُطلِقة نجحت
- [X] T033 [US4] [P] أضف حقلَ `provider_approval_status` إلى **نموذج** `MessageTemplateResource` — ⚠️ **كان عموداً في الجدول وليس حقلاً في النموذج**، فالحالةُ ظاهرةٌ ولا تُغيَّر: صفوفُ واتساب تُشحن `pending` والمُصيِّر يرفض غيرَ المعتمَد، ولا سبيلَ في المنتج لتسجيل اعتمادٍ وقع. خطوةُ دليل النشر كانت تصف زرّاً غيرَ موجود

---

## Phase 7: العابرة

- [X] T034 [P] أضف حالةَ `SC-010` إلى `WhatsAppChannelTest` — صفرُ رقمٍ كاملٍ في أيّ سطرِ سجلّ، مقيساً بمسحٍ على المخرَج
- [X] T035 [P] أضف قسمَ «قناة واتساب» إلى `docs/deployment.md`: حسابُ 360dialog · توثيقُ Meta · رقمُ الهاتف · **اعتمادُ ١٩ قالباً** · قلبُ `provider_approval_status` بعد كلّ اعتماد · ⚠️ `contact_verification` **أوّلاً** فقبله لا يتحقّق رقمٌ واحد
- [X] T036 [P] أضف المزوّدَ إلى قسمِ معالِجي البيانات في `docs/deployment.md` — **يحمل اسمَ قاصرٍ ورقمَ وليّه**، ويُقيَّد في سجلّ ٠١٣ يومَ تصل
- [X] T037 [P] حدِّث `docs/README.md` بالقناة الجديدة وبالأنواع السبعةَ عشرَ
- [X] T038 حدِّث `CLAUDE.md`: «`InAppChannel` هو الوحيد المُنفَّذ» صارت **خاطئة**، وكذلك «واتساب معروفةٌ وغيرُ منفَّذة»
- [X] T039 حدِّث `docs/roadmap.md` — ٠٢٠ صفٌّ جديدٌ مُنفَّذ، والتالي ٠٠٩
- [X] T040 البوّابات: `pest` · `pint --test` · `phpstan analyse` · `tsc --noEmit` · `npm test` — **كلُّها خضراء**
- [ ] T041 ⚠️ **`SC-001` — تدخينٌ حيّ**: رسالةٌ حقيقيةٌ إلى هاتفٍ حقيقيّ. مجموعةٌ خضراءُ تُثبت أنّ الكود يوافق **تقليدَنا للمزوّد** — و٠١٧ وجدت خمسةَ عيوبٍ في أوّل تشغيلٍ حيٍّ لم يرَ أحدُها اختبار. **يبقى مفتوحاً حتى يُنجز المستخدمُ الحسابَ والاعتماد.**

---

## Dependencies

`Phase 1` → `Phase 2` → `Phase 3` (‏المُسلَّم) → `Phase 4` (‏**بلا `Phase 4` لا رقمَ متحقَّق فلا رسالةَ فعلية**) → `5` · `6` · `7` بأيّ ترتيب.

**MVP**: `T001`–`T029` — قناةٌ تعمل، رقمٌ يُتحقَّق منه، وتقريرُ حصّةٍ يصل هاتفاً.
