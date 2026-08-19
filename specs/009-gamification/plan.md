# Implementation Plan: نظام التلعيب (Gamification)

**Branch**: `009-gamification` | **Date**: 2026-08-19 · **مُنقَّحٌ 2026-08-20** | **Spec**: [`spec.md`](./spec.md)

> 🔴 **مُراجَعٌ بخمسةِ وكلاء** — [`review-findings.md`](./review-findings.md): ثمانِ قواطعَ
> وعشرون عالية، وإصابةٌ خاطئةٌ مردودة. المواضعُ المصحَّحةُ معلَّمةٌ 🔴 هنا وفي `research.md`.

**Input**: Feature specification from `/specs/009-gamification/spec.md`

## Summary

وحدةٌ جديدةٌ `Modules/Gamification` تستمع إلى **أحداثٍ قائمةٍ بالفعل** من ٠٠٥ و٠٠٦ و٠٠٨،
وتُقيّد كلَّ منحٍ في سجلٍّ مضافٍ لا يُعدَّل، فتُشتقّ منه ثلاثةُ أشياء: حالةُ الطالب اللحظية،
والنطاقاتُ الستّةُ للترتيب، ورصيدُ عملاته في كلّ مساحةِ عمل.

**النواةُ سطرٌ واحدٌ من المعنى**: `award_entries` هو الحقيقة، وكلُّ ما عداه مُشتقٌّ منه —
`student_progress` تجميعٌ لحظيٌّ لتفادي جمعِ السجلّ عند كلّ طلب، و`leaderboard_entries` جدولٌ
مفهرَسٌ يُعاد بناؤه بأمرٍ واحدٍ متى شُكَّ فيه. فلا يوجد رقمٌ في هذا النظام لا يمكن إثباتُه من
سجلٍّ يُقرأ سطراً سطراً.

**وأربعةُ اصطلاحاتٍ قائمةٍ في المستودع تُستعمل كما هي، ولا يُبنى لها بديل**:

| الحاجة | الاصطلاح القائم | من أين |
|---|---|---|
| منحٌ لا يتكرّر عند إعادة إرسال حدث | `insertOrIgnore` بمفتاحٍ فريدٍ + **قراءةٌ بعده أو رمي** | `CreditLedger::writeEntry()` (٠٠٦) |
| خصمٌ لا يصير سالباً تحت التزامن | `UPDATE … WHERE` شرطيّةٌ ذرّية، **لا `lockForUpdate()`** | `StructureVersion::claim()` · `captured_order_id` |
| أرقامٌ يضبطها المشغّل | `PlatformSettings::get()` بارتدادٍ إلى `config/` | `SettlementSettings` · `MediaLimits` |
| كتمُ إشعارٍ اختياريٍّ دون الإلزاميّ | `NotificationType::isMandatory()` | ٠٠٣ · ساعاتُ الهدوء |

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 (الخلفية) · TypeScript / Next.js 15 App Router (الواجهة)

**Primary Dependencies**: لا تبعيةَ جديدة. `spatie/laravel-permission` (‏وضعُ الفرق) ·
Horizon (‏طوابيرُ `default` و`maintenance` القائمة — 🔴 **لكنّ `redis:maintenance` غيرُ مُدرَجٍ في
`waits` اليومَ**، وهي ثغرةٌ قائمةٌ في المستودع ترثها هذه المرحلةُ ووظيفةُ التجميع عليها؛ تُضاف) ·
Filament (‏لوحةُ ضبطِ الأفعال والمستويات والشارات)

**Storage**: MySQL في الإنتاج · SQLite محلياً وفي الاختبارات. **صفرُ اعتمادٍ على Redis في أيّ
مسارِ قراءة** (‏§R1) — Redis يبقى خادمَ الطوابير كما هو منذ ٠٠٣

**Testing**: Pest (‏`RefreshDatabase` + `WithWorkspace`) للخلفية · Vitest + jsdom للمكوّنات ·
Playwright لِما هو رحلةٌ كاملةٌ أو وصوليّة

**Target Platform**: خادم Linux · متصفّح — والواجهةُ عربيةٌ RTL حصراً بتخطيط الجذر القائم

**Project Type**: تطبيقُ ويب (‏`backend/` + `frontend/`) — وحدةٌ جديدةٌ داخل المونوليث المُقسَّم

**Performance Goals**: رتبةُ طالبٍ أو أفضلُ عشرين في لوحةٍ بـ١٠٠٬٠٠٠ مشاركٍ خلال **١٠٠ms
(p95)** — عبر فهرسٍ مركَّب، لا فرزاً ولا جمعاً وقتَ الطلب (‏`SC-008`) · ومنحُ النقاط **لا يزيد
زمنَ العملية التي أطلقته بقدرٍ يُقاس** (‏`SC-016`)

**Constraints**: سجلُّ المنح مضافٌ لا يُعدَّل ولا يُحذف منه · صفرُ رصيدٍ سالبٍ تحت التزامن ·
صفرُ منحٍ فوق السقف اليوميّ · صفرُ تسريبٍ بين مساحات العمل · حدُّ اليوم والأسبوع بتوقيت الدوحة
والأسبوعُ يبدأ الأحد · صفرُ تحويلٍ للنقاط إلى نقد

**Scale/Scope**: آلافُ الطلاب عند الإطلاق؛ التصميمُ يحتمل النموّ ولا يُهندَس لملايين
(‏`Assumptions`). خمسُ قصصٍ · **٥٣** بنداً وظيفياً (‏٤٣ مرقَّماً + عشرةٌ محرَّفة) · **٢٦** معياراً ·
**عشرةُ جداولَ جديدة** وجدولان قائمان يفقدان عمودَ مساحة العمل

> 🔴 الأرقامُ صُحِّحت: كانت «٤٣ · ٢٢ · تسعةُ جداول» بينما `data-model.md` يرقّم إلى ١١ ووصفُ
> التبرير «ثمانيةٌ مسمّاةٌ + واحدٌ مُشتقّ» حسابٌ لا يصحّ. نفسُ صنفِ الخطأ الذي مسكته مراجعةُ ٠١٣.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

الدستور **v1.2.0**. البوّابةُ مُرَّت **قبل** البحث و**بعد** التصميم؛ ما يلي هو التقييمُ بعد
التصميم، وفيه مخالفةٌ واحدةٌ مُبرَّرةٌ في `Complexity Tracking`.

### المبدأ الأول — عزل المستأجرين · تصنيفُ الطبقات إلزاميّ

> «التصنيف قرار مُلزِم يُوثَّق في مواصفة الميزة… كيان بلا تصنيف معلَن يُرفَض في المراجعة.»

| الكيان | الطبقة | الحارس |
|---|---|---|
| `student_progress` | **منصّة (أ)** — هوية الطالب | ملكيةُ الصفّ للمستخدم + `NFR-001أ` (‏تسجيلٌ نشطٌ لرؤية المدرّس) |
| `badge_awards` | **منصّة (أ)** | نفسه |
| `focus_sessions` | **منصّة (أ)** | نفسه |
| `gamification_actions` · `levels` · `badges` | **منصّة (ب)** — بياناتٌ مرجعية | **صلاحيةٌ منصّية** `gamification.catalog.manage`، **يُمنع** منحُها لأيّ دورِ مستأجر |
| 🔴 `subjects` · `grade_levels` (‏قائمان) | **تُرفَعان إلى منصّة (ب)** — Q8 | صلاحيةٌ منصّية `taxonomy.manage`. اليومَ `BelongsToWorkspace`، فنطاقا المادّة والصفّ «العابران» ينهاران إلى نطاقاتٍ داخل مساحةِ عمل |
| 🔴 `award_daily_counters` | **جسر** | صفُّ حجزٍ للسقف اليوميّ — بدونه السقفُ قراءةٌ ثمّ كتابة |
| `award_entries` | **جسر** | `workspace_id` للسياق + مستخدمٌ عامّ |
| `coin_balances` | **جسر** | `BelongsToWorkspace` |
| `redemptions` | **جسر** | `BelongsToWorkspace` |
| `rewards` | **مساحةُ عمل** — ما ينتجه المدرّس | `BelongsToWorkspace` كاملاً |
| `leaderboard_entries` | **منصّة (ب)** — مُشتقٌّ لا مصدر | لا كاتبَ إلا وظيفةٌ مجدولة؛ القراءةُ بحسب النطاق (‏`FR-028`) |

⚠️ **و`coin_balances` تحمل `BelongsToWorkspace` بينما `student_progress` لا تحمله** — وهذا هو
`Q0` مكتوباً بعمود. الخطأُ في الاتجاه الآخر صامتٌ ومتأخّر: `BelongsToWorkspace` على ملفّ
التقدّم يُنتج **ملفَّ خبرةٍ ومستوىً وسلسلةً لكلّ مدرّسٍ يدرس عنده الطالب**، فيرى الطالبُ
مستواه ينخفض بمجرّد تبديل السياق. وهو نفسُ الخطأ المرآة الذي وُجد `PlatformOwnershipTest`
لالتقاطه في الاتجاهين.

**الاختباراتُ التي يفرضها الدستورُ نصّاً، في نفس الـPR**:

- `tests/Feature/Tenancy/WorkspaceIsolationTest.php` — حالةٌ لكلّ نموذجٍ مملوكٍ لمساحةِ عمل
  (`rewards` · `coin_balances` · `redemptions` · `award_entries`).
- `tests/Feature/Gamification/PlatformOwnershipTest.php` — الصنف (أ) بالاتجاهين: مدرّسٌ **لا**
  يرى ملفَّ طالبٍ غيرِ مسجَّلٍ عنده، وطالبٌ عند ثلاثةِ مدرّسين له **ملفٌّ واحد** (`SC-017`).
- `tests/Feature/Gamification/CatalogPermissionTest.php` — الصنف (ب): حاملُ **أعلى دورِ
  مستأجر** يُردّ بـ403 عن تعديل قيمة فعل.

### المبدأ الثاني — المنطق في الـActions

كلُّ منحٍ يمرّ بـ`AwardPoints` وحدَه، وكلُّ استبدالٍ بـ`RedeemReward` وحدَه. `NFR-002` أشدُّ من
الدستور هنا وهو المقصود: **المستمعون يترجمون حدثاً إلى اسم فعلٍ ولا يحسبون قيمة** — قيمةٌ
محسوبةٌ في مستمعٍ هي القيمةُ التي لا يجدها من يبحث عنها في اللوحة.

### المبدأ الثالث — استقلالُ الوحدات والتكاملُ بالأحداث

**صفرُ حدثٍ جديدٍ في أيّ وحدةٍ قائمة.** 🔴 **وخمسةٌ تُستهلَك لا ستّة**: `SessionCancelled` **لا
يمكن أن يقع بعد منح** (‏الحضورُ يُؤكَّد عند الإتمام والإلغاءُ يرمي على حالةٍ نهائية)،
و`AccessWithheld` يشتعل عند فتح نافذةِ امتحانٍ أيضاً وهو لكلّ كورس. و`AttendanceOverridden` هو
مُطلِقُ العكس الحقيقيّ. 🔴 **وسؤالان يُجابان بعقدَين في `Shared/Contracts`** — الحضورُ (‏الحدثُ
يحمل الحصّةَ لا الحاضرين) والتجميدُ (`FreezePeriod` مقيَّدٌ بمساحةِ عملٍ والسلسلةُ منصّية).
والاتجاهُ الآخر: `Gamification` تُطلق أحداثَها
(`LevelReachedUp` · `BadgeAwarded` · `RewardRedeemed`) وتستمع لها `Notifications` — و**يُمنع**
أن تنادي `Gamification` أيَّ `Action` في وحدةٍ أخرى.

`app/Modules/Gamification` يُضاف إلى `phpstan.neon`، ومجلدُ الهجرات `Database/Migrations`
بحرف M كبير.

### المبدأ الرابع — البوّاباتُ الأربع خضراء

`pest` · `pint --test` · `phpstan analyse` (‏L8 بلا baseline جديد) · `npx tsc --noEmit`.
🔴 والمساراتُ الحرجةُ في `AGENTS.md` **اثنان وثلاثون لا ثمانية**، وهذه المرحلةُ تلمس منها عزلَ
مساحات العمل وفرضَ الأذونات و`ProviderAgnosticTest`/`WhatsAppDefaultsTest` (‏عبر نوعٍ جديدٍ
يستهدف وليَّ الأمر). تبقى خضراء، **وتحديثُ عدّاد `WhatsAppDefaultsTest` جزءٌ من المهمّة**.

### المبدأ الخامس — التفويض بالسياسات والثوابت

صلاحياتٌ جديدةٌ في `Tenancy\Support\Permissions`، **ولا اسمَ مكتوبٌ نصّاً**:

| الثابت | الطبقة | من يحمله |
|---|---|---|
| `GAMIFICATION_CATALOG_MANAGE` | **منصّية** | لا دورَ مستأجرٍ إطلاقاً (‏`RolePermissionMatrix` يشتقّها منصّيةً بالطرح) |
| `REWARDS_MANAGE` | مستأجر | المدرّس — متجرُه |
| `REDEMPTIONS_FULFILL` | مستأجر | المدرّس ومساعدوه لاحقاً في ٠١٠ |
| `PROGRESS_VIEW_STUDENT` | مستأجر | قراءةُ تقدّمِ طالبٍ **مسجَّلٍ عنده**، 🔴 بمسارٍ وسياسةٍ فعليَّين — صلاحيةٌ بلا نقطةِ فرضٍ اسمٌ سيربطه أحدُهم بمسارٍ لاحقاً بلا فحص |
| 🔴 `TAXONOMY_MANAGE` | **منصّية** | الموادُّ والصفوفُ بعد الترقية (‏Q8) |

🔴 **والثلاثُ المستأجرةُ يجب أن تُدرَج في `RolePermissionMatrix::map()`**: `platformPermissions()`
**طرحٌ** ممّا تحمله أدوارُ مساحات العمل، فثابتٌ غيرُ مُدرَجٍ **منصّيٌّ بالاشتقاق** — ثمّ **يرمي**
`Tenancy\Models\Role` حين يمنحه البذرُ لدور المدرّس. **فيفشل البذرُ لا المراجعة.**

⚠️ **و`GAMIFICATION_CATALOG_MANAGE` منصّيةٌ لأن قيمةَ الفعل تُرتَّب بها المنصّةُ كلُّها**: مدرّسٌ
يستطيع رفعَ قيمةِ «حضور حصة» يستطيع أن يضع طلابَه في صدارةِ المادةِ والصفِّ والمنصّة. وهو نفسُ
سببِ كون `billing.limit.manage` منصّية.

### المبدأ السادس — العقودُ الظاهرة

`HasUuid` على كلّ نموذجٍ يظهر في مسار، وكشفُ الـuuid وحدَه، و`declare(strict_types=1);`،
وDTOs ترث `DataTransferObject`، وكلُّ استجابةٍ عبر API Resource.

⚠️ **وحدُّ الحقول في اللوحات ليس اجتهاداً**: `GamificationFieldAllowlist` على نمط
`PublicFieldAllowlist` و`StudentBalanceAllowlist` القائمَين، و`LeaderboardExposureTest` يفشل
البناءَ على أيّ حقلٍ خارج القائمة — بالعربيّة المُعاد ترميزُها (`JSON_UNESCAPED_UNICODE`)، لأن
`getContent()` يهرّب غيرَ الـASCII فتمرّ كلُّ دعوى تسريبٍ بحجّةٍ عربيةٍ **فارغة**.

## Project Structure

### Documentation (this feature)

```text
specs/009-gamification/
├── plan.md              # هذا الملفّ
├── research.md          # المرحلة ٠ — أحد عشر قراراً
├── data-model.md        # المرحلة ١ — تسعةُ جداولَ وفهارسُها
├── quickstart.md        # المرحلة ١ — كيف يُتحقَّق منها يدوياً
├── contracts/           # المرحلة ١ — مسارات API وأحداثُ الوحدة
└── tasks.md             # المرحلة ٢ (‏`/speckit-tasks` — لا يُنشئه التخطيط)
```

### Source Code (repository root)

```text
backend/
├── app/Modules/Gamification/                 ← الوحدةُ الجديدةُ كاملةً
│   ├── GamificationServiceProvider.php       ← Event::listen ×6 · وسمُ لا شيء
│   ├── Actions/
│   │   ├── AwardPoints.php                   ← ⚠️ المدخلُ الوحيدُ لكلّ منح
│   │   ├── ReverseAward.php                  ← قيدٌ عكسيّ، لا حذف (FR-010)
│   │   ├── EvaluateBadges.php                ← يُستدعى من وظيفةٍ لا من المسار
│   │   ├── RecalculateStreak.php
│   │   ├── SaveReward.php · ToggleReward.php
│   │   ├── RedeemReward.php                  ← ⚠️ الخصمُ والمخزونُ والسقفُ في UPDATE واحدة
│   │   ├── FulfillRedemption.php · RejectRedemption.php
│   │   ├── StartFocusSession.php · EndFocusSession.php
│   │   └── RebuildLeaderboards.php           ← SC-011: يُعيد البناءَ من award_entries
│   ├── Models/                               ← تسعةُ نماذج (‏data-model.md)
│   ├── Support/
│   │   ├── GamificationCalendar.php          ← ⚠️ الموضعُ الوحيدُ لحدّ اليوم والأسبوع
│   │   ├── LeaderboardScope.php              ← النطاقاتُ الستّةُ كـenum
│   │   ├── LevelBand.php                     ← الشريحةُ: نطاقُ مستوىً ثمّ نافذة
│   │   ├── GamificationSettings.php          ← PlatformSettings + config/gamification.php
│   │   └── GamificationFieldAllowlist.php
│   ├── Listeners/                            ← ستّةٌ، كلٌّ منها يترجم ولا يحسب
│   ├── Jobs/
│   │   ├── EvaluateBadgesJob.php             ← queue: default
│   │   ├── RollUpLeaderboardsJob.php         ← queue: maintenance
│   │   ├── CloseLeaderboardWeekJob.php       ← الأحدُ فجراً بتوقيت الدوحة
│   │   └── PruneOldLeaderboardsJob.php       ← FR-026، على نمط PruneOldNotificationsJob
│   ├── Http/{Controllers,Requests,Resources}/
│   ├── Data/                                 ← 🔴 كان غائباً وNFR-005 يوجب DTOs
│   ├── Enums/                                ← 🔴 أربعُ مجموعاتٍ مغلقة
│   ├── Filament/Resources/                   ← 🔴 بجوار الكود، لا app/Filament
│   ├── Policies/
│   ├── Database/Migrations/                  ← ⚠️ M كبيرة
│   └── routes/api.php
├── app/Modules/Tenancy/Support/Permissions.php        ← مُعدَّل: خمسةُ ثوابت
├── app/Modules/Tenancy/Support/RolePermissionMatrix.php ← 🔴 مُعدَّل: الثلاثُ المستأجرة، وإلا فشل البذر
├── app/Shared/Contracts/FocusState.php                ← 🔴 عقدٌ على نمط العقود السبعة القائمة
├── app/Shared/Contracts/FreezeDirectory.php           ← 🔴 التجميدُ سؤالٌ منصّيّ عن نموذجٍ مقيَّد
├── app/Shared/Contracts/SessionAttendanceDirectory.php ← 🔴 مُعدَّل: حاضرو حصّة، مستثنيةً المضيف
├── app/Shared/Support/DisplayName.php                 ← 🔴 مُنتزَعٌ من Review، ينادي عليه الاثنان
├── app/Modules/Notifications/Support/NotificationType.php ← مُعدَّل: ثلاثةُ أنواع
├── app/Modules/Notifications/Actions/DispatchNotification.php ← 🔴 مُعدَّل: شرطُ جلسةِ التركيز
├── app/Modules/Marketplace/Database/Migrations/       ← 🔴 هجرتان: توحيدٌ بالـslug ثمّ إسقاطُ العمود
├── app/Providers/Filament/AdminPanelProvider.php      ← 🔴 مُعدَّل: سطرُ discoverResources
├── config/gamification.php                            ← ارتداداتُ الأرقام
├── config/horizon.php                                 ← 🔴 مُعدَّل: redis:maintenance في waits
├── database/seeders/GamificationCatalogSeeder.php     ← بياناتٌ مرجعيةٌ لا تجهيزات
├── database/factories/Modules/Gamification/           ← 🔴 المصانعُ مركزيةٌ في هذا المستودع
├── lang/ar/validation.php                             ← 🔴 حقولُ الطلبات الجديدة، وإلا رُندرت بالإنجليزية
├── tests/Pest.php                                     ← 🔴 بذرُ الفهرس، وإلا مرّ كلُّ تأكيدِ منحٍ ضدّ صفر
├── app/Providers/AppServiceProvider.php               ← 🔴 محدِّدان مُسمّيان
└── routes/console.php                                 ← 🔴 مُعدَّل: أربعُ وظائفَ بـ->timezone('Asia/Qatar')

frontend/src/
├── app/(app)/progress/page.tsx                ← FR-041: كلُّ شيءٍ في مكانٍ واحد
├── app/(app)/leaderboard/page.tsx
├── app/(app)/shop/page.tsx
├── app/(app)/manage/rewards/page.tsx          ← شاشةُ المدرّس
├── components/gamification/                   ← مكوّناتُ هذه المرحلة
└── lib/gamification.ts
```

**Structure Decision**: وحدةٌ واحدةٌ جديدةٌ (`Modules/Gamification`) تُكتشَف تلقائياً عبر
`ModulesServiceProvider`، على نفسِ شكلِ الوحداتِ الأربعَ عشرةَ القائمة.

🔴 **وقائمةُ التعديلات خارجها كانت ناقصةً ماديّاً، وهي ادّعاءُ الخطّة الرئيس.** الشجرةُ أعلاه
هي القائمةُ المصحَّحة؛ الناقصُ كان: `RolePermissionMatrix` (‏وبدونه يفشل البذر) ·
`registerRateLimiters()` · `tests/Pest.php` (‏وبدونه يمرّ كلُّ تأكيدِ منحٍ **فارغاً ضدّ صفر**) ·
المصانعُ المركزية · `lang/ar/validation.php` · سطرُ `discoverResources` · `docs/README.md`.

**ويبقى صفرُ تعديلٍ في `LiveSessions` و`Assessments` و`Payments`** — تُستمَع أحداثُها ولا
تُلمَس؛ 🔴 والعقدان الجديدان في `Shared/Contracts` هما بالضبط الآليةُ التي تجعل ذلك صحيحاً بدل
أن يكون ادّعاءً (‏سبعةُ عقودٍ سابقةٍ بنفس الشكل). و`Marketplace` تُلمَس بهجرتَي الترقية (‏Q8)
وبلا تغييرٍ في أيّ استعلامِ سوقٍ قائم.

## Complexity Tracking

> يُملأ فقط إن كانت في `Constitution Check` مخالفاتٌ تحتاج تبريراً.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| **`leaderboard_entries` جدولٌ مُشتقٌّ يخالف `NFR-009` كما كُتب أوّلَ مرّة** («بنيةُ بياناتٍ مرتّبةٌ في ذاكرةٍ سريعة» = ‏Redis ZSET) | المتطلَّبُ الحقيقيُّ قراءةٌ لوغاريتميةٌ وفترةٌ تنتهي صلاحيتُها، وكلاهما يتحقّق بفهرسٍ مركَّبٍ `(scope_key, period_key, points)` وكنسةٍ مجدولة. والميزةُ الوحيدةُ التي ينفرد بها `ZSET` هي `ZREVRANK` | **Redis هنا هو نفسُه خادمُ الطوابير**، فإخلاءٌ عند `maxmemory` يمحو لوحةً جاريةً في منتصف الأسبوع **بلا خطأٍ في أيّ مكان**؛ ومصدرُ حقيقةٍ ثانٍ يحتاج مسارَ إعادةِ بناءٍ لا يُشغَّل إلا يومَ الكارثة؛ وطرفٌ ثالثٌ في مسارِ القراءة يعني مجموعةَ اختباراتٍ حمراءَ على أيّ جهازٍ بلا Redis. المسارُ الترقيةُ محفوظ: `ZSET` كذاكرةِ قراءةٍ **فوق** نفس الجدول يومَ تتجاوز لوحةٌ ميزانيتَها، بلا تغييرِ مصدرِ الحقيقة. النصُّ عُدِّل في `spec.md` بدل تركِه يناقض الخطّة |

| 🔴 **ترقيةُ `subjects` و`grade_levels` إلى الطبقة (ب) تُعدَّل في `Marketplace`، خارج الوحدة** | نطاقا المادّة والصفّ «العابران» مبنيّان على صفوفٍ تحمل `workspace_id` — فمعرّفُ «رياضيات» يعني شيئاً مختلفاً عند كلّ مدرّس، والنطاقان ينهاران إلى نطاقاتٍ داخل مساحةِ عمل و`SC-018` يمرّ **أخضرَ** على تجهيزةٍ بمساحةٍ واحدة (‏Q8) | إبقاؤهما مقيَّدَين يعني حذفَ نطاقَين من ستّة وإسقاطَ المتطلَّب ‎#١٨‎ جزئياً. وبناءُ تصنيفٍ منصّيٍّ **موازٍ** يعني تصنيفَين لسؤالٍ واحدٍ يتباعدان — وهو الخطأُ الذي يسمّيه الدستورُ v1.2.0 بالاسم. النطاقُ محدودٌ عمداً: هجرتان وصلاحيةٌ منصّية، بلا شاشةٍ جديدةٍ وبلا تغييرٍ في أيّ استعلامِ سوقٍ قائم |

**ولا مخالفةَ ثالثة.** ما يبدو مخالفةً وليس منها:

- 🔴 **العقود الثلاثةُ في `App\Shared\Contracts`** ليست كسراً للمبدأ الثالث بل **آليتَه
  المشحونة**: المجلَّدُ يحمل سبعةَ عقودٍ بتنفيذٍ واحدٍ لكلٍّ منها، ودفترُ كلٍّ منها يعلن ذلك
  حرفياً. ⚠️ **والنسخةُ الأولى رفضت هذا الشكلَ بدعوى «هندسةٌ زائدة» وهي دعوى غيابٍ كاذبة** —
  الصنفُ الذي حذّر منه أوّلُ سطرٍ في `research.md`. وقد كانت تُنتج معها مصدرَ حقيقةٍ ثانياً
  (‏مفتاحُ ذاكرةٍ بجوار عمودِ حالة) وثلاثةَ تحذيراتٍ في الوثائق من خطرٍ **موجودٍ فقط لأن هناك
  نسختَين**.
- **عشرةُ جداول** ليست تضخّماً: كلُّها كياناتٌ مذكورةٌ في `Key Entities` عدا اثنَين — واحدٌ
  مُشتقٌّ يُحذَف ويُعاد بناؤه، وواحدٌ صفُّ حجزٍ للسقف اليوميّ **بلا بديلٍ ذرّيّ**. و**درعُ
  الحماية عمودٌ لا جدول** — الدرعُ مِثليٌّ، فعدَدٌ يجيب كلَّ سؤالٍ يجيبه جدولٌ من صفوفٍ متطابقة.
