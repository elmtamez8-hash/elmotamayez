---

description: "Task list — منحُ اشتراكِ حصصٍ بيدِ موظّفِ المنصّة"
---

# Tasks: منحُ اشتراكِ حصصٍ بيدِ موظّفِ المنصّة (024)

**Input**: `specs/024-staff-credit-grant/` — [spec](./spec.md) · [plan](./plan.md) ·
[research](./research.md) · [data-model](./data-model.md) ·
[contracts](./contracts/staff-credit-grant.md) · [quickstart](./quickstart.md)

**Tests**: **إلزاميّة.** الدستورُ IV يجعلُ اختباراتِ الميزةِ شبكةَ الأمانِ الأساسيّة، وSC-006
وSC-009 وSC-010 لا تُقاسُ بغيرِها.

**Organization**: مجمّعةٌ حسبَ قصّةِ المستخدم. US1 وحدَها منتَجٌ صالح.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يجوزُ التوازي (ملفّاتٌ مختلفة، بلا اعتماديّةٍ على مهمّةٍ غيرِ منجَزة)
- **[Story]**: US1 · US2 · US3
- المسارُ الكاملُ في كلِّ مهمّة

## Path Conventions

مستودعٌ قائم: `backend/` (‏Laravel · وحداتٌ في `app/Modules/`) و`frontend/` (‏Next.js).
**هذه الميزةُ لا تلمسُ `frontend/` إطلاقاً** — السطحُ في لوحةِ Filament على `/admin`
(‏[research §R1](./research.md)).

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: لا تهيئةَ مشروع — المستودعُ قائمٌ ووحدةُ `Payments` مسجَّلةٌ في `phpstan.neon`.
الغرضُ الوحيد: أساسٌ أخضرُ يُقاسُ عليه.

- [X] T001 شغّلْ بوّاباتِ الأساسِ وسجّلْ نتيجتَها قبلَ أيِّ تعديل: `cd backend && php vendor/bin/pest tests/Feature/Payments` ثمّ `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`. ⚠️ رسوبٌ هنا **ليس** من هذه الميزةِ وتشخيصُه فوقَها يضيّعُ الوقت
- [X] T002 شغّلْ `cd backend && php artisan migrate --pretend` للتأكّدِ من أنّ قاعدةَ التطويرِ محدَّثةٌ قبلَ إضافةِ هجرة. ⚠️ **لا `migrate:fresh`** — لا تُدمَّرُ قاعدةٌ محلّيّةٌ بلا إذنٍ صريح

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: الوسيطُ والعمودُ والسياسة — كلُّ قصّةٍ تقفُ عليها.

**⚠️ CRITICAL**: لا عملَ في أيِّ قصّةٍ قبلَ إتمامِ هذه المرحلة.

- [X] T003 رقِّ `teachesIn()` من `private` إلى دالّةٍ عامّةٍ باسم `isSeller()` في `backend/app/Modules/Payments/Support/CourseParticipation.php`، واجعلْ `isPartyTo()` تستدعيها بدلَ الجسدِ المضمَّن. ⚠️ **تغييرُ اسمٍ لا تغييرُ سلوك**: أيُّ فرقٍ في نتيجةِ `isPartyTo()` عطلٌ لا إعادةُ تسمية
- [X] T004 أنشئْ هجرةَ `backend/app/Modules/Payments/Database/Migrations/2026_09_03_000100_add_granted_by_to_orders.php` تضيفُ `granted_by` — `foreignId` nullable على `users` بـ`nullOnDelete()`، **بلا فهرس**، بلا ردم. و`down()` يُسقِطُ القيدَ في مغلَّفِ `Schema::table` خاصٍّ به قبلَ العمود
- [X] T005 في `backend/app/Modules/Payments/Models/Order.php`: أضِفْ `@property ?int $granted_by`، وعلاقةَ `grantor(): BelongsTo` إلى `User::class, 'granted_by'`. ⚠️ **ولا تُضِفْه إلى `$fillable`** — واكتبْ فوقَه تعليقَ السببِ (‏سابقةُ `captured_order_id`) كي لا يُضيفَه تنظيفٌ لاحق
- [X] T006 في `backend/app/Modules/Payments/Actions/PurchaseCredits.php`: أضِفْ وسيطاً خامساً `?User $grantedBy = null`. استبدلْ فحصَ `isPartyTo()` بفحصَين: `isSeller()` **دائماً** (‏رفض)، وطرقُ الدخولِ **فقط حين `$grantedBy === null`**. واختمْ `granted_by` داخلَ مصفوفةِ `Order::create()` التي يملكُها الإجراء. ⚠️ الافتراضيُّ `null` كي يبقى كلُّ نداءٍ قائمٍ حرفيّاً كما هو
- [X] T007 [P] في `backend/app/Modules/Payments/Actions/ListCreditPackages.php`: الوسيطُ نفسُه `?User $grantedBy = null` والتخطّي الجزئيُّ نفسُه (‏السطر ٤٦). بدونه يرى الموظّفُ قائمةً فارغةً لكلِّ طالبٍ جديد — وهي الحالةُ الغالبة
- [X] T008 في `backend/app/Modules/Payments/Policies/OrderPolicy.php` · `uploadReceipt()`: أضِفْ فرعاً يسمحُ لحاملِ `Permissions::BILLING_PURCHASE_APPROVE` بالرفعِ على طلبٍ `requiresPlatformApproval()`. المالكُ يبقى كما هو، وطلبُ الكورسِ يبقى مرفوضاً لحاملِ الإذن
- [X] T009 في `OrderPolicy.php` · `view()` و`approve()` و`reject()`: انقلْ فرعَ `requiresPlatformApproval()` إلى **فوقَ** `belongsToCurrentWorkspace()`، بتعليقٍ يشرحُ السبب (‏الدستور I يُلزِمُ بإعلانِ كلِّ تجاوزٍ متعمّد). الفحصُ يبقى دونَه لطلباتِ الكورس
- [X] T010 أنشئْ `backend/tests/Feature/Payments/StaffCreditGrantTest.php` بتجهيزةٍ تحملُ **مساحتَي عملٍ** ومدرّسَين وباقةً واحدة، وموظّفَ ماليّةٍ عبرَ `makePlatformStaff(Roles::FINANCE_ADMIN)` بعدَ `$this->seed(RolesAndPermissionsSeeder::class)`. ⚠️ **وطالبٌ يُبنى بـ`User::factory()` وحدَها** — بلا بذرةٍ وبلا `addWorkspaceMember` وبلا `last_workspace_id`
- [X] T011 [P] في `StaffCreditGrantTest.php`: اختبارٌ لـT009 — موظّفٌ **له `last_workspace_id`** يقرأُ ويعتمدُ طلبَ رصيدٍ في مساحةِ العملِ الأخرى ⇒ ٢٠٠. وأعِدْها بموظّفٍ بلا مساحةٍ ⇒ ٢٠٠. ⚠️ الحالةُ الثانيةُ وحدَها تمرُّ خضراءَ فوقَ العطل

**Checkpoint**: `php vendor/bin/pest tests/Feature/Payments` أخضر، **وصفرُ اختبارٍ قائمٍ عُدِّل**.

---

## Phase 3: User Story 1 — الموظّفُ يُنشئُ الاشتراكَ ويعتمدُه (Priority: P1) 🎯 MVP

**Goal**: موظّفٌ يختارُ طالباً وكورساً وباقة، يُرفقُ إيصالاً، فيُنشَأُ طلبٌ معلَّقٌ بلقطةِ سعرِه
ولا يتحرّكُ رصيد؛ ثمّ يُعتمَدُ فتدخلُ الحصصُ رصيدَ الطالبِ عند ذلك المدرّسِ وحدَه.

**Independent Test**: موظّفٌ ⟶ طالبٌ جديد ⟶ كورس ⟶ باقة ⟶ إيصال ⟶ اعتماد ⟶ يُقرَأُ الرصيدُ
فيُوجَدُ العددُ المتوقَّعُ عند ذلك المدرّسِ ولا شيءَ عند غيره.

### الاختباراتُ أوّلاً

- [X] T012 [P] [US1] في `backend/tests/Feature/Payments/StaffCreditGrantTest.php`: منحٌ لطالبٍ **بلا تسجيلٍ ولا عضويّة** ⇒ طلبٌ حالتُه `pending` ولقطةُ `CreditPurchase` مكتوبة، **و`CreditBalance` صفرٌ صفر**. ⚠️ اقرأِ **الصفَّ** لا الاستجابة
- [X] T013 [P] [US1] اختبار: اعتمادُ ذلك الطلبِ ⇒ `remaining_credits` و`purchased_credits` = عددُ الباقة، ودفعةُ رصيدٍ واحدةٌ مفتوحة، **و`CreditBalance` على كورسِ المدرّسِ الآخرِ يبقى صفراً**
- [X] T014 [P] [US1] اختبار FR-005: منحٌ يكونُ فيه الطالبُ المختارُ هو **مدرّسَ** ذلك الكورسِ ⇒ يُرفَض. ⚠️ **هذا هو الاختبارُ الذي يُثبِتُ أنّ التخطّيَ جزئيّ**؛ بدونه يمرُّ تنفيذٌ يتخطّى `isPartyTo` كاملةً
- [X] T015 [P] [US1] اختبار FR-004أ: بعدَ منحٍ معتمَد ⇒ `Enrollment` للطالبِ على ذلك الكورسِ **صفر**
- [X] T016 [P] [US1] اختبار FR-008ب في الاتّجاهَين: منحُ الموظّفِ ⇒ `granted_by` = الموظّفُ و`approved_by` = الموظّف؛ وشراءُ الطالبِ بنفسِه ⇒ `granted_by` **فارغ**. ⚠️ اتّجاهٌ واحدٌ يمرُّ فوقَ تنفيذٍ يختمُ الموظّفَ على كلِّ طلبٍ في المنصّة
- [X] T017 [P] [US1] اختبار الإسنادِ الجماعيّ: مرّرْ `granted_by` في حمولةِ تحديثٍ عاديّةٍ للطلبِ ⇒ **يُتجاهَل**. الحقلُ خارجَ `$fillable` عمداً، والاختبارُ هو ما يمنعُ تنظيفاً لاحقاً من إضافتِه

### التنفيذ

- [ ] T018 [US1] أنشئْ صفحةَ `backend/app/Filament/Pages/GrantCreditSubscription.php`: نموذجٌ بأربعةِ حقول (‏طالب · كورس · باقة · ملفُّ إيصال)، يستدعي `ListCreditPackages` للتسعيرِ و`PurchaseCredits` للإنشاءِ و`UploadPaymentReceipt` للإيصالِ — **في معاملةٍ واحدة**. ⛔ **ولا زرَّ اعتمادٍ على هذه الشاشةِ إطلاقاً** (FR-008أ)
- [ ] T019 [US1] في الصفحةِ نفسِها: اعرضِ **المبلغَ المتوقَّعَ قبلَ الحفظ** من `ListCreditPackages` (FR-006). ⚠️ **لا تحسبْه في الصفحة** — الدستورُ II، ورقمٌ ثانٍ يشيخُ عندَ أوّلِ تغييرِ تسعير
- [ ] T020 [US1] احرسِ الصفحةَ بـ`BILLING_PURCHASE_APPROVE` في `canAccess()` **وفي ظهورِ عنصرِ التنقّلِ معاً** (FR-017). ⚠️ الحارسانِ لا واحد: `Gate::before` يمرّرُ المديرَ الأعلى فوقَ كلِّ سياسة، وقائمةٌ مُصفّاةٌ تُشكِّلُ طلباً واحداً ولا تُشكِّلُ التالي — سابقةُ `CreditPackageResource`
- [ ] T021 [US1] في `StaffCreditGrantTest.php`: اختبارُ لوحةٍ بـ`livewire()` على نمطِ `CreditPackagePanelTest` — حاملُ الإذنِ يفتحُ الصفحةَ ويحفظُ بنجاح، ومالكُ مساحةِ العملِ يُردّ. **الاتّجاهان**

**Checkpoint**: US1 تعملُ من طرفِها إلى طرفِها — منتَجٌ صالحٌ ولو توقّفَ العملُ هنا.

---

## Phase 4: User Story 2 — الموظّفُ يرى ما يعتمدُه (Priority: P2)

**Goal**: قبلَ «اعتماد» يرى الموظّفُ الدافعَ والكورسَ وعددَ الحصصِ والمبلغَ ورابطَ الإيصال،
ويستطيعُ الرفضَ بسببٍ يصلُ الطالب.

**Independent Test**: يُفتَحُ صفُّ طلبٍ بحسابٍ يحملُ الإذنَ ويُتحقَّقُ من الحقولِ ومن أنّ الرفضَ
بسببٍ يُغيّرُ الحالةَ ولا يحرّكُ رصيداً.

- [ ] T022 [P] [US2] في `backend/app/Filament/Resources/OrderResource.php` · `getEloquentQuery()`: وسّعِ التصفيةَ **بالإذنِ لا بحذفِ الشرط** — حاملُ `BILLING_PURCHASE_APPROVE` يرى الصنفَين، وغيرُه يرى `OrderKind::teacherListedValues()` كما اليوم. ⚠️ القطعُ على الاستعلامِ لأنّ قائمةَ Filament **لا تستدعي سياسةَ الصفِّ إطلاقاً**
- [ ] T023 [P] [US2] في `backend/app/Modules/Payments/Http/Resources/OrderResource.php`: أضِفْ `granted_by_name` محجوباً بنفسِ حجبِ `payer_name` (‏`orders.view_all`)، واقرأْه من علاقةِ `grantor`. وأضِفِ `'grantor'` إلى التحميلِ المسبقِ في `OrderController::index()` و`show()`
- [ ] T024 [P] [US2] اختبار: حاملُ `BILLING_PURCHASE_APPROVE` يرى طلبَ الرصيدِ في جدولِ اللوحة، ومن لا يحملُه لا يراه. **الاتّجاهان**
- [ ] T025 [P] [US2] اختبار: رفضُ منحٍ بسببٍ مكتوب ⇒ الحالةُ `rejected`، والسببُ في الحمولة، **و`CreditBalance` صفرٌ**
- [ ] T026 [P] [US2] اختبار: `granted_by_name` يصلُ حاملَ `orders.view_all` و**لا يصلُ الطالبَ** (‏المفتاحُ غائبٌ لا `null`)

**Checkpoint**: US1 و US2 تعملانِ معاً.

---

## Phase 5: User Story 3 — مسارُ الطالبِ لم يتغيّرْ (Priority: P3)

**Goal**: تجربةُ الشراءِ التي يبدأها الطالبُ مطابقةٌ لما كانت.

**Independent Test**: تُعادُ حزمةُ اختباراتِ الشراءِ القائمةُ ويُتحقَّقُ من خضرتِها **بلا تعديلِ
ملفِّ اختبارٍ واحد**.

- [ ] T027 [US3] شغّلْ `cd backend && php vendor/bin/pest tests/Feature/Payments` وقارِنِ النتيجةَ بما سُجِّلَ في T001. ⚠️ **أيُّ توكيدٍ قائمٍ احتاجَ تعديلاً يعني أنّ الوسيطَ لم يكنِ افتراضيّاً حقّاً** — أصلِحِ الوسيطَ لا الاختبار
- [ ] T028 [US3] شغّلْ `cd backend && git diff --stat -- tests/` وتأكّدْ أنّ الملفَّ الوحيدَ المتغيّرَ تحتَ `tests/` هو `StaffCreditGrantTest.php` الجديد (SC-008)

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T029 [P] حدِّثْ `docs/README.md`: صفُّ العمودِ الجديدِ في جدولِ Payments، وسطرٌ يذكرُ أنّ `billing.purchase.approve` صارَ يحرسُ **المنحَ والاعتمادَ معاً** ولم يُضَفْ إذنٌ ثانٍ
- [ ] T030 [P] حدِّثْ `docs/erd.md`: `orders.granted_by` مع سببِ `nullOnDelete` وسببِ بقائِه خارجَ `$fillable`
- [ ] T031 أضِفْ فقرةً إلى `CLAUDE.md` عن العطلِ الذي أُغلِقَ في T009: «سياقٌ محلولٌ لا يطابقُ يبقى رفضاً — وموظّفُ ماليّةٍ يملكُ مساحةَ عملٍ سياقُه محلول»، مع التنبيهِ أنّ تجهيزةً بمساحةٍ واحدةٍ لا تراه. ⚠️ هذه ليست زينةً: الملفُّ نفسُه يسجّلُ أنّ العطلَ الشبيهَ في سلسلةِ التدقيقِ عادَ من بابٍ آخرَ لأنّه لم يُكتَبْ
- [ ] T032 راجعْ أنّ **صفرَ سطرٍ** في التغييرِ كلِّه يستدعي `AdjustCredits`: `cd backend && grep -rn "AdjustCredits" app/Filament app/Modules/Payments/Actions/PurchaseCredits.php` ⇒ لا نتيجة (FR-002)
- [ ] T033 شغّلِ البوّاباتِ **دفعةً واحدة**: `cd backend && php vendor/bin/pest tests/Feature/Payments` ثمّ `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`. ⚠️ **لا حزمتَي pest معاً**، ولا حزمةَ اختباراتٍ كاملة — CI يشغّلُها. و`npx tsc --noEmit` غيرُ لازم: صفرُ ملفٍّ في `frontend/`
- [ ] T034 امشِ [quickstart.md](./quickstart.md) يدويّاً على `/admin` — السيناريوهاتُ ١ و٢ و٣ و٥. ⚠️ **السيناريو ٣ هو الذي لا تراه أيُّ تجهيزةٍ بمساحةٍ واحدة**

---

## Dependencies & Execution Order

```
Phase 1 (T001–T002)
   └─> Phase 2 (T003–T011)   ⚠️ حاجزة: كلُّ قصّةٍ تقفُ عليها
          ├─> Phase 3 · US1 (T012–T021)   🎯 MVP
          ├─> Phase 4 · US2 (T022–T026)   مستقلّةٌ عن US1
          └─> Phase 5 · US3 (T027–T028)   عدمُ انحدار
                 └─> Phase 6 (T029–T034)
```

**داخلَ Phase 2 الترتيبُ ليس تجميليّاً**:

```
T003 (isSeller) ──> T006 (التخطّي الجزئيّ)
T004 (الهجرة)  ──> T005 (النموذج) ──> T006 (ختمُ granted_by)
T009 (ترتيبُ السياسة) ──> T011 (اختبارُه) ──> Phase 3
```

⚠️ **T009 قبلَ T018**: بناءُ الشاشةِ فوقَ سياسةٍ تردُّها يجعلُ الردَّ يُقرَأُ خطأً على أنّه
عطلٌ في الشاشة — ساعةٌ تُنفَقُ في المكانِ الخطأ.

---

## Parallel Execution Examples

**Phase 2** — ملفّانِ مختلفان:

```
T007 (ListCreditPackages)  ‖  T008 (OrderPolicy::uploadReceipt)
```

**Phase 3** — ستُّ حالاتِ اختبارٍ في ملفٍّ واحد، تُكتَبُ متوازيةً وتُشغَّلُ مرّةً واحدة:

```
T012 ‖ T013 ‖ T014 ‖ T015 ‖ T016 ‖ T017
```

**Phase 4** — ثلاثةُ ملفّاتٍ مختلفة:

```
T022 (Filament OrderResource)  ‖  T023 (API OrderResource)  ‖  T024–T026 (اختبارات)
```

⚠️ **ولا يُشغَّلُ pest أثناءَ التوازي**: البوّابةُ مرّةٌ واحدةٌ في النهاية (T033)، لا بعدَ
كلِّ ملفّ — وحزمتانِ معاً تتشاركانِ قرصَ الاختبارِ وتصنعانِ فشلاً كاذباً.

---

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 3.** بعدَ T021 يستطيعُ الموظّفُ منحَ اشتراكٍ لطالبٍ جديدٍ
واعتمادَه، وهي المشكلةُ التي بدأَ منها كلُّ هذا. Phase 4 تجعلُ الاعتمادَ **مبصِراً** بدلَ أن
يكونَ توقيعاً، وPhase 5 تُثبِتُ أنّ أحداً لم يُكسَر.

**والتسليمُ التدريجيُّ ممكنٌ فعلاً هنا**: Phase 2 وحدَها تُغلِقُ عطلَ R6 الكامنَ (‏موظّفٌ له
مساحةُ عملٍ يُردُّ عن كلِّ طلبٍ خارجَها) — قيمةٌ تُشحَنُ قبلَ أن تُبنى الشاشة.

**ما لا يُبنى ولو بدا قريباً**: منحٌ جماعيّ · عددُ حصصٍ حرٌّ خارجَ الباقات · إشعارٌ بنوعٍ
جديد · إلغاءُ منحٍ معتمَد · تعديلُ التسعير. كلُّها في «خارج النطاق» في [spec.md](./spec.md)،
وكلٌّ منها له سببُه المكتوب.

---

## Task Summary

| المرحلة | المهامّ | العدد |
|---|---|---|
| Phase 1 · Setup | T001–T002 | ٢ |
| Phase 2 · Foundational | T003–T011 | ٩ |
| Phase 3 · US1 (P1) | T012–T021 | ١٠ |
| Phase 4 · US2 (P2) | T022–T026 | ٥ |
| Phase 5 · US3 (P3) | T027–T028 | ٢ |
| Phase 6 · Polish | T029–T034 | ٦ |
| **المجموع** | | **٣٤** |

**ملفّاتٌ تُنشَأ**: ٣ (‏هجرة · صفحةُ Filament · ملفُّ اختبار).
**ملفّاتٌ تُعدَّل**: ٦ في `backend/app/` + ٣ وثائق.
**ملفّاتٌ في `frontend/`**: **صفر**.
