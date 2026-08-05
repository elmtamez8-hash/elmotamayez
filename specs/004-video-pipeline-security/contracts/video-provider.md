# Contract: مزوّد البث (`VideoProviderInterface`)

**Feature**: `004-video-pipeline-security` | **Namespace**: `App\Modules\Media\Contracts`

نقطة الانعكاس كلها. منطق الدروس والحصص يعتمد على **هذه الواجهة**، ولا يعرف اسم مزوّد
واحد (FR-002 · NFR-003). النمط مأخوذ حرفياً من `Payments\Contracts\PaymentProviderInterface`
القائم منذ الإطلاق.

---

## الواجهة

```php
interface VideoProviderInterface
{
    /** معرّف المزوّد: 'local' · 'bunny' · 'cloudflare' … */
    public function identifier(): string;

    /** ما يقدر عليه هذا المزوّد فعلاً — تقرؤه الواجهة والاختبارات لا تفترضه. */
    public function capabilities(): ProviderCapabilities;

    /** تذكرة رفع: أين يرفع العميل وبأي طريقة وترويسات. */
    public function createUploadTicket(MediaAsset $asset): UploadTicket;

    /** حالة الأصل لدى المزوّد — للمصالحة بعد اكتمال الرفع. */
    public function status(MediaAsset $asset): AssetStatusReport;

    /** بيان تشغيل صالح لهذا المشاهد وهذه الجلسة ولهذه المدة فقط. */
    public function manifest(PlaybackContext $context): PlaybackManifest;

    /** حذف الأصل عند المزوّد. عديم الأثر عند التكرار. */
    public function delete(MediaAsset $asset): void;
}
```

**لماذا `createUploadTicket` واحدة لا دالّتان (رفع مباشر ورفع عبر الخادم)**: المزوّد التجاري
يريد أن يرفع العميل إليه مباشرة، وإلا مرّت الجيجابايتات عبر PHP. والمزوّد المحلّي ليس له
عنوان خارجي. التذكرة تحلّ الاثنين بمسار واحد: العميل يرفع دائماً إلى `ticket.url`،
ويشير المحلّي إلى مسارنا نحن. **مسار رفع واحد في العميل مهما تبدّل المزوّد.**

---

## الأغلفة (`Data/`)

كلها ترث `App\Shared\Data\DataTransferObject` بخصائص `readonly` (الدستور VI).

### `ProviderCapabilities`

```php
readonly class ProviderCapabilities
{
    public bool $adaptiveBitrate;    // جودات متعدّدة تلقائية (FR-004)
    public bool $signedUrls;         // روابط موقّعة من طرف المزوّد
    public bool $automaticCaptions;  // توليد نصّ مصاحب آلياً (FR-032)
    public bool $directUpload;       // العميل يرفع إلى المزوّد مباشرة
    public int  $maxSizeBytes;
    public int  $maxDurationSeconds;
}
```

هذا الصنف هو **إجابة السؤال الصعب** في هذه المرحلة: كيف نلتزم بمتطلّب يحتاج مزوّداً لا
نملكه بعد؟ الجواب أن القدرة تُعلَن لا تُفترَض. الواجهة تخفي زرّ الجودة إن كان
`adaptiveBitrate = false`؛ والاختبار يفرض على **أي** تنفيذ يعلنها أن يقدّمها فعلاً.

### `UploadTicket`

```php
readonly class UploadTicket
{
    public string $url;                  // إلى أين يرفع العميل
    public string $method;               // PUT | POST
    public array  $headers;              // ترويسات مطلوبة
    public array  $fields;               // حقول multipart إن وُجدت
    public CarbonImmutable $expiresAt;
}
```

**يُمنع** أن تحمل التذكرة مفتاح المزوّد. ما يصل العميل رمز رفع لهذا الأصل وحده وينتهي
(FR-011 · NFR-008).

### `PlaybackContext`

```php
readonly class PlaybackContext
{
    public MediaAsset $asset;
    public PlaybackGrant $grant;      // فيه الجلسة والمدة
    public string $viewerIpHash;
}
```

### `PlaybackManifest`

```php
readonly class PlaybackManifest
{
    public PlaybackFormat $format;    // Hls | Progressive
    public string $url;               // ما يبثّه مسارنا أو يحوّل إليه
    public bool   $isRedirect;        // true ⇒ 302 · false ⇒ بثّ من عندنا
    public CarbonImmutable $expiresAt;
    /** @var list<Rendition> */
    public array $renditions;
}
```

`isRedirect` هو ما يجعل FR-011 قابلاً للتحقيق: **العميل يرى مسارنا دائماً**، والتحويل
يقع داخل الخادم. راجع research §R4.

### `AssetStatusReport`

```php
readonly class AssetStatusReport
{
    public MediaAssetStatus $status;
    public ?int    $durationSeconds;
    public ?string $mimeType;
    public ?int    $sizeBytes;
    /** @var list<Rendition> */
    public array   $renditions;
    public ?string $failureReason;
}
```

---

## `LocalVideoProvider` — التنفيذ الوحيد اليوم

| الدالّة | السلوك |
|---|---|
| `identifier()` | `'local'` |
| `capabilities()` | `adaptiveBitrate: false` · `signedUrls: false` · `automaticCaptions: false` · `directUpload: false` · الحدود من `config/media.php` |
| `createUploadTicket()` | تذكرة إلى مسارنا `PUT /api/v1/media/upload/{token}` — الرمز يخصّ هذا الأصل وينتهي بساعة |
| `status()` | يقرأ الملف من القرص الخاص: النوع من محتواه، الحجم منه. `ready` مباشرة (لا معالجة) |
| `manifest()` | `format: Progressive` · `isRedirect: false` · `url` مسار البثّ عندنا |
| `delete()` | يحذف الملف. عديم الأثر إن غاب |

**البثّ يدعم طلبات المدى (`Accept-Ranges: bytes`)** — وهذا ليس تفصيلاً: هو ما يجعل انتهاء
المنحة يقطع المشاهدة في منتصف الملف، فيُثبِت SC-005 بلا مزوّد (research §R5).

---

## اختبار مطابقة العقد (`ProviderContractTest`)

يعمل على **كل** تنفيذ مسجَّل، لا على المحلّي وحده. أي مزوّد يُضاف يخضع لنفس المجموعة
بلا سطر اختبار جديد:

| الفحص | ما يفرضه |
|---|---|
| `identifier()` غير فارغ وفريد | التسجيل في الحاوية سليم |
| تذكرة الرفع لا تحوي مفتاحاً | FR-011 · NFR-008 — يُفحَص نصّياً عن أنماط أسرار |
| البيان ينتهي قبل انتهاء المنحة أو معها | لا رابط يعيش بعد إذنه |
| `status()` لأصل محذوف ⇒ `failed` لا استثناء | Edge Case «المزوّد متوقّف» |
| `delete()` مرتين ⇒ بلا خطأ | عدم الأثر عند التكرار |
| **إن أعلن `adaptiveBitrate`** ⇒ `renditions` فيه اثنان فأكثر | القدرة المُعلَنة قدرة مُقدَّمة |
| **إن أعلن `automaticCaptions`** ⇒ ينتج WebVTT صالحاً | نفس القاعدة |

السطران الأخيران هما ما يجعل تأجيل المزوّد قراراً آمناً: أول تنفيذ يدّعي جودة تكيّفية
ولا يقدّمها **يفشل البناء**، لا يُكتشف في الإنتاج.

---

## عقد الإضافة (SC-002)

إضافة مزوّد = ملفّان وسطر:

```php
// app/Modules/Media/Providers/BunnyVideoProvider.php   ← ملف جديد
// config/media.php: 'provider' => env('MEDIA_PROVIDER', 'local')

// MediaServiceProvider::register()
$this->app->bind(VideoProviderInterface::class, fn ($app) => match (config('media.provider')) {
    'bunny' => $app->make(BunnyVideoProvider::class),
    default => $app->make(LocalVideoProvider::class),
});
```

**صفر تعديل** في `Actions/` أو `Models/` أو `Http/` أو الواجهة.

### الفحص الآلي (`ProviderAgnosticTest`)

يمسح `app/Modules/*/Actions/` و`app/Modules/*/Http/` و`frontend/src/` بحثاً عن أي اسم
مزوّد (`bunny` · `cloudflare` · `mux` · `vimeo` · `jwplayer`) ويفشل عند أي تطابق.
نفس نمط `ProviderAgnosticTest` في spec 003 للقنوات — البوابة التي جعلت عقد القناة عقداً
لا نيّة.

**استثناء واحد**: `app/Modules/Media/Providers/` — مكان أسماء المزوّدين بحكم التعريف.

---

## ما الجاهز لأي مزوّد اليوم

| المكوّن | الحالة |
|---|---|
| العقد وأغلفته | ✅ مُنفَّذ |
| منح التشغيل وإلغاؤها والتجديد | ✅ مُنفَّذ — مستقلّ عن المزوّد كلياً |
| العلامة المائية وحلقة التجديد | ✅ مُنفَّذ |
| مسار البثّ والتحويل (`isRedirect`) | ✅ مُنفَّذ — المسار جاهز للـ302 قبل وجود من يحوّل إليه |
| رفع بتذكرة | ✅ مُنفَّذ — العميل لا يتغيّر عند التبديل |
| مصالحة الحالة (استطلاع) | ✅ مُنفَّذ |
| **الجودة التكيّفية فعلياً** | ⏸ قدرة مزوّد — العقد يفرضها على من يعلنها |
| **بيان HLS في المشغّل** | ⏸ يحتاج `hls.js` — يُضاف مع أول مزوّد |
| **Webhook المزوّد** | ⏸ شكله خاصّ بكل مزوّد — الاستطلاع يغطّي الفجوة |
| **النصّ المصاحب الآلي** | ⏸ قدرة مزوّد — الرفع اليدوي مُنفَّذ |

الأربعة المؤجَّلة كلها **خلف العقد**: لا واحدة منها تتطلّب تعديلاً في `Actions/` أو في
شكل الحمولة عند اعتمادها.
