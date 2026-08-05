# Implementation Plan: خط أنابيب الفيديو وحماية المحتوى

**Branch**: `004-video-pipeline-security` | **Date**: 2026-08-05 | **Spec**: [spec.md](./spec.md)

**Input**: `specs/004-video-pipeline-security/spec.md`

---

## Summary

الدرس اليوم يخزّن فيديوه في `lessons.media` — عمود JSON حرّ بلا بنية ولا تحقّق — والملفات
تُقدَّم من قرص عام بروابط دائمة. هذه المرحلة تستبدل ذلك بـ**كيان أصل موثّق** خلف
**واجهة مزوّد بث** (`VideoProviderInterface`)، وتضيف **منح تشغيل قصير العمر مرتبطاً بجلسة**،
و**علامة مائية** هي نفسها ما يجدّد المنحة، و**حد جلسات لكل حساب طالب**، و**تحققاً ثنائياً**
للمدرّس والإدارة.

**قرار المزوّد (2026-08-05)**: تُبنى الواجهة و**تنفيذ محلّي مكتفٍ بذاته** يعمل بلا حساب
خارجي ولا اتصال شبكي، ويُؤجَّل اختيار المزوّد التجاري. على نمط `PaymentProviderInterface`
القائم بالضبط: العقد يُشحن، والتنفيذ الثاني ملفٌ وسطر ربط.

**ثلاث نتائج تصميمية تحكم كل ما بعدها**:

1. **المنحة صفّ في قاعدة البيانات، لا توقيع URL.** الرابط لا يحمل توقيعاً لأننا نحتاج
   استعلاماً على أي حال (الأصل، حيوية الجلسة، الإلغاء) — فالتوقيع يضيف آليةً ثانية بلا
   فائدة. راجع research §R3.
2. **العلامة المائية هي حلقة التجديد.** إخفاؤها من DOM يوقف التجديد، فتنتهي المنحة،
   فيُرفَض طلب المدى (Range) التالي. لا فحص عميل ثانٍ ولا «حارس» يمكن تعطيله وحده.
   راجع research §R5.
3. **حدّ الأجهزة يقع على الجلسات لا على البصمة.** البصمة تُجمِّع فقط؛ الحدّ يُفرَض بحذف
   رمز Sanctum — وهو ما لا يستطيع العميل تزويره. راجع research §R7.

**وثلاثة قرارات أضافها المستخدم (2026-08-05)**:

4. **حدّ الأجهزة في جدول `platform_settings` لا في ملف إعدادات** — FR-022 يقول «قابلاً
   للضبط»، وقيمة في ملف تغييرها نشرٌ كامل. راجع research §R16.
5. **الخروج التلقائي للجلسة الأقدم بلا فعل من صاحبها** — ثلاث طبقات كلها قائمة في التصميم
   أصلاً: طلب المدى (فوري) · حلقة العلامة المائية (٦٠ ثانية) · نبض جرس الإشعارات
   (٦٠ ثانية). **بلا وسيط جديد وبلا مسار نبض جديد.** راجع research §R15.
6. **أعمدة الدور تخرج من `users` إلى جداول خاصة** — قاعدة سارية على 004 وما بعدها:
   `users` يحمل ما يملكه كل مستخدم، وما يخصّ دوراً واحداً له جدوله. راجع research §R17.

---

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript 5.7 (Next.js 15 App Router)

**Primary Dependencies**: بلا تبعية جديدة.
`pragmarx/google2fa` و`pragmarx/google2fa-qrcode` **مثبَّتان سلفاً** كتبعية لـ`filament/filament`
(راجع `vendor/filament/filament/composer.json`)، و`spatie/laravel-medialibrary` مثبَّت.
**لا** `hls.js` ولا مكتبة مشغّل ولا حزمة 2FA — راجع research §R2 و§R9 و§R11.

**Storage**: MySQL (SQLite محلياً/اختباراً) · قرص `local` الخاص لملفات المزوّد المحلّي

**Testing**: Pest (Feature هي شبكة الأمان) · Playwright للواجهة وإمكانية الوصول

**Target Platform**: خادم Linux · متصفّحات حديثة (RTL عربي)

**Project Type**: تطبيق ويب — `backend/` أحادية معيارية + `frontend/` Next.js

**Performance Goals**: إصدار منحة تشغيل < ١٥٠ مللي · قائمة دروس بأصولها بعدد استعلامات ثابت
(SC-011) · تجديد المنحة كل ٦٠ ثانية لكل مشاهد نشط

**Constraints**: **يُمنع** نداء شبكي حقيقي في الاختبارات (NFR-009) · **يُمنع** ظهور معرّف
المزوّد في أي حمولة أو في حزمة الواجهة (FR-011 · SC-002) · **يُمنع** `WorkspaceContext::set()`
في وظيفة مطبورة (NFR-007)

**Scale/Scope**: وحدة خلفية جديدة واحدة (`Media`) · توسعة `Identity` · ٣ شاشات واجهة جديدة
+ صفحة Filament واحدة · **٩ جداول جديدة** · صفر صلاحية جديدة · نقل عمودين من `users`

---

## Constitution Check

*بوابة: تمرّ قبل Phase 0، وتُعاد بعد Phase 1.*

### قبل التصميم

| المبدأ | الحكم | كيف يُستوفى |
|---|---|---|
| **I — طبقات الملكية** | ✅ | مُصنَّف صراحةً في الجدول أدناه، وهو شرط الدستور «التصنيف قرار مُلزِم يُوثَّق في مواصفة الميزة» |
| **I — حارس رؤية المدرّس** | ✅ | `Device` و`AuthSession` مملوكان للمنصة ⇒ لا يقرؤهما مدرّس **إطلاقاً**، لا بتسجيل ولا بدونه (research §R8) |
| **II — المنطق في Actions** | ✅ | `IssuePlaybackGrant` هو المدخل الوحيد لأي تشغيل: الحارس فيه لا في `FormRequest` |
| **III — استقلال الوحدات** | ✅ | `Media` لا تستدعي `Learning`؛ الأهلية تُقرأ عبر عقد `EnrollmentDirectory` في `Shared` على نمط `GuardianDirectory` (spec 003) |
| **IV — البوابات الخضراء** | ✅ | الأربع + إضافة `Media` إلى `phpstan.neon` |
| **V — التفويض والأسرار** | ✅ | **صفر صلاحية جديدة** (research §R12)؛ بيانات المزوّد من `config/media.php` عن متغيّرات بيئة |
| **VI — العقود الظاهرة** | ✅ | `uuid` حصراً · `declare(strict_types=1)` · DTOs ترث `DataTransferObject` · `VideoProviderInterface` على نمط `PaymentProviderInterface` |

### تصنيف الكيانات (المبدأ I — إلزامي)

| الكيان | الطبقة | السبب |
|---|---|---|
| `MediaAsset` | **مملوك لمساحة العمل** | ينتجه المدرّس. `BelongsToWorkspace` + حالة في `WorkspaceIsolationTest` |
| `MediaCaption` | **مملوك لمساحة العمل** | يتبع الأصل |
| `PlaybackGrant` | **جسر** | يحمل `workspace_id` للسياق ويشير إلى المستخدم العام والجلسة العامة |
| `Device` | **مملوك للمنصة** | حدّ الجهازين على حساب الطالب كله. `BelongsToWorkspace` عليه يعني حدّاً لكل مدرّس — فتسقط الحماية بتسجيل ثانٍ (`docs/roadmap.md` §٥ج يسمّيه صراحةً) |
| `AuthSession` | **مملوك للمنصة** | جلسة الدخول واحدة لا تخصّ مدرّساً |
| `UserSecuritySettings` | **مملوك للمنصة** | التحقق الثنائي للحساب لا لمساحة عمل. **جدول مستقلّ لا أعمدة على `users`** (research §R17) |
| `StudentProfile` | **مملوك للمنصة** | نقل عمودَي الطالب من `users`. المرحلة الدراسية واحدة عبر كل مدرّسيه |
| `PlatformSetting` | **مملوك للمنصة** | حدّ الأجهزة قرار منصة؛ لا مدرّس يملكه في حساب يسجّل عند عشرة (research §R16) |

**اختبار NFR-001ب** لـ`Device` و`AuthSession`: `tests/Feature/Auth/PlatformOwnershipTest.php`
يتحقّق من الاتجاهين — أن مدرّساً **لا** يقرأ أجهزة طالبه ولو كان مسجَّلاً عنده، وأن الطالب
يرى **جهازين اثنين** لا ستة بعد التسجيل عند ثلاثة مدرّسين.

### بعد التصميم (Phase 1)

| المبدأ | الحكم | ملاحظة |
|---|---|---|
| I | ✅ | التصنيف كما أعلاه بلا تغيير. `Media` مضافة إلى `phpstan.neon` |
| II | ✅ | `IssuePlaybackGrant` · `RenewPlaybackGrant` · `CompleteMediaUpload` · `StartAuthSession` — كلها Actions، والمتحكّمات تنسيق فقط |
| III | ✅ | `EnrollmentDirectory` في `Shared/Contracts` بتنفيذ `Learning`؛ حدث `MediaAssetReady` تستهلكه 005 لاحقاً |
| IV | ✅ | لا `@phpstan-ignore` ولا baseline |
| V | ✅ | صفر صلاحية جديدة؛ `RequireTwoFactor` وسيط مُطبَّق صراحةً على مسارات مسمّاة لا قائمة عامة |
| VI | ✅ | لا كسر في API قائم عدا `LessonResource.media` — راجع الجدول أدناه |

**تغيير كاسر واحد ومقصود**: حقل `media` يختفي من `LessonResource` ويحلّ محلّه `asset`.
لا مستهلك له في الواجهة اليوم (`grep` على `frontend/src` لا يُظهر أي قراءة لـ`lesson.media`)،
فالتغيير يقع قبل وجود مستهلك — وهذا أرخص وقت ممكن. موثَّق في `docs/README.md`.

---

## Project Structure

### Documentation (this feature)

```text
specs/004-video-pipeline-security/
├── plan.md              # هذا الملف
├── research.md          # Phase 0 — ١٤ قراراً
├── data-model.md        # Phase 1 — ٦ جداول و٥ تعدادات والحُرّاس
├── quickstart.md        # Phase 1 — ٨ سيناريوهات تحقّق
├── contracts/
│   ├── video-provider.md   # عقد المزوّد — نقطة الانعكاس
│   └── api.md              # ١٩ نقطة نهاية
├── checklists/requirements.md
└── tasks.md             # Phase 2 — /speckit-tasks
```

### Source Code

```text
backend/app/Modules/Media/                     ← وحدة جديدة (الوحيدة)
├── MediaServiceProvider.php                   # ربط المزوّد بسطر واحد
├── Contracts/VideoProviderInterface.php       # نقطة الانعكاس
├── Providers/LocalVideoProvider.php           # التنفيذ الوحيد اليوم
├── Data/                                      # UploadTicket · PlaybackManifest · PlaybackContext
│   ├── ProviderCapabilities.php               #   ProviderCapabilities · Rendition · AssetStatusReport
│   └── …
├── Enums/                                     # MediaAssetStatus · CaptionKind · CaptionSource
│   └── PlaybackFormat.php                     #   PlaybackFormat · SessionEndReason (في Identity)
├── Models/                                    # MediaAsset · MediaCaption · PlaybackGrant
├── Actions/                                   # RequestUploadTicket · CompleteMediaUpload
│   ├── IssuePlaybackGrant.php                 #   IssuePlaybackGrant · RenewPlaybackGrant
│   └── …                                      #   AttachCaption · DeleteMediaAsset
├── Jobs/ReconcileAssetStatus.php              # استطلاع حالة المعالجة (forWorkspace)
├── Events/MediaAssetReady.php                 # تستهلكه 005
├── Http/{Controllers,Requests,Resources}/
├── Policies/MediaAssetPolicy.php
├── Support/WatermarkPayload.php               # الاسم + الرقم المقنّع
├── Database/Migrations/                       # حرف M كبير — الدستور III
└── routes/api.php

backend/app/Modules/Identity/                  ← توسعة
├── Models/{Device,AuthSession,UserSecuritySettings,StudentProfile}.php
├── Actions/                                   # StartAuthSession · TerminateAuthSession
│   └── …                                      #   EnableTwoFactor · ConfirmTwoFactor
│                                              #   DisableTwoFactor · CompleteTwoFactorChallenge
├── Support/{DeviceFingerprint,TwoFactorCodes}.php
├── Http/Controllers/{SessionController,TwoFactorController}.php
├── Policies/AuthSessionPolicy.php
└── Database/Migrations/                       # devices · auth_sessions · user_security_settings
                                               # + student_profiles (نقل) + حذف العمودين

backend/app/Modules/Courses/
└── Database/Migrations/…drop_media_from_lessons.php   # بعد الترحيل

backend/app/Modules/Tenancy/                   ← إعدادات المنصة
├── Models/PlatformSetting.php
├── Support/PlatformSettings.php               # قراءة مخزَّنة مؤقّتاً + رجوع إلى config()
├── Filament/Pages/ManagePlatformSettings.php  # مدير المنصة حصراً
└── Database/Migrations/…create_platform_settings_table.php

backend/app/Shared/
├── Contracts/EnrollmentDirectory.php          # يكسر التبعية Media → Learning
└── Middleware/RequireTwoFactor.php

backend/config/media.php                       # المزوّد · الحدود · مدد المنح (افتراضيات)
backend/tests/Feature/{Media,Auth,Identity,Tenancy}/   # ١٢ ملف اختبار

frontend/src/
├── lib/media.ts                               # الأنواع وعميل الـ API
├── lib/api.ts                                 # ← معالج 401 عام: مسح + تحويل بالسبب
├── components/player/                         # VideoPlayer · Watermark · TranscriptPanel
├── components/app/NotificationBell.tsx        # ← استطلاع ٦٠ ثانية = نبض الجلسة
└── app/(app)/(shell)/
    ├── learn/[enrollment]/[lesson]/page.tsx   # المشاهدة
    ├── manage/courses/[uuid]/lessons/[lessonUuid]/page.tsx   # الرفع والنصوص
    └── settings/security/page.tsx             # الأجهزة والتحقق الثنائي

frontend/e2e/player.spec.ts
```

> **درس spec 003 مُطبَّق مسبقاً**: `settings/page.tsx` يحصل على بطاقة رابط إلى
> `/settings/security` **في نفس المهمة** التي تُنشئ الصفحة. صفحة بلا رابط إليها صفحة
> غير مُسلَّمة.

**Structure Decision**: وحدة خلفية جديدة **واحدة** (`Media`)، لأن خط الأنابيب يخدم الدروس اليوم
والحصص في 005 — فوضعه داخل `Courses` يجبر 005 على الاعتماد على وحدة الكورسات لسبب لا علاقة له
بالكورسات. أما الأجهزة والجلسات والتحقق الثنائي فتذهب إلى `Identity` لأنها **مصادقة**،
ووحدة جديدة لها تجريد بلا مشكلة قائمة (الدستور: «تبرير التعقيد»).

---

## Deferred Verification *(أثر تأجيل المزوّد — مُعلَن لا مُخفى)*

| المعيار | الحالة | لماذا | متى يُغلَق |
|---|---|---|---|
| **SC-008** — الجودة تنخفض تلقائياً على اتصال بطيء | **مؤجَّل جزئياً** | الجودة التكيّفية قدرة مزوّد: تتطلّب ترميزاً إلى تدفّقات متعدّدة ومنفذاً يقدّمها. بناء ذلك محلياً = بناء نصف مزوّد يُرمى عند أول تعاقد | يُغلَق باعتماد المزوّد. **المُنفَّذ الآن**: العقد يعلن `renditions()` و`supportsAdaptiveBitrate()`، والمشغّل يستهلك ما يعلنه البيان، واختبار مطابقة العقد يفرض القدرة على أي تنفيذ يدّعيها |
| **SC-005** — صفر تشغيل بعد إخفاء العلامة | ✅ **مُغطّى** | المزوّد المحلّي يقدّم بطلبات مدى (Range)، وكل طلب مدى يُعيد التحقّق من المنحة — فالانتهاء يقطع فعلاً في منتصف الملف (research §R5) | — |
| **بيان HLS** | **مؤجَّل** | لا مزوّد يُنتجه. المشغّل يرفض صيغة لا يدعمها المتصفّح **برسالة عربية واضحة** لا بشاشة سوداء | يُغلَق بإضافة `hls.js` في مهمة اعتماد المزوّد |
| **Webhook المزوّد** | **مؤجَّل** | شكل الـ webhook خاصّ بكل مزوّد. الاستطلاع (`ReconcileAssetStatus`) يعمل مع أي مزوّد | webhook يُضاف كتنفيذ ثانٍ لنفس المصالحة |

هذا الجدول **جزء من التسليم**: تسليم يدّعي SC-008 ولا يملك مزوّداً يقدّمها تسليم غير صادق.

---

## Complexity Tracking

*لا مخالفات دستورية.* التجريدان الجديدان مبرَّران بمشكلة قائمة الآن:

| التجريد | المشكلة القائمة | البديل الأبسط ولماذا رُفض |
|---|---|---|
| `VideoProviderInterface` | قرار المزوّد التجاري مفتوح، والكود لا يجوز أن ينتظره | ربط مزوّد مباشرة: يوقف التسليم على فتح حساب، ويجعل الاستبدال تعديلاً في منطق الدروس — وهو ما يمنعه FR-002 |
| `EnrollmentDirectory` | `Media` تحتاج «هل هذا الطالب مؤهَّل؟» و`Learning` تملك الجواب | استدعاء `Enrollment` مباشرة من `Media`: يخالف الدستور III نصّاً. نفس النمط المعتمد في spec 003 (`GuardianDirectory`) |
| `platform_settings` | FR-022 يطلب حدّاً **قابلاً للضبط**، وقيمة في ملف تغييرها نشرٌ كامل | `config()` وحدها: تخالف المتطلّب نصّاً. `workspaces.settings`: طبقة خاطئة — الكيان مملوك للمنصة |
| `student_profiles` | عمودان يخصّان الطالب على جدول يُقرأ في كل طلب مصادَق عليه | إبقاؤهما: يجعل الحدود غامضة، ويُغري كل شاشة بقراءة مرحلة دراسية لمستخدم قد لا يكون طالباً |

**ما رُفض بناؤه** (YAGNI صريح): ترميز محلّي بـ ffmpeg · مورد Filament للأصول (لا مستهلك ثانٍ
غير شاشة المدرّس) · صلاحيات جديدة · جدول تحدّيات 2FA (الذاكرة المؤقتة تكفي لعشر دقائق) ·
`hls.js` قبل وجود بيان HLS · **`guardian_profiles` و`admin_profiles`** (لا حقل يخصّهما اليوم؛
جدول فارغ ليس تصميماً) · **وسيط أو دفع حيّ لاكتشاف انتهاء الجلسة** (الحلقات القائمة تكفي —
research §R15).
