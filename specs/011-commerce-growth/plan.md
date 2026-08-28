# Implementation Plan: التجارة والنمو

**Branch**: `011-commerce-growth` | **Date**: 2026-08-29 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/011-commerce-growth/spec.md`

---

## Summary

المنصّةُ بعدَ إحدى عشرةَ مرحلةً كاملةٌ تشغيليّاً: تُدرِّسُ وتُقيِّمُ وتُحصِّلُ وتحتفظُ بطلابِها.
وينقصُها أن **تنمو وترفعَ قيمةَ العميلِ الواحد** — ستّةُ خيوطٍ تجمعُها هذه المرحلة: متجرُ
الكتبِ والمذكّرات · الكوبوناتُ وخصمُ الإخوة · الإحالة · باقاتُ الاشتراك · المدوّنةُ العامّةُ
والسيو · لوحةُ التحليلاتِ ومفاتيحُ المزايا.

**والقرارُ التقنيُّ الحاملُ هو ما لا يُبنى** — بعدَ أن قاسَتْه مراجعةُ الوكلاءِ بدلَ أن
تُصدِّقَه: المقالُ نموذجٌ كاملٌ في `CMS` تنقصُه شاشةٌ وبابٌ عامٌّ **وفهرسانِ**؛ ومكافأةُ
الإحالةِ **نقاطُ تلعيبٍ** لا قيدُ رصيد (`credit_balances` تفرضُ كورساً لا تملكُه إحالة)؛
والمنتَجُ الرقميُّ **نواةُ منحةٍ مستخرَجةٌ** من ٠٠٤ لا نظامُ روابطَ ثانٍ ولا إعادةُ استعمالٍ
مباشرة (‏`IssuePlaybackGrant` مربوطةٌ بـ`Lesson`)؛ والمخزونُ وسقفُ الكوبونِ والإنجازُ وأوّلُ
فتحٍ **أربعةُ مواضعَ لنفسِ `UPDATE` الشرطيِّ** المكتوبِ في هذا المستودعِ أربعَ مرّاتٍ سلفاً.

**وأصعبُ ما في المرحلةِ ليس أيَّ خيطٍ منها، بل حجمُها**: ٤٨ متطلَّباً و٦ قصصٍ و١٣ كياناً —
ضِعفا أكبرِ مرحلةٍ شُحِنَتْ هنا. فتُشحَنُ **بالموجاتِ المرقَّمةِ سلفاً في السبيك**، و`P1`
وحدَها منتَجٌ قابلٌ للنشرِ بنصِّ قائمةِ تحقُّقِها.

**والخطُّ الذي حُسِمَ (2026-08-29) يمرُّ بينَ التدريسِ والسلعة، لا بينَ الكيانات**: المنصّةُ
تُسعِّرُ الكورسَ وحزمةَ الرصيدِ **والباقة**، والمدرّسُ يُسعِّرُ كتابَه ومذكّرتَه — سعرَ رفٍّ
تُقتطَعُ العمولةُ منه، لا رسماً يُضافُ فوقَه. وقد عُدِّلَتْ في السبيكِ **أربعةُ نصوصٍ إلزاميّة** (‏FR-001 · FR-014 · FR-020 · FR-025) في
جلسةِ توضيحِ 2026-08-29 — لا واحدٌ كما قُدِّرَ أوّلاً. التفصيلُ في [research.md §D1](./research.md).

---

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 (backend) · TypeScript 5 · Next.js 15 App Router (frontend)

**Primary Dependencies**: قائمةٌ كلُّها — spatie/permission (وضعُ الفِرَقِ بـ`team_id = workspace_id`) · Sanctum · Horizon · Scout/Meilisearch · Filament 5. **صفرُ رزمٍ جديدة**: لا `laravel/pennant` (‏R5) ولا `laravel/cashier` (‏R8) ولا مكتبةَ schema.org (‏D9) — `sitemap.ts` و`robots.ts` عقدانِ في Next بذاتِهما.

**Storage**: صفحةُ بياناتٍ واحدةٌ مقسَّمةٌ بـ`workspace_id`. SQLite محلّيّاً وفي الاختبار · MySQL + Redis إنتاجاً. **المالُ عددٌ صحيحٌ بالوحدةِ الصغرى** (‏NFR-009) على سابقةِ ٠١٤ لا `decimal:2` — الأخيرُ يُرجِعُ **نصّاً**، فكلُّ جمعٍ يمرُّ بعائم.

**Testing**: Pest مع `RefreshDatabase` و`WithWorkspace` · vitest + jsdom للمكوّنات · Playwright للطرفِ للطرفِ والوصول

**Target Platform**: تطبيقُ وِبٍّ عربيٌّ RTL — **والمدوّنةُ العامّةُ أوّلُ سطحٍ في المنتَجِ غرضُه أن يقرأَه محرّكُ بحث**

**Project Type**: Web — `backend/` وحدةٌ مونوليثيّةٌ معياريّةٌ ⇄ `frontend/` Next.js

**Performance Goals**: SC-016 — لوحةُ التحليلاتِ ومتجرٌ فيه ١٬٠٠٠ منتَجٍ دونَ ٨٠٠ms (p95) **بعددِ استعلاماتٍ ثابتٍ لا ينمو مع الصفوف** (‏NFR-013)

**Constraints**: عربيٌّ فقط ومن اليمينِ لليسار · ألوانٌ من `@theme` حصراً · محدّداتٌ مُسمّاةٌ حصراً (‏NFR-014) · Larastan L8 بلا خطِّ أساسٍ جديدٍ وبلا `@phpstan-ignore` · **لا `migrate:fresh`** · لا سرَّ في المستودع · **يُمنعُ أن يمسَّ خصمٌ استحقاقَ مدرّس** (‏FR-010)

**Scale/Scope**: ١٣ كياناً جديداً · **وحدةٌ واحدةٌ جديدة** (`Store`) · ٣ جداولَ قائمةٍ تكتسبُ أعمدة · ~٤٥ مساراً · ~١٢ شاشةً · و**السيو من الصفر**: لا `sitemap` ولا `robots` ولا JSON-LD ولا OG في الشجرةِ اليوم

**NEEDS CLARIFICATION**: **لا شيء.** الأربعةُ حُسِمَتْ في 2026-08-29 — مَن يُسعِّر (‏C1) · اكتشافُ الإخوة بـ`parent_student_relations` (‏C2) · الأعلى وحدَه بلا تراكم (‏C3) · ٤٨ ساعةً ما لم يُفتَح (‏C4). الجدولُ في [research.md §رابعاً](./research.md).

---

## Constitution Check

*GATE: يمرُّ قبلَ Phase 0 ويُعادُ بعدَ Phase 1.*

| المبدأ | كيف تمرُّ هذه المرحلة | الدليل |
|---|---|---|
| **I · عزلُ المستأجرين** (غيرُ قابلٍ للتفاوض) | ثلاثُ طبقاتٍ مُسنَدةٌ صفّاً صفّاً في D3: `ReferralCode` · `Referral` · `Region` **مملوكةٌ للمنصّةِ بلا `BelongsToWorkspace`** (‏رمزُ إحالةٍ واحدٌ للشخصِ عبرَ كلَّ مدرّسيه؛ إضافتُه تُكرِّرُ الشخصَ لكلِّ مدرّس)، و`StoreItem` · `Coupon` · `Plan` · `FeatureFlag` مملوكةٌ لمساحةِ العمل، والباقي جسور. التحقّقُ بـ`WorkspaceRules::exists()` لا `exists:table,id`. ⚠️ **والحارسُ على كلِّ مسارِ طالبٍ ملكيّةُ الصفِّ لا النطاق** — الطالبُ عضوٌ في لا مساحةٍ فـ`WorkspaceScope` خاملٌ عليه. حالاتٌ في `WorkspaceIsolationTest` و`PlatformOwnershipTest` في نفسِ الدفعة (‏SC-015) | research D3 · NFR-001..001ب |
| **II · المنطقُ في الـActions** | `FormRequest → DTO → Action → Resource`. وقواعدُ الأهليّةِ والخصمِ **مفروضةٌ داخلَ الفعل** لا في التحقّقِ فقط (‏NFR-004): البذرةُ ولوحةُ Filament والمسارُ يمرّون منه — و`SeedCommand` يعملُ داخلَ `Model::unguarded()`، فـ`$fillable` لا يحرسُ البذور | contracts |
| **III · استقلالُ الوحداتِ بالأحداث** | `ReferralCompleted` تستهلكُه Gamification (‏FR-024)، والشراءُ يستهلكُ `PaymentApproved` القائم. **يُمنعُ النداءُ المباشرُ عبرَ الوحدات** (‏NFR-005). ⚠️ ووحدةُ `Store` الجديدةُ تعني **ستّةَ** بنودٍ إجباريّةٍ لا ثلاثة — الثلاثةُ المعروفةُ (`phpstan.neon` · حرفُ `M` · قوائمُ الجداول) **وثلاثةٌ كُشِفَتْ في المراجعة**: كتلةُ `it()` خاصّةٌ بها في `ContextIsolationTest` (مسوحُ الاستيرادِ تسمّي وحداتِها حرفيّاً) · `StorePersonalData` وصفوفُ `data_categories` (‏`shipments` تحملُ عنوانَ منزلِ طفل) · و`docs/README.md` و`docs/erd.md`. التفصيلُ في contracts §عقود | research D2 |
| **IV · البوّاباتُ خضراء** | `pest` · `pint --test` · `phpstan analyse` (L8 بلا خطِّ أساس) · `npx tsc --noEmit` · `npm test` (‏SC-017 · NFR-008) | quickstart |
| **V · التفويضُ بالسياساتِ والثوابت** | سياسةٌ لكلِّ كيانٍ جديد، والأسماءُ من `Tenancy\Support\Permissions` — **صفرُ اسمِ صلاحيةٍ مكتوبٍ نصّاً** (‏NFR-006). و`billing.audit.view` وأخواتُها **صلاحياتُ منصّةٍ لا يحملُها دورُ مساحةِ عمل**: `Tenancy\Models\Role` يرمي على `givePermissionTo()` لصلاحيةِ منصّةٍ تصلُ دوراً بـ`team_id`. ⚠️ **والقائمةُ لا تسألُ سياسةَ الصفّ** — كلُّ شاشةِ قائمةٍ تحملُ قطعَها على الاستعلامِ نفسِه (‏درسُ `OrderResource`) | data-model · contracts |
| **VI · العقودُ الظاهرةُ مقصودة** | `HasUuid` وكشفُ الـuuid وحدَه · `declare(strict_types=1);` · DTO ترثُ `DataTransferObject`. **وأخطرُ عقدٍ هنا عامّ**: كلُّ حمولةِ مقالٍ تمرُّ بـ`PublicFieldAllowlist` وتُفحَصُ في `PublicExposureTest` (‏NFR-003 · SC-010) | research D8 |

**الحكم: يمرُّ بمخالفتَين مسجَّلتَين** في `## Complexity Tracking` أدناه.

⚠️ **وكانت هذه الفقرةُ تقولُ «بلا مخالفةٍ تحتاجُ تبريراً» وتحذفُ ذلك القسم — وهو بعينِه ما
تمنعُه ترويسةُ الدستور**، التي تسجّلُ أنّ مخالفةَ ٠٠٦ «سُجّلت في Complexity Tracking **ولم
يُمرَّر بادّعاء ‹لا مخالفة›**». الادّعاءُ نفسُه كُتِبَ هنا مرّةً أخرى.

⚠️ **وكانت تقولُ إنّ `TeacherFieldAllowlist::FORBIDDEN` «يفرضُ FR-010 آليّاً» — وهي مبالَغة**:
تلك القائمةُ تُفرَضُ على موارِدِ `Modules/Settlement/Http/Resources` وحدَها، و٠١١ يضعُ
`discount_minor` في `Store` و`Payments`. ⇒ **FR-010 يحتاجُ حارساً يُكتَبُ في هذه المرحلة.**
والفحصُ الذي *يمتدُّ* تلقائيّاً فحصٌ آخرُ في الملفِّ نفسِه — إبَرُه `net_minor` وأخواتُها عبرَ
الوحداتِ كلَّها، وهو الذي كان `teacher_net_minor` سيُسقِطُ البناءَ عليه.

---

## Complexity Tracking

| المخالفة | لماذا لزمت | البديلُ الأبسطُ ولماذا رُفِض |
|---|---|---|
| **`coupons` بلا `BelongsToWorkspace`** — والدستورُ §I يسمّي «الكوبونات» في طبقةِ مساحةِ العمل بـ«لا استثناء» | FR-010 نقلَتْ تأليفَ الكوبونِ إلى **المنصّة** ⇒ صارَ مرجعَ منصّةٍ (ب) لا إنتاجَ مدرّس. وكوبونُ المنصّةِ يعني `workspace_id IS NULL`، والنطاقُ العامُّ يضيفُ `= X` ⇒ **يختفي عن كلِّ مساحةٍ في الوجود، بصمت** | إبقاءُ الوصف: يُخفي كلَّ كوبونِ منصّة. وعمودُ سنتينل `0`: يعني «مساحةَ العملِ رقم ٠» في عمودٍ يشيرُ إلى `workspaces`، وهو مفتاحٌ أجنبيٌّ كاذب. **نفسُ مسارِ التعديلِ الذي سلكَتْه `credit_packages` في ٠٠٦** |
| **`feature_flags` بلا `BelongsToWorkspace`** | الصفُّ يحملُ `workspace_id = 0` بمعنى «الافتراضُ العامّ»، والنطاقُ يُخفيه. والمفتاحُ أداةُ تشغيلِ منصّةٍ لا إنتاجُ مدرّس | `nullable` بدلَ السنتينل: فهرسٌ فريدٌ يحملُ عموداً `nullable` **لا يعضّ** ⇒ صفٌّ جديدٌ كلَّ ليلة |

**والاثنتانِ تُختَبَرانِ باختبارِ صنفِ (ب) الذي يشترطُه الدستورُ v1.2.0**: حاملُ أعلى دورِ
مستأجِرٍ يُردُّ بـ٤٠٣. `PlatformOwnershipTest` «في الاتّجاهَين» يخدمُ صنفَ (أ) ولا معنى له
لصفٍّ بلا مالكٍ فرد.

> ⚠️ والسبيكُ تستشهدُ بالدستورِ **v1.1.0**؛ المُصادَقُ عليه **v1.2.0** (2026-08-08)، وهو الذي
> يقسمُ ملكيّةَ المنصّةِ إلى (أ) و(ب) ويشترطُ ذلك الاختبار.

---

## Project Structure

### Documentation (this feature)

```text
specs/011-commerce-growth/
├── plan.md              # هذا الملفّ
├── spec.md              # ٤٨ FR · ١٧ SC · ٦ قصص · ٣ توضيحات
├── research.md          # Phase 0 — ١٠ ادّعاءاتٍ مقيسة · ٢٣ قراراً · حصيلةُ المراجعة
├── data-model.md        # Phase 1
├── contracts/api.md     # Phase 1
├── quickstart.md        # Phase 1
└── checklists/requirements.md
```

### Source Code (repository root)

```text
backend/app/
├── Modules/Store/                             ← ⚠️ الوحدةُ الجديدةُ الوحيدة
│   ├── Models/{StoreItem,StoreOrder,Shipment}.php
│   ├── Actions/{SaveStoreItem,PurchaseStoreItem,ClaimStock,
│   │            IssueDigitalAccess,AdvanceShipment}.php
│   ├── Policies/{StoreItemPolicy,ShipmentPolicy}.php
│   ├── Listeners/FulfilOnPaymentApproved.php  ← يستهلكُ PaymentApproved (٠٠٧)
│   ├── Http/{Controllers,Requests,Resources}/
│   └── Database/Migrations/                   ← ⚠️ M كبيرة
├── Modules/Payments/                          ← الكوبوناتُ والباقاتُ داخلَ سياقِ الفوترة
│   ├── Models/{Coupon,CouponRedemption,Plan,Subscription}.php
│   ├── Support/DiscountResolver.php           ← الكوبون + خصمُ الإخوة + الحدُّ الأدنى
│   ├── Actions/{ApplyCoupon,RedeemCoupon,Subscribe,ExpireSubscriptions}.php
│   └── Database/Migrations/
├── Modules/Identity/
│   ├── Models/{ReferralCode,Referral,Region}.php   ← ⚠️ مملوكةٌ للمنصّة
│   ├── Actions/{IssueReferralCode,AttachReferral,CompleteReferral}.php
│   ├── Events/ReferralCompleted.php           ← تستهلكُه Gamification (نقاطٌ لا رصيد)
│   └── Listeners/{CompleteReferral,ReverseReferralAward}.php
│   └── Database/Migrations/                   ← + student_profiles.region_id (nullable)
├── Modules/CMS/                               ← ⚠️ الجداولُ والنموذجُ والسياسةُ كما هي
│   ├── Models/Article.php                     ← + IsPubliclyListed
│   ├── Actions/{ListPublicArticles,ReadPublicArticle,RelatedTeachers}.php
│   ├── Filament/Resources/CmsArticleResource.php ← ⚠️ الاسمُ يتجنّبُ Http/Resources/ArticleResource
│   └── Http/Controllers/PublicArticleController.php
├── Modules/Tenancy/
│   ├── Models/FeatureFlag.php                 ← جدولٌ مستقلّ · لا platform_settings (R5)
│   └── Support/Flags.php
├── Modules/Analytics/                         ← ⚠️ الوحدةُ اليومَ ٤ ملفّاتٍ ووِدجتان
│   ├── Models/PlatformMetricDaily.php
│   ├── Jobs/RollUpPlatformMetricsJob.php      ← ليليّاً · maintenance · forWorkspace()
│   └── Filament/Pages/PlatformAnalytics.php
└── Modules/Marketplace/Support/PublicFieldAllowlist.php   ← + حقولُ المقال

frontend/src/
├── app/sitemap.ts · app/robots.ts             ← ⚠️ جديدانِ · عقدا Next بذاتِهما
├── app/(public)/page.tsx                      ← ⚠️ الصفحةُ الوحيدةُ بلا metadata اليوم
├── app/(public)/blog/{page.tsx,[slug]/page.tsx}
├── app/(public)/store/…  ·  app/(app)/(shell)/store/…
├── app/(app)/(shell)/manage/{store,coupons,plans}/…
├── components/{store,blog,referral}/
├── components/seo/JsonLd.tsx                  ← <script type="application/ld+json">
└── lib/{store,coupons,plans,referral}.ts
```

**Structure Decision**: توسعةٌ داخلَ الوحداتِ القائمةِ **بوحدةٍ جديدةٍ واحدة**. `Store` لا
تُوضَعُ في `Payments` عمداً: `ContextIsolationTest` يعرّفُ «سياقَ فوترةِ الطالب» بـ
`tablesCreatedBy('Payments')`، فبيعُ كتابٍ مكتوبٌ هناك يدخلُ ميزانَ الأرصدةِ وتقاريرَه بلا
داعٍ. والكوبوناتُ والباقاتُ تُوضَعُ في `Payments` **للسببِ نفسِه معكوساً**: هي تخفضُ سعرَ
حزمةِ رصيدٍ فعلاً، فمكانُها داخلَ ذلك السياقِ لا خارجَه.

---

## ترتيبُ التنفيذ

| الموجة | يشحنُ | يعتمدُ على |
|---|---|---|
| **م١ · المتجر** (US1 · P1) | `Store` · **مطالبةُ الإنجازِ ثمّ المخزون** · السعرُ بيدِ المدرّس · الشحنةُ (‏**بعنوانٍ نصّيٍّ بلا `region_id`**) · ⚠️ **استخراجُ `MintPlaybackGrant`** · `StorePersonalData` | ٠٠٤ · ٠٠٧ |
| **م٢ · الخصومات** (US2 · P2) | `Coupon` · `CouponRedemption` · خصمُ الإخوةِ من `parent_student_relations` · `DiscountResolver` يعودُ **بخصمٍ واحد** | م١ (‏نطاقُ الكوبونِ يشملُ المتجر) |
| **م٣ · الإحالة** (US3 · P3) | `ReferralCode` · `Referral` · قيدُ `Bonus` · **هجرةُ `seedMissing()` لمفتاحِ التلعيب** · العكسُ بعمودٍ خامس | ٠٠٦ · ٠٠٩ |
| **م٤ · الباقات** (US4 · P4) | `Plan` (‏**بسعرِ المنصّة**) · `Subscription` · الأهليّةُ بلا مساسِ الأرصدة · التجميدُ يُقرَأُ ولا يُكتَبُ فيه | ٠٠٥ · ٠٠٦ · ٠١٤ |
| **م٥ · المدوّنةُ والسيو** (US5 · P5) | شاشةُ التأليف · `IsPubliclyListed` · `sitemap.ts` · `robots.ts` · JSON-LD · OG · إخطارُ المحرّك | ٠٠١ |
| **م٦ · التحليلاتُ والمفاتيح** (US6 · P6) | `platform_metrics_daily` + وظيفتُها · صفحةُ Filament · `feature_flags` · `regions` وحقلُها · **`report_subscriptions` (FR-045)** | كلُّ ما سبق |

⚠️ **و م٦ تحملُ FR-042**، وهو تعديلٌ على تدفّقِ تسجيلٍ حيٍّ ومُختبَر: العمودُ `nullable`
في القاعدةِ والإلزامُ في `FormRequest` وحدَه، ويُوثَّقُ في ٠٠١ كما تشترطُ السبيكُ بنصِّها
(«‏ولا يُدخَلُ صامتاً»).

---

## المخاطرُ السّتّةُ التي تستحقُّ الذكر

1. **⚠️ الحجمُ هو الخطرُ الأوّل، لا أيُّ خيطٍ فيه.** ٤٨ متطلَّباً ضِعفا ٠٠٨ (‏١٩٠ مهمّة).
   المرحلةُ تُشحَنُ موجةً موجةً وتُدمَجُ موجةً موجة؛ دفعةٌ واحدةٌ في آخرِها تُراجَعُ
   بلا مراجِع.

2. **⚠️ اختبارُ التزامنِ المتسلسلُ يمرُّ على بناءٍ بلا مطالبةٍ فيه.** «اشترِ آخرَ وحدةٍ
   مرّتَين» يعودُ عندَ فحصِ المخزونِ قبلَ الكتابةِ بخطوة، فيُصادِقُ على العيبِ الذي كُتِبَ
   ليمنعَه. الشكلُ المشحونُ في ٠١٧: خطّافُ اعتمادِ الدفعِ يقعُ **بينَ** القراءةِ والمطالبة،
   فمُطالِبٌ داخلَ تلك النافذةِ **هو** العاملُ الآخرُ يفوز — بلا خيوطٍ ولا انتظار. ينطبقُ
   حرفيّاً على سقفِ الكوبونِ كذلك.

3. **⚠️ حدثُ الإحالةِ بلا صفٍّ في الكتالوجِ يمنحُ صفراً في صمت.** `AwardPoints` تعودُ بلا
   كلمةٍ حين لا مفتاحَ لها، وكلُّ اختبارٍ أخضرُ لأنّ `tests/Pest.php` تبذرُ الكتالوجَ قبلَ
   كلِّ حالة — فالتأكيدُ يُقاسُ على جدولٍ لا تملكُه المنصّةُ الحيّة. **العيبُ نفسُه وقعَ
   ثلاثَ مرّاتٍ في هذا المستودعِ ومسجَّلٌ في `CLAUDE.md`**: هجرةُ backfill بـ`seedMissing()`
   في نفسِ الدفعة، لا في الوقتِ المناسب.

4. **⚠️ المدوّنةُ العامّةُ تفتحُ سطحَ قراءةٍ لزائرٍ غيرِ مصادَقٍ عليه، وعزلُ المستأجرينَ
   خاملٌ هناك تماماً.** `WorkspaceScope::apply()` لا يضيفُ شرطاً حين يكونُ السياقُ `null`،
   وهو كذلك دائماً بلا مستخدِم — فاستعلامٌ عامٌّ بلا حارسٍ يُرجِعُ صفوفَ كلِّ مساحاتِ العمل،
   **ومنها المسوّدات**. الحارسُ `publiclyListed()`، **ولا يُربَطُ نموذجٌ بمسارٍ عامٍّ ضمنيّاً**.
   ⚠️ و`getContent()` يهربُ غيرَ الـASCII، فتأكيدُ تسرُّبٍ بإبرةٍ عربيّةٍ **صادقٌ فراغاً**
   مهما كانتِ الحمولة.

5. **⚠️ خمسُ مخالفاتٍ للحماية كُشِفَتْ في المراجعةِ ولا اختبارَ قائمٌ يمسكُ أيّاً منها.**
   المدرّسُ يعتمدُ بيعَ كتابِه بنفسِه (`OrderPolicy::approve()` يسقطُ إلى `PAYMENTS_APPROVE`)؛
   و`analytics.view` صلاحيةُ مساحةِ عملٍ يحملُها المساعِد؛ و`throttle:auth` عدّادٌ عالميٌّ
   واحد؛ و`{purchase}` بالربطِ الضمنيِّ يحلُّ طلبَ أيِّ مشترٍ؛ و`media_asset_id` الخامُّ
   يُرفِقُ ملفَّ مدرّسٍ آخر. **و`PermissionPanelTest` اشتقاقٌ يُقارَنُ بنفسِه** فلا يرى
   الثانيةَ منها — التثبيتُ الحرفيُّ للأسماءِ هو الحارسُ الوحيد.

6. **⚠️ قراءةُ اللوحةِ بصلاحيةِ منصّةٍ تُعلِنُ `withoutWorkspaceScope()` وتُكرِّرُها في كلِّ
   تحميلٍ مُسبَق.** `WorkspaceContext::id()` يرتدُّ إلى `users.last_workspace_id` حتى للمشرفِ
   العامّ، فلوحةٌ متروكةٌ في النطاقِ تعرضُ أرقامَ مساحةِ عملٍ واحدةٍ كمجموعِ المنصّة —
   **وتمرُّ اختبارَها على تجهيزةٍ بمساحةٍ واحدة**. أيُّ اختبارٍ لقراءةٍ بصلاحيةِ منصّةٍ يحتاجُ
   **مساحتَين** أو لا يُثبِتُ شيئاً. العيبُ شُحِنَ سلفاً في سلسلةِ التدقيقِ وأجابَ «لم
   يُشترَ شيء» بـ`200`.
