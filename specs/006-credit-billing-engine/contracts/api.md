# API Contract: محرّك الأرصدة والتحصيل (006)

**التاريخ**: 2026-08-08 · كل المسارات تحت `/api/v1`، مصادَقة Sanctum، و`HasUuid` يعني أن
المُعرَّفات في المسارات والحمولات **uuid** لا `id`.

---

## ١ — الصلاحيات الجديدة

ثوابت في `Tenancy\Support\Permissions` (`NFR-004`) — **يُمنع** اسم مكتوب نصاً.

| الثابت | الاسم | لمن |
|---|---|---|
| `BILLING_BALANCE_VIEW` | `billing.balance.view` | المدرّس ومساعده: رصيد طالبه **بالأرصدة** وحالة حجبه، في سياق مساحة عمله وحدها (`FR-052` · `FR-054`) |
| `BILLING_CREDITS_ADJUST` | `billing.credits.adjust` | تسوية يدوية / رصيد ترويجي — `adjustment` و`bonus` |
| `BILLING_LIMIT_MANAGE` | `billing.limit.manage` | استثناء على الحد الائتماني، مسجَّل (`FR-038`) |
| `BILLING_EXAM_MODE_MANAGE` | `billing.exam_mode.manage` | تفعيل نافذة وضع الامتحانات |
| `BILLING_PACKAGES_MANAGE` | `billing.packages.manage` | **إدارة المنصة وحدها** — الحزم مملوكة للمنصة (`FR-016`) |
| `BILLING_PRICING_MANAGE` | `billing.pricing.manage` | **سوبر أدمن وحده** — رسم التشغيل ونسبة البوابة، ولا يراهما مدرّس (`FR-021ب`) |

`BILLING_BALANCE_VIEW` **لا تكفي وحدها**: كل قراءة يجريها مدرّس عن طالب تمرّ أولاً بـ
`EnrollmentDirectory::hasActiveEnrollmentInWorkspace()` (`NFR-001أ` · `FR-055`). الصلاحية
تقول «يجوز لك أن تقرأ أرصدةً»، والتسجيل يقول «عن هذا الشخص». الأولى بلا الثانية سبر هوية.

---

## ٢ — مسارات الطالب ووليّ الأمر

| الفعل | المسار | ماذا |
|---|---|---|
| GET | `/billing/balance` | حسابه **الواحد** مقسّماً بسياقاته (`FR-009ج`): لكل **كورس** — عنوانه واسم مدرّسه، المشترى، المستهلَك، المتبقّي، الحد، حالة الحجب. **بالأرصدة، بلا مال** (`FR-051` · `FR-021د`) |
| GET | `/billing/transactions?course={uuid}` | سجلّه مصفّى بالكورس، مرقّم |
| GET | `/billing/packages?course={uuid}` | الحزم المفعَّلة **مسعَّرة لهذا الكورس**: `total` واحد فقط — **يُمنع** أي مكوّن (`FR-021ج` · `SC-015أ`). كورسٌ بلا سعر معتمَد ⇐ **قائمة فارغة** لا سعر افتراضي |
| POST | `/billing/purchases` | ينشئ `credit_purchase` + طلباً بمسار الإيصال القائم. **لا رصيد** حتى الاعتماد (`FR-018`) |
| POST | `/billing/consents` | موافقة شروط التأجيل: النسخة + الوقت + IP (`FR-049`) |

وليّ الأمر يقرأ المسارين الأولين عن أبنائه عبر `GuardianDirectory` القائم (`FR-053`).

**حدّ المعدّل**: `throttle:auth` على الكتابة. **يُمنع** `throttle:5,1` مضمَّناً — الحدود
مسمّاة في `AppServiceProvider::registerRateLimiters()`، وإلا اشتركت كل الحدود في عدّاد واحد.

## ٣ — مسارات المدرّس

| الفعل | المسار | ملاحظة |
|---|---|---|
| GET | `/manage/billing/students` | لوحة الأرصدة: صفّ لكل طالب مسجَّل — الرصيد وحالة الحجب. **٥٠٠ طالب / ٨٠٠ مللي ثانية p95 / ≤١٥ استعلاماً** (`NFR-012`) |
| POST | `/manage/billing/students/{student}/credits` | `bonus` أو `adjustment` بسبب إلزامي |
| PATCH | `/manage/billing/students/{student}/limit` | استثناء مسجَّل في `activity_log` |
| POST · DELETE | `/manage/billing/exam-mode` | نافذة وضع الامتحانات |

**قائمة الحقول المصرّح بها** — `Payments\Support\StudentBalanceAllowlist`، على غرار
`TeacherFieldAllowlist` في 014، ويحرسها اختبار يمسح كل Resource في الوحدة:

```
مسموح : student_uuid · student_name · purchased_credits · consumed_credits
         remaining_credits · credit_limit_credits · is_withheld
ممنوع  : أي *_minor · total · price · currency · package_price
         وأي حقل يعود لمساحة عمل أخرى
```

`FR-052` ليست تفصيل عرض: المدرّس **ليس طرفاً في المطالبة** (`Q-4`)، فالمبلغ النقدي ليس
بياناً حُجب عنه بل بيانٌ لا يخصّه.

## ٤ — مسارات الإدارة

| الفعل | المسار | الصلاحية |
|---|---|---|
| GET · POST · PATCH | `/admin/billing/packages` | `BILLING_PACKAGES_MANAGE` |
| GET · PUT | `/admin/billing/pricing` | `BILLING_PRICING_MANAGE` |
| GET | `/admin/billing/reconciliation` | `BILLING_PRICING_MANAGE` — أرصدة اختلّ فيها المادّي عن مجموع قيوده (`SC-001` كشاشة لا كاختبار فقط) |

---

## ٥ — الأحداث

**المستهلَك من وحدات أخرى** (`Event::listen()` في `PaymentsServiceProvider::boot()`):

| الحدث | من | الأثر |
|---|---|---|
| `SessionDelivered` | LiveSessions (005) | `ChargeSessionSeats` — قيد `consume` لكل مقعد مُجمَّد |
| `PaymentApproved` | Payments (داخلي) | قيد `purchase` — الموضع الوحيد الذي يضيف رصيداً بمقابل |
| `AttendanceOverridden` | LiveSessions (005) | لا شيء مالياً (`FR-025د`) — الاشتراك يُذكر ليُنفى، فلا يُعاد اقتراحه |

**المُطلَق** (يستهلكها 007 و009 و013 و015):

`CreditsPurchased` · `CreditConsumed` · `CreditExpired` · `BalanceThresholdCrossed` ·
`AccessWithheld` · `AccessRestored` · `RefundIssued`

`NFR-003` تسمّي أيضاً `ReceiptUploaded` / `ReceiptApproved` / `ReceiptRejected`؛ الموجود
اليوم `PaymentApproved` و`PaymentRejected` — **تُستعمل هي**، ولا يُخلَق حدثٌ ثانٍ بنفس المعنى.

**يُمنع** أن تظهر أي من هذه الأسماء تحت `Modules/Settlement/` — يحرسه `ContextIsolationTest`
تلقائياً، لأنه يشتقّ الممنوع من `Modules/Payments/Events/`.

---

## ٦ — العقود المشتركة

### `Shared\Contracts\ApprovedRateDirectory` — تنفّذه `Settlement`

```php
/** سعر التسوية المعتمَد لحصص هذا الكورس في هذه اللحظة، بالوحدة الصغرى. */
public function approvedRateMinorForCourse(
    int $courseId,
    ClassSessionType $type,
    DateTimeInterface $moment,
): ?int;
```

يعيد **عدداً**، لا نموذجاً: فلا يعبر صفٌّ من سياق إلى آخر، ويستحيل أن يتسرّب حقل من
`SettlementRate` إلى حمولة. `null` يعني «لا سعر معتمَد» ⇐ **الحزم لا تُعرَض لهذا الكورس**،
لا سعرٌ افتراضي: بيعُ حصةٍ بسعرٍ لم يعتمده أحد هو الخطأ الذي يُدفع نقداً.

**ويأخذ الكورس لا المادة والصفّ** عمداً (`Q-7`): `RateResolver` يُحلّ بهما، ولو أخذهما العقد
لقرأتهما `Payments` من جدول `courses` — قراءة وحدة أخرى. وبتمرير الكورس يقع الاشتقاق **داخل
`Settlement`**، وهي الطرف الذي يشتقّ المُدخَلات نفسها من الحصة عند التسوية. صنفٌ واحد يشتقّ
في الحالتين ⇐ سعر الشراء وسعر التسوية **متساويان بالبناء**، لا باتفاق قارئَين على أن يفعلا
الشيء نفسه.

### `Shared\Contracts\AccountStanding` — تنفّذه `Payments`

```php
/** هل هذا الطالب محجوب مالياً في هذا الكورس الآن؟ */
public function isWithheld(User $student, int $courseId): bool;
```

**بالكورس لا بالطالب**: من عليه مستحقّ في الفيزياء لا تُغلَق عليه مذكّرات الرياضيات التي
سدّدها — والأصل المطلوب يعرف كورسه.

تستدعيها `Media` عند إصدار كل منحة تشغيل لأصل `is_high_value` — نفس الموضع الذي تسأل فيه
`EnrollmentDirectory` اليوم، وللسبب نفسه: `FR-043` تطلب الفحص عند كل طلب لا مرة واحدة.

### توسعة `SessionDelivered` (005)

```php
public function __construct(
    public readonly ClassSession $session,
    public readonly int $billableSeats,
    /** @var list<int> أصحاب المقاعد المُجمَّدة — أُضيف لـ006 */
    public readonly array $billableSeatHolders = [],
) {}
```

خاصية `readonly` ثالثة بقيمة افتراضية: `AccrueUnitsOnDelivery` في 014 لا يتغيّر، و**يُمنع**
أن يقرأ الحقل الجديد — التسوية بالعدد، لا بالأشخاص.

---

## ٧ — الحمولات: ما يُمنع وأين يُختبَر

| القاعدة | الاختبار |
|---|---|
| صفر سعر مدرّس في أي حمولة عامة أو فلتر أو ترتيب (`SC-015`) | `PublicExposureTest` (قائم) + حالة على مسارات السوق |
| صفر تفصيل مكوّنات لطالب أو وليّ أمر (`SC-015أ`) | اختبار حمولة الحزم |
| صفر مبلغ نقدي لمدرّس عن طالب (`SC-015أ` · `FR-052`) | `StudentBalanceAllowlist` + مسح Resources |
| صفر تسريب بين مساحات العمل (`SC-016`) | حالة في `WorkspaceIsolationTest` |
| صفر قراءة لمدرّس عن طالب غير مسجَّل عنده (`SC-020`) | اختبار الملكية المنصّية (`NFR-001ب`) |
| صفر ذكر لنمط الفوترة خارج `BillingSettings` (`FR-013`) | مسح نصّي، على غرار `ProviderAgnosticTest` |
