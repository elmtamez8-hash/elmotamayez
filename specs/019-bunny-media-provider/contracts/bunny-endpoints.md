# واجهةُ باني — ما يعرفه ملفٌّ واحد

**Date**: 2026-08-17 · **Research**: [../research.md](../research.md)

> ⚠️ **هذا الملفّ توثيقٌ لا عقد.** العقدُ هو
> [media-provider.md](./media-provider.md)؛ وما هنا تفاصيلُ **تنفيذٍ** يعرفها
> `BunnyMediaProvider` وحده و`SC-005` يحرس ذلك. وُضع في مكانٍ واحد كي لا يُبحَث عنه في
> التوثيق مرّةً أخرى، ولأن **مرجعَ هذه المرحلة توثيقُ باني بطلبٍ صريح**.
>
> كلُّ سطرٍ هنا مذيَّلٌ بمصدره. وما لم أجده مكتوبٌ في §«ما لم أجده» من `research.md`.

---

## ١ · التسليم — نداءٌ واحد، لا اثنان

```
POST https://video.bunnycdn.com/library/{libraryId}/videos/fetch
AccessKey: {مفتاح المكتبة}
Content-Type: application/json

{ "url": "{رابط R2 موقّع}", "headers": { … }, "title": "mteatch:{asset_uuid}" }
```

**الردّ** — ‏`{ "success": bool, "message": string, "statusCode": int }`

⚠️ **بلا `guid`.** والصفحةُ السرديّة (`docs.bunny.net/stream/http-api`) تُظهر `{id, guid, status}`
و**هي الشاذّة**: مواصفةُ OpenAPI هي عقدُ الواجهة، وردُّها `StatusModel`، و`operationId` اسمُه
`Video_FetchNewVideo`، وجسمُ الطلب لا يقبل `videoId`.
📎 `docs.bunny.net/api-reference/stream/manage-videos/fetch-video`

**ولا يوجد «‏أنشئ ثمّ اسحب إليه»**: `POST /library/{id}/videos` يقبل `title` و`collectionId`
و`thumbnailTime` — **ولا يقبل `url`**. 📎 `docs.bunny.net/stream/http-api`

**فمعرّفُ الفيديو يُستردّ بالعنوان** (§R3 · §R4 من البحث).

### الأخطاء التي لها معنىً مختلف

| الرمز | المعنى | ما يُفعل |
|---|---|---|
| `429` | تجاوزُ حدّ نداءات السحب — **موثَّقٌ بلا رقم** | يبقى `pending`؛ تُعيد الحلقةُ القائمة المحاولة (`FR-008`) |
| `422` | تعذّر السحبُ من المصدر — رابطٌ انتهى مثلاً | إعادةُ محاولةٍ **برابطٍ موقّعٍ جديد**، لا بالقديم |
| `400` | الرابط مرفوض شكلاً | فشلٌ حقيقيّ لا يُعاد |
| `401` · `403` | مفتاحٌ خاطئ | فشلُ تهيئةٍ يُكشَف عند الإقلاع لا عند أوّل حصّة |

⚠️ **و`fetch` يُنشئ فيديو جديداً في كلّ نداء.** فإعادةُ محاولةٍ بعد `429` قد تُنتج اثنين
لحصّةٍ واحدة، وواحدٌ يُدفع ثمنه ولا يشير إليه شيء. **والعنوانُ الفريد هو ما يكشفه.**

---

## ٢ · الحالة والحذف

```
GET    /library/{libraryId}/videos/{videoId}
DELETE /library/{libraryId}/videos/{videoId}
```

`AccessKey` في الرأس (‏`securitySchemes: AccessKey … in: header`).
📎 `docs.bunny.net/api-reference/stream/manage-videos/*`

---

## ٣ · التشغيل — ⚠️ **رمزُ مسار، وهذا هو الفرق كلُّه**

**الفهرس**: `https://{pull_zone}.b-cdn.net/{video_id}/playlist.m3u8`
📎 `docs.bunny.net/stream/storage-structure`

**التوقيع**: `?token=…&expires=…&token_path=…` بمفتاح `ZoneSecurityKey`
📎 `docs.bunny.net/cdn/security/token-authentication/advanced`

📎 **والسطرُ الحاسم**: «‏للبثّ عبر HLS، رموزُ المسار ضرورية لضمان حماية ملفّات `.ts` مع قائمة
التشغيل» — `docs.bunny.net/stream/security-options`

> ⚠️ **بلا `token_path`**: الفهرسُ محميّ وكلُّ قطعةٍ مكشوفة. فمن أخذ عنواناً واحداً أخذ الفيديو
> كلَّه — بلا منحةٍ ولا علامةٍ مائية ولا حدِّ أجهزة. **وهذا يمرّ بأي اختبارٍ يطلب الفهرس وحده.**

### ما لا يُستعمل، ولماذا يُذكر

| الخيار | القرار |
|---|---|
| **رمزُ التضمين** (`AccessKey` + `videoId` + `expires`) | **لا** — مسارُ الـiframe، ونحن نُشغّل في مشغّلنا لأجل العلامة المائية والنصّ. ⚠️ **وهذا ما وصفه بحثُ ٠١٧ §R15‑أ توقيعاً**، وقد صُحّح في `Q3` |
| `ZoneSecurityIncludeHashRemoteIP` | **لا** — هاتفٌ ينتقل من الواي‑فاي إلى بيانات الشبكة ينقطع درسُه. وحدُّ الأجهزة يحلّ المشكلة نفسها ببصمةٍ لا تتغيّر بالشبكة |
| واجهةُ الترجمات (`POST/DELETE …/captions/{srclang}`) | **لا عند الإطلاق** (`Q5`) — موجودةٌ وكاملة، ولا تُستعمل لأن `<track>` لا يُرسل `Authorization`. 📎 `docs.bunny.net/api-reference/stream/manage-videos/add-caption` |
| رفعٌ مباشر (`PUT` · `tusupload`) | **لا لتسجيلات الحصص** (`FR-005`) — جيجابايتٌ لكلّ حصّة عبر عاملنا. وهو ما يقيسه `SC-001` |

---

## ٤ · تدويرُ المفتاح — حقلٌ واحد، ولا نافذة

```
POST /pullzone/{id}/resetSecurityKey   → 204 No Content
```

📎 `docs.bunny.net/api-reference/core/pull-zone/reset-token-key`

و`ZoneSecurityKey` **حقلٌ نصّيٌّ واحد** على منطقة السحب — لا حقلَ لمفتاحٍ سابق ولا قائمةَ
مفاتيح. 📎 `docs.bunny.net/api-reference/core/pull-zone/get-pull-zone`

**فنافذةُ المفتاحين غير موجودة** (`Q6`)، والتدويرُ يُبطل كلّ رمزٍ سارٍ. وحلقةُ التجديد من ٠٠٤
هي ما يجعل ذلك تعثُّرَ قطعةٍ لا انتهاءَ مشاهدة (`FR-021ب` · `SC-012`).

📎 وتهديدُ التسرّب بنصّ التوثيق: «‏من الحاسم إبقاء مفتاح الأمان سرّياً، فالوصول غير المصرَّح به
يسمح بتوليد رموزٍ صالحة» — `docs.bunny.net/cdn/security/token-authentication`

---

## ٥ · المفاتيح الأربعة ومواضعها (`Q11` · `FR-020أ`)

| المفتاح | لماذا | الموضع |
|---|---|---|
| `AccessKey` للمكتبة | إدارة: سحبٌ وحالةٌ وحذف | **البيئة** |
| `ZoneSecurityKey` | توقيعُ عنوان التشغيل | **البيئة** |
| مفتاحا R2 | توقيعُ رابط المصدر | **البيئة** |
| حدودُ الحجم والمدّة | تُعلَن في `capabilities()` | `platform_settings` · احتياطيٌّ في `config/media.php` |

⚠️ **ولا سرَّ في `platform_settings`**: صفٌّ في قاعدة البيانات يُقرأ من لوحة الإدارة — أي
توسيعُ الوصول من «‏من يدير الخادم» إلى «‏من يفتح اللوحة»، لمفتاحٍ يفتح تسرُّبُه المكتبةَ كلَّها.
