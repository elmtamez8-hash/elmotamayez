# API Contract: محرّك الأرصدة والتحصيل (006)

**التاريخ**: 2026-08-08 · **مُراجَع** بخمس مراجعات وكلاء؛ ما صحّحته مُعلَّم بـ⚠️.
كل المسارات تحت `/api/v1` بمصادقة Sanctum، والمُعرَّفات **uuid**.

---

## ١ — الصلاحيات الجديدة

ثوابت في `Tenancy\Support\Permissions` (`NFR-004`) — **يُمنع** اسم مكتوب نصاً.

| الثابت | لمن | ملاحظة |
|---|---|---|
| `BILLING_BALANCE_VIEW` | المدرّس ومساعده | رصيد طالبه **بالأرصدة** وحالة حجبه، في مساحة عمله وحدها |
| `BILLING_SETTINGS_MANAGE` | ⚠️ **المنصة**، لا المدرّس | صُحّح بعد الشحن: كان مالك المساحة. تبديل النمط إقراضٌ من المنصة، لأن ‎014‎ تدفع للمدرّس عن **التسليم** فتبقى المنصة حاملةً للدين |
| `BILLING_PURCHASE_APPROVE` | **المنصة وحدها** | ⚠️ **جديد** — راجع §٢أ |
| `BILLING_CREDITS_ADJUST` | ⚠️ **المنصة**، لا المدرّس | منح `bonus` يخلق طلباً على المال بلا ساق دفع |
| `BILLING_LIMIT_MANAGE` | ⚠️ **المنصة**، لا المدرّس | رفع الحدّ يخلق ديناً تتحمّله المنصة وحدها (`Q-4`) |
| `BILLING_EXAM_MODE_MANAGE` | المدرّس | نافذة وضع الامتحانات |
| `BILLING_PACKAGES_MANAGE` | إدارة المنصة | `FR-016` |
| `BILLING_PRICING_MANAGE` | سوبر أدمن | رسم التشغيل ونسبة البوابة (`FR-021ب`) |

### ⚠️ ١أ — الصلاحية لا تكفي: **كل مسار يأخذ معرّفاً أجنبياً يثبت الملكية أولاً**

نسختي الأولى ذكرت هذا للقراءة وحدها، فبقيت الكتابتان بلا حارس — وهو نصّ ما يحذّر منه
`CLAUDE.md`: «فعلٌ يعمل على طالب مُسمّى يجب أن يُثبت أن الطالب طالبه؛ و`exists:users,uuid`
يجيب سؤالاً آخر». الجدول كامل:

| المسار | المعرّف | الإثبات المطلوب |
|---|---|---|
| `POST /manage/billing/students/{student}/credits` | طالب | `EnrollmentDirectory::hasActiveEnrollmentInWorkspace()` **قبل** الكتابة، و403 لا 404 |
| `PATCH /manage/billing/students/{student}/limit` | طالب | نفسه |
| `POST /billing/consents` | `student_user_id` | الموقِّع هو الطالب نفسه، أو `GuardianDirectory::isAuthorised(…, GuardianPermission::Payments)` |
| `GET /billing/balance?student=` | طفل | نفسه |
| `GET /billing/packages?course=` · `POST /billing/purchases` | كورس | **طرفٌ في الكورس**: تسجيل نشط، أو عضو في مساحة عمله — راجع §٢ب |
| `GET /billing/transactions?course=` | كورس | ترشيح بحسابه هو |
| `GET /manage/billing/students` | — | القيادة من `enrollments` النشطة (§٣) |

**والفشل 403 لا 404**: الـ404 عرّافٌ بذاته.

---

## ٢ — مسارات الطالب ووليّ الأمر

| الفعل | المسار | ماذا |
|---|---|---|
| GET | `/billing/balance` | حسابه **الواحد** مقسّماً **بالكورس** (`FR-009ج`): العنوان واسم المدرّس، المشترى، المستهلَك، المتبقّي، الحد، الحجب. **بالأرصدة، بلا مال** |
| GET | `/billing/transactions?course={uuid}` | سجلّه، مرقّم |
| GET | `/billing/packages?course={uuid}` | الحزم المفعَّلة **مسعَّرة لهذا الكورس**: `total` واحد — **يُمنع** أي مكوّن. كورسٌ بلا سعر معتمَد ⇒ قائمة فارغة |
| POST | `/billing/purchases` | ينشئ `credit_purchase` وطلباً بـ`kind = credits`. **لا رصيد** حتى الاعتماد |
| POST | `/billing/consents` | النسخة + الوقت + IP |

⚠️ **وليّ الأمر يحتاج دالةً غير موجودة**: `GuardianDirectory` يحمل `authorisedGuardians()` و
`isAuthorised()` فقط — **لا دالة «أبناء هذا الوليّ»**. فالمسار كما وصفتُه غير قابل للتنفيذ
بلا استعلام `ParentStudentRelation` من `Payments`، وهو خرقٌ للمبدأ الثالث على جدولٍ منصّي بلا
نطاق. يُضاف `childrenOf(User $guardian, GuardianPermission $permission): Collection<User>`.
**والصلاحية `GuardianPermission::Payments` تحديداً** — تعليقها القائم يقول ذلك: «وليّ أمر لا
يجوز له رؤية السجلّ المالي لا شأن له بتذكير دفع عنه».

### ⚠️ ٢أ — اعتماد شراء الأرصدة يخرج من يد المدرّس

`RolePermissionMatrix.php:69` يضع `PAYMENTS_APPROVE` داخل مصفوفة **`$teacher`**،
و`OrderPolicy::approve` يقبلها مع فحص مساحة عمل يستوفيه المدرّس بداهةً. فالمدرّس يعلّم حوالةً
لم تقع بأنها معتمَدة ⇒ أرصدة تُسكّ ⇒ الطالب يحجز ⇒ الحصة تُنفَّذ ⇒ **014 تدفع للمدرّس عن حصص
لم يدخل مقابلها ريال**؛ وعزل السياقين يجعل كشف ذلك من جهة التسوية مستحيلاً بالتصميم.

`Q-4` نقلت البيع إلى المنصة ولم تنقل معه اعتماد ذمّتها. **الحسم**: `OrderPolicy::approve`
ترفض طلباً بـ`kind = credits` إلا بـ`BILLING_PURCHASE_APPROVE`، واختبارٌ يؤكّد أن مدرّساً
يحمل `PAYMENTS_APPROVE` يُردّ بـ403 وبصفر قيد.

### ⚠️ ٢ب — سعر الحزمة عرّافٌ على سعر التسوية

`total = (rate + operating_fee[type] + gateway(…)) × credits`، والمكوّنان الآخران **ثابتان
منصّيان**. فمن يعرف سعره هو — وكل مدرّس يعرفه، `SETTLEMENT_STATEMENT_VIEW` على دوره — يحلّ
المجهولين من زوجين من أرقامه، ثم **يقلب `total` أي كورسٍ آخر إلى سعر مدرّسه بالضبط**. وأحجام
الحزم المتعدّدة تجعل الجملة زائدة التحديد فلا يُخفي التقريبُ شيئاً.

فـ`FR-021ب` مُستوفاة حقلاً حقلاً ومنقوضة بالجبر — وهذا الرقم **أخطر** من `hourly_rate` الذي
تُخرجه المرحلة من الأسطح العامة. **الحسم**: التسعير لطرفٍ في الكورس وحده (§١أ)، بحدّ معدّل
مسمّى، واختبارٌ يؤكّد أن غريباً يُردّ بـ403 لا بقائمة مسعَّرة.

### ⚠️ ٢ج — حدّ المعدّل: **`throttle:auth` خطأ هنا**

`AppServiceProvider:66-69` يعرّف `auth` بحدّين، ثانيهما
`Limit::perMinute(5)->by('email:'.$request->input('email'))`. وكتابةُ فوترةٍ بلا حقل `email`
تجعل المفتاح النصّ الثابت `'email:'` — **دلوٌ واحد لكل المنصة**: مهاجمٌ يدور على
`POST /billing/consents` فيمنع كل طالب من الشراء. وهو نفس العطل المسجَّل في `CLAUDE.md` بحرفه.

يُعرَّف `billing` مفتاحه المستخدم — شكلَ `contact-verification` و`settlement` القائمين —
ويُسمّى لكل مسار.

---

## ٣ — مسارات المدرّس

| الفعل | المسار | ملاحظة |
|---|---|---|
| GET | `/manage/billing/students` | ⚠️ صفٌّ لكل **(طالب × كورس)** |
| POST | `/manage/billing/students/{student}/credits` | صلاحية منصّة (§١) |
| PATCH | `/manage/billing/students/{student}/limit` | صلاحية منصّة، بسقفٍ يُفرَض في الـAction |
| POST · DELETE | `/manage/billing/exam-mode` | نافذة الامتحانات |
| PATCH | `/manage/billing/settings` | ⚠️ **جديد** — النمط والعتبات وسلوك الصفر. `FR-015` تُفرَض هنا: نمطٌ غير مهيّأ بالكامل (`PAYMENT_GATEWAY` قبل 007) **يُرفض حفظه** |

### ⚠️ ٣أ — الحبيبة، وحقلا الكورس

`credit_balances` فريد على `(حساب, كورس)`، فـ«صفّ لكل طالب» غير قابل للتعبير. والجمع عبر
الكورسات **جوابٌ خاطئ لا مضغوط**: `+10` رياضيات و`−6` فيزياء يظهر `+4` وغير محجوب، بينما
الحجب بالكورس تحديداً كي يبقى المسدَّد مفتوحاً.

فالصفّ **(طالب × كورس)**، وقائمة السماح تحمل `course_uuid` و`course_title` — وليسا مالاً.
و`NFR-012` تُعاد صياغتها بعدد **صفوف الرصيد** لا الطلاب، وإلا زُرع الاختبار بغير ما ينتجه
الإنتاج.

### ٣ب — قائمة الحقول المصرّح بها

`Payments\Support\StudentBalanceAllowlist`:

```
مسموح : student_uuid · student_name · course_uuid · course_title
         purchased_credits · consumed_credits · remaining_credits
         credit_limit_credits · is_withheld
ممنوع  : أي *_minor · total · price · currency · package_price
```

⚠️ **والمسح يقتصر على `Payments/Http/Resources/Manage/`**: `OrderResource` القائم يُصدِّر
`amount` و`currency` و`receipt_url` **إلى الطالب المشتري** عن حقّ، فمسحٌ على كل الوحدة يفشل
يوم كتابته. حارس 014 نجح لأن كل موارد وحدته للمدرّس؛ توسعة `Payments` تخدم جمهورين.

⚠️ **والقائمة حارس حقول، و`FR-054`/`FR-055` قاعدتا صفوف.** مسحُ Resource لا يرى أي صفوف
اختيرت. فاللوحة تُقاد من **التسجيلات النشطة**، ويُضاف اختبارٌ على مستوى الصفّ: طالب مسجَّل
عند مدرّس واحد ⇒ لوحة الآخر خالية؛ ثم يُلغى التسجيل ⇒ يختفي الصفّ في الطلب التالي. السابقة
`PlatformOwnershipTest:99-115` وتعليقها: «الوصول يتبع التسجيل، لا لقطةً أُخذت عند بدايته».

⚠️ **وباقٍ لا يُزال**: المدرّس يضرب `purchased_credits` في سعره فيعرف حدّاً أدنى لما دفعه
الطالب. لصيقٌ بـ`cost-plus`، ولا تُزيله قائمة حقول. يُذكر هنا لئلا يُقرأ `SC-015أ` أقوى ممّا هو.

---

## ٤ — مسارات الإدارة

| الفعل | المسار | الصلاحية |
|---|---|---|
| GET · POST · PATCH | `/admin/billing/packages` | `BILLING_PACKAGES_MANAGE` |
| GET · PUT | `/admin/billing/pricing` | `BILLING_PRICING_MANAGE` |
| GET | `/admin/billing/reconciliation` | `BILLING_PRICING_MANAGE` |

⚠️ **والمطابقة وظيفةٌ لا صفحة**: كما وُصفت هي `GROUP BY` على أسرع جداول المرحلة نمواً، بلا
مرشّح مستأجر ولا ترقيم، على GET. تصير `ReconcileCreditBalancesJob` مجدولة تكتب الانحرافات في
جدول نتائج، والشاشة تقرؤه مع وقت آخر تشغيل.

⚠️ **وهي عمياء عن أخطر حالة**: طرفاها يكتبهما نفس المسار في نفس المعاملة، فحصةٌ لم تُشحَن
أصلاً تتركهما متطابقين. تُضاف الثابتة: `الحصص المُسلَّمة × مقاعدها == قيود الاستهلاك`،
و`SUM(credit_lots.credits_remaining) == remaining_credits` حين يكون موجباً.

---

## ٥ — الأحداث

راجع [events.md](./events.md). المُستهلَك: `SessionDelivered` · `PaymentApproved`.
المُطلَق: `CreditsPurchased` · `CreditConsumed` · `BalanceUpdated` · `CreditExpired` ·
`BalanceThresholdCrossed` · `AccessWithheld` · `AccessRestored` · `RefundIssued`.

⚠️ `BalanceUpdated` مُدرَج لأن `SC-006` تشترط اختبار تكامل **يرصد الأحداث** لسلسلةٍ رباعية،
وكانت الحلقة الرابعة غير موجودة في أي قائمة إطلاق.

---

## ٦ — العقود المشتركة

### `ApprovedRateDirectory` — تنفّذه `Settlement`

```php
/** سعر التسوية المعتمَد لحصص هذا الكورس في هذه اللحظة، بالوحدة الصغرى. */
public function approvedRateMinorForCourse(
    int $courseId, ClassSessionType $type, DateTimeInterface $moment,
): ?int;
```

يعيد **عدداً** لا نموذجاً، فيستحيل تسرّب حقل من `SettlementRate` إلى حمولة. ويأخذ الكورس
ليقع الاشتقاق داخل `Settlement` — الطرف الذي يشتقّ المُدخَلات نفسها عند التسوية.

⚠️ **ولا يقوم بلا ثلاثة**: `courses.teacher_profile_id` (`RateResolver` يبدأ منه، و`courses`
تحمل `created_by` القابل للإفراغ فقط)؛ وتمرير `grade_level` في `AccrueTeachingUnits` (يمرّر
أربعة وسائط اليوم فكل سعر مخصَّص بصفّ غير مرئي عند التسوية)؛ وحفظُ الجواب لكل طلب — الحزم
الستّ تعطي جوابين لا ستّة، لأن السعر يتغيّر بنوع الحصة وحده.

⚠️ **وقراءة الكورس تتجاوز النطاق صراحةً**: `Course` يستعمل `BelongsToWorkspace`، وطلب الطالب
محلولٌ على مساحة عمل واحدة — فكورسٌ عند مدرّس آخر يعود `null` فتُقرأ «لا سعر معتمَد» فتظهر
قائمة فارغة، بلا خطأ. التنفيذ يستعمل `forWorkspace()` بتعليق يذكر السبب.

⚠️ **و`ClassSessionType` في `Shared\Contracts` أوّل استيرادٍ لوحدة من هذه الطبقة**؛ العقود
الثلاثة القائمة لا تأخذ إلا `User` وقيماً أوّلية. يُقبَل صراحةً بتعليق، أو يُمرَّر نصّاً.

### `AccountStanding` — تنفّذه `Payments`، تستدعيها `Media` و`LiveSessions`

```php
public function isWithheld(User $student, int $courseId): bool;

/** @return list<int> — لقراءة الاستحقاق مرة واحدة لقائمة كاملة (SC-011). */
public function withheldCourseIdsFor(User $student): array;
```

⚠️ **بالكورس لا بمساحة العمل** — نسخة `research.md` الأولى قالت `workspaceId`، ونتيجتها أن
من عليه مستحقّ في الفيزياء تُغلَق عليه مذكّرات الرياضيات التي سدّدها.
⚠️ **والدالة الجماعية إلزامية**: العقدان الشقيقان يحملانها بالتعليق نفسه. **ويُمنع** نداء
هذا العقد من داخل Resource — نداءٌ لكل صفّ هو N+1 بالبناء، ويُحرَس بنفس مسح `Manage/`.

### ⚠️ ولا توسعة على `SessionDelivered`

نسختي الأولى كانت تضيف `billableSeatHolders`. **نُقضت**: `AccrueTeachingUnits:41` يقرأ
`seatHolderIds($session)` من الحجوزات مباشرة، وتعليقه يعرّف القسمة — «**العدد** سلطته الحدث،
**والحجوزات تُقرأ للهوية فقط**، واختلافهما تباينٌ يستحقّ نظر إنسان». 006 تفعل الشيء نفسه:
صفر تعديل على 005، وصفر فخّ قيمةٍ افتراضية `[]` تشحن لا أحد بلا خطأ.

### ⚠️ وتوسعة على `GuardianDirectory`

`childrenOf(User $guardian, GuardianPermission $permission): Collection<User>` — بدونها لا
مسار لوليّ الأمر أصلاً (§٢).

---

## ٧ — الحمولات: ما يُمنع وأين يُختبَر

| القاعدة | الاختبار |
|---|---|
| صفر سعر مدرّس في أي حمولة عامة أو فلتر أو ترتيب | ⚠️ `hourly_rate` يدخل `PublicFieldAllowlist::FORBIDDEN`، **و`PublicExposureTest` يُحوَّل إلى قائمة سماح فعلية**: هو اليوم قائمة **منع** لا يقابل الحمولة بثوابته السبعة، وهي غير مرجوعة من أي كود |
| `price_min` / `sort=price_asc` | يُردّان بـ422 من الـFormRequest، لا يُتجاهَلان |
| صفر تفصيل مكوّنات لطالب أو وليّ أمر | اختبار حمولة الحزم |
| صفر مبلغ نقدي لمدرّس عن طالب | `StudentBalanceAllowlist` + مسح `Manage/` |
| صفر صفّ لطالب غير مسجَّل، أو من مساحة أخرى | ⚠️ اختبار **على مستوى الصفّ** (§٣ب) |
| صفر تسريب بين مساحات العمل | حالات في `WorkspaceIsolationTest` لـ`credit_balances` و`credit_transactions` و`credit_purchases` |
| صفر ذكر لنمط الفوترة خارج `BillingSettings` | مسح نصّي |
| `Payments` لا تذكر `App\Modules\Settlement` | ⚠️ حالة جديدة — الاتجاه غير محروس اليوم |
