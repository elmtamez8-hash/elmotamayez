# Contract — `PaymentProviderInterface` (مُوسَّع)

**السند:** `research.md` §ح · `spec.md` `FR-001` · `NFR-003` · `NFR-011`
· إذن السبيك الصريح: «وإن احتاج فتعديله جزء من هذه المرحلة»

---

## أ — العقد القائم، ولماذا لا يكفي

```php
interface PaymentProviderInterface
{
    public function identifier(): string;
    public function createCharge(Order $order): array;
    public function verify(array $reference): array;   // {status, verified}
    public function supportsRefund(): bool;
}
```

**ثلاث فجوات، كلٌّ منها متطلبٌ في السبيك:**

| الفجوة | المتطلب المتعطّل |
|---|---|
| لا مدخل لحمولةٍ واردة موقَّعة | `FR-005` — التحقّق من التوقيع قبل أي أثر |
| `verify()` تأخذ مرجعاً واحداً | `FR-012` — التسوية تسأل عن **مدى**، لا عن صفٍّ تعرفه |
| لا شكل مُعلَن لصفحة الدفع | `FR-009` — لا اعتماد على عودة المستخدم |

⚠️ **و`verify()` تجيب عن السؤال الخطأ للتسوية.** هي تسأل «ما حال العملية التي أعرفها؟»،
والتسوية تسأل «**ما الذي نجح عندك ولم أعرف عنه شيئاً؟**» — وهذا الفرق هو `US2` كلها. مسحٌ
مبنيّ على `verify()` يمشي ما نعرفه فقط، فيبقى أعمى تماماً عن دفعةٍ نجحت وضاع إشعارها،
وهي **الحالة التي وُجدت التسوية لأجلها**.

---

## ب — العقد بعد التوسيع

```php
interface PaymentProviderInterface
{
    // — القائم، بلا تغيير —
    public function identifier(): string;
    public function createCharge(Order $order): ChargeIntent;   // ⚠️ نوعٌ مُعلَن، لا array
    public function verify(array $reference): array;
    public function supportsRefund(): bool;                     // ⚠️ false دائماً (د3)

    // — المضاف —

    /**
     * هل هذه الحمولة صادرة عن المزوّد فعلاً؟
     *
     * ⚠️ تأخذ الجسد الخام لا المُفكَّك: التوقيع يُحسب على البايتات كما وصلت،
     * وأي فكٍّ ثم إعادة ترميز يغيّر ترتيب المفاتيح والمسافات فيكسر التحقّق —
     * على المزوّد الحقيقي وحده، بعد أن يكون الاختبار الوهمي أخضر.
     *
     * ⚠️ والمقارنة تُنفَّذ بزمن ثابت (`hash_equals`)، لا بـ`===`.
     */
    public function verifySignature(string $rawBody, array $headers): bool;

    /** ما الذي وقع في هذه الحمولة، بلغة النظام لا بلغة المزوّد. */
    public function parseCallback(string $rawBody): CallbackEvent;

    /**
     * كل ما تغيّرت حالته لدى المزوّد في هذا المدى — لا ما نعرفه فقط.
     *
     * ⚠️ هذه هي دالّة التسوية، والفرق بينها وبين verify() هو US2 بأكملها.
     */
    public function transactionsInWindow(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
```

**ولا `refund()` في العقد.** `supportsRefund()` تبقى وتعيد `false` دائماً بقرار `د3`:
دالّةٌ مُعلَنة بلا تنفيذ تجعل «لا استرداد نقدي» **قابلاً للفحص** بدل أن يكون عُرفاً يخرقه
من لم يقرأ القرار.

---

## ج — الأنواع المُعلَنة

```php
final readonly class ChargeIntent {
    public string $reference;      // مرجع المزوّد
    public string $redirectUrl;    // صفحة الدفع الآمنة لديه
    public ?CarbonImmutable $expiresAt;
}

final readonly class CallbackEvent {
    public string $externalId;     // معرّف الحدث — مفتاح عدم التكرار
    public string $reference;      // العملية التي يخصّها
    public PaymentStatus $status;
    public int $amountMinor;       // ⚠️ صحيح، وحدات صغرى — NFR-007
    public string $currency;
    public array $safePayload;     // ⚠️ مُنقّى: بلا بطاقة ولا سرّ — FR-030
}
```

⚠️ **`safePayload` مُنقّى عند حدود العقد، لا عند العرض.** تنقيةٌ في طبقة العرض تعني أن
الحمولة الخام كُتبت في قاعدة البيانات وفي سجلّ التطبيق أوّلاً — و`NFR-010` يمنع الثانية
و`FR-030` يمنع الأولى. فالمزوّد هو من يسلّم نظيفاً، لأنّه وحده يعرف أيّ حقولٍ عنده حسّاسة.

---

## د — المزوّد الوهمي: أداةُ اختبارٍ أولى الدرجة

`NFR-011` **يمنع** أي نداء شبكي حقيقي في الاختبارات، ويسمّي أربع حالات يحاكيها:

| الحالة | ماذا تُثبت |
|---|---|
| نجاح | `SC-001` — الإشعار يفتح الوصول |
| فشل | `FR-008` — يبقى المستحق والحجب، ويُبلَّغ بسببٍ مفهوم |
| تكرار | `SC-003` — عشر مرّات، أثرٌ واحد |
| توقيع غير صالح | `SC-002` — رفضٌ **وتسجيل** |

**وحالة خامسة يفرضها `US2`:** عمليةٌ ناجحة عنده **بلا إرسال إشعارها** — وهي مدخل اختبار
التسوية الوحيد، وبلاها لا يمكن إثبات `SC-004` إطلاقاً.

⚠️ **والوهميّ يعيش في `tests/Support/`، لا في `app/Modules/Payments/Providers/`.** مزوّدٌ
مزيّف مسجَّل في الحاوية هو مزوّدٌ يمكن أن يُختار بمتغيّر بيئة في الإنتاج فيقبل كل دفعة بلا
مال. الشكل نفسه المستعمل في `FakeBroadcastProvider`.
