# Data Model: محرّك الأرصدة والتحصيل (006)

**التاريخ**: 2026-08-08 · **المرحلة**: Phase 1 · القرارات في [research.md](./research.md)
· **مُراجَع** بخمس مراجعات وكلاء؛ ما صحّحته مُعلَّم بـ⚠️.

كل الجداول تحت `backend/app/Modules/Payments/Database/Migrations/` (M كبيرة) — التبرير في
`research.md › R1`.

---

## ١ — طبقات الملكية

| الجدول | الطبقة | `BelongsToWorkspace`؟ | الحارس |
|---|---|---|---|
| `student_credit_accounts` | **مملوك للمنصة** | **لا** | ملكية الصفّ للطالب + `NFR-001أ` |
| `terms_consents` | **مملوك للمنصة** | **لا** | ملكية الصفّ للموقِّع، وتفويض الوصاية للكتابة |
| `credit_balances` · `credit_transactions` · `credit_lots` · `credit_allocations` · `credit_purchases` | **جسر** | **نعم** | النطاق العام + تجاوزٌ صريح واحد (أدناه) |
| `credit_packages` | ⚠️ **متنازَع** | — | راجع §٦ |
| `exam_mode_windows` | **مملوك لمساحة العمل** | **نعم** | النطاق + حالة في `WorkspaceIsolationTest` |

⚠️ **قرار الجسر كان مسكوتاً عنه، وكلا الجوابين يكسر شيئاً.** بالسمة: قراءة الطالب لحسابه
تُرشَّح إلى مساحة العمل التي صادف أن حملها سياقه، فيرى رصيداً جزئياً و`FR-009ج` تنكسر بصمت.
بدونها: كل استعلام للمدرّس يكتب مرشّحه بيده بلا شبكة، وأول من ينسى يعيد أرصدة الجميع.

**الحسم — السمة، بتجاوزٍ واحد صريح**، وله سابقة منشورة تفعل هذا بالضبط: `Enrollment` جسرٌ
**ويستعمل السمة**، ولذلك يُنادي `EloquentEnrollmentDirectory` على `withoutWorkspaceScope()`
في كل استعلام بتعليق يقول إن التقييد «سيعيد لا شيء ويمنع كل تشغيل بصمت». قراءة الطالب لحسابه
عبر مدرّسيه هي الموقف نفسه حرفاً بحرف.

فالتجاوز الوحيد المسموح: قراءة الطالب لحسابه — `withoutWorkspaceScope()` **مع ترشيح صريح
بـ`student_credit_account_id`** وتعليق يذكر السبب. كل ما عداه بالنطاق.

> **الطبقة المنصّية لا يحرسها نطاق.** `WorkspaceScope` لا يمسّها، فهي مكشوفة تماماً ما لم
> يُكتب الحارس صراحةً — درس `notifications` في 003. و`NFR-001ب` تفرض اختباراً بالاتجاهين.
> ⚠️ و`SC-016` تَعِد بحالة في اختبار العزل لـ«الحساب والمعاملة والحد»، وكنتُ قد خصّصتها
> لـ`exam_mode_windows` وحده: تُضاف حالات لـ`credit_balances` و`credit_transactions`
> و`credit_purchases`.

---

## ٢ — الجداول

### `student_credit_accounts`

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | `HasUuid` |
| `user_id` | FK → `users` · **unique** | `FR-001`: حساب واحد لا يتكرّر بعدد المدرّسين |
| `timestamps` | | |

بلا `workspace_id` وبلا السمة. إضافتهما تنتج شخصاً مكرّراً لكل مدرّس — مرآة العطل الذي
يختبره `PlatformOwnershipTest`.

يُنشأ **كسولاً**؛ `US1/1` («حساب برصيد صفر») يُستوفى بأن القراءة تعيد صفراً. والإنشاء
المتزامن يُمتَصّ بالتقاط `UniqueConstraintViolationException` ثم إعادة القراءة — صراحةً، على
شكل `BookSeat.php:76-83`، لا اتّكالاً على سلوك نسخةٍ من الإطار.

### `credit_balances`

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | |
| `student_credit_account_id` | FK | |
| `student_user_id` | FK | ⚠️ **مُنزَّل** — بدونه تقفز لوحة المدرّس عبر ثلاثة جداول (`R18`) |
| `course_id` | FK | **السياق** (`Q-7`) |
| `workspace_id` | FK | مُنزَّل من الكورس. ⚠️ **يُسنَد صراحةً**، لا بالملء التلقائي: المستمع المطبور بلا سياق، فالتلقائي يكتب فارغاً |
| `purchased_credits` · `consumed_credits` · `remaining_credits` | `integer` **مُوقَّع** | `unsigned` يعمل على SQLite وينفجر على أول رصيد سالب في MySQL وحدها |
| `credit_limit_credits` | ⚠️ `integer` **مُوقَّع** (فحص `>= 0` في الـAction) | كان `unsigned`، وهو مصدر فخّ الحساب في `R6` |
| `negative_since` | timestamp nullable | ⚠️ يُكتب عند النزول تحت الصفر ويُمحى عند العودة — بدونه يشتقّ كنسُ الحدّ «كم يوماً سالباً» بمسح سجلٍّ لكل رصيد |
| `last_transaction_at` | timestamp nullable | ⚠️ لكنس الخمول (`Q-8`) — بدونه `MAX()` لكل رصيد |
| `notified_tier` | `unsignedTinyInteger` default 0 | رتبة آخر تنبيه (`R12`) |
| `timestamps` | | |
| | **unique** `(student_credit_account_id, course_id)` | |
| | index `(workspace_id, course_id, student_user_id)` | ⚠️ لوحة المدرّس. الفهرس السابق `(workspace_id, remaining_credits)` **حُذف**: لا اللوحة تستعمله ولا «مسح العتبات» الذي بُرِّر به موجود |
| | index `(workspace_id, negative_since)` | كنس الحدّ الائتماني |

`remaining_credits` رقم **مادّي** (`NFR-012`)، و`FR-004` التزامٌ يُختبَر (`SC-001`).

**والثابتة بين العدّادات تُعلَن**: `remaining = purchased − consumed`. الاسترداد يزيد
`remaining` و**يُنقص `purchased`**؛ ⚠️ ولا يمسّ `consumed` أبداً — وإلا انحرف العمودان اللذان
تعرضهما لوحة المدرّس بلا أن تلحظه `SC-001`، لأنها تفحص `remaining` وحده.

### `credit_transactions` — السجلّ المضاف

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` | | ⚠️ يُملأ **صراحةً** في مصفوفة الإدراج — `insertOrIgnore` لا تُطلق `creating` فلا تعمل `HasUuid` |
| `credit_balance_id` | FK | |
| `type` | `string(16)` | `purchase` `consume` `bonus` `refund` `adjustment` `expire` |
| `credits` | `integer` **موقَّع** | موجب يزيد، سالب ينقص. **لا** تُشتقّ من النوع |
| `source_type` | `string(32)` | ⚠️ بطول صريح: يقع في فهرس فريد رباعي |
| `source_id` | `unsignedBigInteger` nullable | |
| `performed_by` | FK → `users` nullable | فارغ = النظام |
| `reason` | string nullable | **إلزامي** للـ`adjustment` — يُفرَض في الـAction |
| `meta` | json nullable | |
| `created_at` | | ⚠️ يُملأ **صراحةً**. بلا `updated_at`: عمودٌ لصفٍّ لا يُعدَّل دعوةٌ لتعديله |
| | **unique** `(credit_balance_id, type, source_type, source_id)` | `FR-007` |
| | index `(credit_balance_id, created_at)` · index `(credit_balance_id, type, source_type, source_id)` | القراءة والتحقّق من التكرار |

**مضاف لا يُعدَّل**: `booted()` ترمي على `updating`/`deleting` — نسخة `LedgerEntry`.

⚠️ **والمفتاح لا يمنع تكرار القيد اليدوي**: `source_id` فارغة، والقيم الفارغة متمايزة في
الفهرس الفريد على MySQL وSQLite معاً. فالـAction تسكّ `source_id` من مفتاح تعامُد يرسله
العميل — وإلا منح الضغط المزدوج مكافأتين. **والاسترداد يُفتَح بهويّته هو**
(`source_type='credit_refund'`)، لا بهوية الشراء، وإلا مُنع الاسترداد الجزئي الثاني وأُبلغ
نجاحاً.

### `credit_lots` — ⚠️ **جديد**: الدفعة، وحدها قابلة للتعديل

| العمود | النوع |
|---|---|
| `id` | |
| `credit_transaction_id` | FK · **unique** — قيد `purchase` أو `bonus` |
| `credit_balance_id` | FK |
| `credits_total` · `credits_remaining` | `integer` |
| `expires_at` | timestamp nullable |
| `timestamps` | |
| | index `(credit_balance_id, expires_at, id)` |

**لماذا جدول قابل للتعديل بجوار سجلٍّ مضاف**: اختيار الدفعة كان يشتقّ «كم بقي» بـ
`SUM(credit_allocations)` — قراءةٌ ثم كتابة، في الموضع الوحيد الذي لم تُطبَّق فيه قاعدة
المقعد. مستهلكان متزامنان يقرآن «بقي ١» فيُدرجان تخصيصين، **و`SC-001` تمرّ** لأنها لا تنظر
إلى التخصيصات. والدفعة **حزمةٌ من ٨ أو ١٦**، فالعطل حيٌّ من اليوم الأول حتى مع إطفاء الانتهاء.

السحب بـUPDATE شرطي لكل دفعة (§٥). والعدّاد لا يمكن أن يعيش على `credit_transactions` لأنها
ترمي على التعديل — ومن هنا الجدول.

### `credit_allocations` — سجلّ ما ادّعاه السحب

| العمود | النوع |
|---|---|
| `id` · `consumed_transaction_id` FK · `lot_transaction_id` FK · `credits` `integer` · `created_at` | |
| | **unique** `(consumed_transaction_id, lot_transaction_id)` — ⚠️ بدونه تُكرّر إعادةُ محاولةٍ صفوفَ التخصيص |
| | index `(lot_transaction_id)` · index `(consumed_transaction_id)` |

مضاف. يجيب «أي دفعةٍ دفعت أي استهلاك» — سؤالٌ **لا يمكن اشتقاقه لاحقاً**، لأن ترتيب «الأقرب
انتهاءً أولاً» يعيد كتابة الجواب بأثر رجعي كلما دخلت دفعةٌ أقرب انتهاءً.

### `credit_packages` — القالب، لا السعر

`id` · `uuid` · `name` · `credits` (`unsignedSmallInteger`) · `session_type` · `validity_days`
(nullable) · `is_active` · `sort_order` · `timestamps`.

**لا عمود سعر**: السعر يُحتسب **لكل كورس** لأن مُدخَله سعر مدرّس ذلك الكورس. عمود سعر هنا
يعني سعراً واحداً لكل المدرّسين.

⚠️ **الملكية متنازَع عليها** — راجع §٦. و`FR-016` تذكر «نطاق سريانها» ولا عمود له؛ يُضاف أو
يُقيَّد نصّ `FR-016` بـ«النطاق = نوع الحصة في هذه المرحلة».

### `credit_purchases` — اللقطة

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` · `credit_balance_id` · `credit_package_id` · `course_id` · `workspace_id` | | |
| `order_id` | FK → `orders` | مسار الإيصال القائم |
| `credits` | `unsignedSmallInteger` | منسوخ لا مقروء |
| `teacher_rate_minor` · `operating_fee_minor` · `gateway_fee_minor` · `total_minor` | ⚠️ `bigInteger` | كان `unsignedInteger`؛ وهجرة 014 تقول لماذا: «٢٫١ مليار وحدة صغرى ليست إلا ٢١ مليون ريالاً، وهو سقف تبلغه منصة» |
| `currency` `char(3)` · `purchased_at` | | |
| | index `(workspace_id, purchased_at)` · index `(credit_balance_id)` | ⚠️ كان بلا فهرس بينما له ثلاثة قرّاء: 015، وشاشة اعتماد السعر في 014، وتاريخ الطالب |

يُكتب **مرة**، ولا يُعاد حسابه عند أي اعتماد سعر لاحق (`FR-020` · `FR-021ز` · `SC-015ج`).

### `terms_consents`

`id` · `uuid` · `user_id` (الموقِّع) · `student_user_id` (عمّن) · `document` · `version` ·
`ip_address` · `user_agent` · `consented_at`.
فهارس: `(student_user_id, document, version)` و⚠️`(user_id, consented_at)`.

**مملوك للمنصة** بلا `workspace_id`: الموافقة للمنصة — هي البائع والمطالِبة (`Q-4`).
⚠️ **والكتابة تحتاج إثبات تفويض**: `GuardianDirectory::isAuthorised($signer, $student,
GuardianPermission::Payments)` أو أن يكون الموقِّع الطالبَ نفسه — وإلا وقّع أي مستخدم وثيقةً
قانونية باسم غيره، وأكّدت الاستجابة أن المعرّف لشخص حقيقي.
⚠️ **ومصدر `ip_address`**: `bootstrap/app.php` لا يهيّئ `TrustProxies`، فـ`$request->ip()`
يعيد عنوان موازِن الحمل في الإنتاج — أي **العنوان نفسه للجميع** في السجلّ الذي وُجد للاحتجاج.
يُهيَّأ قبل الاعتماد عليه، وتُكتب مدّة الحفظ واستثناؤها من محو 013 كسجلٍّ لالتزام قانوني.

### `exam_mode_windows`

`id` · `uuid` · `workspace_id` · `starts_on` `date` · `ends_on` `date` · `created_by` ·
`timestamps` · index `(workspace_id, starts_on, ends_on)`.

`BelongsToWorkspace`. والقراءة تقارن بنصّ تاريخ لا بـ`whereDate()` — الدالة حول العمود تُلغي
الفهرس، والحدّ الأعلى لعمود timestamp مقابل تاريخ هو **بداية اليوم التالي**.

---

## ٣ — تعديلات على كود منشور

| الجدول / الملف | التغيير | لماذا |
|---|---|---|
| `lessons` | `+ is_high_value` | `FR-041` |
| `courses` | `+ subject_id` · `+ grade_level` | مُدخَلا `RateResolver` |
| `courses` | ⚠️ `+ teacher_profile_id` | بدونه `approvedRateMinorForCourse` **غير قابلة للتنفيذ**: `RateResolver` يبدأ من `teacher_profile_id`، و`courses` تحمل `created_by` القابل للإفراغ فقط |
| `courses.price` · `currency` | ⚠️ **قرار مطلوب** | موجودان ومنشوران (`PublicCourseCardResource:36-40`). سعرٌ يملكه المدرّس بجوار سعرٍ تحسبه المنصة سعران لشيء واحد، و`FR-021ب` تمنع الأول |
| `orders` | ⚠️ `+ kind` `string(16)` default `course` | بدونه يستقبل `CreateEnrollmentFromOrder` طلبَ الأرصدة ويُسجّل الطالب مجاناً (يفحص `course_id === null` وحده) |
| `class_sessions.course_id` | → **إلزامي** | حصةٌ بلا كورس حصةٌ بلا سعر |
| `class_sessions.subject_id` · `grade_level` | يُملآن **من الكورس** عند الجدولة | وإلا حلّ الطرفان بمُدخَلات مختلفة |
| `class_sessions` | ⚠️ `+ charged_at` | «سُلِّمت ولم تُشحَن» يجب أن تكون مجموعةً **قابلة للاستعلام** (`R17`) |
| `AccrueTeachingUnits:48-53` | ⚠️ يمرّر `grade_level` | يمرّر أربعة وسائط اليوم، فكل سعر مخصَّص بصفّ **غير مرئي عند التسوية** — والتساوي المُدَّعى لا يقوم بدون هذا |
| `RolePermissionMatrix` | ⚠️ اعتماد طلب `credits` يخرج من `$teacher` | ثغرة سكّ نقود (`R16`) |
| `Marketplace` + الواجهة | إخراج `hourly_rate` — **١٩ موضعاً** | `research.md › R14` |

> ⚠️ **هجرة `course_id` ثلاث لا اثنتان.** نسختي الأولى قالت «ما لا يُملأ يُترك ويُسجَّل عدده»
> ثم اشترطت `quickstart` «صفر صفّ فارغ» — والاثنان لا يجتمعان: هجرة القيد **تفشل** على أول
> صفّ ناجٍ، بعد أن التزمت هجرة التعبئة. الترتيب:
> 1. **تعبئة** بـ`chunkById` لا `chunk` — المُسنَد `course_id IS NULL` يتقلّص تحت ترقيمٍ
>    بـOFFSET فتُتخطّى صفوف ويُبلَّغ نجاح (درس backfill الـuuid في 016). ولا `UPDATE … JOIN`:
>    MySQL وSQLite تختلفان في صياغته.
> 2. **حسم البقية صراحةً** — الأرجح: تبقى `course_id` قابلة للإفراغ **إلى الأبد** ويُفرَض
>    الوجود في `ScheduleClassSession` و`StoreClassSessionRequest`. أرخص وأصدق من اختراع
>    كورسات لحصص تاريخية.
> 3. إن اختير القيد: **تأكيد قبل التغيير** يرمي برسالة تحمل العدد، لا `errno` من MySQL.
>    و`->nullable(false)->change()` **يعيد تصريح العمود**، فكل مُعدِّل لا يُكرَّر (`unsigned`)
>    يسقط بصمت.

---

## ٤ — المفاتيح المضبوطة

**منصّية (`platform_settings`، خاصة)**:

```
billing.operating_fee_minor.individual · .group
billing.gateway_fee_bps                    ⚠️ نقاط أساس صحيحة، لا نسبة مئوية صحيحة:
                                              لا بوابة تتقاضى ٪ صحيحاً، والعشري يعيد المال عائماً
billing.gateway_fixed_fee_minor            ⚠️ مكوّن ثابت — كل بوابة حقيقية تحمله
billing.currency
billing.limit.initial_credits · .increase_after_on_time · .increase_by_credits
billing.limit.max_credits · .decrease_after_late_days
billing.dormant_notice_months
```

**لكل مساحة عمل (`workspaces.settings.billing`)**: `mode` · `zero_behavior` · `thresholds`.

---

## ٥ — القواعد التنفيذية

### أ — المُسنَد الواحد

⚠️ نسختي الأولى حملت مُسنَدين متعارضين. **واحد، يُعرَّف مرة**:

```
floor(balance) = mode == prepaid || insideExamWindow  ?  0
                                                      :  −credit_limit_credits
canAfford(balance, n) ⟺ remaining_credits − n ≥ floor(balance)
blocked(balance)      ⟺ ¬canAfford(balance, 1)
```

عند `remaining = 0, limit = 0` ⇒ **محجوب**، وهو ما تشترطه `quickstart §9`. الصيغة القديمة
كانت تقول «غير محجوب» في الحالة الافتراضية بالذات.

### ب — الترتيب داخل المعاملة، **معاملةٌ لكل مقعد**

1. `insertOrIgnore` القيد، بـ`uuid` و`created_at` صراحةً.
2. **صفر صفوف ⇒ `SELECT` بالمفتاح.** وُجد ⇒ تكرار، لا عمل. **لم يوجد ⇒ يُرمى**: الصفر كان
   خطأً (انتهاك `NOT NULL`، مفتاح أجنبي، مدىً) لا مُعادَلة — `INSERT IGNORE` تخفض صنف
   الأخطاء كله إلى «صفر صفوف».
3. سحب الدفعات، ثم الخصم.

**الترتيب هو القاعدة**: الخصم أولاً يعني أن إعادة تسليمٍ للحدث تخصم مرتين ويُتجاهَل الإدراج
مرة ⇒ الرصيد لا يساوي مجموع قيوده، **نهائياً وبصمت**.

**ومعاملةٌ لكل مقعد**: معاملة واحدة لثلاثين مقعداً تجعل رفض طالبٍ واحد يُسقط التسعة والعشرين،
وتحمل ثلاثين قفلاً عبر المستمع كلّه. وأي عبارة تمسّ أرصدةً متعدّدة **ترتّب بـ`credit_balances.id`**
— حصّتان متزامنتان تتقاسمان طالبين تُقفلان بترتيبين متعاكسين فتتجمّدان.

### ج — سحب الدفعة

```sql
UPDATE credit_lots SET credits_remaining = credits_remaining - :take
 WHERE id = :lot AND credits_remaining >= :take
```

بترتيب `(expires_at IS NULL), expires_at, id`؛ صفر صفوف ⇒ استُنفدت، إلى التالية. `MySQL` لا
ترتّب بتعبير من فهرس، لكن المرشَّحات محصورة برصيد واحد فالفرز محدود — ويُعلَن سقفٌ لعدد
الدفعات في السحب الواحد لأن الحلقة استعلامٌ لكل دفعة، مقابل ميزانية `NFR-012`.

### د — الخصم الذرّي

```sql
UPDATE credit_balances
   SET remaining_credits = remaining_credits - :n,
       consumed_credits  = consumed_credits  + :n
 WHERE id = :id
   AND remaining_credits - :n >= GREATEST(:floorCap, -1 * CAST(credit_limit_credits AS SIGNED))
```

⚠️ **العمود في `WHERE` لا قيمةً مربوطة** — وإلا فتغييرٌ متزامن للحدّ لا يراه الخصم، وهو
القراءة-ثم-الكتابة نفسها التي بُنيت العبارة لإلغائها.
⚠️ **و`CAST(... AS SIGNED)` إلزامي، والترتيب الجبري ممنوع**: في MySQL يكفي معاملٌ بلا إشارة
ليصير الناتج بلا إشارة، فتصير `remaining + limit >= :n` خطأً `1690` عند `remaining = −3` —
٥٠٠ على كل حالة وُجد الحارس ليرفضها، ولفئة المتأخّرين وحدهم، وSQLite لا يُظهر شيئاً.

**يُمنع `lockForUpdate()`**: لا أثر له على SQLite، فالاختبار المبنيّ عليه ينجح محلياً ولا
يقول شيئاً عن الإنتاج.

### هـ — الأرضية تحرس **الحجز**، لا تسجيل دَينٍ وقع

⚠️ خصم **التسليم** يكتب القيد ويُنزل الرصيد **بلا أرضية**: الحصة وقعت، و014 استحقّت أجرها من
الحدث نفسه. رفضُ التسجيل يعني أن المنصة مدينة للمدرّس بلا مطالبة على أحد — ووضع الامتحانات
يجعلها منهجية لأنه يُجبر الأرضية إلى صفر. المنع مكانه **الحجز**، و`SC-004` تُعاد صياغتها
قاعدةً على الحجز — وهو ما تقوله `US6/4` و`US8/1` أصلاً.

### و — الرتبة والحجب

الرتبة تُحسب من `remaining_credits` وتُقارن بـ`notified_tier` في نفس المعاملة؛ التنبيه على
الانتقال هبوطاً وحده.

**والحجب مشتقّ** — لا جدول: `FR-033` («يُرفع فوراً بلا تدخّل يدوي») تصير صحيحة بالبناء بدل
أن تكون مهمّةً تُنسى. ⚠️ **لكنه ينقلب في ثلاثة مواضع لا تكتب قيداً**: تغيير الحدّ، وفتح نافذة
امتحانات، وإغلاقها. الثلاثة مواضع إطلاق لـ`AccessWithheld`/`AccessRestored`، وإلا فالتنبيه
لا يصل إلا مع الحركة المالية التالية.

### ز — التسعير، في موضع واحد

```
سعر الحصة = approvedRateMinorForCourse(الكورس, النوع, الآن)
          + billing.operating_fee_minor[النوع]
          + gateway(المجموع)
```

⚠️ **واتجاه رسوم البوابة يُكتب صراحةً**: إن كانت البوابة تقتطع نسبةً من **المبلغ المحصَّل**
فالصيغة الصحيحة `المجموع ÷ (1 − نسبة)` لا `المجموع + نسبة×المجموع` — والثانية تُقصّر عن
التغطية في كل عملية، والفرق يقع على هامش المنصة بصمت.

`null` من العقد ⇒ **الحزم لا تُعرَض**، لا سعر افتراضي.

### ح — ما لا جدول له، عمداً

| الكيان | القرار |
|---|---|
| `AccessHold` | مشتقّ (§٥و) |
| تاريخ `CreditLimit` | `activity_log` — يخزّن القيمة السابقة والجديدة والسبب، وهو نصّ `FR-039` |
| `PricingPolicy` | `platform_settings` |
| `BillingMode Setting` | `workspaces.settings` |

---

## ٦ — ⚠️ تعارض دستوري مفتوح: ملكية `credit_packages`

الدستور v1.1.0 سطر ٥٢ يعدّد «**حزم الأرصدة**» ضمن ما تملكه **مساحة العمل**. وسبيك 006 يقول
عكسه نصّاً — `FR-016`: «**المنصة** (لا المدرّس) **يجب** أن تعرّف حزم الأرصدة»، وهو مشتقّ من
`Q-1` (التسعير `cost-plus` تملكه المنصة، والمدرّس لا يعرّف سعراً).

هذا **تعارض** لا خيار تصميم، وقسم الحوكمة يفرض تعديلاً موصوفاً بموافقة ورفع إصدار في نفس
الـPR. يُحسَم قبل التنفيذ بأحد أمرين، ويُسجَّل في `Complexity Tracking` — لا يُمرَّر بادّعاء
«لا مخالفة»، وهو ما كتبتُه أولاً.
