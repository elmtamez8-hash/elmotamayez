# Contract: نقاط النهاية

**Feature**: `004-video-pipeline-security` | **Base**: `/api/v1`

المفاتيح المكشوفة `uuid` حصراً (الدستور VI). كل استجابة عبر API Resource.
**يُمنع** ظهور `provider` أو `provider_asset_id` أو أي رابط مصدر دائم في أي حمولة (FR-011).

---

## الأصول — `Modules/Media/routes/api.php`

### `POST /lessons/{lesson}/assets`

يطلب تذكرة رفع. `LESSONS_MANAGE` + `MediaAssetPolicy::create`.

```json
{ "original_filename": "درس-١.mp4", "size_bytes": 734003200, "duration_seconds": 2400 }
```

```json
{
  "asset": { "uuid": "…", "status": "pending", "original_filename": "درس-١.mp4" },
  "upload": { "url": "/api/v1/media/upload/…", "method": "PUT", "headers": {}, "expires_at": "…" }
}
```

`422` قبل استهلاك الرفع إن تجاوز الحجم أو المدة الحدّ (FR-003 · Edge Case «حدّ أقصى»).
الفحص على البيان المُعلَن أولاً، ثم على الملف فعلاً عند الإتمام — المُعلَن يوفّر رفع
جيجابايت سيُرفض.

### `PUT /media/upload/{token}` — المزوّد المحلّي فقط

مسار تذكرة `LocalVideoProvider`. الرمز يخصّ أصلاً واحداً وينتهي بساعة. مزوّد تجاري يجعل
التذكرة تشير إلى عنوانه، **فلا يتغيّر سطر في العميل**.

### `POST /media/assets/{asset}/complete`

يُثبّت النوع من **محتوى** الملف لا من العميل، ويستدعي `status()` من المزوّد.

```json
{ "uuid": "…", "status": "processing", "duration_seconds": 2400 }
```

الفشل ⇒ `status: "failed"` مع `failure_reason` عربي، والدرس **لا** يبقى في حالة تشغيل غير
صالحة (FR-007 · SC-010).

### `GET /media/assets/{asset}` · `DELETE /media/assets/{asset}`

قراءة الحالة للمالك · حذف عند المزوّد وعندنا. الحذف عديم الأثر عند التكرار.

### `POST /media/assets/{asset}/captions` · `DELETE /media/captions/{caption}`

رفع WebVTT (FR-032). `422` لملف غير صالح البنية.

---

## التشغيل

### `POST /lessons/{lesson}/playback`

**قلب المرحلة.** `IssuePlaybackGrant` — الحارس في الـ Action لا في `FormRequest`.

```json
{
  "grant": "…uuid…",
  "expires_at": "2026-08-05T12:05:00+03:00",
  "manifest_url": "/api/v1/playback/…uuid…/stream",
  "format": "progressive",
  "watermark": { "name": "أحمد م.", "phone_masked": "…٥٦٧٨" },
  "captions": [{ "uuid": "…", "language": "ar", "label": "العربية", "url": "…", "is_default": true }],
  "resume_at_seconds": 412,
  "renditions": []
}
```

| الحالة | متى |
|---|---|
| `403` | لا تسجيل نشط، ولا درس معاينة، ولا ملكية مساحة العمل (FR-010) |
| `404` | الدرس غير موجود — **وأيضاً** إن لم يملك الطالب حق رؤيته أصلاً: وجود الدرس نفسه معلومة (Acceptance 1.4) |
| `409` | الأصل ليس `ready` — الجسم يحمل `status` ليعرض العميل «قيد التجهيز» (Edge Case) |
| `429` | `throttle:playback` — محدّد **مسمّى** (FR-014) |

**يُمنع** الحدّ السطري `throttle:5,1`: مفتاحه `domain|ip` بلا مسار، فيتشارك عدّاداً واحداً
مع تسجيل الدخول والتصفّح العام — العطل الموثّق في `AppServiceProvider::registerRateLimiters()`.

### `GET /playback/{grant}/stream`

**بلا مصادقة Bearer** — عنصر `<video>` لا يرسل ترويسة `Authorization`. الحارس هو صفّ المنحة،
ويُفحص عند **كل طلب مدى** لا مرة واحدة:

```
المنحة موجودة ∧ غير ملغاة ∧ expires_at > now ∧ AuthSession نشطة ∧ الأصل ready
```

يعيد `206 Partial Content` مع `Accept-Ranges: bytes` (محلياً) أو `302` إلى رابط المزوّد
الموقّع. فشل الحارس ⇒ `403` **بلا كشف** اسم الملف أو مصدره.

> إعادة الفحص عند كل مدى هي ما يجعل الفيديو يتوقّف فعلاً حين تنتهي جلسة المشاهد — لا عند
> فتحه فقط (research §R5 · §R15).

### `POST /playback/{grant}/renew`

يستدعيه **مكوّن العلامة المائية** كل ٦٠ ثانية. مصادَق بـ Sanctum.

```json
{ "position_seconds": 427 }
```

```json
{ "expires_at": "…", "manifest_url": "…" }
```

ثلاث وظائف في نداء واحد: تمديد المنحة · حفظ موضع المشاهدة (FR-036) · **اكتشاف انتهاء
الجلسة** (`401` ⇒ الخروج بالسبب). مسار ثالث بنفس الوتيرة يضاعف الحمل مقابل لا شيء.

`403` بعد تجاوز سقف التجديدات — يحدّ جلسة مشاهدة لا نهائية.

**العلامة المائية هي الحلقة**: إزالتها من DOM توقف النداء، فتنتهي المنحة، فيُرفض المدى
التالي (FR-018 · SC-005).

---

## الأجهزة والجلسات — `Modules/Identity/routes/api.php`

### `GET /auth/sessions`

```json
{
  "data": [{
    "uuid": "…", "status": "active", "is_current": true,
    "device": { "uuid": "…", "label": "Chrome على ويندوز" },
    "last_active_at": "…", "created_at": "…"
  }]
}
```

**الحارس**: `user_id === $user->id`. **يُمنع** على المدرّس مطلقاً، ولو كان الطالب مسجَّلاً
عنده — جهاز الطالب ليس معلومة تعليمية (research §R12).

### `DELETE /auth/sessions/{uuid}`

إنهاء يدوي (FR-024). إنهاء الجلسة الحالية = خروج.

### `GET /auth/sessions/{uuid}/end-reason` — **بلا مصادقة**

```json
{ "reason": "device_limit", "ended_at": "…" }
```

حقلان لا ثالث: لا اسم ولا جهاز ولا عنوان شبكة. يجيب على «لماذا خرجت؟» بعد أن مات الرمز،
فالمصادقة مستحيلة بحكم التعريف. `uuid` غير قابل للتخمين ويملكه العميل من لحظة الدخول.
محدود بـ`throttle:public`.

### الدخول — تعديل على `POST /auth/login`

يقبل ترويسة `X-Device-Id` (قيمة عشوائية يولّدها العميل ويحفظها). غيابها ⇒ جهاز جديد.

```json
{ "user": {…}, "token": "…", "session_uuid": "…" }
```

`session_uuid` يحفظه العميل لسؤال `end-reason` لاحقاً.

تجاوز الحدّ ⇒ إنهاء جلسات **أقدم جهاز** كلها معاً + `SecurityAlert` **إلزامي** إن كانت نشطة
حديثاً (FR-025) — النوع قائم في spec 003 ولا يمكن إيقافه ولا تأجيله. الحدّ الافتراضي
**جهاز واحد**، والعدّ على `device_id` المتمايزة لا على الجلسات (FR-022ب).

**حساب مفعَّل عليه التحقق الثنائي لا يُصدر رمزاً**:

```json
{ "two_factor": true, "challenge": "…uuid…" }
```

---

## التحقق الثنائي

| المسار | ما يفعل |
|---|---|
| `POST /auth/2fa/setup` | يولّد سرّاً ويعيد `otpauth://` — **يتطلّب كلمة المرور الحالية** |
| `POST /auth/2fa/confirm` | `{code}` ⇒ يضبط `two_factor_confirmed_at` ويعيد رموز الاسترداد **مرة واحدة** |
| `DELETE /auth/2fa` | يتطلّب رمزاً حالياً + كلمة المرور |
| `POST /auth/2fa/recovery-codes` | توليد جديد يُبطل القديم |
| `POST /auth/2fa/challenge` | `{challenge, code}` أو `{challenge, recovery_code}` ⇒ رمز Sanctum |

`GET /auth/2fa` يعيد `{ enabled, confirmed_at, required_at, recovery_codes_remaining }` —
**بلا** السرّ وبلا الرموز.

كلها خلف `throttle:two-factor` (محدّد مسمّى جديد). التحدّي يعيش عشر دقائق في الذاكرة
المؤقّتة لا في جدول (research §R10).

**سرّ واحد للسطحين**: نفس صفّ `user_security_settings` يخدم `/admin` (عبر عقد Filament)
والـ API. المدرّس يسجّل مرة ويدخل بها في الاثنين.

### العمليات الحسّاسة (FR-028)

وسيط `2fa.required` يُطبَّق **صراحةً** على مسارات مسمّاة — لا قائمة عامة تنمو بالنسيان:
اعتماد الدفع ورفضه · إدارة الأعضاء · إعدادات مساحة العمل · اعتماد المدرّسين · حذف الأصول.

بعد انقضاء `two_factor_required_at` بلا تفعيل ⇒ `403` برمز `two_factor_required` ورسالة
عربية تشرح ما يجب فعله. **قبل** الانقضاء يمرّ ويظهر تنبيه فقط.

---

## ملف الطالب وإعدادات المنصة

### `GET /auth/me` — تعديل

`grade_level_slug` و`registered_by_parent` يخرجان من جذر `UserResource` إلى كائن
`student_profile` (يظهر لمن له ملف طالب فقط):

```json
{ "uuid": "…", "name": "…", "phone": "…", "student_profile": { "grade_level_slug": "secondary" } }
```

تغيير كاسر موثَّق — راجع `plan.md` (research §R17).

### إعدادات المنصة — **صفحة Filament لا API**

مستهلكها الوحيد شاشة مدير المنصة، وبناء API لها يضاعف سطح التفويض بلا مستهلك ثانٍ.
محميّة بـ`users.is_super_admin` حصراً.

| المفتاح | القيمة | يخدم |
|---|---|---|
| `auth.device_limits` | `{"student": 1}` — **جهاز واحد**، والعدّ على الأجهزة لا الجلسات | FR-022 · FR-022ب · FR-023 |
| `media.max_size_bytes` · `media.max_duration_seconds` | عدد | FR-003 |
| `media.grant_ttl_seconds` · `media.max_renewals` | عدد | FR-008 · FR-012 |
| `auth.two_factor_grace_days` | عدد | FR-028 |

---

## أخطاء موحّدة

| الحالة | متى | نصّ الواجهة |
|---|---|---|
| `401` | الجلسة أُنهيت أو انتهت | يُمسح الرمز ويُحوَّل إلى الدخول بالسبب من `end-reason` |
| `403` | حارس المنحة · تحقق ثنائي مطلوب · ملكية | «لا تملك صلاحية لهذا الإجراء.» |
| `404` | درس أو أصل ليس للمستخدم | «العنصر المطلوب غير موجود أو حُذف.» |
| `409` | الأصل ليس `ready` | «الفيديو قيد التجهيز، حاول بعد قليل.» |
| `422` | ملف غير صالح · تجاوز حدّ · رمز خاطئ | عربي من `lang/ar/` تحت حقله |
| `429` | تجاوز محدّد المعدّل | «محاولات كثيرة في وقت قصير…» |

كل حقل `FormRequest` جديد **يجب** أن يُضاف إلى `attributes` في
`backend/lang/ar/validation.php` وإلا ظهر باسمه البرمجي للمستخدم.

---

## المحدّدات المسمّاة الجديدة

```php
RateLimiter::for('playback', …);      // إصدار منحة — لكل مستخدم لا لكل IP
RateLimiter::for('two-factor', …);    // التحدّي والتفعيل — لكل حساب ولكل IP
```

**يُمنع** أي حدّ سطري (`throttle:5,1`) — الأثر موثّق في `AppServiceProvider`.
