# Data Model: محرّك الأرصدة والتحصيل (006)

**التاريخ**: 2026-08-08 · **المرحلة**: Phase 1 من [plan.md](./plan.md) · القرارات في [research.md](./research.md)

كل الجداول تحت `backend/app/Modules/Payments/Database/Migrations/` (M كبيرة) — التبرير في
`research.md › R1`.

---

## ١ — طبقات الملكية

الدستور v1.1.0 يصنّف كل نموذج في واحدة من ثلاث. التصنيف هنا **صريح**، لأن الخطأ فيه لا يظهر
في أي اختبار لم يُكتب له:

| الجدول | الطبقة | الحارس |
|---|---|---|
| `student_credit_accounts` | **مملوك للمنصة** | ملكية الصفّ للطالب + `NFR-001أ` (رؤية المدرّس بالتسجيل) |
| `terms_consents` | **مملوك للمنصة** | ملكية الصفّ للموقِّع |
| `credit_balances` | **جسر** | `workspace_id` للسياق + إشارة إلى الحساب المنصّي |
| `credit_transactions` | **جسر** | `workspace_id` موروث من رصيده |
| `credit_allocations` | **جسر** | تابع لقيوده |
| `credit_purchases` | **جسر** | `workspace_id` للسياق |
| `credit_packages` | **مملوك للمنصة** | `FR-016`: المنصة تعرّفها، لا المدرّس |
| `exam_mode_windows` | **مملوك لمساحة العمل** | `BelongsToWorkspace` + حالة في `WorkspaceIsolationTest` |

> **الطبقة المنصّية لا يحرسها نطاق عام.** `WorkspaceScope` لا يمسّها أصلاً، فهي مكشوفة تماماً
> ما لم يُكتب الحارس صراحةً — نفس درس `notifications` و`parent_student_relations` في 003.
> `NFR-001ب` تفرض اختباراً مخصّصاً بالاتجاهين: مدرّس لا يرى طالباً غير مسجَّل عنده، وطالب
> يرى حسابه الواحد عبر كل مدرّسيه.

---

## ٢ — الجداول

### `student_credit_accounts` — الحساب الواحد

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | — | `HasUuid`؛ المسارات تكشف الـuuid |
| `user_id` | FK → `users` · **unique** | `FR-001`: حساب واحد لا يتكرّر بعدد المدرّسين |
| `timestamps` | | |

**لا `workspace_id`، ولا `BelongsToWorkspace`.** إضافتهما تنتج شخصاً مكرّراً لكل مدرّس —
مرآة العطل الذي يختبره `PlatformOwnershipTest` في 003.

يُنشأ **كسولاً** عند أول حاجة (`firstOrCreate` داخل الـAction)، لا بمستمع على تسجيل المستخدم:
حساب لكل حساب مسجَّل ولو لم يشترِ شيئاً هو صفوف بلا معنى، و`US1/1` («يُنشأ له حساب برصيد
صفر») يُستوفى بأن القراءة تعيد صفراً — لا بأن يوجد الصفّ.

### `credit_balances` — الرصيد في سياقه

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | |
| `student_credit_account_id` | FK | |
| `workspace_id` | FK | السياق (`research.md › R5`) |
| `purchased_credits` · `consumed_credits` · `remaining_credits` | `integer` **موقَّع** | `NFR-011`: `unsigned` هنا يعمل على SQLite وينفجر على أول رصيد سالب في MySQL وحدها |
| `credit_limit_credits` | `unsignedInteger` default 0 | أقصى **سالب** مسموح؛ صفر = لا تأجيل |
| `notified_tier` | `unsignedTinyInteger` default 0 | رتبة آخر تنبيه أُطلق (`research.md › R12`) |
| `timestamps` | | |
| | **unique** `(student_credit_account_id, workspace_id)` | |
| | index `(workspace_id, remaining_credits)` | لوحة المدرّس ومسح العتبات |

`remaining_credits` **رقم مادّي**، لا `SUM()`. `NFR-012` تفرضه صراحةً، و`FR-004` تصير التزاماً
يُختبَر (`SC-001`) بدل أن تكون بنيةً.

### `credit_transactions` — السجلّ المضاف

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | |
| `credit_balance_id` | FK | ومنه `workspace_id` و`account_id` |
| `type` | enum: `purchase` `consume` `bonus` `refund` `adjustment` `expire` | `FR-003` — قائمة مغلقة |
| `credits` | `integer` **موقَّع** | موجب يزيد، سالب ينقص. القيمة **لا** تُشتقّ من النوع: `adjustment` يذهب في الاتجاهين |
| `source_type` · `source_id` | string · `unsignedBigInteger` nullable | `FR-006`: مصدره (`class_session` · `credit_purchase` · `manual`) |
| `course_id` | FK nullable | تقريري فقط — **لا يدخل أي قرار** (`R5`) |
| `expires_at` | timestamp nullable | على قيود الإضافة وحدها؛ `null` = لا تنتهي (`Q-5`) |
| `performed_by` | FK → `users` nullable | `FR-006`؛ فارغ = النظام |
| `reason` | string nullable | **إلزامي للـ`adjustment`** (`FR-005`) — يُفرَض في الـAction |
| `meta` | json nullable | |
| `created_at` | | **بلا `updated_at`**: عمودٌ لصفٍّ لا يُعدَّل كذبة صغيرة تدعو للتعديل |
| | **unique** `(credit_balance_id, type, source_type, source_id)` | `FR-007` — عدم التكرار بالقاعدة (`R7`) |
| | index `(credit_balance_id, created_at)` | |

**مضاف لا يُعدَّل**: `booted()` ترمي على `updating` و`deleting` — نسخة `LedgerEntry` (`R8`).

### `credit_allocations` — أي قيدِ إضافةٍ دفع أي استهلاك

| العمود | النوع |
|---|---|
| `id` | |
| `consumed_transaction_id` | FK → `credit_transactions` |
| `lot_transaction_id` | FK → `credit_transactions` (قيد `purchase` أو `bonus`) |
| `credits` | `unsignedInteger` |
| `created_at` | |
| | index `(lot_transaction_id)` |

**لماذا جدول لا عمود**: انتهاء الصلاحية يحتاج «كم بقي من هذه الدفعة». واشتقاقه بافتراض
FIFO **خاطئ**: ترتيب الاستهلاك «الأقرب انتهاءً أولاً» (`FR-009`)، فحزمةٌ اشتُريت متأخرة
بانتهاء أقرب تتخطّى الطابور — ويتغيّر معها **بأثر رجعي** أي دفعةٍ دفعت أي استهلاك ماضٍ.
والجدول مضاف مثل السجلّ، فلا نقطة تعديل جديدة.

الانتهاء مطفأ في الإطلاق (كل `expires_at` فارغة)، فالتخصيص حينها واحدٌ لواحد. بُني الآن لأن
بناءه لاحقاً هجرة على استهلاك ماضٍ لا يمكن إعادة اشتقاقه (`R10`).

### `credit_packages` — القالب، لا السعر

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | |
| `name` | string | |
| `credits` | `unsignedSmallInteger` | ١ · ٨ · ١٦ … **قيمة لا ثابت** (`FR-017`) |
| `session_type` | enum من `ClassSessionType` | نوع الحصة التي تُستهلَك بها |
| `validity_days` | `unsignedSmallInteger` nullable | `null` = لا تنتهي (`Q-5`) |
| `is_active` | boolean | التعطيل لا يمسّ ما اشتُري منها (`FR-019`) |
| `sort_order` | `unsignedSmallInteger` | |
| `timestamps` | | |

**لا عمود سعر.** السعر `cost-plus` يُحتسب **لكل مدرّس** لحظة العرض، لأن مُدخَله سعرُ ذلك
المدرّس المعتمَد (`FR-021`). عمود سعر هنا يعني سعراً واحداً لكل المدرّسين — نقضٌ للمعادلة.

### `credit_purchases` — اللقطة

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | |
| `credit_balance_id` · `credit_package_id` · `workspace_id` | FK | |
| `order_id` | FK → `orders` nullable | مسار الإيصال اليدوي القائم؛ البوابة في 007 |
| `credits` | `unsignedSmallInteger` | منسوخ لا مقروء — الحزمة قد تتغيّر |
| `teacher_rate_minor` · `operating_fee_minor` · `gateway_fee_minor` · `total_minor` | `unsignedInteger` | `FR-021ح` — **المكوّنات الأربعة**، لا الإجمالي وحده |
| `currency` | char(3) | |
| `purchased_at` | timestamp | |

هذا الصفّ هو ما تُولَّد منه دفاتر 015 (`Q-2`). يُكتب **مرة**، ولا يُعاد حسابه عند أي اعتماد
سعر لاحق (`FR-020` · `FR-021ز` · `SC-015ج`).

### `terms_consents` — الموافقة الموثّقة

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | |
| `user_id` | FK | الموقِّع: وليّ الأمر أو الطالب البالغ |
| `student_user_id` | FK | عمّن وُقِّعت |
| `document` | string | `deferred_payment` — الأداة صالحة لغيره |
| `version` | string | `FR-049`: نسخة جديدة ⇐ قبول جديد (`US9/3`) |
| `ip_address` · `user_agent` | | |
| `consented_at` | timestamp | |
| | index `(student_user_id, document, version)` | |

**مملوك للمنصة**، بلا `workspace_id`: الموافقة على شروط الدفع موافقةٌ للمنصة — هي البائع
والمطالِبة (`Q-4`) — لا لمدرّس. و`FR-050` تمنع خلطها بموافقة معالجة البيانات (013): جدول
مستقلّ وقيمة `document` مستقلّة.

### `exam_mode_windows` — وضع الامتحانات

| العمود | النوع |
|---|---|
| `id` · `uuid` · `workspace_id` | |
| `starts_on` · `ends_on` | `date` |
| `created_by` | FK |
| `timestamps` | |
| | index `(workspace_id, starts_on, ends_on)` |

`BelongsToWorkspace`. والقراءة تُقارن بنصّ تاريخ لا بـ`whereDate()` — الدرس المدفوع في
`FreezePeriod::covering()` (005): الدالة حول العمود تُلغي الفهرس، والحدّ الأعلى لعمود
timestamp مقابل تاريخ هو **بداية اليوم التالي**.

### تعديلان على جدولين قائمين

| الجدول | العمود | لماذا |
|---|---|---|
| `lessons` (Courses) | `is_high_value` boolean default false | `FR-041`: المدرّس يصنّف محتواه. القرار المالي وحده في `Payments` |
| `workspaces` (Tenancy) | — | لا هجرة: `settings` (json) موجود منذ أول هجرة وفارغ، وهذا أول مستهلك له (`R9`) |

---

## ٣ — ما **لا** يوجد له جدول، عمداً

| الكيان في السبيك | القرار | لماذا |
|---|---|---|
| **`AccessHold`** | **مشتقّ** — دالة على الرصيد والحد والنمط | `FR-033` تفرض رفع الحجب «فوراً بلا تدخّل يدوي». الصفّ المخزَّن هو ما يجعل ذلك مهمةً تُنسى؛ المشتقّ يجعله صحيحاً بالبناء. نفس منطق `is_publicly_listed` و«درجة الثقة» في `CLAUDE.md` |
| **`CreditLimit` كتاريخ** | `activity_log` | `FR-039` تطلب القيمة السابقة والجديدة والسبب — وهو بالضبط ما يخزّنه `spatie/activitylog` المستعمل في 014. جدولٌ لذلك ثالث نسخة من نفس الفكرة |
| **`PricingPolicy`** | `platform_settings` بمفاتيح `billing.*` | رسم تشغيل لكل نوع حصة ونسبة بوابة — قيم يضبطها مشغّل، لا صفوف يملكها أحد (`R9`) |
| **`BillingMode Setting`** | `workspaces.settings` | لكل مساحة عمل، و`PlatformSettings` منصّي بحكم بنيته |
| **`CreditBalance` كمجموع** | عمود مادّي | `NFR-012` |

---

## ٤ — المفاتيح المضبوطة

**منصّية (`platform_settings`، خاصة — `FR-021ب`)**:

```
billing.operating_fee_minor.individual     رسم التشغيل الثابت للحصة الفردية
billing.operating_fee_minor.group          ورسم الجماعية — واحدةٌ لكل الحضور لا لكلٍّ منهم (Q-1)
billing.gateway_fee_percent                النسبي الوحيد فعلاً
billing.currency                           موحّدة في الإطلاق
billing.limit.increase_after_on_time       ← اقتراح تشغيلي، لا قرار منتج (R15)
billing.limit.increase_by_credits          ←
billing.limit.decrease_after_late_days     ←
```

**لكل مساحة عمل (`workspaces.settings.billing`)**:

```
mode                 prepaid_credits | manual_collection | payment_gateway | hybrid
zero_behavior        block | remind | both
thresholds           [2, 0]   ← الرتبة ١ تنبّه الطالب، الرتبة ٢ تنبّه وليّ الأمر
```

---

## ٥ — انتقالات وقواعد

**دورة الشراء** (`FR-018`): `credit_purchases` يُنشأ عند رفع الإيصال بحالة الطلب القائمة،
و**قيد `purchase` لا يُكتب إلا على `PaymentApproved`**. لا رصيد قبل الاعتماد — والشرط مكانه
المستمع، لا التحقّق.

**الخصم الذرّي** (`FR-008` · `NFR-008` · `SC-003` · `SC-004`):

```sql
UPDATE credit_balances
   SET remaining_credits = remaining_credits - :n,
       consumed_credits  = consumed_credits  + :n
 WHERE id = :id
   AND remaining_credits - :n >= :floor
```

`:floor` = `0` في `prepaid_credits` وفي نافذة وضع الامتحانات، وإلا `-credit_limit_credits`.
صفر صفوف متأثّرة = رُفض، بلا استثناء ولا قراءة سابقة. **يُمنع `lockForUpdate()`** — لا أثر
له على SQLite، فاختبارٌ مبني عليه ينجح محلياً ولا يقول شيئاً عن الإنتاج.

**الرتبة والتنبيه** (`FR-034`): تُحسب الرتبة الجديدة من `remaining_credits` والعتبات، وتُقارن
بـ`notified_tier` في نفس المعاملة. التنبيه على **الانتقال هبوطاً** وحده؛ الصعود يصفّر الرتبة.

**الحجب المشتقّ** (`FR-031` · `FR-042` · `FR-044`):

```
محجوب  ⟺  remaining_credits < 0  ∧  |remaining_credits| ≥ credit_limit_credits
```

يُقرأ عند كل قرار: الحجز، وإصدار منحة وسائط لأصل `is_high_value`. لا وظيفة ترفع ولا وظيفة
تخفض.
