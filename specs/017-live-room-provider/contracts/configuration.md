# Contract — الإعدادات والبيئة

**`FR-005` قاطع**: لا سرّ ولا مفتاح ولا بيانات اعتماد في المستودع. وهذا الملفّ يعرّف
السطح كاملاً — **بأسماء المفاتيح بلا قيمة واحدة**.

---

## أ — `.env` (‏تُضاف إلى `.env.example` **فارغةً**)

| المفتاح | ما هو | ملاحظة |
|---|---|---|
| `BROADCAST_PROVIDER` | `livekit` في الإنتاج | **يبقى `null` في الاختبار والتطوير** — `FR-017` · `SC-006` |
| `LIVEKIT_URL` | عنوان `wss://…` الذي يتّصل به المتصفّح | يُرسَل في `JoinTicket.room_url` — ليس سرّاً |
| `LIVEKIT_API_KEY` | 🔒 | خادميّ حصراً |
| `LIVEKIT_API_SECRET` | 🔒 | خادميّ حصراً · **يوقّع كلّ تذكرة** |
| `LIVEKIT_EGRESS_BUCKET` | دلو **Cloudflare R2** | وجهة `EncodedFileOutput` |
| `LIVEKIT_EGRESS_ENDPOINT` | نقطة R2 المتوافقة مع S3 | |
| `LIVEKIT_EGRESS_REGION` | `auto` في R2 | |
| `LIVEKIT_EGRESS_KEY` | 🔒 | اعتمادُ الكتابة الذي **يحمله المزوّد** |
| `LIVEKIT_EGRESS_SECRET` | 🔒 | |

⚠️ **ولماذا ليست وجهةُ التصدير قرصاً في `filesystems.php`**: اسمُ قرصٍ في لارافل **لا يصل
LiveKit أبداً**. عمليّةُ التصدير تكتب حيث يقول لها `EncodedFileOutput` وحده، وخياراتها
محصورة في: S3‑متوافق · GCP · Azure · AliOSS · **أو مسارٍ محلّي على مضيف التصدير**.

⚠️ **وباني ستريم ليس منها.** ‏Edge Storage يصادق برأس `AccessKey` على واجهته الخاصّة، وليس
متوافقاً مع S3 (‏مُتحقَّقٌ من توثيق باني). **فباني وجهةُ تسليمٍ نهائية لا وجهةُ تصدير**،
ويبقى بينهما وسيط — و**القرار: Cloudflare R2** (‏رسومُ خروجٍ صفرية، وقاعدةُ دورة حياةٍ
تحذف بعد ‎٢٤‎ ساعة فلا سطرَ كودٍ للتنظيف).

**وقاعدةُ دورة الحياة تُضبط في R2، لا في الكود.** حذفٌ مكتوبٌ عندنا يعني وظيفةً أخرى تُراقَب
وتفشل بصمت؛ وقاعدةُ الدلو تعمل ولو توقّف التطبيق كلّه.

**و`FR-012` يبقى تافهاً بدل أن يكون قاعدةً تُحرَس**: `RecordingArtifact::$downloadUrl` يشير
إلى دلوٍ نملكه، فلا يوجد رابطُ مزوّدٍ ليتسرّب أصلاً.

⚠️ **ولا مفتاح واحد منها يبدأ بـ`NEXT_PUBLIC_`**. المتصفّح لا يرى إلّا ما يعود في
`JoinTicketResource`: `room_url` و`token` قصير العمر. والمفتاح السرّي **يوقّع** التذكرة ولا
يسافر معها.

---

## ب — `config/sessions.php` (‏إضافة، لا استبدال)

```php
'provider' => env('BROADCAST_PROVIDER', 'null'),   // قائم — لا يتغيّر سطره

// مدّة التذكرة، بالدقائق. FR-007.
// ⚠️ الافتراضي في المكتبة ‎٦‎ ساعات، والاختبار القائم يؤكّد «‏أقلّ من ‎٢٤‎ ساعة» —
//    فمدّةٌ منسيّة تشحن خضراء. القيمة تُقرأ عبر SessionSettings، ويُشدّ التأكيد.
'ticket_ttl_minutes' => 10,

// سقف الغرفة. FR-003 يمنع الثابت في الكود، ويُمرَّر إلى المزوّد لا يُعلَن فقط.
'max_participants' => 50,

'livekit' => [
    'url' => env('LIVEKIT_URL'),
    'key' => env('LIVEKIT_API_KEY'),
    'secret' => env('LIVEKIT_API_SECRET'),
    // وجهة التصدير: دلوٌ نملكه، ومزوّدُ البثّ يحمل اعتماد الكتابة إليه — §أ.
    'egress' => [
        'bucket' => env('LIVEKIT_EGRESS_BUCKET'),
        'endpoint' => env('LIVEKIT_EGRESS_ENDPOINT'),
        'region' => env('LIVEKIT_EGRESS_REGION'),
        'key' => env('LIVEKIT_EGRESS_KEY'),
        'secret' => env('LIVEKIT_EGRESS_SECRET'),
    ],
],
```

**والأوّلان صفّان في `platform_settings`** يقرؤهما `SessionSettings` (‏`ticketTtlMinutes()`
و`maxParticipants()`)، وهذا الملفّ احتياطُ قاعدةٍ بلا بذور — نفس قاعدة كلّ رقمٍ تشغيليّ في
المنتج.

**والكتلة الثالثة `livekit` بيئةٌ محضة**، ولا تمرّ بـ`platform_settings`: مفتاحُ اعتمادٍ
يُحرَّر من لوحة الأدمن هو مفتاحٌ في قاعدة البيانات وفي كلّ نسخةٍ احتياطية منها.

---

## ج — أين يُسمح باسم المزوّد، وأين لا

`FR-002` يمنع ظهور اسم المزوّد أو مفاتيحه «‏خارج ذلك المجلّد». والقراءة الحرفية تتعارض مع
`FR-001` نفسه («‏يُختار بقيمة `sessions.provider = 'livekit'`»)، فالحدّ يُرسَم هنا صراحةً:

| المكان | مسموح؟ | لماذا |
|---|---|---|
| `Providers/LiveKitBroadcastProvider.php` | ✅ | **الملفّ الوحيد** الذي يستورد `Agence104\LiveKit\*` |
| ذراع `match` في `LiveSessionsServiceProvider` | ✅ | يوجبها `FR-001` — نقطة الانعكاس، سطرٌ واحد |
| `config/sessions.php` · `.env` | ✅ | نفس شكل `config/media.php` المشحون في ‎٠٠٤‎ |
| `BroadcastProviderContractTest` (‏الـdataset) | ✅ | يوجبها `FR-004` — التنفيذ يُحاسَب بالاسم |
| `frontend/package.json` + `BroadcastStage.tsx` | ✅ | مكتبة العميل. ونطاق `SC-005` مُعرَّف في §R12 |
| أيّ `Action` · `Model` · `Resource` · `Job` · `Policy` | 🚫 | يجعل المكتبة هي الواجهة، ويُسقط ما بُني ليجعل تبديل المزوّد ملفّاً واحداً |
| أيّ حمولة API | 🚫 | `SC-005` |

---

## د — `routes/console.php` (‏سطرٌ واحد)

```php
Schedule::job(new RetryPendingRecordingsJob, 'maintenance')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
```

الطابور `maintenance` و`withoutOverlapping()` هما ما يفرضه التعليق الموجود فوق كنس الفوترة
في الملفّ نفسه، والاتّساق معه مقصود. والدقائق الخمس عشرة هي إيقاع `ReleasePendingUnitsJob`
الذي يقرأ نتيجة هذه الوظيفة — أسرعُ منه إيقاعاً بلا فائدة، وأبطأُ منه يعني وحدةً محرَّرة
بعد ساعةٍ من وصول تسجيلها.

⚠️ **وطابورٌ مُعلَنٌ في `defaults` وحده هو طابورٌ تُدرَج فيه الوظائف ولا يُصرَّف أبداً**،
بصمت وبلوحةٍ لا تُظهر شيئاً خاطئاً. **مُتحقَّقٌ منه**: `supervisor-maintenance` قائم في
`config/horizon.php` في `defaults` و`environments` معاً (‏الأسطر ‎٢٦٠‎ · ‎٣٢٢‎ · ‎٣٤٠‎)،
فلا تغيير في هذا الملفّ.
