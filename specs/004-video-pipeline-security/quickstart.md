# Quickstart — التحقّق من خط أنابيب الفيديو والحماية

**Feature**: `004-video-pipeline-security`

دليل تشغيل يثبت أن الميزة تعمل من طرف إلى طرف. الكيانات في [data-model.md](./data-model.md)،
والعقود في [contracts/](./contracts/).

---

## المتطلّبات

```bash
cd backend
php artisan migrate:fresh --seed     # يشمل ترحيل lessons.media و users → student_profiles
php artisan storage:link             # مرة واحدة لكل نسخة
```

المزوّد الافتراضي `local`: **لا حساب خارجي ولا اتصال شبكي**. القيمة في
`config/media.php` عن `MEDIA_PROVIDER`.

```bash
cd frontend && npm run dev      # :3000 — يوجّه /api/* إلى :8000
```

> **للتشغيل القاطع لـPlaywright** (`npx playwright test` بالإعداد المُلتزَم) شغّل الـAPI
> بـ`PHP_CLI_SERVER_WORKERS=8 php artisan serve`. البناء يُقدّم صفحتَي التسجيل مسبقاً بنداءات
> API متوازية، وخادم PHP المدمج بخيط واحد يرفض الباقي ⇒ `ECONNREFUSED` وفشل **البناء** قبل
> أي اختبار.

> **تذكير**: **يُمنع** تشغيل `npm run build` وخادم `npm run dev` معاً — كلاهما يكتب في `.next/`.

---

## البوابات الأربع (SC-014)

```bash
cd backend && php vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse
cd ../frontend && npx tsc --noEmit
```

الأربع خضراء شرط قبول. **يُمنع** baseline جديد أو `@phpstan-ignore` لتمريرها.

---

## السيناريو ١ — العقد يُبدَّل بملف وسطر (FR-002 · SC-002)

```bash
php vendor/bin/pest tests/Feature/Media/ProviderContractTest.php
```

**المتوقّع**: كل تنفيذ مسجَّل يمرّ بنفس المجموعة — تذكرة بلا مفتاح، بيان ينتهي مع المنحة،
حذف عديم الأثر عند التكرار. و**إن أعلن تنفيذٌ `adaptiveBitrate`** ⇒ يُفرض عليه أن يقدّم
جودتين فأكثر فعلاً. هذا السطر هو ما يجعل تأجيل المزوّد آمناً.

```bash
php vendor/bin/pest tests/Feature/Media/ProviderAgnosticTest.php
```

يمسح `Actions/` و`Http/` و`frontend/src/` بحثاً عن أي اسم مزوّد ويفشل عند أي تطابق.

---

## السيناريو ٢ — الرابط لا يعمل خارج جلسته (SC-001 · SC-003 · SC-009)

```bash
php vendor/bin/pest tests/Feature/Media/PlaybackGrantTest.php
```

**المتوقّع**:
- طالب مسجَّل ⇒ منحة صالحة ⇒ `200` مع `Accept-Ranges: bytes`. (الطلب بلا ترويسة `Range`
  يعيد `200`؛ الـ`206` يظهر حين يطلب المتصفّح مدىً بعينه — والترويسة هي ما يجعل كل مقطع
  تالٍ رحلة جديدة عبر الحارس.)
- **نفس الرابط بعد انتهاء مدته** ⇒ `403`.
- **نفس الرابط بعد إنهاء الجلسة التي أصدرته** ⇒ `403` بلا انتظار انتهاء المدة (FR-009).
  الرابط غير مُصادَق عمداً — عنصر `<video>` لا يرسل ترويسة — فالحارس هو صفّ المنحة وحالة
  جلستها، لا هوية الطالب في الطلب.
- غير مسجَّل · انتهى تسجيله · مساحة عمل أخرى ⇒ `403` في الثلاث (SC-003).
- انتهاء الرابط **أثناء** المشاهدة ⇒ طلب المدى التالي `403`، والتجديد يعيده بلا انقطاع
  محسوس (SC-009 · FR-012).

**يدوياً**: افتح درساً على `http://localhost:3000/learn/…`، انسخ `manifest_url` من لسان
الشبكة، والصقه في نافذة خفية ⇒ لا يعمل.

**فحص FR-011**:

```bash
php vendor/bin/pest tests/Feature/Media/PlaybackGrantTest.php --filter="provider"
```

يمشي على كل حمولة تخصّ درساً ويفشل عند ظهور `provider` أو `provider_asset_id` أو مسار قرص.

---

## السيناريو ٣ — العلامة المائية والتوقّف عند إخفائها (SC-004 · SC-005)

```bash
php vendor/bin/pest tests/Feature/Media/WatermarkTest.php
```

**المتوقّع**: مشاهدان مختلفان ⇒ حمولتا علامة مختلفتان تحملان بيانات كلٍّ منهما (SC-004)،
والرقم **مقنَّع في الخادم** — الرقم الكامل لا يظهر في أي استجابة (FR-019 · research §R6).

**إخفاء العلامة** (SC-005):

```bash
cd frontend && npx playwright test e2e/player.spec.ts --config=e2e/playwright.local.config.ts
```

الحالة تحذف عنصر العلامة من DOM ثم تؤكّد **توقّف التشغيل فوراً** — يوقفه `MutationObserver`
داخل المكوّن. لكنّ الراحة لا الحارس: الحارس أن التجديد توقّف بتوقّف المكوّن الذي يستدعيه،
فتنتهي المنحة ويُرفض طلب المدى التالي. هذا النصف يُختبر في الخادم لا في المتصفّح
(`PlaybackGrantTest --filter="expired"`)، لأن انتظار انتهاء منحة في متصفّح اختبارٌ من خمس
دقائق يثبت الشيء نفسه.

> **يتخطّى نفسه بصمت** إن لم يوجد درس بفيديو `ready` — ولا يوجد في البيانات المبذورة، فلا
> ملف فيديو في المستودع. ارفع واحداً من صفحة إدارة الدرس أولاً وإلا لم يُنفَّذ شيء.

---

## السيناريو ٤ — جهاز واحد في الوقت الواحد (SC-006 · SC-006ج · FR-023)

```bash
php vendor/bin/pest tests/Feature/Auth/DeviceLimitTest.php
```

**المتوقّع**:
- **جهازان متتابعان** ⇒ جلسات الأول `ended` بسبب `device_limit` والجديدة تعمل وحدها — الدخول
  الجديد **لا يُرفض ولو مرة** (FR-023).
- **جلستان على الجهاز نفسه ⇒ تبقيان معاً** — العدّ على الأجهزة لا الجلسات (SC-006ج · FR-022ب).
- الحدّ يُقرأ من `platform_settings` لا من ملف الإعدادات: غيّر الصفّ إلى `2` ⇒ جهازان
  يبقيان بلا نشر (FR-022 · research §R16).
- رمز الجلسة المنتهية محذوف من `personal_access_tokens` ⇒ `401` على أي مسار.
- `SecurityAlert` وصل بجهاز الدخول الجديد ووقته (FR-025).

**السيناريو الذي سأل عنه المستخدم** — الطالب يشاهد ولا يفعل شيئاً:

```bash
php vendor/bin/pest tests/Feature/Media/PlaybackGrantTest.php --filter="session has ended"
```

الحالة تُصدر منحة، تسحب أول مقطع بنجاح، تُنهي الجلسة بسبب `device_limit`، ثم تؤكّد أن **طلب
المدى التالي `403`** — بلا أي فعل من المشاهد ولا انتظار انتهاء المدة (research §R15).

**يدوياً**: افتح درساً وشغّله، ثم سجّل الدخول بنفس الحساب من متصفّح **آخر** ⇒ الفيديو في
الأول يتوقّف وتظهر رسالة الخروج خلال ٦٠ ثانية على الأكثر. (نافذة خفية في نفس المتصفّح قد
تُحتسب جهازاً آخر لأن `localStorage` معزول فيها — وهو سلوك متوقّع.)

**قائمة الأجهزة**: `http://localhost:3000/settings/security` — إنهاء أي جلسة يدوياً (FR-024).

---

## السيناريو ٥ — التحقق الثنائي (SC-007)

```bash
php vendor/bin/pest tests/Feature/Auth/TwoFactorTest.php
```

**المتوقّع**:
- حساب مفعَّل ⇒ الدخول بكلمة المرور وحدها يعيد `{two_factor: true, challenge}` **بلا رمز**
  (SC-007 — صفر دخول ناجح بكلمة المرور وحدها).
- رمز استرداد يعمل **مرة واحدة**، ويُبطَل، ويُسجَّل، ويُطلق `SecurityAlert` (FR-029).
- تغيير كلمة المرور أو وسيلة التحقق ⇒ **إنهاء بقية الجلسات** + تنبيه (FR-031).
- بعد انقضاء المهلة بلا تفعيل ⇒ `403` على مسار حسّاس برمز `two_factor_required` (FR-028).

**السرّ الواحد للسطحين**: فعّل التحقق من `http://localhost:3000/settings/security`، ثم ادخل
على `http://localhost:8000/admin` ⇒ **نفس التطبيق المصادق يعمل** بلا تسجيل ثانٍ (research §R9).

> **تذكير محلي**: `SESSION_DOMAIN` في `backend/.env` يجب أن يطابق المضيف الذي تتصفّحه.
> كوكي على `.localhost` لا يُرسَل أبداً إلى `127.0.0.1`، والنتيجة `419` صامتة عند الدخول.

---

## السيناريو ٦ — الرفع ودورة حياة الأصل (SC-010 · SC-012)

```bash
php vendor/bin/pest tests/Feature/Media/UploadLifecycleTest.php
```

**المتوقّع**:
- ملف بامتداد فيديو وليس فيديو ⇒ **يُرفَض** — النوع من محتوى الملف لا من العميل (FR-003).
- تجاوز الحجم أو المدة ⇒ `422` **قبل** استهلاك الرفع كاملاً.
- رفع مقاطَع في منتصفه ⇒ الأصل `failed` والدرس **لا** يقبل تشغيلاً (SC-010 · FR-007).
- المزوّد متوقّف ⇒ `status()` يعيد `failed` بلا استثناء، والأصول الجاهزة تبقى قابلة للتشغيل.

**النصّ المصاحب** (SC-012):

```bash
php vendor/bin/pest tests/Feature/Media/CaptionsTest.php
```

ارفع WebVTT ⇒ يظهر في المشغّل ويُشغَّل ويُخفى بطلب المشاهد، والنصّ الكامل معروض قابلاً
للبحث ومرتبطاً بمواضعه الزمنية (FR-034) — **مشتقّاً من نفس الملف** لا مخزَّناً مرتين.

**يدوياً**: `http://localhost:3000/manage/courses/{uuid}/lessons/{lessonUuid}`.

---

## السيناريو ٧ — الترحيل بلا فقد (FR-006 · research §R13 · §R17)

```bash
php vendor/bin/pest tests/Feature/Media/MediaMigrationTest.php
php vendor/bin/pest tests/Feature/Identity/StudentProfileMigrationTest.php
```

**المتوقّع**:
- كل `lessons.media` غير فارغ ⇒ أصل `ready` — و**المشوّه ⇒ `failed` لا مفقود**. صفر فقد.
- كل `users.grade_level_slug` / `registered_by_parent` ⇒ صفّ في `student_profiles`، والعمودان
  محذوفان من `users` بعدها.
- `parent_student_relations.student_grade_level_slug` **بلا مساس** — لقطة تاريخية.

---

## السيناريو ٨ — الملكية والعزل والأداء (NFR-001ب · SC-011 · SC-013)

```bash
php vendor/bin/pest tests/Feature/Auth/PlatformOwnershipTest.php
php vendor/bin/pest tests/Feature/Tenancy/WorkspaceIsolationTest.php
```

**المتوقّع**:
- مدرّس **لا** يقرأ أجهزة طالبه ولا جلساته **ولو كان الطالب مسجَّلاً عنده** — الجهاز ليس
  معلومة تعليمية (research §R12).
- الطالب المسجَّل عند ثلاثة مدرّسين يرى **قائمة أجهزة واحدة** لا ثلاث نسخ (NFR-001ب).
- `MediaAsset` لا يُقرأ من مساحة عمل أخرى.

**الأداء** (SC-011):

```bash
php vendor/bin/pest tests/Feature/Media/PlaybackGrantTest.php --filter="without a query per lesson"
```

إصدار منح لقائمة ٥٠ درساً بعدد استعلامات **ثابت** — الأهلية تُقرأ مرة عبر
`EnrollmentDirectory::activeCourseIdsFor()` ثم تُرشَّح في الذاكرة (research §R14).

**إمكانية الوصول** (SC-013):

```bash
cd frontend && npx playwright test e2e/accessibility.spec.ts --config=e2e/playwright.local.config.ts
```

صفر انتهاك على كل صفحة في مصفوفة `PAGES`، وفيها `/settings/security` منذ هذه المرحلة.

**المشغّل وصفحة إدارة الدرس مساراهما يحتاجان `uuid` حقيقياً**، فلا يدخلان مصفوفة ثابتة:
فحص المشغّل يعيش في `e2e/player.spec.ts` خلف نفس التخطّي (يحتاج فيديو `ready`)، وصفحة إدارة
الدرس **بلا فحص آلي حتى الآن** — نقص معلوم لا مُغطّى.

قابلية التشغيل بلوحة المفاتيح مجانية: ضوابط المتصفّح الأصلية لا مشغّل مخصّص (research §R11).

---

## تنظيف

```bash
cd backend && php artisan migrate:fresh --seed
```
