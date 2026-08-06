---
description: "Task list for 004-video-pipeline-security"
---

# Tasks: خط أنابيب الفيديو وحماية المحتوى

**Input**: `specs/004-video-pipeline-security/` — [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/](./contracts/) · [quickstart.md](./quickstart.md)

**Tests**: **مطلوبة**. الدستور IV يجعل اختبارات الميزة شبكة الأمان الأساسية، والمواصفة تربط
كل `SC-` باختبار. لا مهمة تنفيذ بلا مهمة اختبار تسبقها أو ترافقها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: قابلة للتوازي — ملفات مختلفة، بلا اعتماد على مهمة غير مكتملة
- **[Story]**: `US1`…`US4` لمهام مراحل قصص المستخدم فقط

## Path Conventions

`backend/` أحادية معيارية · `frontend/` Next.js. كل المسارات من جذر المستودع.

---

## Phase 1: Setup (البنية المشتركة)

**Purpose**: هيكل الوحدة والإعدادات والمحدّدات — بلا منطق.

- [X] T001 أنشئ هيكل وحدة `Media` في `backend/app/Modules/Media/` بمجلدات `Contracts` · `Providers` · `Data` · `Enums` · `Models` · `Actions` · `Jobs` · `Events` · `Http/{Controllers,Requests,Resources}` · `Policies` · `Support` · `Database/Migrations` (حرف M كبير — الدستور III) · `routes`
- [X] T002 أنشئ `backend/app/Modules/Media/MediaServiceProvider.php` يمتدّ `App\Shared\Modules\Module` بـ `protected string $name = 'Media'` — **يُمنع** تسجيله في `bootstrap/providers.php` (الاكتشاف تلقائي)
- [X] T003 أضف `app/Modules/Media/Database/Migrations` إلى `databaseMigrationsPath` في `backend/phpstan.neon` — بدونها لا يستنتج Larastan أنواع خصائص النماذج الجديدة
- [X] T004 [P] أنشئ `backend/config/media.php` بمفاتيح `provider` · `max_size_bytes` · `max_duration_seconds` · `grant_ttl_seconds` · `max_renewals` · `device_limits` · `two_factor_grace_days` — كلها **افتراضيات** يعلوها `platform_settings` (research §R16)
- [X] T005 [P] أضف `MEDIA_PROVIDER=local` إلى `backend/.env.example` مع تعليق يوضّح أن المحلّي لا يحتاج حساباً خارجياً
- [X] T006 [P] أضف المحدّدين المسمّيين `playback` و`two-factor` في `AppServiceProvider::registerRateLimiters()` بـ `backend/app/Providers/AppServiceProvider.php` — `playback` بمفتاح المستخدم لا IP، و`two-factor` بالاثنين. **يُمنع** أي `throttle:5,1` سطري
- [X] T007 [P] أنشئ `backend/app/Modules/Media/routes/api.php` فارغاً بترويسة توضّح أن `Module` يضيف البادئة `/api/v1` ومجموعة `api` تلقائياً

**Checkpoint**: الوحدة مكتشَفة، وPHPStan يقرأ هجراتها، والمحدّدات مسمّاة.

---

## Phase 2: Foundational (متطلّبات حاجبة)

**Purpose**: العقد والكيانات والإعدادات وفصل الجداول — **يُمنع** بدء أي قصة قبل اكتمالها.

**⚠️ CRITICAL**: كل قصة تعتمد على العقد والنماذج هنا.

### إعدادات المنصة (research §R16)

- [X] T008 أنشئ هجرة `platform_settings` في `backend/app/Modules/Tenancy/Database/Migrations/` بأعمدة `key` (PK) · `value` (json) · `updated_by_user_id` · `updated_at` حسب data-model §٦ب
- [X] T009 أنشئ `backend/app/Modules/Tenancy/Models/PlatformSetting.php` — **بلا** `BelongsToWorkspace` (مملوك للمنصة)، مفتاحه نصّي لا تسلسلي
- [X] T010 أنشئ `backend/app/Modules/Tenancy/Support/PlatformSettings.php` بدالّة `get(string $key, mixed $default = null)` مخزَّنة مؤقّتاً إلى الأبد، تُبطِل المخزون عند `set()`، وترجع إلى `config()` عند غياب الصفّ
- [X] T011 [P] أنشئ `backend/tests/Feature/Tenancy/PlatformSettingsTest.php`: قراءة بلا صفّ ⇒ قيمة `config()` · الحفظ يُبطل المخزون · **مستخدم غير `is_super_admin` لا يصل صفحة الإعدادات**
- [X] T012 أنشئ صفحة Filament `backend/app/Modules/Tenancy/Filament/Pages/ManagePlatformSettings.php` محميّة بـ `users.is_super_admin` حصراً، وسجّل مجلدها في `discoverPages` بـ `backend/app/Providers/Filament/AdminPanelProvider.php`

### فصل جداول الأدوار (research §R17)

- [X] T013 أنشئ هجرة `student_profiles` في `backend/app/Modules/Identity/Database/Migrations/` **وتنقل** كل صفّ `users` له `grade_level_slug` أو `registered_by_parent = true` — data-model §٦أ
- [X] T014 أنشئ هجرة **منفصلة** تحذف `grade_level_slug` و`registered_by_parent` من `users` — الفصل يجعل `down()` ذا معنى (research §R13)
- [X] T015 أنشئ `backend/app/Modules/Identity/Models/StudentProfile.php` وأضف علاقة `studentProfile(): HasOne` إلى `backend/app/Models/User.php`، واحذف العمودين من docblock الخصائص وmن `casts()`
- [X] T016 حدّث `RegisterStudent` و`RegisterStudentData` في `backend/app/Modules/Identity/` لتكتب في `student_profiles` بدل أعمدة `users`
- [X] T017 حدّث `backend/app/Modules/Identity/Http/Resources/UserResource.php`: `grade_level_slug` و`registered_by_parent` ينتقلان إلى كائن `student_profile` يظهر لمن له ملف طالب فقط
- [X] T018 [P] أنشئ `backend/tests/Feature/Identity/StudentProfileMigrationTest.php` يعيد بناء العمودين يدوياً، يملؤهما، يشغّل الهجرتين، ويؤكّد **صفر فقد** وأن `parent_student_relations.student_grade_level_slug` **بلا مساس**
- [X] T019 حدّث `backend/tests/Feature/Marketplace/StudentRegistrationTest.php` و`ParentAccountTest.php` للتأكيد على `student_profiles` بدل أعمدة `users`

### إعدادات الأمان للمستخدم

- [X] T020 أنشئ هجرة `user_security_settings` في `backend/app/Modules/Identity/Database/Migrations/` — data-model §٦
- [X] T021 أنشئ `backend/app/Modules/Identity/Models/UserSecuritySettings.php` بـ `casts()` تجعل السرّ `encrypted` والرموز `encrypted:array`
- [X] T022 اجعل `backend/app/Models/User.php` ينفّذ `HasAppAuthentication` و`HasAppAuthenticationRecovery` **بلا سماتهما** — الدالّات الخمس تفوّض إلى `securitySettings()` (research §R9)، وفعّل `AppAuthentication::make()->recoverable()` في `AdminPanelProvider`

### عقد المزوّد (contracts/video-provider.md)

- [X] T023 [P] أنشئ التعدادات في `backend/app/Modules/Media/Enums/`: `MediaAssetStatus` (٥ حالات) · `PlaybackFormat` · `CaptionKind` · `CaptionSource`
- [X] T024 [P] أنشئ أغلفة `backend/app/Modules/Media/Data/`: `ProviderCapabilities` · `UploadTicket` · `PlaybackContext` · `PlaybackManifest` · `AssetStatusReport` · `Rendition` — كلها ترث `DataTransferObject` بخصائص `readonly`
- [X] T025 أنشئ `backend/app/Modules/Media/Contracts/VideoProviderInterface.php` بالدالّات الستّ حسب contracts/video-provider.md
- [X] T026 أنشئ هجرة `media_assets` + `media_captions` + `playback_grants` في `backend/app/Modules/Media/Database/Migrations/` — data-model §١–٣، بفهارسها الثلاثة. **انتبه**: `size_bytes` هو `unsignedBigInteger` لا `unsignedInteger` (يفيض عند ٤ جيجابايت، وSQLite يخفي ذلك)
- [X] T027 [P] أنشئ نماذج `backend/app/Modules/Media/Models/`: `MediaAsset` (بـ `BelongsToWorkspace` + `HasUuid` + علاقة `owner()` متعدّدة الأشكال) · `MediaCaption` (نفسه) · `PlaybackGrant` (بـ `HasUuid` فقط — **جسر**، يحمل `workspace_id` بلا `BelongsToWorkspace`)
- [X] T028 [P] أنشئ مصانع `backend/database/factories/Modules/Media/` للنماذج الثلاثة
- [X] T029 أنشئ `backend/app/Modules/Media/Providers/LocalVideoProvider.php` — التذكرة تشير إلى مسارنا، والبثّ يعلن `Accept-Ranges: bytes` (research §R5)، و`capabilities()` تعلن `adaptiveBitrate: false`
- [X] T030 اربط `VideoProviderInterface` في `MediaServiceProvider::register()` بـ `match (config('media.provider'))` مع `local` افتراضاً — سطر الربط الذي يجعل الاستبدال ملفاً وسطراً

### كسر التبعية بين الوحدات (الدستور III · research §R14)

- [X] T031 أنشئ `backend/app/Shared/Contracts/EnrollmentDirectory.php` بدالّتَي `hasActiveEnrollment(User $user, int $courseId): bool` و`activeCourseIdsFor(User $user): array`
- [X] T032 أنشئ `backend/app/Modules/Learning/Support/EloquentEnrollmentDirectory.php` واربطه في `LearningServiceProvider::register()` — على نمط `EloquentGuardianDirectory` في spec 003

### ترحيل `lessons.media` (research §R13)

- [X] T033 أضف الترحيل إلى هجرة `media_assets` (T026): كل `lessons.media` غير فارغ ⇒ أصل `ready`؛ والمشوّه ⇒ `failed` مع `failure_reason` — **يُرحَّل ولا يُفقد**
- [X] T034 أنشئ هجرة **منفصلة** في `backend/app/Modules/Courses/Database/Migrations/` تحذف عمود `media` من `lessons`
- [X] T035 حدّث `backend/app/Modules/Courses/Models/Lesson.php`: احذف `media` من `$fillable` و`casts()`، وأضف `mediaAsset(): MorphOne`
- [X] T036 حدّث `backend/app/Modules/Courses/Http/Resources/LessonResource.php`: `media` ⇒ `asset` (uuid + status + duration فقط — **يُمنع** `provider` و`provider_asset_id`)
- [X] T037 [P] أنشئ `backend/tests/Feature/Media/MediaMigrationTest.php` يعيد بناء العمود القديم، يملؤه بأربعة أشكال (مسار نصّي · كائن · `null` · JSON مشوّه)، ويؤكّد صفر فقد

### اختبارات العقد (البوابة التي تجعل تأجيل المزوّد آمناً)

- [X] T038 أنشئ `backend/tests/Feature/Media/ProviderContractTest.php` يعمل على **كل** تنفيذ مسجَّل: معرّف غير فارغ · تذكرة بلا مفتاح · بيان ينتهي مع المنحة أو قبلها · `status()` لأصل محذوف ⇒ `failed` لا استثناء · `delete()` مرتين بلا خطأ · **إن أعلن `adaptiveBitrate` ⇒ `renditions` فيه اثنان فأكثر** · **إن أعلن `automaticCaptions` ⇒ ينتج WebVTT صالحاً**
- [X] T039 [P] أنشئ `backend/tests/Feature/Media/ProviderAgnosticTest.php` يمسح `app/Modules/*/Actions/` و`app/Modules/*/Http/` و`frontend/src/` عن أسماء المزوّدين (`bunny` · `cloudflare` · `mux` · `vimeo` · `jwplayer`) ويفشل عند أي تطابق — باستثناء `app/Modules/Media/Providers/` وحده
- [X] T040 [P] أنشئ `backend/tests/Support/FakeVideoProvider.php` — مزوّد اختباري يعلن `adaptiveBitrate: true` ويقدّمها فعلاً، لإثبات أن اختبار العقد يمسك الادّعاء الكاذب

**Checkpoint**: العقد يعمل، والكيانات موجودة، والترحيل مثبت — يمكن بدء القصص.

---

## Phase 3: User Story 1 — مشاهدة محمية لا يعمل رابطها خارج الجلسة (P1) 🎯 MVP

**Goal**: طالب مسجَّل يشاهد؛ ورابطه لا يعمل لغيره، ولا بعد انتهائه، ولا من جلسة أخرى.

**Independent Test**: استخرج رابط تشغيل لطالب مسجَّل، ثم استعمله بعد انتهاء مدته، ومن جلسة
أخرى، ومن مستخدم غير مسجَّل — يُرفض في الثلاث.

> **ملاحظة تسلسل**: المنحة تُربط بـ`auth_session_id`، وجدوله يصل في القصة ٣.
> **لذلك تُنفَّذ T075–T076 (هجرة `auth_sessions` ونموذجها) قبل T044** — مع Phase 2 عملياً.
> هذا الاعتماد الوحيد بين القصص، ومشروح في قسم Dependencies.

### الاختبارات أولاً

- [X] T041 [P] [US1] أنشئ `backend/tests/Feature/Media/PlaybackGrantTest.php` بحالة «طالب مسجَّل ⇒ منحة صالحة ⇒ `206` مع `Accept-Ranges: bytes`»
- [X] T042 [P] [US1] أضف حالات الرفض في نفس الملف: بعد انتهاء المدة · من جلسة أخرى (FR-009) · غير مسجَّل · انتهى تسجيله · مساحة عمل أخرى (SC-001 · SC-003)
- [X] T043 [P] [US1] أضف حالة `FR-011` في نفس الملف: تمشي على كل حمولة تخصّ درساً وتفشل عند ظهور `provider` أو `provider_asset_id` أو مسار قرص (SC-002)

### التنفيذ

- [X] T044 [US1] أنشئ `backend/app/Modules/Media/Actions/IssuePlaybackGrant.php` — **الحارس هنا لا في `FormRequest`** (الدستور II): تسجيل نشط عبر `EnrollmentDirectory` **أو** `is_preview`/`is_free` **أو** ملكية مساحة العمل؛ ثم صفّ منحة بمدة `grant_ttl_seconds` مربوط بـ `auth_session_id`
- [X] T045 [US1] أنشئ `backend/app/Modules/Media/Actions/RenewPlaybackGrant.php` — يمدّد المنحة، يحفظ `position_seconds`، ويرفض بعد `max_renewals`
- [X] T046 [US1] أنشئ `backend/app/Modules/Media/Support/PlaybackGuard.php` يفحص الشروط الخمسة (موجودة · غير ملغاة · غير منتهية · الجلسة نشطة · الأصل `ready`) — **يُستدعى عند كل طلب مدى** لا مرة واحدة
- [X] T047 [US1] أنشئ `backend/app/Modules/Media/Policies/MediaAssetPolicy.php` وسجّله في `MediaServiceProvider::boot()`
- [X] T048 [US1] أنشئ `backend/app/Modules/Media/Http/Controllers/PlaybackController.php` بدالّات `issue` · `stream` · `renew` — المتحكّم تنسيق فقط
- [X] T049 [US1] أنشئ `backend/app/Modules/Media/Http/Resources/PlaybackGrantResource.php` حسب contracts/api.md — **بلا** أي حقل مزوّد
- [X] T050 [US1] سجّل المسارات في `backend/app/Modules/Media/routes/api.php`: `POST /lessons/{lesson}/playback` (`auth:sanctum` + `throttle:playback`) · `GET /playback/{grant}/stream` (**بلا مصادقة** — الحارس هو صفّ المنحة) · `POST /playback/{grant}/renew` (`auth:sanctum`). **يُمنع** ربط `{grant}` بالنموذج ضمنياً
- [X] T051 [US1] أضف عمود `last_position_seconds` إلى `lesson_progress` بهجرة في `backend/app/Modules/Learning/Database/Migrations/` (FR-036)
- [X] T052 [US1] أضف حالة `MediaAsset` إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` — إلزامي بالدستور I في نفس الـ PR

### الرفع (يغذّي القصة)

- [X] T053 [P] [US1] أنشئ `backend/tests/Feature/Media/UploadLifecycleTest.php`: امتداد فيديو وليس فيديو ⇒ رفض · تجاوز الحجم/المدة ⇒ `422` قبل استهلاك الرفع · رفع مقاطَع ⇒ `failed` والدرس لا يقبل تشغيلاً (SC-010) · المزوّد متوقّف ⇒ `failed` بلا استثناء
- [X] T054 [US1] أنشئ `RequestUploadTicket` و`CompleteMediaUpload` و`DeleteMediaAsset` في `backend/app/Modules/Media/Actions/` — النوع يُقرأ من **محتوى** الملف لا من العميل (FR-003)
- [X] T055 [US1] أنشئ `backend/app/Modules/Media/Http/Controllers/MediaAssetController.php` ومسارات الرفع والإتمام والحذف حسب contracts/api.md
- [X] T056 [US1] أنشئ `backend/app/Modules/Media/Jobs/ReconcileAssetStatus.php` — يستطلع الأصول `processing`، ويستعمل `forWorkspace()`. **يُمنع** `WorkspaceContext::set()` (NFR-007)
- [X] T057 [US1] أنشئ `backend/app/Modules/Media/Events/MediaAssetReady.php` وأطلقه عند الانتقال إلى `ready` — تستهلكه 005
- [X] T058 [P] [US1] أضف اختبار يفشل عند وجود `WorkspaceContext::set(` تحت `app/Modules/Media/Jobs/` — على نمط `TrustScoreJobIsolationTest`

### الواجهة

- [X] T059 [P] [US1] أنشئ `frontend/src/lib/media.ts` بأنواع `PlaybackGrant` · `MediaAsset` · `Caption` وعميل الـ API
- [X] T060 [US1] أنشئ `frontend/src/components/player/VideoPlayer.tsx` — عنصر `<video controls controlsList="nodownload">` أصلي بلا مكتبة (research §R11)، يرفض صيغة لا يدعمها المتصفّح **برسالة عربية** لا شاشة سوداء
- [X] T061 [US1] أنشئ `frontend/src/app/(app)/(shell)/learn/[enrollment]/[lesson]/page.tsx` مبنيّة على `components/ui/` حصراً — **يُمنع** `className` حرّ أو لون خارج `@theme`
- [X] T062 [US1] أنشئ `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/lessons/[lessonUuid]/page.tsx` لرفع الأصل وعرض حالته
- [X] T063 [US1] أضف رابطاً إلى صفحة الدرس من `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/page.tsx` — صفحة بلا رابط إليها صفحة غير مُسلَّمة (درس spec 003)
- [X] T064 [P] [US1] أضف حالة أداء إلى `PlaybackGrantTest`: إصدار منح لقائمة ٥٠ درساً بعدد استعلامات **ثابت** عبر `activeCourseIdsFor()` (SC-011)

**Checkpoint**: US1 قابلة للتسليم وحدها — أوسع قناة تسريب مغلقة.

---

## Phase 4: User Story 2 — العلامة المائية تكشف المُسرِّب (P2)

**Goal**: كل تشغيل يحمل هوية مشاهده، وإخفاء العلامة يوقف التشغيل.

**Independent Test**: شغّل الدرس لطالبين مختلفين وتحقّق من ظهور بيانات كلٍّ منهما وتغيّر موضعها.

**Depends on**: US1 (لا علامة بلا مشغّل).

- [X] T065 [P] [US2] أنشئ `backend/tests/Feature/Media/WatermarkTest.php`: مشاهدان ⇒ حمولتا علامة مختلفتان (SC-004) · **الرقم الكامل لا يظهر في أي استجابة** (FR-019)
- [X] T066 [US2] أنشئ `backend/app/Modules/Media/Support/WatermarkPayload.php` — يبني `{name, phone_masked}` في **الخادم**، آخر أربعة أرقام فقط (research §R6)
- [X] T067 [US2] أضف `watermark` إلى `PlaybackGrantResource` في `backend/app/Modules/Media/Http/Resources/`
- [X] T068 [US2] أنشئ `frontend/src/components/player/Watermark.tsx` — الطبقة الشفافة **وحلقة التجديد كل ٦٠ ثانية**. المكوّن نفسه هو ما يستدعي `renew`؛ إزالته توقف التجديد فتنتهي المنحة (research §R5 · FR-018)
- [X] T069 [US2] اجعل موضع العلامة يتغيّر دورياً في `Watermark.tsx` (FR-017) بحيث لا تُقصّ ولا تُغطّى بموضع ثابت، وبلا حجب محتوى الشرح (FR-020)
- [X] T070 [US2] أضف `MutationObserver` في `Watermark.tsx` يوقف التشغيل فوراً عند إزالة العنصر — **تحسين تجربة لا حارس**؛ الحارس هو انتهاء المنحة
- [X] T071 [P] [US2] أنشئ `frontend/e2e/player.spec.ts` بحالة تحذف العلامة من DOM، تتخطّى مهلة المنحة، وتؤكّد **توقّف التشغيل** (SC-005)

**Checkpoint**: قناة التصوير الخارجي صار لها رادع، والإخفاء لا يُجدي.

---

## Phase 5: User Story 3 — حساب واحد لا يخدم فصلاً كاملاً (P3)

**Goal**: **جهاز واحد نشط** لكل حساب طالب. الدخول من جهاز ثانٍ يُنهي جلسات الأول تلقائياً — ولو كان صاحبها يشاهد ولا يفعل شيئاً. والعدّ على الأجهزة لا على الجلسات.

**Independent Test**: سجّل الدخول من جهازين متتابعين وتحقّق من انتهاء الأول وبقاء الثاني وحده؛ ثم من جلستين على **نفس** الجهاز وتحقّق من بقاء الاثنتين.

> **تنبيه ترتيب**: T075–T076 (هجرة `auth_sessions` ونموذجها) **تُنفَّذ قبل T044** لأن المنحة
> تُربط بـ`auth_session_id`. بقية القصة مستقلّة عن US1 و US2.

### الاختبارات أولاً

- [X] T072 [P] [US3] أنشئ `backend/tests/Feature/Auth/DeviceLimitTest.php`: جهازان متتابعان ⇒ جلسات الأول `ended` بسبب `device_limit` والجديدة تعمل وحدها، و**الدخول الجديد لا يُرفض ولو مرة** (SC-006 · FR-023). وحالة ثانية: **جلستان على نفس الجهاز تبقيان معاً** — صفر إنهاء خاطئ (SC-006ج · FR-022ب)
- [X] T073 [P] [US3] أضف حالة **`mid-playback`** في نفس الملف: أصدر منحة، ابدأ طلبات المدى، أنهِ الجلسة من جهاز ثالث، وتأكّد أن **طلب المدى التالي `403`** والتجديد `401` — **بلا أي فعل من المشاهد** (SC-006أ · research §R15)
- [X] T074 [P] [US3] أضف حالة الإعدادات: غيّر `auth.device_limits` في `platform_settings` إلى `2` ⇒ جهازان يبقيان، **بلا إعادة نشر** (SC-006ب · FR-022)

### الكيانات والمنطق

- [X] T075 [US3] أنشئ هجرة `devices` + `auth_sessions` في `backend/app/Modules/Identity/Database/Migrations/` — data-model §٤–٥ بفهرسَيهما
- [X] T076 [US3] أنشئ `backend/app/Modules/Identity/Models/{Device,AuthSession}.php` — **بلا** `BelongsToWorkspace` (مملوكان للمنصة)، و`SessionEndReason` تعداداً في `Identity/Support/`
- [X] T077 [P] [US3] أنشئ مصانع `backend/database/factories/Modules/Identity/` للنموذجين
- [X] T078 [US3] أنشئ `backend/app/Modules/Identity/Support/DeviceFingerprint.php` — `sha256` لـ `X-Device-Id` + `User-Agent` + `Accept-Language`، مع `label()` مقروء بالعربية (research §R7)
- [X] T079 [US3] أنشئ `backend/app/Modules/Identity/Actions/StartAuthSession.php` بالخوارزمية الخماسية في data-model §٥: يحلّ الجهاز، يُنشئ الجلسة **أولاً** (FR-023 يمنع رفض الدخول الجديد)، ثم يعدّ **`device_id` المتمايزة** بين الجلسات النشطة ويُنهي جلسات **أقدم جهاز كلها معاً** حتى يصل العدد لحدّ `PlatformSettings::get('auth.device_limits')`. **يُمنع** العدّ على الجلسات (FR-022ب)
- [X] T080 [US3] أنشئ `backend/app/Modules/Identity/Actions/TerminateAuthSession.php`: يحذف رمز Sanctum ويضبط `status` و`ended_reason` — **حذف الرمز هو الإنهاء**، بلا وسيط (research §R8)
- [X] T081 [US3] أطلق `SecurityAlert` عند الإنهاء بسبب `device_limit` عبر `DispatchNotification` من `Modules/Notifications/` — نوع إلزامي قائم من spec 003. **فقط إن كانت الجلسة المنتهية نشطة حديثاً** (`last_active_at` داخل النافذة): بحدّ جهاز واحد يقع الإنهاء عند كل تنقّل عادي، وتنبيه يومي يُدرَّب المستخدم على تجاهله فيضيع حين يقع الاختراق (FR-025)
- [X] T082 [US3] عدّل `login` و`registerStudent` و`registerParent` في `backend/app/Modules/Identity/Http/Controllers/AuthController.php` لتمرّ عبر `StartAuthSession`، وأضف `session_uuid` إلى استجابة الدخول
- [X] T083 [US3] عدّل `logout` و`changePassword` في نفس الملف: الخروج يُنهي جلسته، وتغيير كلمة المرور يُنهي **بقية** الجلسات وينبّه (FR-031)

### النقاط والحُرّاس

- [X] T084 [US3] أنشئ `backend/app/Modules/Identity/Policies/AuthSessionPolicy.php` — ملكية الصفّ للمستخدم. **يُمنع** على المدرّس مطلقاً، ولو كان الطالب مسجَّلاً عنده (research §R12)
- [X] T085 [US3] أنشئ `backend/app/Modules/Identity/Http/Controllers/SessionController.php` ومسارات `GET /auth/sessions` · `DELETE /auth/sessions/{uuid}` · `GET /auth/sessions/{uuid}/end-reason` (**بلا مصادقة**، `throttle:public`، حقلان لا ثالث)
- [X] T086 [P] [US3] أنشئ `backend/tests/Feature/Auth/PlatformOwnershipTest.php` — الاتجاهان معاً (NFR-001ب): مدرّس **لا** يقرأ أجهزة طالبه المسجَّل عنده، والطالب المسجَّل عند ثلاثة مدرّسين يرى **قائمة أجهزة واحدة** لا ثلاث نسخ

### الواجهة — اكتشاف الإنهاء (research §R15)

- [X] T087 [US3] أضف معالج `401` عاماً في `frontend/src/lib/api.ts`: مسح الرمز + تحويل إلى `/login?ended={reason}` بالسبب من `end-reason`، وحفظ `session_uuid` عند الدخول
- [X] T088 [US3] حوّل `frontend/src/components/app/NotificationBell.tsx` من نداء واحد عند التركيب إلى **استطلاع كل ٦٠ ثانية** — يصلح الشارة ويؤدّي دور نبض الجلسة بنفس النداء. أضف تعليق `ponytail:` يسمّي Reverb (المرحلة 010) مسار الترقية
- [X] T089 [US3] اعرض سبب الخروج على `frontend/src/app/(app)/login/page.tsx` من `?ended=` برسالة عربية من `src/lib/labels.ts`
- [X] T090 [US3] أنشئ `frontend/src/app/(app)/(shell)/settings/security/page.tsx` بقائمة الأجهزة والجلسات وزر إنهاء لكلٍّ منها (FR-024)، مبنيّة على `components/ui/`
- [X] T091 [US3] أضف بطاقة رابط إلى `/settings/security` في `frontend/src/app/(app)/(shell)/settings/page.tsx` — **في نفس المهمة** التي تُنشئ الصفحة

**Checkpoint**: البديل المجاني للشراء مغلق، والإنهاء يصل صاحبه بلا أن يفعل شيئاً.

---

## Phase 6: User Story 4 — حسابات المدرّسين والإدارة محصّنة (P4)

**Goal**: تسرّب كلمة المرور وحده لا يكفي لاختراق محتوى المدرّس ومال المنصة.

**Independent Test**: فعّل التحقق الثنائي لحساب مدرّس ثم حاول الدخول بكلمة المرور وحدها.

**Depends on**: Phase 2 (T020–T022).

- [X] T092 [P] [US4] أنشئ `backend/tests/Feature/Auth/TwoFactorTest.php`: حساب مفعَّل ⇒ الدخول بكلمة المرور يعيد `{two_factor: true, challenge}` **بلا رمز** (SC-007)
- [X] T093 [P] [US4] أضف حالات في نفس الملف: رمز استرداد يعمل **مرة واحدة** ويُبطَل ويُنبّه (FR-029) · تغيير وسيلة التحقق يُنهي بقية الجلسات (FR-031) · بعد انقضاء المهلة ⇒ `403` برمز `two_factor_required` (FR-028)
- [X] T094 [US4] أنشئ `backend/app/Modules/Identity/Support/TwoFactorCodes.php` يغلّف `PragmaRX\Google2FA\Google2FA` (مثبَّت سلفاً تحت Filament) وتوليد رموز الاسترداد
- [X] T095 [US4] أنشئ `EnableTwoFactor` و`ConfirmTwoFactor` و`DisableTwoFactor` في `backend/app/Modules/Identity/Actions/` — التفعيل والتعطيل **يتطلّبان كلمة المرور الحالية**
- [X] T096 [US4] أنشئ `backend/app/Modules/Identity/Actions/CompleteTwoFactorChallenge.php` — التحدّي في `Cache` عشر دقائق لا في جدول (research §R10)
- [X] T097 [US4] عدّل `AuthController::login` ليعيد التحدّي بدل الرمز لحساب `two_factor_confirmed_at` غير فارغ
- [X] T098 [US4] أنشئ `backend/app/Modules/Identity/Http/Controllers/TwoFactorController.php` والمسارات الخمسة حسب contracts/api.md، كلها خلف `throttle:two-factor`. `GET /auth/2fa` **بلا** السرّ وبلا الرموز
- [X] T099 [US4] أنشئ `backend/app/Shared/Middleware/RequireTwoFactor.php` وسجّله باسم `2fa.required` في `backend/bootstrap/app.php`
- [X] T100 [US4] طبّق `2fa.required` **صراحةً** على مسارات مسمّاة: اعتماد الدفع ورفضه · إدارة الأعضاء · إعدادات مساحة العمل · اعتماد المدرّسين · حذف الأصول — **لا قائمة عامة تنمو بالنسيان**
- [X] T101 [US4] اضبط `two_factor_required_at` عند إنشاء أو ترقية حساب بصلاحيات إدارية، بمهلة من `PlatformSettings::get('auth.two_factor_grace_days')`
- [X] T102 [US4] أضف قسم التحقق الثنائي إلى `frontend/src/app/(app)/(shell)/settings/security/page.tsx`: التفعيل ورمز `otpauth` ورموز الاسترداد **مرة واحدة** والتعطيل
- [X] T103 [US4] أضف شاشة التحدّي إلى `frontend/src/app/(app)/login/page.tsx` مع خيار رمز الاسترداد
- [X] T104 [P] [US4] أضف حالة إلى `TwoFactorTest`: **سرّ واحد للسطحين** — تفعيل من الـ API ثم دخول لوحة `/admin` بنفس التطبيق المصادق (research §R9)

**Checkpoint**: الحسابات التي تملك المحتوى والمال محصّنة.

---

## Phase 7: النصّ المصاحب وإمكانية الوصول (FR-032–FR-037)

**Purpose**: مجموعة متطلّبات لا قصة مستخدم — تمدّد مشغّل US1. بلا وسم قصة.

- [X] T105 [P] أنشئ `backend/tests/Feature/Media/CaptionsTest.php`: رفع WebVTT صالح ⇒ يظهر · ملف مشوّه ⇒ `422` · نصّ كامل مشتقّ من المقاطع (FR-034)
- [X] T106 أنشئ `AttachCaption` و`DeleteCaption` في `backend/app/Modules/Media/Actions/` مع تحقّق من بنية WebVTT
- [X] T107 أنشئ `backend/app/Modules/Media/Http/Controllers/CaptionController.php` والمسارين حسب contracts/api.md
- [X] T108 أضف `<track kind="captions">` وتغيير سرعة العرض إلى `frontend/src/components/player/VideoPlayer.tsx` (FR-033 · FR-035)
- [X] T109 استأنف من `resume_at_seconds` في `VideoPlayer.tsx`، واحفظ الموضع عبر نداء `renew` القائم لا بمسار جديد (FR-036)
- [X] T110 أنشئ `frontend/src/components/player/TranscriptPanel.tsx` — يحلّل WebVTT، يعرض المقاطع بأوقاتها، والنقر يقفز. **لا تخزين ثانٍ للنصّ**
- [X] T111 تأكّد أن العلامة المائية **لا تحجب** النصّ المصاحب في `Watermark.tsx` (FR-037)
- [X] T112 أضف قسم النصوص إلى صفحة إدارة الدرس `frontend/src/app/(app)/(shell)/manage/courses/[uuid]/lessons/[lessonUuid]/page.tsx`
- [X] T113 [P] أضف الصفحات الثلاث الجديدة إلى مصفوفتَي `PAGES` في `frontend/e2e/accessibility.spec.ts` و`frontend/e2e/rtl.spec.ts` (SC-013 · NFR-011)

---

## Phase 8: Polish & Cross-Cutting

- [ ] T114 [P] أضف مدخلات `attributes` العربية للحقول الجديدة في `backend/lang/ar/validation.php` — بدونها يظهر `original_filename` بالإنجليزية للمستخدم
- [ ] T115 [P] أضف رسائل الأخطاء الجديدة (`409` قيد التجهيز · `two_factor_required` · سبب انتهاء الجلسة) إلى `frontend/src/lib/errors.ts` و`labels.ts` — **يُمنع** عرض خطأ خام
- [ ] T116 أضف بذر القيم الافتراضية لـ`platform_settings` إلى `backend/database/seeders/` — النظام يعمل بلا بذر (الرجوع إلى `config()`)، والبذر يجعل القيم ظاهرة قابلة للتحرير
- [ ] T117 أضف أمراً مجدولاً يوميّاً لحذف المنح المنتهية في `backend/routes/console.php` مع تعليق `ponytail:` يسمّي التقسيم مسار الترقية إن بلغ الجدول الملايين
- [ ] T118 [P] حدّث `docs/erd.md` بالجداول التسعة وطبقة كلٍّ منها، وبحذف `lessons.media` ونقل عمودَي `users`
- [ ] T119 [P] حدّث `docs/README.md`: وحدة `Media` · نقاط النهاية · الحدث `MediaAssetReady` · التغيير الكاسر في `LessonResource` و`UserResource`
- [ ] T120 [P] حدّث `CLAUDE.md` بثلاث مزالق: العلامة المائية هي حلقة التجديد · الحدّ على الجلسات لا البصمة · `users` لا يقبل عمود دور
- [ ] T121 [P] حدّث `AGENTS.md` بالاختبارات الحرجة الجديدة (منح التشغيل · حدّ الأجهزة · مطابقة العقد) وبقسم فصل جداول الأدوار
- [ ] T122 حدّث `docs/roadmap.md`: علّم 004 مُنفَّذة، وصحّح سطرها (المزوّد مؤجَّل خلف واجهة لا «Bunny Stream»)
- [ ] T123 شغّل البوابات الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit` — **يُمنع** baseline جديد أو `@phpstan-ignore` (SC-014)
- [ ] T124 شغّل Playwright كاملاً: `player.spec.ts` + `accessibility.spec.ts` + `rtl.spec.ts` بـ `--config=e2e/playwright.local.config.ts`
- [ ] T125 راجع `quickstart.md` سيناريو سيناريو على نسخة نظيفة (`migrate:fresh --seed`) وصحّح أي انحراف بين الوثيقة والسلوك

---

## Dependencies

```
Phase 1 (Setup)
   ↓
Phase 2 (Foundational) ──────────────────── حاجبة لكل ما بعدها
   ↓
Phase 3 (US1 · P1) ◄── تعتمد على T075–T076 من Phase 5 (auth_session_id)
   ↓
Phase 4 (US2 · P2) ── تعتمد على US1 (لا علامة بلا مشغّل)
   ↓
Phase 5 (US3 · P3) ── بقيتها مستقلّة عن US1 و US2
   ↓
Phase 6 (US4 · P4) ── مستقلّة تماماً بعد Phase 2
   ↓
Phase 7 (النصوص) ── تمدّد مشغّل US1
   ↓
Phase 8 (Polish)
```

**الاعتماد الوحيد بين القصص**: `PlaybackGrant.auth_session_id` يحتاج جدول `auth_sessions`.
الحل: **نفّذ T075–T076 مبكراً** (مع Phase 2) وأبقِ بقية US3 في مكانها. البديل — منحة بلا
ربط جلسة ثم إضافته لاحقاً — يعني شحن FR-009 معطّلاً ثم إصلاحه، وهو ما يخلق نافذة يعمل فيها
الرابط من أي جلسة.

**US4 مستقلّة تماماً**: يمكن تنفيذها بالتوازي مع US1 بعد Phase 2.

---

## Parallel Execution Examples

**Phase 2** — بعد T007:
```
T023 (Enums) · T024 (Data) · T028 (Factories) · T011 (PlatformSettingsTest)
```

**Phase 3** — الاختبارات معاً قبل التنفيذ:
```
T041 · T042 · T043 · T053   ← أربعة ملفات/حالات مستقلّة
```

**Phase 5 و Phase 6 معاً** — بعد Phase 2، فريقان:
```
فريق أ: T072 → T091   (الأجهزة والجلسات)
فريق ب: T092 → T104   (التحقق الثنائي)
```
يتقاطعان في `AuthController` فقط (T082/T083 مقابل T097) — نسّق ترتيبهما.

**Phase 8** — التوثيق كله متوازٍ:
```
T118 · T119 · T120 · T121   ← أربعة ملفات مختلفة
```

---

## Implementation Strategy

### MVP = Phase 1 + Phase 2 + Phase 3 (US1)

**ما يُغلَق**: أوسع قناة تسريب — الرابط الدائم القابل للنسخ. رابط موقّت مربوط بجلسة،
وأصل موثّق البنية بدل عمود JSON حرّ، وعقد مزوّد يجعل التعاقد لاحقاً ملفاً وسطراً.

**ما يبقى مفتوحاً**: التصوير الخارجي (US2) · مشاركة الحساب (US3) · تسرّب كلمة مرور مدرّس (US4).

### الترتيب المقترح للتسليم

1. **Phase 1–2** — بلا قيمة ظاهرة للمستخدم، وبدونها لا شيء يعمل.
2. **US1** — أول قيمة حقيقية. قابل للشحن وحده.
3. **US3** (قبل US2) — أثرها المالي أكبر، وهي مستقلّة عن العلامة المائية.
4. **US2** — الرادع الأخير الذي لا يغلقه رابط موقّع.
5. **US4** — لا تحجب شيئاً قبلها، وأخطر ما تحميه أندره وقوعاً.
6. **Phase 7–8**.

### ما لا يُنفَّذ في هذه المرحلة — معلناً

`hls.js` وبيان HLS · webhook المزوّد · النصّ المصاحب الآلي · الجودة التكيّفية فعلياً
(SC-008) — كلها **خلف العقد**، ولا واحدة منها تتطلّب تعديلاً في `Actions/` عند اعتمادها.
راجع جدول *Deferred Verification* في [plan.md](./plan.md).
