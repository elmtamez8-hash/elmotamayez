---

description: "Task list — 011 التجارة والنمو"
---

# Tasks: التجارة والنمو (Commerce & Growth)

**Input**: Design documents from `/specs/011-commerce-growth/`

**Prerequisites**: [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) · [data-model.md](./data-model.md) · [contracts/api.md](./contracts/api.md) · [quickstart.md](./quickstart.md)

**Tests**: **مطلوبةٌ في هذه المرحلة.** المبدأُ الرابعُ في الدستورِ يجعلُ البوّاباتِ الخضراءَ شرطَ اندماج، و`NFR-008` تُكرِّرُه، و**١١ من الـ١٧ `SC` تصفُ بناءَ الاختبارِ نفسِه** لا نتيجتَه (تدخّلٌ بينَ القراءةِ والمطالبة · مساحتا عملٍ لا واحدة · شاهدٌ موجبٌ في الحصّةِ نفسِها · تشغيلُ الوظيفةِ مرّتَين). فمهامُّ الاختبارِ جزءٌ من التسليم.

**⚠️ وهذه أكبرُ مرحلةٍ في المستودع** — ٤٨ متطلَّباً و٦ قصصٍ و١٣ جدولاً، ضِعفا ٠٠٨. **كلُّ موجةٍ تُدمَجُ خضراءَ وحدَها**؛ دفعةٌ واحدةٌ في آخرِها تُراجَعُ بلا مراجِع.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يجوزُ توازيه (ملفٌّ مختلف، بلا اعتمادٍ على ناقص)
- **[Story]**: US1 … US6
- كلُّ مهمّةٍ تحملُ مسارَ ملفِّها

## Path Conventions

`backend/app/Modules/{Module}/…` · `backend/tests/Feature/{Module}/…` · `frontend/src/…`

---

## Phase 1: Setup

**Purpose**: ما تحتاجُه المرحلةُ كلُّها ولا يخصُّ قصّةً بعينِها.

### أ. الوحدةُ الجديدة — ستّةُ بنودٍ إجباريّة

> ⚠️ **`Store` أوّلُ وحدةٍ جديدةٍ منذُ ٠١٣**، والثلاثةُ المعروفةُ ليست كلَّ ما تحتاجُه. الثلاثةُ الأخرى كُشِفَتْ في مراجعةِ الوكلاء.

- [X] T001 أنشئْ هيكلَ `backend/app/Modules/Store/` (`StoreServiceProvider.php` يرثُ `App\Shared\Modules\Module`، و`routes/api.php` فارغاً، و`Database/Migrations/`). ⚠️ **`M` كبيرةٌ في `Migrations`**: `Module::registerMigrations()` يطابقُ الاسمَ حرفيّاً — يعملُ على ويندوز ويُحمِّلُ **صفرَ** هجرةٍ على لينكس. **ولا تُسجِّلْ مزوِّدَ الوحدةِ في `bootstrap/providers.php`** — الاكتشافُ آليّ.
- [X] T002 أضِفْ صفَّ `app/Modules/Store/Database/Migrations` إلى `backend/phpstan.neon`. ⚠️ بدونِه تُقرَأُ خصائصُ نماذجِ المتجرِ كـ`mixed` ويسقطُ L8.
- [X] T003 أضِفْ `'Store'` إلى قوائمِ الجداولِ في `backend/tests/Feature/Settlement/ContextIsolationTest.php`، **وكتلةَ `it()` خاصّةً بها**: مسوحُ الاستيرادِ الأربعةُ تسمّي وحداتِها **حرفيّاً**، والملفُّ يقولُ عن نفسِه إنّ «وحدةً تُكتَبُ في ٢٠٢٧ غيرُ مرئيّةٍ لكلَيهما».
- [X] T004 ⚠️ **مُصحَّحٌ عندَ التنفيذ — عقدُ حمايةِ البياناتِ لا يسبقُ النماذج.** كان هنا «أنشئْ `StorePersonalData`»، و`export`/`erase`/`expire` كلُّها تمشي `StoreOrder` و`Shipment` — وهما في T029–T032. فكتابتُها الآنَ تعني `PersonalDataOwner` تُرجِعُ ثلاثةَ أصفار: **الوسمُ حاضرٌ والاختبارُ أخضرُ وطلبُ المحوِ يكتملُ تاركاً العنوانَ مكانَه** — شكلُ الحارسِ الذي سجَّلَه هذا المستودعُ مرّتَين. ⇒ الوحدةُ تشحنُ بلا عمودٍ شخصيٍّ **وبلا وسم** (متّسقةٌ لا نصفَ محروسة)، والقرارُ مكتوبٌ في دفترِ تعليقِ `StoreServiceProvider`، **والتنفيذُ انتقلَ إلى T032**.
- [X] T005 صفُّ وحدةِ `Store` في جدولِ وحداتِ `docs/README.md`. ⚠️ **و`docs/erd.md` والنقاطُ والصلاحياتُ تُوثَّقُ مع موجتِها لا هنا**: الجداولُ الثلاثةَ عشرَ تصلُ عبرَ ستِّ موجات، وتوثيقُ جدولٍ قبلَ هجرتِه توثيقُ نيّةٍ لا بناء. **كلُّ موجةٍ توثِّقُ جداولَها في دفعتِها**، وهو ما تعنيه بوّابةُ «سير العمل» بالدستور.

### ب. الصلاحيات — المكانُ هو التصنيف

> ⚠️ `platformPermissions()` = `all()` ناقصَ ما تحملُه أدوارُ المستأجِر. **الغيابُ عن كلِّ مصفوفةِ دورٍ هو الإعلانُ**، وثابتٌ خارجَ `all()` **لا يحملُه أحدٌ ولو كان مشرفاً عامّاً**.

- [X] T006 أضِفْ خمسةَ ثوابتَ إلى `backend/app/Modules/Tenancy/Support/Permissions.php` **وإلى `all()`**: `store.items.manage` · `store.shipments.manage` · `plans.manage` · `billing.coupons.manage` · `flags.manage`.
- [X] T007 أضِفِ الثلاثةَ الأولى إلى `$teacher` في `backend/app/Modules/Tenancy/Support/RolePermissionMatrix.php`، و**اترُكِ الأخيرتَين خارجَ كلِّ مصفوفة**. ⚠️ ولا تلمسْ `billing.settings.manage` — نزعَتْها ٠١٤ من دورِ المالكِ عمداً و`Tenancy\Models\Role` يرمي إن عادت.
- [X] T008 [P] أضِفْ تسمياتٍ عربيّةً للخمسةِ في `backend/app/Modules/Tenancy/Support/PermissionLabels.php`.
- [X] T009 [P] اختبارُ `backend/tests/Feature/Tenancy/CommercePermissionNamesTest.php` **يُثبِّتُ الأسماءَ الخمسةَ حرفيّاً** في جانبَيها (منصّة/مستأجِر). ⚠️ `PermissionPanelTest` يقارنُ `tenantMap()` بـ`tenantPermissions()` و**الأولى مبنيّةٌ من الثانية** — اشتقاقٌ يُقارَنُ بنفسِه، فلا يرى خطأً في التصنيف.

### ج. المحدّدات

- [X] T010 أضِفْ `store-write` (٣٠/دقيقة، مفتاحُه المستخدِم) و`coupon` (١٠/دقيقة، المستخدِم + IP) في `backend/app/Providers/AppServiceProvider.php › registerRateLimiters()`. ⚠️ **`throttle:auth` ممنوعٌ على مسارِ كتابة**: مفتاحُه الثاني `'email:'.$request->input('email')` ومسارٌ بلا `email` يجعلُه ثابتاً ⇒ عدّادٌ واحدٌ لكلِّ كتاباتِ المنصّة. والسطريُّ `throttle:N,M` ممنوعٌ كذلك.
- [X] T011 [P] أضِفْ `throttle:authoring` إلى مساراتِ `backend/app/Modules/CMS/routes/api.php` الستّ — تشحنُ اليومَ بـ`auth:sanctum` وحدَها، وأربعةٌ منها كتابة (NFR-014).

### د. كتالوجاتُ وقتِ التشغيل — **القاعدة، لا المهامّ**

> ⚠️ كتالوجٌ يُقرَأُ وقتَ التشغيلِ ولا صفَّ له **يسكتُ ولا يُخطئ**، وقد وقعَ ثلاثَ مرّاتٍ في هذا المستودع. و`tests/Pest.php` تبذرُها قبلَ كلِّ حالةٍ ⇒ **كلُّ اختبارٍ أخضرُ على جدولٍ لا تملكُه المنصّةُ الحيّة**.

- [X] T012 ⚠️ **مُصحَّحٌ عندَ التنفيذ — الصفُّ يُشحَنُ مع الكودِ الذي يقرأُه، لا قبلَه بخمسِ موجات.** كانت هنا أربعُ هجراتِ `seedMissing()` مجموعةً بالموضوعِ (تلعيبٌ · فئاتُ بيانات · قوالبُ إشعارٍ · مناطق) — وثلاثٌ منها تصفُ جداولَ وأنواعاً لم تُخلَقْ بعد، والرابعةُ تمنحُ نقاطاً لحدثٍ لا وجودَ له. **وهذا نقضُ القاعدةِ التي كُتِبَتْ فوقَها**: صفٌّ يسبقُ قارئَه صفٌّ لا يُقاسُ، تماماً كما أنّ صفّاً يتأخّرُ عنه صفٌّ يسكت. ⇒ **كلُّ كتالوجٍ ينتقلُ إلى موجتِه**: `invite_friend` ⇒ **T081** · `data_categories` ⇒ **T032** · قوالبُ الشحنةِ والاشتراك ⇒ **T039** و**T096** · `regions` ⇒ **T118**. والقاعدةُ تبقى هنا لأنّها تسري على الموجاتِ الستِّ كلِّها.
- [X] T013 ⚠️ مدموجةٌ في T012 أعلاه — انظرْ سببَ النقل.
- [X] T014 ⚠️ مدموجةٌ في T012 أعلاه.
- [X] T015 ⚠️ مدموجةٌ في T012 أعلاه.

---

## Phase 2: Foundational (Blocking Prerequisites)

**⚠️ CRITICAL**: لا تبدأُ قصّةٌ قبلَ اكتمالِ هذه المرحلة.

### أ. استخراجُ نواةِ منحةِ التشغيل — أخطرُ مهمّةٍ في المرحلة

> ⚠️ **يمسُّ البابَ الوحيدَ لتشغيلِ أيِّ ملفٍّ في المنتَج.** `IssuePlaybackGrant::handle()` توقيعُها `(Lesson $lesson, …)` وترفضُ أيَّ أصلٍ `owner_type !== Lesson::class` — فمنتَجُ المتجرِ لا يركبُها، والادّعاءُ الأوّلُ بأنّها «FR-004 بنصِّه» كان خطأً.
> **الانضباطُ معكوسٌ عن TDD**: اختباراتُ `IssuePlaybackGrant` القائمةُ **خضراءُ قبلَ الاستخراجِ وبعدَه بلا تعديلِ حرفٍ فيها**، أو الاستخراجُ غيّرَ معنًى.

- [X] T016 شغِّلْ `php vendor/bin/pest tests/Feature/Media` وسجِّلْ خطَّ الأساسِ الأخضر — شبكةُ الأمانِ لـT017–T020.
- [X] T017 أنشئْ `backend/app/Modules/Media/Actions/MintPlaybackGrant.php` يحملُ **سكَّ المنحةِ منقولاً حرفيّاً** من `IssuePlaybackGrant` (المدّةُ · الجهازُ · العلامةُ المائيّةُ · الإبطال)، بلا أيِّ فرعِ استحقاق.
- [X] T018 حوِّلْ `backend/app/Modules/Media/Actions/IssuePlaybackGrant.php` إلى **بابِ استحقاقٍ رفيعٍ** فوقَ `MintPlaybackGrant`: نفسُ التوقيعِ ونفسُ الشروطِ ونفسُ الرسائل، بلا تغييرِ مُنادٍ واحد.
- [X] T019 أعِدْ تشغيلَ `php vendor/bin/pest tests/Feature/Media` — **خطُّ الأساسِ نفسُه، وبلا تعديلِ أيِّ ملفِّ اختبار**.
- [~] T020 **مُرحَّلةٌ إلى Phase 3 (T036أ) — لا تُكتَبُ هنا.** البابُ يتحقّقُ من ملكيّةِ `store_orders`، والنموذجُ يصلُ في T032. وبابٌ يُحَلُّ ملكيّتُه من جدولٍ غيرِ موجودٍ إمّا لا يُصرَّفُ أصلاً أو يُكتَبُ بشرطٍ مؤقّتٍ يُنسى — وهو شكلُ الخللِ نفسُه الذي رحَّلَ T004: حارسٌ حاضرٌ واختبارٌ أخضرُ وسؤالٌ لا يُسأَلُ. ⚠️ **وما يهمُّ من T020 قد أُنجِزَ**: `MintPlaybackGrant` مستخرَجةٌ (T017) و`IssuePlaybackGrant` بابٌ رفيعٌ فوقَها (T018) بخطِّ أساسٍ لم يتغيّرْ (T019) — فلم يبقَ إلّا بابُ المتجرِ ذاتُه، ومكانُه حيثُ صفُّه.

### ب. تعدادُ الطلبِ وقُرّاؤه

- [X] T021 أضِفْ **حالتَين** إلى `backend/app/Modules/Payments/Enums/OrderKind.php`: `Store = 'store'` و`Subscription = 'subscription'`. ⚠️ العمودُ `string(16)` يتّسعُ لهما.
- [X] T022 امشِ على **كلِّ** قارئٍ لـ`orders.kind`. المحميّانِ بعودةٍ مبكّرة: `RecordCreditPurchase` · `CreateEnrollmentFromOrder`. **والمكشوفانِ قائمتان**: `backend/app/Modules/Payments/Http/Controllers/OrderController.php` و`backend/app/Filament/Resources/OrderResource.php` — كلاهما `where('kind','!=',Credits)` ⇒ **مبيعاتُ المتجرِ والاشتراكاتُ تسقطُ في قائمةِ طلباتِ المدرّسِ وجدولِ اللوحة**. اقطعْهما على الاستعلامِ نفسِه.
- [X] T023 أضِفِ الحالتَين إلى فرعِ صلاحيةِ المنصّةِ في `backend/app/Modules/Payments/Policies/OrderPolicy.php › approve()`. ⚠️ **البائعُ لا يشهدُ أنّ ثمنَه وصل**: `PAYMENTS_APPROVE` في مصفوفةِ المدرّسِ وفحصُ مساحةِ العملِ يستوفيه بالتعريف — وهو ما يشرحُه دفترُ تعليقِ ذلك التابعِ لشراءِ الأرصدةِ حرفيّاً.
- [X] T024 [P] اختبارُ `backend/tests/Feature/Payments/StoreApprovalIsPlatformTest.php`: مدرّسٌ **لا يعتمدُ** طلبَ متجرِه ولا اشتراكاً في مساحتِه.

### ج. الهجراتُ المشتركة

- [X] T025 [P] هجرةُ `backend/app/Modules/CMS/Database/Migrations/…_dedupe_cms_article_slugs.php` — تُلحِقُ لاحقةً بكلِّ تصادمِ slug قائم. ⚠️ **قبلَ الفهرسِ لا بعدَه** (درسُ ٠١٦: الترقيمُ قبلَ الفهرس).
- [X] T026 هجرةُ `…_add_public_slug_index_to_cms_articles.php`: `unique(slug)` + `index(status, published_at)`. ⚠️ **كلُّ فهارسِ الجدولِ تبدأُ اليومَ بـ`workspace_id` والقراءةُ العامّةُ زائرٌ بلا مساحة** ⇒ مسحُ جدولٍ كاملٍ على كلِّ فتحةِ مقالٍ وكلِّ خريطةِ موقع؛ و`unique(workspace_id, slug)` تجعلُ `/blog/{slug}` **غامضاً بالتعريف**.
- [X] T027 [P] هجرةُ `feature_flags` في `backend/app/Modules/Tenancy/Database/Migrations/` بـ`unique(key, workspace_id)` وسنتينل **`0` = الافتراضُ العامّ**. ⚠️ عمودٌ `nullable` في فهرسٍ فريدٍ **لا يعضّ**.
- [X] T028 [P] `backend/app/Modules/Tenancy/Support/Flags.php` بتوقيعِ `enabled(string $key, ?int $workspaceId)`، يقرأُ **خريطةً واحدةً** `whereIn('workspace_id', [$ws, 0])` مُذكَّرةً في ربطٍ **`scoped()`**. ⚠️ لا `bind()` (يعملُ عشراتِ المرّاتِ في الصفحة) ولا `singleton()` (حاويةُ العاملِ تعيشُ أطولَ من الوظيفةِ فيبقى مفتاحٌ أُطفِئَ ظهراً مشتعِلاً). و`abort_if` قبلَ الكتابةِ لأنّ `(int) null === 0` يُخاطِبُ الصفَّ الافتراضيّ.

---

## Phase 3: US1 — متجرُ الكتبِ والمذكّرات (P1) 🎯 MVP

**Goal**: مدرّسٌ يبيعُ كتاباً رقميّاً أو مطبوعاً؛ الرقميُّ يُسلَّمُ فوراً بمنحةٍ قصيرةِ العمر، والمطبوعُ يخصمُ المخزونَ ويُنشئُ شحنة.

**Independent Test**: أنشئْ منتَجاً رقميّاً وآخرَ مطبوعاً، اشترِهما، وتحقّقْ من التسليمِ الفوريِّ للأوّلِ وإنشاءِ شحنةٍ للثاني.

### الهجراتُ والنماذج

- [X] T029 [P] [US1] هجرةُ `store_items` في `backend/app/Modules/Store/Database/Migrations/` — أعمدةُ data-model §١، و`stock` **`integer` مُوقَّعٌ nullable** (⚠️ `unsigned` يُفجِّرُ `ERROR 1690` على MySQL وحدَها)، وفهارسُ `[workspace_id, is_active, id]` (⚠️ `id` في الذيلِ ليخدمَ الترتيبَ) · `[workspace_id, kind]` · `[course_id]`.
- [X] T030 [P] [US1] هجرةُ `store_orders` — أعمدةُ §٢ ومنها **`fulfilled_at`** و`first_accessed_at` و`refunded_at`، وفهارسُ `[buyer_user_id, created_at]` · `[workspace_id, created_at]` · ⚠️ **`[store_item_id]`** (بدونِه `withCount` مسحٌ لكلِّ صفّ).
- [X] T031 [P] [US1] هجرةُ `shipments` — §٣، **بلا `region_id`** (⚠️ يربطُ م١ بجدولٍ يشحنُ في م٦ فتسقطُ استقلاليّتُها)، وفهرسُ `[workspace_id, status]`.
- [X] T032 [US1] ⚠️ **+ صفوفُ `data_categories` وهجرةُ `seedMissing()` (منقولةٌ من T013).** نماذجُ `backend/app/Modules/Store/Models/{StoreItem,StoreOrder,Shipment}.php` — `HasUuid` + `BelongsToWorkspace` + `declare(strict_types=1)`. **وفي نفسِ المهمّة** (منقولةٌ من T004): `backend/app/Modules/Store/Support/StorePersonalData.php` مُنفِّذةً `PersonalDataOwner`، والسطرُ الموسومُ في `StoreServiceProvider::register()`. ⚠️ `export()` **مولِّدٌ** يُركِّبُ قائمةَ حقولِ الوحدةِ لا `->toArray()`، و`erase()`/`expire()` بـ**`chunkById`** لا `chunk`، وكلتاهما تأخذُ حدّاً وتُعيدُ عدّاً ليكونَ المحوُ قابلاً للاستئناف. **والوسمُ والهجرةُ يصلانِ معاً** — `PersonalDataContractCoverageTest` يُحمِّرُ البناءَ لحظةَ تملكُ الوحدةُ عموداً شخصيّاً.
- [X] T033 [P] [US1] تعدادا `backend/app/Modules/Store/Enums/{StoreItemKind,ShipmentStatus}.php` بـ`HasArabicLabel` و`BuildsOptions`.
- [X] T034 [P] [US1] مصانعُ `backend/database/factories/Modules/Store/{StoreItemFactory,StoreOrderFactory,ShipmentFactory}.php`. ⚠️ `guessFactoryName()` يرمي حين تغيب، وكلُّ اختبارٍ في هذه القصّةِ يعتمدُ عليها.

### الأفعال

- [X] T035 [US1] `backend/app/Modules/Store/Actions/SaveStoreItem.php` — قواعدُ النوعِ الثلاثُ **في الفعلِ لا في التحقّقِ وحدَه** (`SeedCommand` يعملُ داخلَ `Model::unguarded()`)، و`media_asset_id` **يُستقبَلُ uuid ويُحَلُّ داخلَه** بفحصِ مساحةِ العمل. ⚠️ `exists:media_assets,id` الخامُّ يدعُ مدرّساً يُرفِقُ ملفَّ مدرّسٍ آخرَ ويبيعُه.
- [X] T036 [US1] `backend/app/Modules/Store/Actions/PurchaseStoreItem.php` — ينشئُ `Order(kind=store)` و`store_orders` **بلا خصمِ مخزونٍ ولا تسليم**، ويرفضُ مطبوعاً بلا عنوان (FR-007). ⚠️ `workspace_id` يُسنَدُ **صراحةً** من `StoreItem`: الوصفُ يملأُه `if (!== null)` وسياقُ الطالبِ `null` دائماً ⇒ **فراغٌ صامت**.
- [X] T036أ [US1] (مُرحَّلةٌ من T020) `backend/app/Modules/Store/Actions/IssueStoreAccess.php` — البابُ الثاني فوقَ `MintPlaybackGrant`: يتحقّقُ من صفِّ `store_orders` باسمِ المشتري ومن حالتِه المُسلَّمة، ومن `AccountStanding`، ثمّ يستدعي السَّكَّ. ⚠️ **`AccountStanding` لا `Payments\Support\WithholdingReader`**: الأوّلُ هو العقدُ المُصرَّحُ به عبرَ الوحدات، والثاني نموذجُ وحدةٍ أخرى يُحمِّرُ `ContextIsolationTest`. ⚠️ **ولا شرطَ استحقاقٍ يُكتَبُ داخلَ `MintPlaybackGrant`**: شرطٌ يزحفُ إلى السَّكِّ يبدأُ البابُ الآخرُ بفرضِه بلا قرارٍ من أحد.
- [X] T037 [US1] `backend/app/Modules/Store/Listeners/FulfilOnPaymentApproved.php` — `ShouldQueue` + **`ShouldHandleEventsAfterCommit`** (⚠️ `ApproveOrder` يُطلِقُ الحدثَ داخلَ `DB::transaction`؛ مستمِعٌ عاديٌّ هناك نقطةُ فشلٍ مفردةٍ لما بعدَه، ومطبورٌ بلا الواجهةِ الثانيةِ يقرأُ الطلبَ `pending` **وينجح**). **وأوّلُ جملةٍ فيه مطالبةُ `fulfilled_at`**، وكلُّ ما بعدَها مشروطٌ بالفوز.
- [X] T038 [US1] `backend/app/Modules/Store/Actions/ClaimStock.php` — `UPDATE … WHERE id = ? AND stock >= :qty`. ⚠️ **الفرعُ على `kind` أوّلاً**: `stock` `null` للرقميّ و`stock >= :qty` عليه `NULL` ⇒ صفرُ صفوفٍ ⇒ «نفدَ» منتَجٌ لا ينفد. **ولا `lockForUpdate()`** — لا أثرَ له على SQLite.
- [X] T039 [US1] ⚠️ **+ قالبُ إشعارِ تغيُّرِ حالةِ الشحنةِ وهجرةُ `seedMissing()` (منقولٌ من T014) — إشعارٌ بلا قالبٍ يُسقَطُ صامتاً.** `backend/app/Modules/Store/Actions/AdvanceShipment.php` — `WHERE status = :expected`، وإشعارٌ عبرَ `DispatchNotification`. ⚠️ قراءةٌ ثمّ كتابةٌ من مشغّلَين تُخبِرُ المشتريَ مرّتَين أو تُرجِعُ الحالةَ للوراء.
- [X] T040 [US1] `backend/app/Modules/Store/Actions/RefundStorePurchase.php` — ≤٤٨ ساعةً **و**`first_accessed_at IS NULL`.
- [X] T041 [US1] فرعُ نفادِ المخزونِ بعدَ القبض: الطلبُ ⇒ `orders.status = refund_due` + إشعارُ المشتري + طابورُ المشغّل. ⚠️ **أيّامٌ تفصلُ الشراءَ عن الاعتمادِ في تحويلٍ بنكيٍّ يدويّ**، و«صفرُ صفٍّ = خسِرْتَ ولا شيءَ بعدَها» كلمةٌ أخيرةٌ خاطئةٌ لطلبٍ قُبِضَ ثمنُه.

### السياساتُ والمساراتُ والموارِد

- [X] T042 [P] [US1] `backend/app/Modules/Store/Policies/{StoreItemPolicy,ShipmentPolicy}.php` بأسماءٍ من `Permissions`.
- [X] T043 [US1] `backend/app/Modules/Store/Http/Resources/{StoreItemResource,StoreOrderResource,ShipmentResource}.php`. ⚠️ **لا مفتاحَ `teacher_net_minor` ولا `amount_minor`**: الفحصُ الثاني في `ContextIsolationTest` يمسحُ الوحداتِ كلَّها بإبرةِ `net_minor` (`str_contains`) و`amount_minor` معفًى لـ`Payments/` وحدَها. الشاشةُ تعرضُ سعرَ البيعِ ونسبةَ العمولةِ المعلَنة، والمبلغُ اسمُه `total_minor`.
- [X] T044 [US1] مساراتُ `backend/app/Modules/Store/routes/api.php` كعقودِ م١، بـ`throttle:store-write` و`idempotent` على الشراء. ⚠️ **`{purchase}` لا يُحَلُّ بالربطِ الضمنيّ** — `BelongsToWorkspace` يحمي صفراً على مسارِ طالب؛ الملكيّةُ تُحَلُّ داخلَ الفعلِ بـ`where buyer_user_id`.
- [X] T045 [P] [US1] `FormRequest`ات المتجرِ + مداخلُها في `backend/lang/ar/validation.php › attributes`.

### الاختبارات

- [X] T046 [US1] `backend/tests/Feature/Store/StockConcurrencyTest.php` — `SC-002`. ⚠️ **المُطالِبُ الثاني يُخلَقُ داخلَ خطّافِ اعتمادِ الدفعِ نفسِه** (شكلُ ٠١٧)، لا باستدعاءَين متتاليَين: المتسلسلُ يعودُ عندَ فحصِ المخزونِ قبلَ الكتابةِ بخطوةٍ فيمرُّ على بناءٍ **بلا مطالبةٍ فيه إطلاقاً**.
- [X] T047 [P] [US1] `backend/tests/Feature/Store/FulfilIdempotencyTest.php` — يُعادُ تسليمُ `PaymentApproved` مرّتَين ⇒ **خصمُ مخزونٍ واحد**.
- [X] T048 [P] [US1] `backend/tests/Feature/Store/DigitalAccessTest.php` — `SC-001` + `first_accessed_at` **مرّةً واحدةً تحتَ منحتَين متزامنتَين**، بنفسِ شكلِ الخطّاف.
- [X] T049 [P] [US1] `backend/tests/Feature/Store/StoreWorkspaceIdTest.php` — المشتري يُبنى **بلا بذرةٍ وبلا `setCurrentWorkspace()`** و`workspace_id` مكتوبٌ صحيحاً.
- [X] T050 [P] [US1] `backend/tests/Feature/Store/StoreMediaOwnershipTest.php` — مدرّسٌ لا يُرفِقُ أصلَ مدرّسٍ آخر.
- [X] T051 [P] [US1] `backend/tests/Feature/Store/StoreQueryBudgetTest.php` — `SC-016` بألفِ منتَج: عددٌ **ثابت**، **والحقولُ حاضرةٌ كذلك** (⚠️ تحميلٌ مُسبَقٌ محذوفٌ يجعلُ الصفحةَ أرخصَ وفارغةً فيُقرَأُ التراجعُ تحسيناً).
- [X] T052 [P] [US1] `backend/tests/Feature/Store/RefundWindowTest.php` · `SoldOutAfterPaymentTest.php` · `WithholdingAppliesToStoreTest.php`.
- [X] T053 [P] [US1] حالةٌ في `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` لجداولِ المتجرِ الثلاثة (`SC-015`).

> ⚠️ **و`Queue::fake()` في هذه المجموعةِ يبتلعُ المستمِعَ الذي تدورُ حولَه القصّةُ كلُّها.** `FulfilOnPaymentApproved` مطبورة ⇒ بارٌّ بلا وسيطٍ يجعلُ «خُصِمَ المخزون» ادّعاءً واثقاً عن جدولٍ لم يكتبْه شيء.

### الواجهة

- [X] T054 [P] [US1] `frontend/src/lib/store.ts` — أنواعٌ ونداءاتُ عقودِ م١.
- [X] T055 [US1] شاشةُ المدرّس `frontend/src/app/(app)/(shell)/manage/store/page.tsx` + `frontend/src/components/store/StoreItemForm.tsx` — سعرُ البيعِ ونسبةُ العمولةِ ونصيبُ المدرّسِ **محسوباً في المتصفّح**.
- [X] T056 [US1] طابورُ الشحنات `frontend/src/app/(app)/(shell)/manage/store/shipments/page.tsx`.
- [X] T057 [US1] شاشةُ الطالب `frontend/src/app/(app)/(shell)/store/page.tsx` + `frontend/src/components/store/{StoreItemCard,PurchaseDialog,ShippingAddressFields}.tsx`.
- [X] T058 [P] [US1] اختباراتُ `frontend/src/components/store/*.test.tsx` — نموذجُ المنتَجِ يُبدِّلُ حقولَه بالنوع، وزرُّ الفتحِ **يقولُ قبلَ الضغطِ إنّ الاسترداد يسقطُ به**.
- [X] T059 [US1] روابطُ دخولٍ إلى شاشتَي المتجرِ في `frontend/src/components/layout/Sidebar.tsx` (المدرّسُ والطالبُ كلٌّ حسبَ دورِه). ⚠️ **سطحٌ لا يبلغُه رابطٌ ليس مُنجَزاً.**

**Checkpoint**: م١ تُدمَجُ خضراءَ وحدَها — منتَجٌ قابلٌ للنشرِ بنصِّ قائمةِ التحقّق.

---

## Phase 4: US2 — الكوبوناتُ وخصمُ الإخوة (P2)

**Goal**: خصمٌ يظهرُ صراحةً قبلَ الدفع، لا يمسُّ استحقاقَ المدرّس، ولا يُتجاوَزُ سقفُه تحتَ التزامن.

**Independent Test**: كوبونٌ صالحٌ ومنتهٍ ومستنفَد، وابنٌ ثانٍ لوليِّ أمرٍ له ابنٌ مسجَّل.

- [X] T060 [P] [US2] هجرةُ `coupons` في `backend/app/Modules/Payments/Database/Migrations/` — §٤، `workspace_id` **`nullable` كنطاقٍ لا كمفتاحِ مستأجِر**، و`starts_at`/`ends_at` **`timestamp` لا `date`** (⚠️ تاريخٌ يُقارَنُ بـ`<=` يقتلُ الكوبونَ في يومِه الأخير)، وفهرسُ `[workspace_id, is_active]`.
- [X] T061 [P] [US2] هجرةُ `coupon_redemptions` — `unique(coupon_id, order_id)` وكلاهما `NOT NULL` (⚠️ `NULL != NULL` يُبطِلُ الفهرس)، وفهرسُ `[user_id]`.
- [X] T062 [P] [US2] مفتاحُ `billing.sibling_discount` في `PlatformSettings::KEYS` و`config/billing.php`. ⚠️ **لا جدولَ لكلِّ مساحة**: القيمةُ قرارُ منصّةٍ واحدٌ (قرارُ المستخدم)، والخصمُ يخرجُ من عمولتِها.
- [X] T063 [P] [US2] نموذجا `backend/app/Modules/Payments/Models/{Coupon,CouponRedemption}.php` — ⚠️ **`Coupon` بلا `BelongsToWorkspace`**، والمخالفةُ مسجَّلةٌ في `plan.md › Complexity Tracking`.
- [X] T064 [US2] `backend/app/Modules/Payments/Support/DiscountResolver.php` — يعودُ **بخصمٍ واحدٍ ومصدرِه** (الأعلى وحدَه)، والثابتُ `min(value, line_total)` **داخلَه وحدَه**. ⚠️ الحارسُ في الفعل: `$q->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $current))` — **والقوسانِ ليسا زينة**، بدونَهما ينفصلُ `OR` فيُقبَلُ الكوبونُ المنتهي.
- [X] T065 [US2] `backend/app/Modules/Payments/Support/SiblingDiscount.php` — يقرأُ عبرَ **`Shared\Contracts\GuardianDirectory`** لا باستعلامٍ مباشر. ⚠️ دفترُ تعليقِ ذلك العقدِ يقولُ بالاسمِ إنّ «Payments تستعلمُ `parent_student_relations` بنفسِها» مخالفةٌ للمبدأِ الثالث. **وسِّعِ العقدَ** بدالّةِ «كم طفلاً لهذا الوليّ» — الدالّتانِ القائمتانِ مفتاحُهما `GuardianPermission`، و«هل هذا ابنٌ ثانٍ» ليست إذناً.
- [X] T066 [US2] `backend/app/Modules/Payments/Actions/{PreviewDiscount,RedeemCoupon}.php`. ⚠️ **`preview` لا يستهلكُ سقفاً**، و`RedeemCoupon` **تكتبُ صفَّ الاستعمالِ ثمّ تزيدُ العدّاد** (ترتيبُ `CreditLedger`) — معكوساً يبتلعُ حدثٌ مُعادٌ الإدراجَ ويزيدُ العدّادَ فينفدُ الكوبونُ مبكّراً بلا ما ينتبه.
- [X] T067 [US2] خطّافُ الخصمِ في **ثلاثةِ مسارات**: `PurchaseStoreItem` · `backend/app/Modules/Payments/Actions/CreateOrder.php` (كورس) · شراءُ الأرصدة. ⚠️ نطاقُ الكوبونِ يشملُ `course` و`credit_package`، وكان مسارُ المتجرِ وحدَه يقبلُ كوداً.
- [X] T068 [P] [US2] مورِدُ Filament ‏`backend/app/Modules/Payments/Filament/Resources/CouponResource.php` بصلاحيةِ منصّة، ومسارُ `/admin/sibling-discount`.
- [X] T069 [P] [US2] أدرِجْ موارِدَ الكوبونِ في قائمةِ `backend/tests/Feature/Payments/PaymentExposureTest.php`. ⚠️ `PaymentFieldAllowlist` **قائمةُ حمولاتٍ مكتوبةٌ بيد** وتقولُ عن نفسِها إنّ حمولةً تُضافُ لاحقاً **غيرُ مفحوصة**.

### الاختبارات

- [X] T070 [P] [US2] `CouponCapConcurrencyTest.php` — `SC-003` بشكلِ الخطّافِ لا بحلقة.
- [X] T071 [P] [US2] `CouponScopeTest.php` — كوبونُ منصّةٍ يُقبَلُ في مساحتَين ⇐ يسقطُ لو أُضيفَ `BelongsToWorkspace`.
- [X] T072 [US2] `CouponLeakTest.php` — ⚠️ **الاتّجاهُ المُسرِّب**: يمشي **كلَّ فعلٍ يقرأُ كوبوناً** ويُثبِتُ أنّ كوبونَ مساحةٍ لا يُطبَّقُ في أخرى. `WorkspaceIsolationTest` لا يستضيفُه.
- [X] T073 [P] [US2] `CouponBracketTest.php` (منتهٍ يُرفَض) · `FixedCouponClampTest.php` (٥٠ على ٣٠ ⇒ **صفرٌ لا سالب**) · `RedemptionBeforeCounterTest.php`.
- [X] T074 [P] [US2] `SiblingDiscountTest.php` (`SC-004`) · `DiscountStackingTest.php` · `CouponNeverTouchesTeacherTest.php` (⚠️ `teacher_net_minor` لا يتحرّكُ و`commission_minor` يُسمَحُ له بالسالب) · `CouponOracleTest.php`.
- [X] T075 [P] [US2] واجهةُ الخصمِ: `frontend/src/components/store/CouponField.tsx` + السطرُ الصريحُ قبلَ الدفع (FR-011) + اختبارُ مكوّن.

---

## Phase 5: US3 — الإحالة (P3)

**Goal**: كودٌ دائمٌ لكلِّ مستخدِم، ومكافأةٌ **نقاطاً** للطرفَين عندَ اشتراكٍ فعليٍّ معتمَد، تُعكَسُ عندَ الاسترداد.

**Independent Test**: إحالةٌ تكتملُ باشتراكٍ فعليٍّ وأخرى تتوقّفُ عندَ التسجيل.

> ✅ **`POST /auth/register` أُصلِحَ في `b40ec73`** — كان شرطَ هذه الموجةِ: تعليقُ مالٍ على إنشاءِ حسابٍ بلا محدِّدِ معدّلٍ مسارُ سكٍّ مجّانيّ.

- [ ] T076 [P] [US3] هجرتا `referral_codes` و`referrals` في `backend/app/Modules/Identity/Database/Migrations/` — §٩ و§١٠، **بلا `BelongsToWorkspace`** (⚠️ إضافتُه تُكرِّرُ الشخصَ لكلِّ مدرّس)، وحالةُ `flagged`، وفهرسُ `[referrer_user_id, status]`.
- [ ] T077 [P] [US3] نموذجانِ ومصنعانِ في `backend/app/Modules/Identity/`.
- [ ] T078 [P] [US3] مفتاحا `referral.reward_points` و`referral.max_completed_per_referrer` في `PlatformSettings::KEYS` (FR-023).
- [ ] T079 [US3] `backend/app/Modules/Identity/Actions/IssueReferralCode.php`. ⚠️ **يتسابقُ مع نفسِه**: طلبانِ متزامنانِ يجدانِ لا شيءَ ويُدرِجان ⇒ `QueryException` كـ٥٠٠ على مسارِ قراءة. `firstOrCreate` داخلَ `catch` + حلقةُ إعادةٍ لتصادمِ الرمز. **ولا `insertOrIgnore`** — يتجاوزُ `HasUuid`.
- [ ] T080 [US3] `AttachReferral` داخلَ مسارِ التسجيلِ القائم (`referral_code` اختياريّ) ⇒ صفٌّ `pending`، ورفضُ إحالةِ الذاتِ بحالةِ `flagged` (FR-022).
- [ ] T081 [US3] ⚠️ **+ مفتاحُ `invite_friend` في الكتالوجِ وهجرةُ `seedMissing()` (منقولٌ من T012) — في نفسِ الدفعةِ أو المكافأةُ صفرٌ صامت.** `backend/app/Modules/Identity/Listeners/CompleteReferral.php` — `ShouldQueue` + `ShouldHandleEventsAfterCommit`، ⚠️ **بمرشِّحِ `kind ∈ {credits, subscription}`**: بلا مرشِّحٍ يُكمِلُ **أرخصُ منتَجٍ في المتجرِ** إحالةً. يمنحُ نقاطاً عبرَ `AwardPoints` بمفتاحِ `invite_friend`، ويحترمُ السقف.
- [ ] T082 [US3] `backend/app/Modules/Identity/Events/ReferralCompleted.php` — **معرِّفانِ ولا شيءَ غيرُهما**؛ يستهلكُه Gamification (FR-024 · NFR-005).
- [ ] T083 [US3] `backend/app/Modules/Identity/Listeners/ReverseReferralAward.php` على `PaymentReversed`/`RefundIssued` ⇒ قيدٌ عكسيٌّ في `award_entries` بـ`reversal_of_id`. ⚠️ **العمودُ في مكانِه الأصليِّ المشحون** — مفتاحُ `credit_tx_idempotency` يحملُ `type` سلفاً فلا يحتاجُ خامساً.
- [ ] T084 [P] [US3] مسارا `/referrals/code` و`/referrals` + موردٌ + `where referrer_user_id` صراحةً.
- [ ] T085 [P] [US3] `ReferralCompletionTest` · `ReferralKindFilterTest` (⚠️ شراءُ منتَجِ متجرٍ **لا يُكمِلُ** إحالة) · `SelfReferralTest` · `ReferralCapTest`.
- [ ] T086 [US3] `ReferralReversalTest.php` — ⚠️ `SC-007` بالتأكيدِ على **المجموعِ العائدِ إلى ما قبلَ المنح** لا على عددِ الصفوف: عدُّ صفَّين يمرُّ على تصميمٍ لا يُعيدُ شيئاً.
- [ ] T087 [P] [US3] `ReferralCatalogueTest.php` — يقرأُ `gamification_actions` **بعدَ الهجرةِ وحدَها بلا بذرة**.
- [ ] T088 [P] [US3] `ReferralCodeRaceTest.php` + واجهةُ `frontend/src/app/(app)/(shell)/referrals/page.tsx` ورابطُها.

---

## Phase 6: US4 — باقاتُ الاشتراك (P4)

**Goal**: نمطُ تسعيرٍ ثالثٌ فوقَ المحرّكِ لا داخلَه — الوصولُ بأهليّةِ الاشتراك، وصفرُ حصّةٍ تستهلكُ رصيداً.

**Independent Test**: اشتراكٌ، وصولٌ إلى ما تغطّيه، وانتهاءُ المدّة.

- [ ] T089 [P] [US4] هجرتا `plans` و`subscriptions` — §٧ و§٨، بـ**`unique(order_id)`** (⚠️ الحارسُ الوحيدُ ضدَّ اشتراكَين لدفعةٍ واحدة)، و**`effective_ends_on`**، وفهرسا `[student_user_id, status]` · `[status, effective_ends_on]`.
- [ ] T090 [P] [US4] نموذجانِ ومصنعانِ وسياسةٌ في `backend/app/Modules/Payments/`.
- [ ] T091 [US4] `backend/app/Modules/Payments/Actions/SavePlan.php` — المدرّسُ يملأُ المدّةَ والتغطية، و`price_minor` **تُرفَضُ من طالبٍ لا يحملُ صلاحيةَ المنصّة**.
- [ ] T092 [US4] `backend/app/Modules/Payments/Listeners/ActivateSubscription.php` — `ShouldQueue` + `ShouldHandleEventsAfterCommit`، والحارسُ `unique(order_id)`.
- [ ] T093 [US4] `backend/app/Modules/Payments/Support/SubscriptionEligibility.php` — يفتحُ ما تغطّيه الباقةُ طوالَ المدّة، ⚠️ وكورسٌ حُذِفَ أو أُوقِفَ **يسقطُ من التغطيةِ ويبقى الاشتراكُ على الباقي** (حالةُ حافّة).
- [ ] T094 [US4] فرعُ الاشتراكِ في `backend/app/Modules/Payments/Actions/ChargeSessionSeats.php` — قيدُ `Consume` بـ`credits = 0` و`meta` تسمّي الاشتراك. ⚠️ **قراءةٌ جماعيّةٌ واحدةٌ قبلَ الحلقة** (`whereIn` على حاجزي المقاعد): دفترُ تعليقِ ذلك الفعلِ يحملُ قاعدةً مكتسَبةً بإصلاحٍ سابق — «كلُّ حقيقةٍ مشتركةٍ تُقرَأُ مرّةً للحصّةِ لا مرّةً لكلِّ مقعد». **ولا في `ChargeSeatsOnDelivery`** (غلافٌ من خمسةِ أسطر).
- [ ] T095 [US4] `backend/app/Modules/Payments/Support/EffectiveSubscriptionEnd.php` + إعادةُ الحسابِ عندَ **ثلاثةِ أحداث**: إنشاءُ فترةِ تجميدٍ · تعديلُها · ⚠️ **حذفُها** (وإلّا بقيَ التمديدُ بلا سبب) · ⚠️ **وإنشاءُ اشتراكٍ داخلَ فترةٍ جارية** (وإلّا وُلِدَ بلا تمديدٍ يستحقُّه).
- [ ] T096 [US4] ⚠️ **+ قالبُ إشعارِ قربِ الانتهاءِ وهجرةُ `seedMissing()` (منقولٌ من T014).** `backend/app/Modules/Payments/Jobs/ExpireSubscriptionsJob.php` — يقرأُ `effective_ends_on` بـ`< … + 1 day`، **`chunkById`** (⚠️ الشرطُ يتقلّصُ تحتَ المشي فترقيمُ OFFSET يقفزُ **ويُبلِّغُ نجاحاً**)، و`expiring_notified_at` **يُختَمُ قبلَ الإرسال**، و`withoutOverlapping()` على `Schedule::job()`.
- [ ] T097 [P] [US4] مساراتٌ وموارِدُ ‏م٤، ومسارُ `/admin/plans/{plan}/price` ⚠️ **بـ`withoutWorkspaceScope()` صريح**: السياقُ يرتدُّ إلى `users.last_workspace_id` **حتى للمشرفِ العامّ** فيَحُلُّ الربطُ باقاتِ مساحةٍ واحدةٍ و`404` لغيرِها.
- [ ] T098 [US4] `SubscriptionCoveredSeatTest.php` — ⚠️ `SC-008` **بشاهدٍ موجب**: مشترِكٌ **وغيرُ مشترِكٍ في الحصّةِ نفسِها**. «صفرُ صفوف» وحدَه صادقٌ عن تجهيزةٍ لم يُطلَق فيها الحدثُ أصلاً.
- [ ] T099 [P] [US4] `SubscriptionReconcileTest.php` (⚠️ `ReconcileCreditBalancesJob` **بلا نتيجة**) · `SubscriptionAccruesTeacherTest.php` · `SubscriptionOrderUniqueTest.php` · `SubscriptionFreezeTest.php` · `SubscriptionPricingTest.php` · `PriorDuesSurviveTest.php`.
- [ ] T100 [US4] واجهةُ الباقاتِ `frontend/src/app/(app)/(shell)/{manage/plans,plans}/page.tsx` + `frontend/src/lib/plans.ts` + روابطُها.

---

## Phase 7: US5 — المدوّنةُ العامّةُ والسيو (P5)

**Goal**: زائرٌ يصلُ من محرّكِ بحثٍ إلى مقالٍ، يقرأُه كاملاً، فيجدُ مدرّسين متخصّصين.

**Independent Test**: مقالٌ منشورٌ يُقرَأُ بلا تسجيل، ومسوّدةٌ تُرفَضُ برابطِها المباشر.

> **الجداولُ قائمةٌ منذُ ٢٠ يوليو** — `cms_articles` تحملُ `slug` و`seo_*` و`canonical_url` و`published_at`. الناقصُ **بابانِ وفهرسان**، لا جدول.

- [ ] T101 [US5] أضِفْ `IsPubliclyListed` إلى `backend/app/Modules/CMS/Models/Article.php` مع `publicListingConstraints()` (منشورٌ · غيرُ محذوف).
- [ ] T102 [US5] `backend/app/Modules/CMS/Support/CmsFieldAllowlist.php` **تملكُها CMS** وتُعيدُ استعمالَ `PublicFieldAllowlist::FORBIDDEN` — شكلُ `AssessmentFieldAllowlist`. ⚠️ **لا تُنمِّ قائمةَ Marketplace**: تستوردُ CMS منها وتملكُ Marketplace حقولاً لا تعرفُها.
- [ ] T103 [US5] `backend/app/Modules/CMS/Http/Resources/PublicArticleResource.php` — ⚠️ المورِدُ القائمُ يُصدِرُ `category.id` و`tags[].id` و`body` و`status`، و`'id'` في قائمةِ الممنوع.
- [ ] T104 [US5] `backend/app/Modules/CMS/Actions/{ListPublicArticles,ReadPublicArticle}.php` — تبدأُ من `publiclyListed()`. ⚠️ **لا يُربَطُ نموذجٌ بمسارٍ عامٍّ ضمنيّاً**: `WorkspaceScope` لا يضيفُ شرطاً بلا مستخدِم ⇒ استعلامٌ بلا حارسٍ يُرجِعُ **مسوّداتِ كلِّ مساحاتِ العمل**.
- [ ] T105 [US5] `backend/app/Modules/Marketplace/Actions/RelatedTeachers.php` — ⚠️ **في Marketplace لا في CMS**، وعبرَ عقدٍ: الشرطُ `publiclyListed()` وهو ما يُنفِّذُ FR-038 («يُمنعُ أن تشمل معلَّقاً أو خارجاً عن السوقِ العامّ»).
- [ ] T106 [P] [US5] مساراتٌ عامّةٌ `/public/articles` و`/public/articles/{slug}` بـ`throttle:public` في `backend/app/Modules/CMS/routes/api.php`.
- [ ] T107 [US5] مورِدُ Filament ‏`backend/app/Modules/CMS/Filament/Resources/CmsArticleResource.php` — ⚠️ **بهذا الاسمِ** كي لا يصطدمَ بـ`Http/Resources/ArticleResource.php`، و**يُعلِنُ `canViewAny()`**: `ArticlePolicy` بلا `viewAny()` ⇒ `PanelResourceDoorTest` يسقط.
- [ ] T108 [P] [US5] `frontend/src/app/sitemap.ts` بـ`generateSitemaps()` للتقسيم (FR-036) — **المنشورُ فقط**.
- [ ] T109 [P] [US5] `frontend/src/app/robots.ts`.
- [ ] T110 [US5] `frontend/src/components/seo/JsonLd.tsx` — ⚠️ **يهرِّبُ يدويّاً**: `dangerouslySetInnerHTML` **لا يهرِّبُ شيئاً** والحقولُ من لوحةِ مفاتيحِ المدرّس ⇒ `<` تصيرُ `<` (و`>` و`&`)، و`canonical_url` يُتحقَّقُ أنّه رابطٌ مطلقٌ `http(s)`.
- [ ] T111 [US5] صفحتا `frontend/src/app/(public)/blog/{page.tsx,[slug]/page.tsx}` بـ`generateMetadata` + OG + JSON-LD.
- [ ] T112 [P] [US5] أضِفْ `metadata` إلى `frontend/src/app/(public)/page.tsx` — ⚠️ **الصفحةُ الوحيدةُ في المنتَجِ بلا وصفٍ اليوم**، وهي أوّلُ ما يقرأُه محرّكُ البحثِ عن المنصّة.
- [ ] T113 [US5] `backend/app/Modules/CMS/Jobs/PingSearchEnginesJob.php` على `maintenance` (FR-037) + صفٌّ في `data_processors`. ⚠️ نداءٌ خارجيّ — و`ProcessorAllowlistTest` **لا يمسحُ نداءاتِ HTTP الحرّة** فلن يُمسِكَه؛ يُسجَّلُ للاكتمالِ ويُذكَرُ أنّ الحارسَ لا يفرضُه.
- [ ] T114 [US5] `PublicArticleExposureTest.php` — `SC-009` · `SC-010`. ⚠️ **بشواهدَ ASCII**: `getContent()` يهربُ غيرَ الـASCII فتأكيدُ تسرُّبٍ بإبرةٍ عربيّةٍ **صادقٌ فراغاً**.
- [ ] T115 [P] [US5] `ArticleSlugUniquenessTest.php` · `ArticleSlugDedupeMigrationTest.php` · `SitemapTest.php` (`SC-011`) · `RegionsExposureTest.php` (⚠️ `PublicExposureTest` مشيُ روابطَ مكتوبٌ بيد).
- [ ] T116 [P] [US5] `frontend/src/components/seo/JsonLd.test.tsx` — عنوانٌ يحملُ `</script>` لا يكسرُ الوسم.
- [ ] T117 [US5] قرارُ «اشتراكِ النشرِ العامّ»: يُعادُ استعمالُ `participates_in_marketplace` **ويُذكَرُ في شاشةِ الإعداد** — ⚠️ مساحةٌ قبلَتْ إدراجَ السوقِ لم تقبلْ بذلك فهرسةَ مقالاتِها، وإطفاءُ المشاركةِ يُنزِلُ المدوّنةَ صامتاً.

---

## Phase 8: US6 — التحليلاتُ والمفاتيحُ والمناطق (P6)

**Goal**: لوحةٌ واحدةٌ بأرقامٍ تطابقُ المصدرَ بفارقِ صفر، ومفتاحٌ يُشعَلُ لمدرّسٍ واحدٍ ويُطفَأُ فوراً.

**Independent Test**: مقارنةُ أرقامِ اللوحةِ ببياناتٍ معروفة، وتفعيلُ مفتاحٍ لمدرّسٍ واحد.

- [ ] T118 [US6] ⚠️ **+ بذرةُ `regions` وهجرةُ `seedMissing()` (منقولةٌ من T015) — جدولٌ فارغٌ يعني ٤٢٢ لكلِّ تسجيلٍ جديد.** هجرتا `regions` و`platform_metrics_daily` و`report_subscriptions` — §١٠ و§١٢ و§١٣، بـ**`numerator`/`denominator` مُوقَّعَين** و**فهرسٍ ثانٍ `[metric_key, workspace_id, region_id, date]`** (⚠️ الفريدُ يبدأُ بالتاريخِ واللوحةُ تقرأُ بالمؤشِّرِ أوّلاً).
- [ ] T119 [US6] هجرةُ `student_profiles.region_id` **`nullable`** + فهرس، ⚠️ **وإضافتُه إلى `$fillable`**: ٠١٣ شحنَتْ ثلاثةَ أعمدةٍ على هذا الجدولِ بالضبطِ ابتلعَها الإسنادُ الجماعيُّ صامتاً — `201` وثلاثةُ فراغات. والإسقاطُ يحتاجُ `dropIndex(['region_id'])` في جملةٍ مستقلّةٍ أوّلاً (SQLite ترفضُ إسقاطَ عمودٍ مُفهرَس).
- [ ] T120 [US6] `region_id` إلزاميٌّ في `RegisterStudentRequest` وفي نموذجِ التسجيلِ بالواجهة، ومسارُ `/regions` عامٌّ بـ`throttle:public` ومُخزَّنٌ مؤقّتاً. ⚠️ ويُوثَّقُ في ٠٠١ («ولا يُدخَلُ صامتاً»).
- [ ] T121 [US6] `backend/app/Modules/Analytics/Jobs/RollUpPlatformMetricsJob.php` — **`forWorkspace()` حصراً** (⚠️ `WorkspaceContext::set()` في وظيفةٍ يُسرِّبُ المساحةَ إلى ما يعالجُه العاملُ بعدَها)، `chunkById`، نافذةُ اليومِ `>= $start AND < $start->addDay()` (⚠️ **لا `whereDate()`** ولا `CONVERT_TZ()` — الأخيرُ يعودُ **NULL** على أيِّ MySQL بلا جداولِ المناطقِ الزمنيّةِ وSQLite لا يملكُه ⇒ لا اختبارَ محلّيٌّ يراه)، والمناطقُ **`GROUP BY region_id` واحدٌ لكلِّ مساحة**.
- [ ] T122 [US6] وسِّعْ `backend/tests/Feature/Marketplace/TrustScoreJobIsolationTest.php` ليشملَ `Modules/*/Jobs/` — ⚠️ **بنمطٍ لا بقائمةِ وحداتٍ مكتوبةٍ بيد**.
- [ ] T123 [US6] `backend/app/Modules/Analytics/Actions/ReadPlatformAnalytics.php` — ⚠️ **`withoutWorkspaceScope()` مُعلَنٌ ومُكرَّرٌ في كلِّ تحميلٍ مُسبَق** (التجاوزُ لكلِّ نموذجٍ على حِدة)، والقراءةُ تبدأُ من `regions` **وتضمُّ يساراً** (منطقةٌ بلا تسجيلٍ تظهرُ صفراً لا تُحذَف).
- [ ] T124 [US6] ترتيبُ المدرّسين والطلاب (FR-041): ⚠️ **فهرسٌ على `teacher_profiles.reviews_count`** (مرشِّحُ الحدِّ الأدنى مسحٌ و`filesort` بدونِه)، والنصفُ الطلابيُّ من `leaderboard_entries` ⚠️ **بـ`with('user:id,uuid,first_name,last_name')`** — `users` **بلا عمودِ `name`** والتحميلُ المقيَّدُ الذي يسمّيه يرسمُ **اسماً فارغاً** بـ`200`، وقد شُحِنَ ستَّ مرّاتٍ في ٠١٠.
- [ ] T125 [US6] صفحةُ `backend/app/Modules/Analytics/Filament/Pages/PlatformAnalytics.php` بصلاحيةِ **`analytics.cross_teacher.view`** ⚠️ لا `analytics.view` (صلاحيةُ مساحةِ عملٍ في مصفوفةِ المساعِد)، **وتُعلِنُ `canAccess()`** — `PanelResourceDoorTest` يمشي `getResources()` وحدَها فصفحةٌ بلا بابٍ تشحنُ ولا يسقطُ شيء.
- [ ] T126 [P] [US6] مورِدُ `FeatureFlagResource` بصلاحيةِ `flags.manage` + مسارا `/admin/feature-flags`.
- [ ] T127 [US6] `backend/app/Modules/Analytics/Jobs/SendScheduledReportsJob.php` (FR-045) — يمرُّ بـ`DispatchNotification`، و`last_sent_on` **يُختَمُ قبلَ الإرسال**. **ولا مُولِّدَ تقاريرَ جديد**: التقريرُ صفوفُ `platform_metrics_daily` نفسُها.
- [ ] T128 [P] [US6] مسارا `/reports/subscriptions` وشاشتُها.
- [ ] T129 [P] [US6] `PlatformAnalyticsTest.php` — ⚠️ `SC-012` **بمساحتَي عملٍ لا واحدة**: قراءةٌ متروكةٌ في النطاقِ تعرضُ أرقامَ مساحةٍ واحدةٍ كمجموعِ المنصّةِ وتمرُّ على تجهيزةٍ بمساحةٍ واحدة.
- [ ] T130 [P] [US6] `AnalyticsPermissionTest.php` (⚠️ **مساعِدُ مدرّسٍ يُردُّ بـ٤٠٣**) · `RollupIdempotencyTest.php` (⚠️ **يشغّلُ الوظيفةَ مرّتَين**) · `TopTeachersTest.php` (`SC-013`، **بأسماءٍ غيرِ فارغة**).
- [ ] T131 [P] [US6] `FeatureFlagTest.php` (`SC-014` بحالتَين متقابلتَين) · `FlagAudienceTest.php` (⚠️ **طالبُ ذلك المدرّسِ يرى الميزة** — سياقُه `null` فيقعُ على الصفِّ العامّ) · `ScheduledReportTest.php`.
- [ ] T132 [US6] `FlagRevocationTest.php` — ⚠️ `FR-048` **بوظيفتَين مطبورتَين وإطفاءٍ بينهما**، لا بطلبَي HTTP: `forgetScopedInstances()` يُنادى من خطّافِ العاملِ وحدَه، فداخلَ عمليّةِ اختبارٍ واحدةٍ لا يُفرَّقُ بينَ `scoped()` و`singleton()` — **والاختبارُ يسقطُ على كودٍ صحيح**.
- [ ] T133 [P] [US6] `RegistrationStillWorksTest.php` — ⚠️ **بالتأكيدِ على الصفِّ المخزَّنِ لا على صدى الاستجابة**.
- [ ] T134 [US6] رابطُ لوحةِ التحليلاتِ في تنقّلِ `/admin` عبرَ `backend/app/Providers/Filament/AdminPanelProvider.php › navigationGroups()`، ورابطُ التقاريرِ المجدولةِ في `frontend/src/components/layout/Sidebar.tsx`.

---

## Phase 9: Polish & Cross-Cutting

- [ ] T135 [P] حالاتٌ في `backend/tests/Feature/Notifications/PlatformOwnershipTest.php` لكياناتِ صنفِ (أ): `referral_codes` · `referrals` — **في الاتّجاهَين**.
- [ ] T136 حالاتُ **صنفِ (ب)** لكلِّ مرجعِ منصّة (`coupons` · `regions` · `feature_flags`): ⚠️ **حاملُ أعلى دورِ مستأجِرٍ يُردُّ بـ٤٠٣** — يشترطُها الدستورُ **v1.2.0**، ولا يخدمُها اختبارُ صنفِ (أ).
- [ ] T137 [P] راجعْ كلَّ حقلِ `FormRequest` جديدٍ ضدَّ `backend/lang/ar/validation.php › attributes`.
- [ ] T138 [P] راجعْ كلَّ صنفِ لونٍ جديدٍ ضدَّ `frontend/src/lib/theme-tokens.test.ts` — ⚠️ صنفٌ يُسمّي رمزاً غيرَ معرَّفٍ **لا يرسمُ شيئاً بصمت**، وقد شُحِنَ أربعَ مرّات.
- [ ] T139 [P] تحقّقْ أنّ كلَّ سطحٍ جديدٍ يصلُه رابطٌ من مكانٍ ما، واترُكْ حارسَ `e2e` حيثُ يلزم.
- [ ] T140 شغِّلِ البوّاباتِ الأربعَ كاملةً: `pest` · `pint --test` · `phpstan analyse` · `tsc --noEmit && npm test` (`SC-017`).

---

## Dependencies

```
Phase 1 (Setup) ──► Phase 2 (Foundational) ──┬─► Phase 3 · US1 المتجر      (P1) ── منتَجٌ قابلٌ للنشر
                                              ├─► Phase 5 · US3 الإحالة     (P3) ── مستقلّةٌ عن م١
                                              ├─► Phase 7 · US5 المدوّنة    (P5) ── مستقلّةٌ عن م١
                                              └─► Phase 8 · US6 التحليلات   (P6) ── تقرأُ ما تُنتِجُه البقيّة

Phase 3 (US1) ──► Phase 4 · US2 الخصومات (P2)   ← نطاقُ الكوبونِ يشملُ المتجر
Phase 2       ──► Phase 6 · US4 الباقات   (P4)
كلُّ ما سبق   ──► Phase 9 · Polish
```

**مستقلّاتٌ حقيقيّة**: US3 و US5 لا تعتمدانِ على US1 — تُنفَّذانِ بالتوازي بعدَ Phase 2 إن توفّرَ من ينفّذُهما.

## Parallel Opportunities

- **Phase 1**: T004 · T008 · T009 · T011 · T012–T015 معاً (ملفّاتٌ مختلفة).
- **Phase 3**: الهجراتُ والنماذجُ والمصانع (T029–T034) معاً؛ ثمّ الاختباراتُ T047–T053 معاً بعدَ الأفعال.
- **Phase 4**: T060–T063 معاً · T070–T075 معاً.
- **Phase 8**: T129–T133 معاً.
- ⚠️ **ولا يُوازى شيءٌ داخلَ Phase 2 §أ** — T016→T020 سلسلةٌ واحدةٌ تمسُّ البابَ الوحيدَ لتشغيلِ ملفّ.

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 3 (US1)** — قائمةُ تحقُّقِ السبيكِ تقولُها بنصِّها: «‏P1 وحدها منتج قابل للنشر». تُدمَجُ خضراءَ وتُنشَرُ قبلَ أن تُفتَحَ م٢.

ثمّ **م٣ و م٥** (مستقلّتان، أرخصُهما تنفيذاً)، ثمّ **م٢** (تعتمدُ على م١)، ثمّ **م٤**، ثمّ **م٦** التي تقرأُ ما أنتجَه الجميع.

⚠️ **ولا تُجمَعُ موجتانِ في دفعةٍ واحدة.** ٤٨ متطلَّباً في مراجعةٍ واحدةٍ مراجعةٌ بلا مراجِع — وهذه المرحلةُ ضِعفا أكبرِ ما شُحِنَ هنا.

---

## Task Count

| المرحلة | المهامّ |
|---|---|
| Phase 1 · Setup | ١٥ |
| Phase 2 · Foundational | ١٣ |
| Phase 3 · US1 المتجر (P1) | ٣١ |
| Phase 4 · US2 الخصومات (P2) | ١٦ |
| Phase 5 · US3 الإحالة (P3) | ١٣ |
| Phase 6 · US4 الباقات (P4) | ١٢ |
| Phase 7 · US5 المدوّنة والسيو (P5) | ١٧ |
| Phase 8 · US6 التحليلات (P6) | ١٧ |
| Phase 9 · Polish | ٦ |
| **المجموع** | **١٤٠** |
