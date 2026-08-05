# Data Model — خط أنابيب الفيديو وحماية المحتوى

**Feature**: `004-video-pipeline-security` | **Date**: 2026-08-05

ستة جداول جديدة، وعمودان على `users`، وعمود واحد على `lesson_progress`، وحذف
`lessons.media`. الطبقة مذكورة لكل جدول — **قرار مُلزِم** بحكم الدستور I.

---

## ١. `media_assets` — **مملوك لمساحة العمل**

الأصل المرئي بمعرّفه لدى المزوّد. يستبدل `lessons.media` (FR-006).

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` | bigint PK | |
| `uuid` | uuid unique | المفتاح المكشوف — الدستور VI |
| `workspace_id` | bigint index | `BelongsToWorkspace` |
| `owner_type` · `owner_id` | morph | `Lesson` اليوم، `ClassSession` في 005 |
| `provider` | string(32) | `local` اليوم. **يُمنع** ظهوره في أي حمولة |
| `provider_asset_id` | string(191) nullable | معرّف الأصل لدى المزوّد. **يُمنع** كشفه (FR-011) |
| `status` | string(24) | `MediaAssetStatus` |
| `original_filename` | string | للعرض للمالك |
| `mime_type` | string(96) nullable | يُثبَّت بعد التحقّق لا من العميل |
| `size_bytes` | unsignedBigInteger nullable | `unsignedInteger` يفيض عند ٤ جيجابايت — راجع تحذير SQLite في الدستور |
| `duration_seconds` | unsignedInteger nullable | يملؤه المزوّد بعد المعالجة |
| `renditions` | json nullable | ما يعلنه المزوّد فعلاً؛ فارغ للمحلّي |
| `failure_reason` | string nullable | يُعرض للمالك لا للمشاهد |
| `ready_at` · `created_at` · `updated_at` | timestamps | |

**فهارس**: `(workspace_id, owner_type, owner_id)` · `(status, updated_at)` — الثاني يخدم
`ReconcileAssetStatus` فلا يمسح الجدول كله.

**قواعد**:
- التحقّق من الحجم والمدة والنوع **في `CompleteMediaUpload`** لا في `FormRequest` وحده
  (الدستور II) — الحدود في `config/media.php`.
- `mime_type` يُقرأ من محتوى الملف لا من العميل (FR-003 · Edge Case «امتداد فيديو وليس فيديو»).
- **يُمنع** أن يظهر `provider` أو `provider_asset_id` في أي `Resource`.

### حالات `MediaAssetStatus` (FR-005)

```
pending ──► uploading ──► processing ──► ready
   │            │              │
   └────────────┴──────────────┴──────► failed
                                          │
                                    (رفع جديد) ──► pending
```

- `pending`: صدرت تذكرة رفع ولم يبدأ.
- `uploading`: بدأ ولم يكتمل.
- `processing`: وصل المزوّد ويُعالَج — الدرس يظهر «قيد التجهيز» ولا يفشل التشغيل بخطأ.
- `ready`: قابل للتشغيل. **الحالة الوحيدة التي تُصدر منحة** (FR-007 · SC-010).
- `failed`: مع `failure_reason`.

---

## ٢. `media_captions` — **مملوك لمساحة العمل**

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | |
| `workspace_id` | bigint index | |
| `media_asset_id` | bigint index | حذف متتالٍ مع الأصل |
| `language` | string(8) | `ar` افتراضاً |
| `kind` | string(16) | `captions` · `subtitles` |
| `source` | string(16) | `manual` · `auto` — `auto` قدرة مزوّد غير مُنفَّذة اليوم |
| `storage_path` | string | ملف WebVTT على القرص الخاص |
| `is_default` | boolean | واحد افتراضي لكل لغة |
| `created_at` · `updated_at` | | |

**فهرس فريد**: `(media_asset_id, language, kind)`.

**النصّ الكامل (FR-034)** يُشتقّ من مقاطع WebVTT عند العرض — **لا عمود ولا جدول له**.
تخزينه مرتين يُنتج نسختين تتباعدان عند أول تحرير للترجمة.

---

## ٣. `playback_grants` — **جسر**

إذن تشغيل مؤقّت يربط مشاهداً بأصل بجلسة (FR-008 · FR-009).

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | `uuid` هو **الرمز في المسار** |
| `workspace_id` | bigint index | للسياق — **لا** `BelongsToWorkspace` (يُصدَر ويُستهلك بلا سياق مساحة) |
| `media_asset_id` | bigint index | |
| `user_id` | bigint index | المشاهد — مستخدم المنصة |
| `auth_session_id` | bigint index | **الربط بالجلسة**. انتهاؤها يقتل المنحة |
| `expires_at` | timestamp index | يُمدَّد بالتجديد |
| `revoked_at` | timestamp nullable | إلغاء صريح |
| `renewed_count` | unsignedSmallInteger | سقفه في الإعدادات — يحدّ جلسة مشاهدة لا نهائية |
| `issued_ip_hash` | string(64) nullable | للتدقيق فقط. **لا يُفرَض**: شبكة الهاتف تغيّر العنوان أثناء المشاهدة |
| `last_seen_at` | timestamp nullable | آخر طلب مدى |
| `created_at` | timestamp | |

**فهرس**: `(user_id, media_asset_id, expires_at)`.

**حارس التشغيل** — يُفحص عند **كل** طلب مدى لا مرة واحدة:

```
المنحة موجودة  ∧  revoked_at = null  ∧  expires_at > now
              ∧  AuthSession.status = active
              ∧  MediaAsset.status = ready
```

فشل أيّ منها ⇒ `403` بلا كشف أي شيء عن الأصل.

**التنظيف**: `ponytail:` المنح المنتهية تُحذف بأمر مجدول يومي بسيط. لا تقسيم ولا أرشفة —
يُراجَع إن تجاوز الجدول ملايين الصفوف.

---

## ٤. `devices` — **مملوك للمنصة**

> **يُمنع** `BelongsToWorkspace`. حدّ الجهازين على حساب الطالب كله؛ نسخة لكل مدرّس تعني
> جهازين × عدد المدرّسين — أي لا حدّ أصلاً. `docs/roadmap.md` §٥ج يسمّي `Device` صراحةً
> في طبقة المنصة.

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | |
| `user_id` | bigint index | |
| `fingerprint_hash` | string(64) | `sha256` — **لا** يُخزَّن الخام |
| `label` | string | «Chrome على ويندوز» — مقروء للمستخدم |
| `last_seen_at` | timestamp | |
| `created_at` · `updated_at` | | |

**فهرس فريد**: `(user_id, fingerprint_hash)` — البصمة نفسها لمستخدمين مختلفين جهازان
مختلفان، وهو المطلوب.

---

## ٥. `auth_sessions` — **مملوك للمنصة**

> الاسم `AuthSession` مقصود: يتفادى التصادم مع «الحصة» (`ClassSession` في 005) ومع جلسة
> الإطار المدمجة. **يُمنع** تسمية أيٍّ منهما `Session` مجرّداً.

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` · `uuid` | | `uuid` يحفظه العميل ليسأل عن سبب الإنهاء (research §R8) |
| `user_id` · `device_id` | bigint index | |
| `token_id` | bigint nullable index | `personal_access_tokens.id`. `null` لجلسة لوحة Filament |
| `status` | string(16) | `active` · `ended` |
| `ended_reason` | string(32) nullable | `SessionEndReason` |
| `ip_hash` | string(64) nullable | تدقيق |
| `last_active_at` · `ended_at` | timestamp nullable | |
| `created_at` · `updated_at` | | |

**فهرس**: `(user_id, status, created_at)` — يخدم «أقدم جلسة نشطة» في استعلام واحد.

### `SessionEndReason`

`logout` · `device_limit` · `password_change` · `two_factor_change` · `manual` · `expired`

**سجلّ الأحداث (FR-026)**: الصفّ **يبقى** بعد الإنهاء ولا يُحذف — هو نفسه السجلّ.
جدول أحداث ثانٍ يكرّر ما فيه.

### الحدّ (FR-022 · FR-023)

`PlatformSettings::get('auth.device_limits')` = `['student' => 1]` مفتاحه `platform_role`
— **صفّ في `platform_settings` يحرّره مدير المنصة**، لا قيمة في ملف إعدادات
(research §R16). `config('media.device_limits')` يبقى الافتراضي عند غياب الصفّ.

**الوحدة المعدودة هي الجهاز لا الجلسة** (FR-022ب · research §R7): جلستان على `device_id`
واحد = **جهاز واحد**، ولا تُنهيان بعضهما. طالب يعيد الدخول على حاسوبه نفسه لا يُطرَد منه.

غير المذكور بلا حدّ: مدرّس يفتح لوحة وجهازين وهاتفاً عمل مشروع، وحدّه يعطّل عمله لا يحميه.

**الخوارزمية** عند الدخول:

```
١. حُلَّ الجهاز من البصمة (موجود ⇒ أعِد استعماله)
٢. أنشئ الجلسة الجديدة  ← أولاً دائماً: FR-023 يمنع رفض الدخول الجديد
٣. احسب device_id المتمايزة بين الجلسات النشطة
٤. ما دام العدد > الحدّ: أنهِ جلسات أقدم جهاز — كلها معاً، الجهاز يُطرَد لا جلسة منه
٥. أرسل التنبيه فقط إن كانت الجلسة المنتهية نشطة حديثاً (FR-025)
```

الخطوة ٥ ليست تفصيلاً: بحدّ جهاز واحد يقع الإنهاء عند كل تنقّل عادي بين هاتف وحاسوب،
وتنبيه يصل كل يوم يُدرَّب المستخدم على تجاهله فيضيع حين يقع الاختراق فعلاً.

**الإنهاء التلقائي بلا فعل من صاحب الجلسة** (research §R15): حذف رمز Sanctum يقع لحظياً
في الخادم. يكتشفه العميل بثلاث طبقات قائمة أصلاً — طلب المدى في المشغّل (فوري)، حلقة
العلامة المائية (٦٠ ثانية)، نبض جرس الإشعارات (٦٠ ثانية على كل صفحة). **لا وسيط جديد
ولا مسار نبض جديد.**

---

## ٦. `user_security_settings` — **مملوك للمنصة**

> **جدول مستقلّ لا أعمدة على `users`** (research §R17). أربعة أعمدة `null` لكل مستخدم لم
> يفعّل التحقق الثنائي — وهم الأغلبية — تُقرأ في كل طلب مصادَق عليه بلا معنى.

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint unique | صفّ واحد لكل مستخدم، يُنشأ عند أول تفعيل لا عند التسجيل |
| `app_authentication_secret` | text nullable | مشفَّر (`encrypted`) |
| `app_authentication_recovery_codes` | text nullable | مشفَّر مصفوفةً (`encrypted:array`) |
| `two_factor_confirmed_at` | timestamp nullable | **مفعَّل فعلاً** — سرّ بلا تأكيد لا يحمي |
| `two_factor_required_at` | timestamp nullable | مهلة FR-028. تجاوزها ⇒ حجب العمليات الحسّاسة |
| `created_at` · `updated_at` | | |

**عقد Filament**: `User` ينفّذ `HasAppAuthentication` و`HasAppAuthenticationRecovery`
**بلا سماتهما** — الدالّات الخمس تفوّض إلى هذه العلاقة. السمتان تفترضان عمودين على `users`،
والعقد لا يفرض مكان التخزين. ثمانية أسطر تشتري تسجيلاً واحداً يعمل في `/admin` والـ API معاً
(research §R9).

**استهلاك رمز الاسترداد (FR-029)**: يُحذف من المصفوفة عند الاستعمال ويُطلق `SecurityAlert`.

---

## ٦أ. `student_profiles` — **مملوك للمنصة**

> نقل، لا إضافة. العمودان قائمان اليوم على `users`
> (`Identity/…_add_platform_profile_to_users.php`) ويخرجان منه (research §R17).

| العمود | النوع | ملاحظات |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint unique | |
| `grade_level_slug` | string nullable | **منقول من `users`** |
| `registered_by_parent` | boolean default false | **منقول من `users`** |
| `created_at` · `updated_at` | | |

**هجرتان** على نمط §R13: الأولى تُنشئ وتنقل كل صفّ له `grade_level_slug` أو
`registered_by_parent = true`؛ الثانية تحذف العمودين من `users`.

**لا يُنقل**: `phone` و`country` (يملكهما كل دور) · `platform_role` (هو المميِّز نفسه —
نقله يعني استعلاماً لمعرفة أي جدول نستعلم).

**لا يُنشأ**: `guardian_profiles` ولا `admin_profiles` — لا حقل يخصّهما اليوم، وجدول فارغ
ليس تصميماً. يُنشأ الأول في 013 مع حقول الموافقة.

**غير متأثّر**: `parent_student_relations.student_grade_level_slug` — لقطة تاريخية على
العلاقة لا مرجع للحقل.

---

## ٦ب. `platform_settings` — **مملوك للمنصة**

> FR-022 يقول «حدّاً معلناً **قابلاً للضبط**». قيمة في ملف إعدادات ليست قابلة للضبط —
> تغييرها نشرٌ كامل (research §R16).

| العمود | النوع | ملاحظات |
|---|---|---|
| `key` | string(64) **PK** | `auth.device_limits` · … |
| `value` | json | |
| `updated_by_user_id` | bigint nullable | من غيّر آخر مرة — سؤال أول عند أي نزاع |
| `updated_at` | timestamp | |

**القراءة**: `PlatformSettings::get(string $key, mixed $default = null)` — مخزَّنة مؤقّتاً
إلى الأبد، ويُبطَل المخزون عند الحفظ. الرجوع إلى `config()` عند غياب الصفّ، فالنظام يعمل
على قاعدة بيانات نظيفة بلا بذر.

**التحرير**: صفحة Filament واحدة، **مدير المنصة حصراً** (`users.is_super_admin`) — لا صلاحية
مستأجر. الحدّ يخصّ حساباً يسجّل عند عشرة مدرّسين، فلا مدرّس يملك القرار فيه.

**ليس** `workspaces.settings`: تلك طبقة مساحة العمل، وهذه كيانات مملوكة للمنصة.

---

## ٧. عمود على `lesson_progress`

| العمود | النوع | ملاحظات |
|---|---|---|
| `last_position_seconds` | unsignedInteger default 0 | FR-036 |

يُحدَّث **مع نداء التجديد** لا بمسار مستقلّ — النداء قائم كل ٦٠ ثانية على أي حال، وإضافة
مسار ثانٍ بنفس الوتيرة تضاعف الحمل مقابل لا شيء.

---

## ٨. حذف `lessons.media`

هجرتان منفصلتان (research §R13):

1. `…_create_media_assets_table` — يُنشئ ويُرحّل. كل `media` غير فارغ ⇒ أصل `ready`.
   المشوّه ⇒ `failed` مع `failure_reason` — **يُرحَّل ولا يُفقد**.
2. `…_drop_media_from_lessons` — يحذف العمود.

`LessonResource.media` ⇒ `LessonResource.asset` (تغيير كاسر موثَّق، بلا مستهلك اليوم).

---

## خريطة الحرّاس

| المورد | الحارس | الاختبار |
|---|---|---|
| رفع أصل | `MediaAssetPolicy` + `LESSONS_MANAGE` + `BelongsToWorkspace` | `WorkspaceIsolationTest` |
| إصدار منحة | `IssuePlaybackGrant`: تسجيل نشط **أو** `is_preview`/`is_free` **أو** ملكية مساحة العمل | `PlaybackGrantTest` |
| بثّ مدى | حارس المنحة الخماسي أعلاه — **كل طلب** | `PlaybackGrantTest` |
| جهاز · جلسة | ملكية الصفّ للمستخدم. **يُمنع** على المدرّس مطلقاً | `PlatformOwnershipTest` |
| 2FA | ملكية الصفّ + تأكيد كلمة المرور قبل التفعيل والتعطيل | `TwoFactorTest` |
| عملية حسّاسة | `RequireTwoFactor` بعد انقضاء `two_factor_required_at` | `TwoFactorTest` |
| ملف الطالب | ملكية الصفّ للمستخدم. المدرّس يقرأ المرحلة **عبر تسجيل نشط فقط** (NFR-001أ) | `PlatformOwnershipTest` |
| إعدادات المنصة | `users.is_super_admin` حصراً — لا صلاحية مستأجر | `PlatformSettingsTest` |

---

## ما لا يُخزَّن — عمداً

| البيان | لماذا |
|---|---|
| رقم الهاتف في `playback_grants` | يُبنى مقنَّعاً عند الإصدار من `users.phone`. تخزينه ينسخ بياناً شخصياً بلا حاجة |
| البصمة الخام | التجزئة تكفي للمطابقة، والخام يوسّع أثر أي تسريب |
| رابط المزوّد الموقّع | يُطلَب عند الحاجة وينتهي. تخزينه يُنتج رابطاً دائماً — وهو بالضبط ما تلغيه هذه المرحلة |
| النصّ الكامل للأصل | يُشتقّ من WebVTT. نسختان تتباعدان |
