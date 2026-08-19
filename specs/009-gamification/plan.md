# Implementation Plan: نظام التلعيب (Gamification)

**Branch**: `009-gamification` | **Date**: 2026-08-19 | **Spec**: [`spec.md`](./spec.md)

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
Horizon (‏طوابيرُ `default` و`maintenance` **القائمة**، فلا تعديلَ في `config/horizon.php` ولا
ثغرةَ `waits` جديدة) · Filament (‏لوحةُ ضبطِ الأفعال والمستويات والشارات)

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
(‏`Assumptions`). خمسُ قصصٍ · ٤٣ متطلَّباً وظيفياً · ٢٢ معياراً · تسعةُ جداول

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

**صفرُ حدثٍ جديدٍ في أيّ وحدةٍ قائمة.** الأحداثُ الستّةُ التي تُستهلَك موجودةٌ ومُطلَقةٌ اليوم،
وواحدٌ منها كُتب لهذه المرحلة بالاسم (‏§R2). والاتجاهُ الآخر: `Gamification` تُطلق أحداثَها
(`LevelReachedUp` · `BadgeAwarded` · `RewardRedeemed`) وتستمع لها `Notifications` — و**يُمنع**
أن تنادي `Gamification` أيَّ `Action` في وحدةٍ أخرى.

`app/Modules/Gamification` يُضاف إلى `phpstan.neon`، ومجلدُ الهجرات `Database/Migrations`
بحرف M كبير.

### المبدأ الرابع — البوّاباتُ الأربع خضراء

`pest` · `pint --test` · `phpstan analyse` (‏L8 بلا baseline جديد) · `npx tsc --noEmit`.
والمساراتُ الحرجةُ الثمانية تبقى خضراء — وهذه المرحلةُ لا تلمس أياً منها: تستمع ولا تُعدّل.

### المبدأ الخامس — التفويض بالسياسات والثوابت

صلاحياتٌ جديدةٌ في `Tenancy\Support\Permissions`، **ولا اسمَ مكتوبٌ نصّاً**:

| الثابت | الطبقة | من يحمله |
|---|---|---|
| `GAMIFICATION_CATALOG_MANAGE` | **منصّية** | لا دورَ مستأجرٍ إطلاقاً (‏`RolePermissionMatrix` يشتقّها منصّيةً بالطرح) |
| `REWARDS_MANAGE` | مستأجر | المدرّس — متجرُه |
| `REDEMPTIONS_FULFILL` | مستأجر | المدرّس ومساعدوه لاحقاً في ٠١٠ |
| `PROGRESS_VIEW_STUDENT` | مستأجر | قراءةُ تقدّمِ طالبٍ **مسجَّلٍ عنده** — و`NFR-001أ` هو الحدّ |

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
│   ├── Policies/
│   ├── Database/Migrations/                  ← ⚠️ M كبيرة
│   └── routes/api.php
├── app/Modules/Tenancy/Support/Permissions.php        ← مُعدَّل: أربعةُ ثوابت
├── app/Shared/Support/FocusWindow.php                 ← مُشترَك: لا وحدةَ تقرأ الأخرى
├── app/Modules/Notifications/Support/NotificationType.php ← مُعدَّل: ثلاثةُ أنواع
├── app/Filament/Resources/                            ← ثلاثةُ موارد (‏الأفعال · المستويات · الشارات)
├── config/gamification.php                            ← ارتداداتُ الأرقام
├── database/seeders/GamificationCatalogSeeder.php     ← بياناتٌ مرجعيةٌ لا تجهيزات
└── routes/console.php                                 ← مُعدَّل: ثلاثُ وظائفَ مجدولة

frontend/src/
├── app/(app)/progress/page.tsx                ← FR-041: كلُّ شيءٍ في مكانٍ واحد
├── app/(app)/leaderboard/page.tsx
├── app/(app)/shop/page.tsx
├── app/(app)/manage/rewards/page.tsx          ← شاشةُ المدرّس
├── components/gamification/                   ← مكوّناتُ هذه المرحلة
└── lib/gamification.ts
```

**Structure Decision**: وحدةٌ واحدةٌ جديدةٌ (`Modules/Gamification`) تُكتشَف تلقائياً عبر
`ModulesServiceProvider`، على نفسِ شكلِ الوحداتِ الأربعَ عشرةَ القائمة. التعديلُ خارجها
**مُعدَّدٌ ومحدود**: ثوابتُ صلاحيات، ثلاثةُ أنواعِ إشعار، ثلاثةُ أسطرِ جدولة، وصنفٌ مُشترَكٌ
واحد. **صفرُ تعديلٍ في `LiveSessions` أو `Assessments` أو `Payments`** — تُستمَع أحداثُها ولا
تُلمَس.

## Complexity Tracking

> يُملأ فقط إن كانت في `Constitution Check` مخالفاتٌ تحتاج تبريراً.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| **`leaderboard_entries` جدولٌ مُشتقٌّ يخالف `NFR-009` كما كُتب أوّلَ مرّة** («بنيةُ بياناتٍ مرتّبةٌ في ذاكرةٍ سريعة» = ‏Redis ZSET) | المتطلَّبُ الحقيقيُّ قراءةٌ لوغاريتميةٌ وفترةٌ تنتهي صلاحيتُها، وكلاهما يتحقّق بفهرسٍ مركَّبٍ `(scope_key, period_key, points)` وكنسةٍ مجدولة. والميزةُ الوحيدةُ التي ينفرد بها `ZSET` هي `ZREVRANK` | **Redis هنا هو نفسُه خادمُ الطوابير**، فإخلاءٌ عند `maxmemory` يمحو لوحةً جاريةً في منتصف الأسبوع **بلا خطأٍ في أيّ مكان**؛ ومصدرُ حقيقةٍ ثانٍ يحتاج مسارَ إعادةِ بناءٍ لا يُشغَّل إلا يومَ الكارثة؛ وطرفٌ ثالثٌ في مسارِ القراءة يعني مجموعةَ اختباراتٍ حمراءَ على أيّ جهازٍ بلا Redis. المسارُ الترقيةُ محفوظ: `ZSET` كذاكرةِ قراءةٍ **فوق** نفس الجدول يومَ تتجاوز لوحةٌ ميزانيتَها، بلا تغييرِ مصدرِ الحقيقة. النصُّ عُدِّل في `spec.md` بدل تركِه يناقض الخطّة |

**ولا مخالفةَ ثانية.** ما يبدو مخالفةً وليس منها:

- **`FocusWindow` في `App\Shared\Support`** ليس كسراً للمبدأ الثالث بل تطبيقُه: البديلُ أن
  تقرأ `Notifications` جدولَ `focus_sessions` (‏وصولٌ مباشرٌ لنموذج وحدةٍ أخرى — **ممنوع**
  نصّاً)، أو أن يُبنى عقدٌ بتنفيذٍ واحد. صنفٌ مشترَكٌ بمفتاحِ ذاكرةٍ واحدٍ وثلاثِ دوالّ لا
  يعرف عنه أيٌّ من الطرفَين شيئاً.
- **تسعةُ جداول** ليست تضخّماً: ثمانيةٌ منها كياناتٌ مذكورةٌ بالاسم في `Key Entities`،
  والتاسعُ مُشتقٌّ يُحذَف ويُعاد بناؤه. و**درعُ الحماية عمودٌ لا جدول** — الدرعُ مِثليٌّ، فعدَدٌ
  على ملفّ التقدّم يجيب كلَّ سؤالٍ يجيبه جدولٌ من صفوفٍ متطابقة.
