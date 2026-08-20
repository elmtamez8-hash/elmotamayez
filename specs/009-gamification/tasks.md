---

description: "Task list — نظام التلعيب (٠٠٩)"
---

# Tasks: نظام التلعيب (Gamification)

**Input**: Design documents from `/specs/009-gamification/`

**Prerequisites**: [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) ·
[data-model.md](./data-model.md) · [contracts/api.md](./contracts/api.md) ·
[quickstart.md](./quickstart.md) · [review-findings.md](./review-findings.md)

**Tests**: **مطلوبةٌ صراحةً** — المواصفةُ تعلّق **ستّةً وعشرين** معياراً على حرّاسٍ آليّة، و
`SC-011` و`SC-021` و`SC-024` و`SC-025` و`SC-026` تصف كلُّها **عيباً لا يظهر إلّا باختبارٍ مصاغٍ
بشكلٍ معيّن**. فالصياغةُ هنا هي التسليم، لا توثيقُه.

**Organization**: بالقصص الخمس، لتكون كلُّ قصّةٍ زيادةً قابلةً للتسليم والاختبار وحدها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يمكن التوازي (‏ملفّاتٌ مختلفة، بلا تبعيّة)
- **[Story]**: القصّة التي تخدمها المهمّة (`US1`…`US5`)

## Path Conventions

`backend/` أحاديّةٌ معياريّة · `frontend/` Next.js App Router. الوحدةُ الجديدةُ كاملةً تحت
`backend/app/Modules/Gamification/`.

---

## ⚠️ اقرأ هذا قبل `T001`

سبعةُ أشياءَ في هذه المرحلة **يمرّ تنفيذُها الخاطئ أخضر**. كلٌّ منها مكتوبٌ في مهمّته، وتُجمَع
هنا لأن نسيانَ أيٍّ منها يُبطل معياراً كاملاً أو يشحن ميزةً لا تفعل شيئاً:

1. **`GamificationCatalogSeeder` بياناتٌ مرجعيةٌ لا تجهيزات** (`T041` · `T042`). بلا صفوفه لا فعلَ له
   قيمة، فيمرّ **كلُّ** تأكيدٍ على المنح **فارغاً ضدّ صفر**. يُبذَر في `tests/Pest.php`.
2. **`Queue::fake()` بلا وسائط يبتلع مستمعي المنح** (`T068`). «الحدثُ وقع إذن المنحُ موجود»
   تأكيدٌ واثقٌ عن جدولٍ فارغ. زيّف وظائفَ التوقيت وحدَها ودعِ المنحَ يجري على `sync`.
3. **`reversal_of_id` داخل مفتاح الإدماج** (`T012`). بدونه يصطدم القيدُ العكسيُّ بأصله فيُبتلَع
   ويُبلَّغ بالنجاح، و**النقاطُ لا تُرَدّ أبداً** — و`SC-024` يمرّ إن عدَّ الصفوف بدل التجميع.
4. **`RolePermissionMatrix::map()` يستقبل الثلاثَ المستأجرة** (`T006`). `platformPermissions()`
   **طرحٌ**، فثابتٌ غيرُ مُدرَجٍ منصّيٌّ بالاشتقاق ثمّ **يرمي** `Tenancy\Models\Role` عند البذر.
   **فيفشل البذرُ لا المراجعة.**
5. **`GREATEST` وكلُّ فيضٍ غيرِ موقَّع ينقلبان بين المحرّكَين** (`T054` · `T019`). SQLite لا
   تستطيع إنتاج `ERROR 1690` ولا `1264` أبداً — البيئةُ التي تفشل هي التي لا يشغّل أحدٌ
   المجموعةَ فيها. و`lockForUpdate()` **ممنوعٌ في كلّ مسارٍ من هذه المرحلة**.
6. **إطلاقُ حدثٍ مرّتين متتاليتَين يثبت الفهرسَ لا السباق** (`T065`). صحيحٌ للمطالبة الشرطية،
   **وغيرُ صحيحٍ** للسقف اليوميّ وللاستبدال: يمرّ الاختبارُ المتتالي والسباقُ يقع.
7. **`WorkspaceScope` عديمُ الأثر للطالب** (`T104`). الطالبُ ليس عضواً في أيّ مساحةِ عملٍ أبداً،
   فـ`BelongsToWorkspace` **لا يحرس أيَّ مسارٍ من مسارات الطالب**: ربطٌ ضمنيٌّ لـ`{reward}` يحلّ
   مكافأةَ أيّ مدرّس، و`GET /redemptions` بلا `where user_id` يُرجع طلباتِ المنصّةِ كلَّها.

---

## Phase 1: Setup (‏التهيئة المشتركة)

**Purpose**: الوحدةُ والصلاحياتُ والمحدِّداتُ والتهيئة — بلا سطرِ منطقِ منحٍ واحد.

- [X] T001 أنشئ هيكلَ الوحدة `backend/app/Modules/Gamification/` بمجلّداتها (`Actions` · `Models` · `Support` · `Listeners` · `Jobs` · `Http/{Controllers,Requests,Resources}` · `Data` · `Enums` · `Filament/Resources` · `Policies` · `Database/Migrations` · `routes/`) و`GamificationServiceProvider.php` يرث `App\Shared\Modules\Module` — ⚠️ **`Database/Migrations` بحرف M كبير** (‏مطابقةٌ حرفيّة، وتصير صفرَ هجرةٍ على Linux)، و**لا تسجّله يدوياً** في `bootstrap/providers.php`
- [X] T002 أضِف `app/Modules/Gamification` إلى مسارات `backend/phpstan.neon` — وإلى قائمة `Database/Migrations` التي يقرؤها لاستنتاج أنواع خصائص النماذج
- [X] T003 [P] أنشئ `backend/config/gamification.php` بارتدادات الأرقام: عرضُ نطاقِ المستوى (٥) · نافذةُ اللوحة (٥٠) · مدةُ الاحتفاظ باللوحات · السقفُ الأعلى للسقف الشهريّ · حدُّ دقائق جلسة التركيز — **الأرقامُ نفسُها صفوفُ `platform_settings` والملفُّ ارتدادُها** (‏نمط `config/media.php`)
- [X] T004 [P] أضِف `redis:maintenance` إلى `waits` في `backend/config/horizon.php` — ⚠️ **ثغرةٌ قائمةٌ اليومَ ترثها هذه المرحلة**: زوجٌ غائبٌ عن `waits` ليس مراقَباً بحدٍّ افتراضيّ، بل **غيرُ مراقَبٍ أصلاً**، ووظيفةُ التجميع تعيش عليه
- [X] T005 أضِف خمسةَ ثوابتَ إلى `backend/app/Modules/Tenancy/Support/Permissions.php`: `GAMIFICATION_CATALOG_MANAGE` · `TAXONOMY_MANAGE` · `REWARDS_MANAGE` · `REDEMPTIONS_FULFILL` · `PROGRESS_VIEW_STUDENT` — **ولا اسمَ صلاحيةٍ مكتوبٌ نصّاً في أيّ ملفّ بعدها** (`NFR-004`)
- [X] T006 أدرِج **الثلاثَ المستأجرةَ وحدَها** (`REWARDS_MANAGE` · `REDEMPTIONS_FULFILL` · `PROGRESS_VIEW_STUDENT`) في `RolePermissionMatrix::map()` بـ`backend/app/Modules/Tenancy/Support/RolePermissionMatrix.php` — ⚠️ **والاثنتان المنصّيّتان تُترَكان خارجَه عمداً**: `platformPermissions()` طرحٌ، فالغيابُ هو الإعلان. إدراجُ `GAMIFICATION_CATALOG_MANAGE` هنا يهدي كلَّ مدرّسٍ قيمةَ «حضور حصة»
- [X] T007 [P] سجّل محدِّدَين مُسمّيَين في `AppServiceProvider::registerRateLimiters()` بـ`backend/app/Providers/AppServiceProvider.php`: `gamification-write` (‏الاستبدالُ وبدءُ الجلسة) و`gamification-board` (‏الصدارة) — **مفتاحُهما المستخدمُ لا الـIP** (‏مدرسةٌ خلف عنوانٍ واحدٍ قرّاءٌ شرعيّون كثيرون)، و`throttle:N,M` سطريّاً ممنوع
- [X] T008 [P] أضِف حقولَ الطلبات الجديدة إلى `attributes` في `backend/lang/ar/validation.php` (`minutes` · `price_coins` · `stock` · `monthly_cap` · `reward_type` · `scope` · `period`) — بدونها تُرندَر رسالةُ ٤٢٢ بالإنجليزية تحت حقلٍ عربيّ
- [X] T009 أضِف سطرَ `discoverResources` للوحدة في `backend/app/Providers/Filament/AdminPanelProvider.php` — ⚠️ **`app/Filament/Resources/` هو الموضعُ القديم**، وتعليقُ المزوّد نفسِه يقول «كلُّ وحدةٍ تحتفظ بشاشاتها بجوار الكود الذي تديره»

**Checkpoint**: البوّاباتُ الأربع خضراء، و`php artisan migrate` بلا هجرةٍ جديدة — **لا شيءَ تغيّر سلوكاً**.

---

## Phase 2: Foundational (‏شروطٌ حاجبة)

**Purpose**: المخطّطُ والنماذجُ والتقويمُ والعقود. **حاجبٌ لكلّ القصص** — ولا قصّةَ تبدأ قبل خضرة `T045`.

⚠️ **وترتيبُ الهجرتَين ١٣و١٤ إلزاميّ**: الفهرسُ الفريدُ الجديدُ على بياناتٍ لم تُوحَّد بعدُ
**يُفشل النشرَ على بياناتٍ حيّة** — درسُ ٠١٦ حرفياً («الترقيمُ قبل الفهرس»).

### المخطّط

- [X] T010 هجرةُ `gamification_actions` في `backend/app/Modules/Gamification/Database/Migrations/`: `uuid` · `key` unique · `name_ar` · `xp` **موقَّع** · `coins` **موقَّع** · `daily_cap` nullable · `is_active` — ⚠️ **الموقَّعان بقصد**: الفعلُ السالبُ يعيش في الصفّ لا في فرعٍ في الكود
- [X] T011 [P] هجرةُ `levels` (`level` unique · `name_ar` · `xp_threshold`) و`badges` (`key` unique · `name_ar` · `icon` · `rule_type` · `rule_value` · `is_active`)
- [X] T012 هجرةُ `award_entries` بالأعمدة في [data-model.md §٢](./data-model.md): `student_user_id` · `action_key` نصٌّ **لا FK** · `xp`/`coins` موقَّعان · `workspace_id` **nullable** · `course_id`/`lesson_id` nullable · `level_band` · `source_type`/`source_id` **غيرُ فارغَين** · `reversal_of_id` **`NOT NULL DEFAULT 0`** — والفهارسُ الأربعةُ بالضبط: `unique(student_user_id, action_key, source_type, source_id, reversal_of_id)` · `index(student_user_id, action_key, created_at)` · `index(created_at, student_user_id)` · `index(workspace_id, created_at)` · `index(course_id, created_at)`. ⚠️ **ولا فهرسَ مفردٍ زائدٍ على أيّ مفتاحٍ أجنبيّ** — كلُّ مركَّبٍ أعلاه يبدأ بعموده، وكلُّ فهرسٍ إضافيٍّ كلفةُ كتابةٍ على الجدول الذي يمسّه كلُّ منح (`SC-016`). و🔴 **`reversal_of_id` صفرٌ حارسٌ لا `NULL`**: `NULL ≠ NULL` فيتوقّف الحارسُ عن العضّ لكلّ منحٍ عاديّ — سابقةُ `concept_stats.lesson_id`
- [X] T013 [P] هجرةُ `award_daily_counters`: `student_user_id` · `day_key` `string(10)` · `action_key` · `count` · **`unique(student_user_id, day_key, action_key)`** — صفُّ الحجز الذي يُخرج المسحَ الزمنيَّ من مسار الكتابة
- [X] T014 [P] هجرةُ `student_progress`: `user_id` unique · `xp` · `level` · `current_streak` · `best_streak` · `last_active_day` `string(10)` · `shield_count` · `notified_level` · `streak_evaluated_day` `string(10)` — ⚠️ **صفرُ `workspace_id`، ولا يُضاف**: إضافتُه تُنتج ملفَّ خبرةٍ ومستوىً وسلسلةً **لكلّ مدرّس**، فيرى الطالبُ مستواه ينخفض بتبديل السياق
- [X] T015 [P] هجرةُ `coin_balances`: `user_id` · `workspace_id` · **`unique(user_id, workspace_id)`** · `coins` `unsignedInteger`
- [X] T016 [P] هجرةُ `badge_awards`: `user_id` · `badge_key` · `awarded_at` · **`unique(user_id, badge_key)`** — الحارسُ هو الفهرس، لا فحصٌ في الكود
- [X] T017 [P] هجرةُ `rewards`: `title` · `price_coins` · `stock` · `type` · `monthly_cap` **nullable** · `month_key` `string(7)` **`NOT NULL DEFAULT ''`** · `month_redeemed` **`NOT NULL DEFAULT 0`** · `is_active` · `workspace_id`
- [X] T018 [P] هجرةُ `redemptions`: `user_id` · `workspace_id` · `reward_id` · `coins_spent` · `claimed_month_key` `string(7)` · `status` · `decided_by` · `decided_at` — والفهرسان **وكانا غائبَين كلياً**: `index(workspace_id, status, created_at)` (‏شاشةُ المدرّس) و`index(user_id, created_at)` (‏شاشةُ الطالب)
- [X] T019 [P] هجرةُ `leaderboard_entries`: `scope_key` `string(80)` · `period_key` `string(16)` · `user_id` · `points` 🔴 **`integer` موقَّع** · `level_band` · `rank` `unsignedInteger` · `run_stamp` — والفهارس: `unique(scope_key, period_key, user_id)` · `index(scope_key, period_key, level_band, rank)` · `index(period_key)` للكنسة. ⚠️ **الموقَّعُ هو المهمّ**: أسبوعٌ صافيه سالبٌ يرفع `ERROR 1264` فتموت إعادةُ البناء في منتصفها، وSQLite تخزّن `-40` **والاختبارُ أخضر**
- [X] T020 [P] هجرةُ `focus_sessions`: `user_id` · `planned_minutes` · `started_at` · `ended_at` nullable · `status` · `index(user_id, status)`
- [X] T021 هجرةُ **توحيد** `subjects` و`grade_levels` بالـslug في `backend/app/Modules/Marketplace/Database/Migrations/`: يُبقى صفٌّ واحدٌ لكلّ slug ويُعاد ربطُ كلّ مرجعٍ إليه — ⚠️ **بـ`chunkById` لا `chunk`**: المُسنَدُ يتقلّص تحت المشي، فكلُّ صفحةٍ بعد الأولى تتخطّى بقدر ما أصلحته سابقتُها **وتبلّغ بالنجاح**
- [X] T022 هجرةُ **إسقاط** `workspace_id` من `subjects` و`grade_levels` وتحويلِ الفهرس الفريد المركَّب إلى `unique(slug)` — **بعد `T021` حصراً**
- [X] T023 شغّل `php artisan migrate` (‏**لا `migrate:fresh`**) وتحقّق أن الاثنتَي عشرةَ هجرةً تمرّ على قاعدةٍ فيها بيانات

### النماذج والاصطلاحات

- [X] T024 [P] أنشئ `AwardEntry` و`AwardDailyCounter` في `backend/app/Modules/Gamification/Models/` — و`AwardEntry::booted()` **يرمي على `updating` و`deleting`** على نمط `LedgerEntry` (`FR-004`)
- [X] T025 [P] أنشئ `StudentProgress` و`BadgeAward` و`FocusSession` — **بلا `BelongsToWorkspace`** (‏الطبقة أ)
- [X] T026 [P] أنشئ `CoinBalance` و`Redemption` بـ`BelongsToWorkspace`، و`Reward` بـ`BelongsToWorkspace` كاملاً
- [X] T027 [P] أنشئ `GamificationAction` و`Level` و`Badge` و`LeaderboardEntry`
- [X] T028 أزِل `BelongsToWorkspace` من `backend/app/Modules/Marketplace/Models/Subject.php` و`GradeLevel.php` وراجع كلَّ مستدعٍ لهما في `Marketplace` — ⚠️ **وصفرُ تغييرٍ في أيّ استعلامِ سوقٍ قائم** هو معيارُ الصواب هنا
- [X] T029 أنشئ `backend/app/Modules/Gamification/Support/GamificationCalendar.php` بثلاث دوالّ: `dayBounds()` · `weekKey()` · `termKey()` — 🔴 **تقرأ صفَّ `sessions.timezone` القائم** (`LiveSessions/Support/SessionSettings.php:25`) لا مفتاحاً جديداً، و`dayBounds()` **تُرجع زوجاً من طوابع UTC** لا نصّاً يُقارَن بعمود. ⚠️ **و`whereDate()` و`CONVERT_TZ()` كلاهما ممنوع**: الأولى تلفّ العمودَ فتُفقد الفهرسَ، والثانيةُ **تُرجع `NULL` على أيّ MySQL بلا جداولِ مناطقَ زمنيةٍ محمَّلة** فلا يُطابق الشرطُ شيئاً و**لا يُفرَض السقفُ أبداً بلا خطأ**. و`format('W')` ممنوعة (‏أسابيعُ ISO تبدأ الإثنين فينقسم الأسبوعُ إلى مفتاحَين) — المفتاحُ من **تاريخِ** `startOfWeek(CarbonInterface::SUNDAY)` المُمرَّرِ صراحةً
- [X] T030 [P] اكتب `backend/tests/Feature/Gamification/CalendarTest.php`: منحٌ الساعةَ ١١ مساءً بتوقيت الدوحة يقع في **يوم الدوحة** لا يوم UTC التالي · وأحدٌ وسبتٌ في مفتاح أسبوعٍ **واحد** (`SC-010`)
- [X] T031 [P] أنشئ `GamificationSettings.php` يقرأ `PlatformSettings` بارتدادٍ إلى `config/gamification.php` — على نمط `SettlementSettings` و`MediaLimits`
- [X] T032 [P] أنشئ `Enums/`: `LeaderboardScope` · `RewardType` · `RedemptionStatus` · `BadgeRuleType` — 🔴 **و`BadgeRuleType` مجموعةٌ مغلقةٌ بمُقيِّمٍ لكلّ صنف**، لا محرّكَ قواعدَ عامّاً بلا مفردات
- [X] T033 [P] أنشئ `Support/LevelBand.php`: نطاقُ مستوىً عرضُه من `GamificationSettings` (‏ابتدائياً ٥) — ⚠️ **ولا تشريحَ بالرتبة وحدَها**: `floor(rank/50)` يجتاز `SC-009` حرفياً ويضع طالبَ المستوى الثاني بين مجتهدي المستوى الأربعين، وهو نقيضُ سبب وجود الشريحة
- [X] T034 [P] أنشئ `Support/GamificationFieldAllowlist.php` **بنصفَيها**: `fields()` **و** `forbidden()` — على نمط `StudentBalanceAllowlist`. الممنوعُ يسمّي على الأقلّ `user_uuid` و`email` و`phone` و`workspace_uuid` و`last_name`؛ ⚠️ **و`user_uuid` خصوصاً** هو مفتاحُ الربط الذي يحوّل اللوحةَ من مستعارةٍ إلى مُعرِّفٍ عابرِ الأسطح
- [X] T035 انتزع `App\Shared\Support\DisplayName::forStudent(User): string` إلى `backend/app/Shared/Support/DisplayName.php`، **وحوِّل `Review::studentDisplayName()` لتناديها** — ⚠️ الأصلُ دالّةُ **نسخةٍ** على نموذجٍ `BelongsToWorkspace` تقرأ `$this->student`، فاستعمالُها من التلعيب يعني بناءَ نموذج Marketplace (‏وصولٌ ممنوع) وعدمُ استعمالها يعني تكراراً — و`Q6` تَعِد بأنها لا تُنشئ أياً منهما. أبقِ سلوكَ تخطّي «ال» في اسم العائلة ونقلِ اختبارِه
- [X] T036 [P] أنشئ عقدَ `backend/app/Shared/Contracts/FocusState.php` (`isFocusing(User): bool`) بتنفيذٍ واحدٍ في `Gamification` يقرأ `focus_sessions.status = 'running'` — ⚠️ **ولا مفتاحَ ذاكرةٍ بجواره**: النسخةُ الثانيةُ تصنع خطرَ `close()` الذي حذّرت منه الوثائقُ ثلاثَ مرّات، والخطرُ موجودٌ **فقط لأن هناك نسختَين**
- [X] T037 [P] أنشئ عقدَ `backend/app/Shared/Contracts/FreezeDirectory.php` بتنفيذٍ في `LiveSessions` يجيب عن التجميد **بـ`withoutWorkspaceScope()`** — السلسلةُ منصّيةٌ و`FreezePeriod` مقيَّدٌ بمساحةِ عمل، فسؤالٌ مقيَّدٌ يُجيب عن مدرّسٍ واحدٍ عن حالةٍ تخصّ الطالبَ كلَّه
- [X] T038 أضِف دالّةَ «حاضرو هذه الحصّة» إلى `backend/app/Shared/Contracts/SessionAttendanceDirectory.php` وتنفيذِها — ⚠️ **مستثنيةً المضيفَ** بـ`Attendance::scopeExcludingHost()` القائم: بدونها يُمنح المدرّسُ خبرةَ حضورٍ في كلّ درسٍ يلقيه فيظهر في صدارة طلابه
- [ ] T039 [P] أنشئ `Data/` — DTOs ترث `App\Shared\Data\DataTransferObject`: `AwardRequest` · `RedeemRequest` · `LeaderboardQuery` · `FocusRequest` (`NFR-005`)
- [X] T040 [P] أنشئ المصانعَ في `backend/database/factories/Modules/Gamification/` — 🔴 **مركزيةٌ في هذا المستودع** لا داخل الوحدة (`AppServiceProvider::guessFactoryName()`)
- [X] T041 أنشئ `backend/database/seeders/GamificationCatalogSeeder.php` بالقيم الابتدائية من جدول الوثيقة (‏أفعالٌ · مستوياتٌ · شارات) — ⚠️ **بياناتٌ مرجعيةٌ لا تجهيزات**، على نمط `NotificationTemplateSeeder`
- [X] T042 ابذر `GamificationCatalogSeeder` في `backend/tests/Pest.php` قبل كلّ اختبارِ ميزة — ⚠️ **وبدونه يمرّ كلُّ تأكيدٍ على المنح فارغاً ضدّ صفر**، وهو أخطرُ أشكال الاختبار الأخضر
- [X] T043 [P] أنشئ `Policies/` (`RewardPolicy` · `RedemptionPolicy` · `StudentProgressPolicy`) وسجّلها في `GamificationServiceProvider`
- [X] T044 أنشئ `backend/app/Modules/Gamification/routes/api.php` فارغاً مع مجموعتَي المحدِّدَين — يُملأ في القصص
- [X] T045 اكتب `backend/tests/Feature/Gamification/CatalogPermissionTest.php`: حاملُ **أعلى دورِ مستأجر** يُردّ بـ**403** عن تعديل قيمة فعلٍ أو تحرير التصنيف — والبذرُ نفسُه يمرّ خضراء (‏حارسُ `T006`)

**Checkpoint**: المخطّطُ والعقودُ والتقويمُ جاهزة، البوّاباتُ الأربع خضراء، **وصفرُ نقطةٍ مُنحت بعد**.

---

## Phase 3: User Story 1 — النقاط تُمنح بسقفٍ يوميّ (Priority: P1) 🎯 MVP

**Goal**: الآلةُ نفسُها — فعلٌ يقع فيُقيَّد في سجلٍّ مضافٍ ويرتفع رصيدُ الطالب، بلا استغلالٍ وبلا تكرار.

**Independent Test**: أطلِق أفعالاً معروفةَ القيمة وتحقّق من الرصيد؛ كرّر فعلاً ذا سقفٍ **ضِعفَ** سقفِه.

### التنفيذ

- [X] T046 [US1] نفّذ `Actions/AwardPoints.php` — **المدخلُ الوحيدُ لكلّ منح**. الترتيبُ داخلَه غيرُ قابلٍ للتبديل: (١) اقرأ الفعلَ وارفض المعطَّل · (٢) **طالِب بالسقف اليوميّ** (`T047`) · (٣) احسب `level_band` لحظتَه · (٤) **اكتب القيدَ أوّلاً** بـ`insertOrIgnore` (`T048`) · (٥) ثمّ حرّك التجميع بجملٍ شرطيّةٍ ذرّية. ⚠️ **العكسُ يخصم مرّتين عند إعادة إرسال حدث** بينما القيدُ المكرَّرُ يُتجاهَل مرّةً — فينفكّ الرصيدُ عن مجموع قيوده **دائماً** وبلا شيءٍ يلاحظ (`FR-005` · §R9)
- [X] T047 [US1] نفّذ مطالبةَ السقف اليوميّ في `AwardPoints`: `insertOrIgnore` لصفّ `award_daily_counters` ثمّ `UPDATE … SET count = count + 1 WHERE count < :cap` — **وصفرُ صفوفٍ هو «بلغ السقف»** فيُتخطّى المنحُ **ولا يفشل الفعلُ نفسُه** (`FR-006`). ⚠️ **قراءةٌ ثمّ كتابةٌ هي العيب**: حدثان في الثانية نفسِها يقرآن ٩ من ١٠ فيكتبان ١١
- [X] T048 [US1] نفّذ الإدماجَ في `AwardPoints`: `insertOrIgnore` **بـ`uuid` و`created_at`/`updated_at` و`workspace_id` مُمرَّرةً صراحةً** (‏النموذجُ لا يُقلَع فلا `HasUuid` ولا الطوابعُ ولا التعبئةُ التلقائية) ثمّ **قراءةٌ بالمفتاح، والغيابُ يرمي** — ⚠️ صفرُ صفوفٍ يعني *إمّا* مكرَّراً *وإمّا* فشلاً مبتلَعاً، ونسيانُ الـuuid يخزّن `''` على MySQL **بتحذيرٍ لا خطأ** فتُقرأ كلُّ صفوف الجدول التاليةِ على أنها مكرَّرة (§R3 · نمط `CreditLedger::writeEntry()`)
- [X] T049 [US1] نفّذ حدَّ الخصم في `AwardPoints`: القيدُ يُكتب **بما أمكن خصمُه فعلاً** لا بالقيمة الاسمية — ⚠️ طالبٌ بـ٢٠ خبرةً وفعلٌ بـ‎−٥٠‎: كتابةُ ‎−٥٠‎ وحدُّ التجميع عند صفرٍ يجعل المجموعَ ‎−٣٠‎ والتجميعَ ٠ **دائماً**، فيتناقض `FR-005` مع `FR-009` وينذر تقريرُ المطابقة كلَّ ليلةٍ عن انحرافٍ **يفرضه التصميم** (§R10)
- [X] T050 [US1] نفّذ توجيهَ العملات في `AwardPoints`: `workspace_id` **يُمرَّر من القيد لا من `WorkspaceContext`** — ⚠️ التعبئةُ التلقائيةُ تقرأ سياقاً هو `null` للطالب ولكلّ وظيفةٍ مطبورة، وطالبٌ صار سياقُه غيرَ فارغٍ يوماً (‏عضويةٌ سابقة، `last_workspace_id` مبذور) توجَّه عملاتُه إلى مساحةٍ يختارها وينفقها هناك و`FR-028ج` مكسورٌ بلا خطأ. **وفعلٌ ذو عملاتٍ بلا مساحةِ عملٍ يُرفَض عند تعريفه** (`T059`)، لأن لا وجهةَ صحيحةً له
- [X] T051 [US1] نفّذ `Actions/ReverseAward.php`: قيدٌ سالبٌ يشير إلى الأصل بـ`reversal_of_id`، **بلا حذفٍ ولا تعديل** — والقيمةُ المعكوسةُ هي المُجمَّدةُ في الأصل، محدودةً بما يمكن خصمُه (`FR-010` · §R10)
- [X] T052 [US1] [P] أنشئ `Listeners/AwardOnAttendanceConfirmed.php` — يترجم `AttendanceConfirmed` إلى اسم فعلٍ **ويستدعي `SessionAttendanceDirectory` للحاضرين** (‏الحدثُ يحمل الحصّةَ لا الحاضرين)، **مستثنياً المضيف** (`T038`)
- [X] T053 [US1] [P] أنشئ ثلاثةَ مستمعين: `AwardOnAttemptFinalized` · `AwardOnMistakeResolved` · `AwardOnSubmissionGraded`، و`ReverseOnAttendanceOverridden` — ⚠️ **كلُّها تترجم ولا تحسب**: قيمةٌ محسوبةٌ في مستمعٍ هي القيمةُ التي لا يجدها من يبحث عنها في اللوحة (`NFR-002`). 🔴 **و`SessionCancelled` و`AccessWithheld` لا يُوصَلان** — الأولُ **لا يمكن أن يقع بعد منح** (‏الحضورُ يُؤكَّد عند الإتمام والإلغاءُ يرمي على حالةٍ نهائية)، والثاني يشتعل عند **فتح نافذةِ امتحان** أيضاً وهو **لكلّ كورس** فيخسر الطالبُ خبرةً ثلاثَ مرّاتٍ لأن مدرّسَه فتح نافذة (§R2)
- [X] T054 [US1] نفّذ جملَ التجميع الشرطيّة على `student_progress` و`coin_balances` بـ`Support/ProgressWriter.php`: `SET coins = coins - ? WHERE coins >= ?` · وخصمُ الخبرة **فرعان** — ⚠️ **`GREATEST()` غيرُ موجودةٍ في SQLite، و`MAX(x,0)` سُلَّميّةٌ فيها و*تجميعيّةٌ فقط* في MySQL**، فالإصلاحُ المحلّيُّ البديهيُّ يشحن جملةً تفشل في الإنتاج وحدَه (`CreditLedger.php:406-408` كُتب فرعَين لهذا بالذات). **و`lockForUpdate()` ممنوع**
- [X] T055 [US1] سجّل المستمعين بـ`Event::listen()` في `GamificationServiceProvider::boot()` — **مطبورون** (‏`ShouldQueue`) على `default`، فلا يُبطئ المنحُ العمليةَ التي أطلقته (`SC-016` · §R14)، و**لا `WorkspaceContext::set()`** في أيٍّ منها بل `forWorkspace()`
- [X] T056 [US1] أنشئ `Http/Controllers/ProgressController@me` و`Resources/ProgressResource` لـ`GET /gamification/me` بالحمولة في [contracts/api.md](./contracts/api.md) — **ولا حقلَ `coins_total`**: لا مجموعَ صحيح، ومجموعٌ معروضٌ يَعِد بما يرفضه المتجرُ عند أوّل محاولة
- [X] T057 [US1] أزِل ثلاثةَ استعلاماتٍ لكلّ صفٍّ من `ProgressResource`: حمِّل فهرسَ `badges` **مرّةً** بـ`keyBy('key')` (‏`badge_key` نصٌّ لا FK فلا ينفع `with()`)، واسمَ المدرّس بـ`with('workspace.owner:id,first_name,last_name')`، والمستوى مرّةً — ⚠️ **Resource يعمل مرّةً لكلّ صفّ، فاستعلامٌ داخله N+1 بالبناء**
- [X] T058 [US1] أضِف `GET /gamification/students/{user}` خلف `PROGRESS_VIEW_STUDENT` **و**`EnrollmentDirectory::hasActiveEnrollmentInWorkspace()` — ⚠️ **صلاحيةٌ بلا نقطةِ فرضٍ اسمٌ سيربطه أحدُهم بمسارٍ لاحقاً بلا فحص التسجيل** (`NFR-001أ`)
- [X] T059 [US1] أنشئ `Filament/Resources/GamificationActionResource` خلف `GAMIFICATION_CATALOG_MANAGE` — **يمرّ بالـAction ولا يكتب مباشرةً**، والـAction تسجّل في `activity_log` **بنداءٍ صريح** (‏`spatie/activitylog` لا يغطّي شيئاً تلقائياً في هذا المستودع)، وترفض فعلاً ذا عملاتٍ بلا مساحةِ عمل (`T050`)
- [ ] T060 [US1] [P] أنشئ `frontend/src/app/(app)/progress/page.tsx` و`frontend/src/lib/gamification.ts` — مكوّناتٌ من `components/ui/` بلا `className` حرّ، وألوانٌ من `@theme`، وخصائصُ منطقيّة (`ms-*` · `start-*`)

### الاختبارات

- [X] T061 [US1] [P] `backend/tests/Feature/Gamification/AwardCapTest.php`: فعلٌ ذو سقفٍ يُكرَّر **ضِعفَ** سقفِه ⇒ المنحُ يقف عند السقف **والفعلُ نفسُه ينجح** (`SC-002`)
- [X] T062 [US1] [P] `AwardIdempotencyTest.php`: الحدثُ نفسُه عشرَ مرّات ⇒ **قيدٌ واحد** (`SC-003`) — ⚠️ **وصفرُ قيودٍ فشلٌ أيضاً**: يعني أن `insertOrIgnore` ابتلع خطأً حقيقياً، وقراءةُ `T048` هي ما يمنعه
- [X] T063 [US1] [P] `LedgerEqualityTest.php`: ‎١٠٬٠٠٠‎ منحٍ متسلسل ⇒ التجميعُ يساوي مجموعَ القيود في ١٠٠٪ (`SC-001`) — وحالةُ خصمٍ يتجاوز الرصيد تُثبت `T049`
- [X] T064 [US1] [P] `AwardReversalTest.php`: امنح حضوراً ثمّ بدّله إلى غياب ⇒ **التجميعُ يعود إلى قيمته قبل المنح** (`SC-024`) — ⚠️ **لا تعدّ الصفوف**: تأكيدٌ يعدّ صفَّين يمرّ على تصميمٍ يبتلع القيدَ العكسيَّ في مفتاح الإدماج ولا يردّ نقطةً واحدة
- [X] T065 [US1] `AwardConcurrencyTest.php`: فعلان متزامنان على نفس السقف ⇒ لا تجاوزَ ولا ضياع — ⚠️ **وإطلاقان متتاليان يثبتان الفهرسَ لا السباق**؛ اجعل الفحصَ يقع **بين** القراءة والمطالبة (‏نمط `IngestSessionRecordingJob` في ٠١٧: نداءٌ في تلك النافذة بالضبط، بلا خيوطٍ ولا انتظار)
- [X] T066 [US1] [P] `ImmutableAwardTest.php`: `update()` و`delete()` على `AwardEntry` يرميان · وتعديلُ قيمةِ فعلٍ **لا يعيد حساب منحٍ سابق** (`SC-005`)
- [X] T067 [US1] [P] أضِف حالاتِ `award_entries` و`coin_balances` إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` (`SC-015`)
- [X] T068 [US1] `AwardListenerWiringTest.php`: الحدثُ الحقيقيُّ يُطلَق فيقع المنح — ⚠️ **`Queue::fake()` بلا وسائط يبتلع المستمعَ** فيصير «الحدثُ وقع إذن المنحُ موجود» تأكيداً واثقاً عن جدولٍ فارغ. زيّف وظائفَ التوقيت وحدَها (`Queue::fake([...])`) ودعِ المنحَ يجري على `sync`؛ **واستدعاءُ الـAction مباشرةً يختبر الحسابَ ويثبت صفراً عن التوصيل**
- [X] T069 [US1] [P] `PlatformOwnershipTest.php` (‏الجزء الأوّل): طالبٌ عند **ثلاثة** مدرّسين له **ملفُّ تقدّمٍ واحد** وثلاثةُ أرصدةِ عملات (`SC-017`) · ومدرّسٌ **لا** يرى ملفَّ طالبٍ غيرِ مسجَّلٍ عنده (`NFR-001ب`) — بالاتجاهَين

**Checkpoint**: `US1` قابلةٌ للتسليم وحدَها — طالبٌ يرى رصيدَه ينمو بفعله، بأرقامٍ لا تُستغَلّ.

---

## Phase 4: User Story 2 — المستويات والسلاسل والشارات (Priority: P2)

**Goal**: مستوىً يرتفع، وسلسلةٌ تنمو وتنكسر وتُحمى بدرع، وشاراتٌ تُمنح مرّةً واحدة.

**Independent Test**: تجاوز عتبةَ مستوى · انشط يومَين متتاليَين ثمّ انقطع · استوفِ شرطَ شارة.

- [X] T070 [US2] نفّذ `Actions/RecalculateStreak.php` — **كلُّ كتابةٍ شرطيّةٌ ورتيبةٌ في اتجاهٍ واحد**: `SET current_streak = current_streak + 1, last_active_day = :d WHERE last_active_day <> :d` (‏صفرُ صفوفٍ = «حُسِب اليومَ سلفاً»، فمنحان في يومٍ لا يزيدانها مرّتين) · `SET best_streak = :n WHERE best_streak < :n` (§R9)
- [X] T071 [US2] نفّذ استهلاكَ الدرع في `RecalculateStreak`: `SET shield_count = shield_count - 1 WHERE shield_count > 0` **ويُختَم `streak_evaluated_day` في نفس الجملة** — ⚠️ **سابقةُ `notified_dormant_at`**: تقييمٌ يجري مرّتين يحرق درعَين لانقطاعٍ واحد، و`CLAUDE.md` يسجّل ٧٢ تمريرةً متراكمةً بعد إعادة تشغيل عامل (`FR-016`)
- [X] T072 [US2] استشِر `FreezeDirectory` (`T037`) في `RecalculateStreak`: أيامُ التجميد **لا تكسر السلسلة ولا تُحتسب نشاطاً** (`FR-015`)
- [X] T073 [US2] نفّذ رفعَ المستوى في `AwardPoints`: `SET level = :n WHERE level < :n` و`notified_level` مثلُه — ⚠️ **بدونه يهبط المستوى بين طلبَين فيشتعل `LevelReachedUp` مرّتين** وتصل تهنئةٌ مكرَّرة
- [X] T074 [US2] نفّذ `Actions/EvaluateBadges.php` و`Jobs/EvaluateBadgesJob.php` على `default` — يُستدعى من الوظيفة لا من المسار (`FR-018` · `SC-016`)، والكتابةُ `insertOrIgnore` على `badge_awards` **بـuuid وطوابعَ صريحة** والفهرسُ الفريدُ هو حارسُ «مرّةً واحدة»
- [X] T075 [US2] [P] نفّذ مُقيِّمَ كلّ صنفٍ من `BadgeRuleType` — يقرأ `index(student_user_id, action_key, created_at)` القائم لأسئلةِ «حلّ ١٠٠ سؤالاً إجمالاً»
- [X] T076 [US2] [P] أضِف `level_up` و`badge_awarded` إلى `NotificationType` وقوالبَهما في `NotificationTemplateSeeder` — **بقناة الجرس وحدَها**: تهنئةٌ يوميةٌ على هاتف الأب تُطفَأ بعد أسبوعٍ ومعها ما يهمّه
- [X] T077 [US2] أطلِق `LevelReachedUp` و`BadgeAwarded` **بعد الإيداع** (`afterCommit`) واستمع لهما في `Notifications` — ⚠️ حدثٌ داخل المعاملة يعلن صفّاً قد يُلغى، وعلى Horizon قد تبدأ الوظيفةُ **قبل** الإيداع فلا تقرأ شيئاً
- [X] T078 [US2] [P] أنشئ `Filament/Resources/LevelResource` و`BadgeResource` خلف `GAMIFICATION_CATALOG_MANAGE` (`FR-012` · `FR-017`)
- [X] T079 [US2] [P] `StreakTest.php`: النموُّ بالمتتالي · التصفيرُ بالانقطاع · **لا كسرَ بالتجميد** — الحالاتُ الثلاث (`SC-006`)
- [X] T080 [US2] [P] `ShieldTest.php`: انقطاعُ يومٍ واحدٍ بدرعٍ ⇒ السلسلةُ محفوظةٌ والدرعُ مستهلَك · **وتقييمٌ يجري مرّتين يستهلك درعاً واحداً** (‏حارسُ `T071`)
- [X] T081 [US2] [P] `BadgeTest.php`: صفرُ شارةٍ ممنوحةٍ مرّتين · وشارةٌ **لا تُسحَب** بعد تغيّر قاعدتها (`SC-007`)
- [X] T082 [US2] [P] `LevelUpTest.php`: العتبةُ تُرفع المستوى وتُبلِّغ **مرّةً واحدة** ولو أُعيد تشغيل التقييم
- [ ] T083 [US2] [P] أضِف بطاقاتِ المستوى والسلسلة والشارات إلى `frontend/src/app/(app)/progress/page.tsx` (`FR-041`)

**Checkpoint**: `US1` + `US2` تعملان مستقلّتَين — والعودةُ اليومية لها محرّك.

---

## Phase 5: User Story 3 — لوحاتُ الصدارة الأسبوعية (Priority: P3)

**Goal**: ستّةُ نطاقاتٍ، شريحةٌ بمستوىً متقارب، تصفيرٌ أسبوعيٌّ فجرَ الأحد بتوقيت الدوحة، وجدولٌ **مُشتقٌّ** يُعاد بناؤه بأمر.

**Independent Test**: امنح نقاطاً لطلابٍ متعدّدين، اقرأ الترتيبَ في كلّ نطاق، اعبر حدَّ الأسبوع.

- [X] T084 [US3] نفّذ `Jobs/RollUpLeaderboardsJob.php` على `maintenance` — يجمّع من `award_entries` ويكتب `leaderboard_entries` **بـ`upsert` ثمّ حذفِ ما لم يُلمَس** بختمِ تشغيلة. ⚠️ **حذفٌ ثمّ إدراجٌ يجعل كلَّ لوحةٍ فارغةً طولَ زمن البناء** — وهو بالضبط ما رُفض Redis بسببه، بالساعة بدل `maxmemory`، **و`SC-011` يمرّ لأنه يقارن الحالةَ النهائيةَ وحدَها**
- [X] T085 [US3] جمّد الرتبةَ وقتَ الكتابة بـ`ROW_NUMBER() OVER (PARTITION BY scope_key, period_key, level_band ORDER BY points DESC, user_id)` — ⚠️ **`COUNT(*) WHERE points > ?` كلفتُه هي الرتبةُ نفسُها** فالطالبُ ٦٠٬٠٠٠ يمشي ٦٠٬٠٠٠ مدخلاً بينما `SC-008` يقيس p95 أي النصفَ العميق. **والفاصلُ `, user_id` يصلح عيباً ثانياً**: العدُّ يعطي المتعادلين رتبةً واحدةً بينما القائمةُ ترتّبهم عشوائياً، فيقرأ الطالبُ `my_rank: 7` ويجد اسمَه في الموضع التاسع **من نفس الحمولة**
- [X] T086 [US3] اقرأ `level_band` **من القيد** لا من المستوى الحاليّ عند التجميع — ⚠️ بدونه تُعيد الوظيفةُ التصنيفَ بالمستوى الحاليّ فتُنتج ترتيباً آخر، و**`SC-011` مستحيل** (`FR-025` · §R5)
- [X] T087 [US3] [P] نفّذ `Jobs/CloseLeaderboardWeekJob.php` (‏فجرَ الأحد) و`Jobs/PruneOldLeaderboardsJob.php` (`FR-026`، على نمط `PruneOldNotificationsJob`) و`Jobs/ReconcileGamificationJob.php` — 🔴 **والمصالحةُ محدودةٌ بالحركة**: `WHERE created_at >= :last_run` و`chunkById`، لا `GROUP BY` على أسرعِ جداولِ المنتجِ نموّاً كلَّ ليلة. ⚠️ **والمقارنةُ وحدَها تثبت أقلَّ ممّا تبدو** — الطرفان يكتبهما نفسُ المسار، فمنحٌ لم يقع أصلاً يتركهما متّفقَين تماماً؛ التحقّقُ الثاني عدَدُ الأحداثِ المُترجَمةِ مقابلَ عدَدِ القيود
- [X] T088 [US3] جدوِل الوظائفَ في `backend/routes/console.php` — **والثلاثُ التي يقع حدُّها على حافّةِ تقويمٍ قطريّ تحمل `->timezone('Asia/Qatar')` صراحةً** (‏التجميعُ والإغلاقُ والكنسة): المُجدوِلُ يعمل بـ`config/app.timezone` أي UTC، فـ«فجرُ الأحد» بدونها **ثلاثُ ساعاتٍ بعد بدء أسبوع الطالب**. `ReconcileGamificationJob` محدودةٌ بـ`last_run` فساعتُها لا تعني شيئاً
- [X] T089 [US3] نفّذ `Actions/RebuildLeaderboards.php` — يعيد البناءَ من `award_entries` وحدَها (`SC-011`)
- [X] T090 [US3] نفّذ `Support/LeaderboardScope.php`: حلُّ `lesson:{uuid}` · `course:{uuid}` · `teacher:{uuid}` · `subject:{uuid}` · `grade:{slug}` · `platform` — 🔴 **بالـuuid والـslug لا بمعرّفاتٍ تسلسلية**: التسلسليُّ ضدّ قاعدة `HasUuid` **وهو أيضاً ما يجعل مسحَ الفضاء كلِّه ممكناً في ثوانٍ**. والحمولةُ تُعيد `scope` بنفس الصيغة الواردة لا بالمخزَّنة
- [X] T091 [US3] نفّذ `LeaderboardController` و`LeaderboardResource`: التشريحُ **بنطاقِ المستوى أوّلاً** ثمّ نافذةُ رتبةٍ داخله، و`entries` **لا تتجاوز خمسين**، و`display_name` من `DisplayName::forStudent()` في النطاقات العابرة (`T035`)
- [X] T092 [US3] نفّذ الحرّاسَ الثلاثةَ على المسار: مدرّسٌ يطلب نطاقاً عابراً ⇒ **403** (`FR-020د`) · طالبٌ يطلب نطاقاً مقيَّداً لا ينتمي إليه ⇒ **403** · 🔴 **ومُعرِّفٌ غيرُ موجودٍ يُجيب بالجواب نفسِه**، وإلا صار الفرقُ بين ٤٠٣ و٤٠٤ عرّافاً يخبر بما هو موجود
- [X] T093 [US3] طبّق `throttle:gamification-board` على مسار الصدارة — ⚠️ **وهو المسارُ الوحيدُ القابلُ للإحصاء وكان بلا محدِّدٍ إطلاقاً** لأن `NFR-014` يذكر الكتابةَ وحدَها
- [X] T094 [US3] نفّذ `FR-042`: دالّةٌ **جماعيّةٌ** تأخذ قائمةَ مستخدمين وتُرجع رتبةَ كلٍّ منهم ومستواه في نطاقٍ وفترة — 🔴 **كانت بلا أثرٍ في أيّ ملفّ**؛ لا استعلامٌ لكلّ صفّ، وحقولُها داخل قائمة السماح (‏تستهلكها ٠١٠)
- [ ] T095 [US3] [P] أنشئ `frontend/src/app/(app)/leaderboard/page.tsx` — **واللوحةُ الفارغةُ تقول شيئاً مفهوماً** لا جدولاً بلا صفوف: أوّلُ أسبوعٍ لكلّ طالبٍ جديدٍ يبدأ فارغاً
- [X] T096 [US3] [P] `LeaderboardScopeTest.php`: **الستّةُ كلُّها** تُنتج ترتيباً صحيحاً من نفس القيود (`SC-018`) — ⚠️ **وبمساحتَي عملٍ على الأقلّ** في كلّ اختبارِ نطاقٍ منصّيّ؛ بواحدةٍ لا يثبت شيئاً (`SC-025`)
- [X] T097 [US3] [P] `LeaderboardBandTest.php`: طالبُ المستوى ٢ بنقاطٍ عاليةٍ **لا يظهر** في لوحةٍ مع عشرين طالباً بالمستوى ٤٠ (`SC-009`) — ⚠️ تشريحٌ بالرتبة وحدَها يجتاز «خمسون كحدٍّ أقصى» حرفياً ويرسب هنا
- [X] T098 [US3] [P] `LeaderboardRebuildTest.php`: احذف كلَّ الصفوف المُشتقّة وأعِد البناء ⇒ **الترتيبُ مطابق** (`SC-011`) · **وشغّل التجميعَ مرّتين ⇒ نفسُ الأرقام** (`SC-026`) — ⚠️ تشغيلةٌ واحدةٌ خضراءُ إلى الأبد وتثبت العكس (‏سابقةُ `RollupIdempotencyTest`)
- [X] T099 [US3] [P] `LeaderboardExposureTest.php`: صفرُ حقلٍ خارج `GamificationFieldAllowlist` (‏نصفَيها) · وصفرُ اسمٍ كاملٍ في نطاقٍ عابر (`SC-021`) — ⚠️ **بتجهيزةٍ اسمُ عائلتها متعدّدُ الأحرف ومميَّز** (‏على اسمٍ قصيرٍ يتطابق المختصرُ والكامل فيمرّ التأكيدُ فارغاً على تنفيذٍ يسرّب كلَّ شيء)، **وبالحمولة مُعادَ ترميزِها `JSON_UNESCAPED_UNICODE`**: `getContent()` يهرّب غيرَ الـASCII فتمرّ كلُّ دعوى تسريبٍ بحجّةٍ عربيةٍ **فارغة**
- [X] T100 [US3] [P] `TeacherScopeRefusalTest.php`: مدرّسٌ يطلب `platform` و`subject:` و`grade:` ⇒ **403** في الثلاثة (`SC-023`)
- [ ] T101 [US3] `LeaderboardPerformanceTest.php`: ١٠٠٬٠٠٠ مدخلٍ ⇒ رتبةُ طالبٍ أو أفضلُ عشرين خلال **١٠٠ms (p95)** (`SC-008`) — والقياسُ على الفهرس المركَّب، بلا فرزٍ ولا جمعٍ وقتَ الطلب

**Checkpoint**: الوجهُ الاجتماعيُّ يعمل، والجدولُ مُشتقٌّ يُعاد بناؤه بأمرٍ واحد.

---

## Phase 6: User Story 4 — متجرُ المكافآت (Priority: P4)

**Goal**: مكافآتٌ حقيقيةٌ يملكها المدرّس، تُستبدَل بعملاتٍ من سياقها، بمخزونٍ وسقفٍ شهريٍّ لا يصيران سالبَين.

**Independent Test**: استبدل برصيدٍ كافٍ وبغير كافٍ · تجاوز السقفَ الشهريّ · طلبان متزامنان على آخر وحدة.

- [X] T102 [US4] [P] نفّذ `Actions/SaveReward.php` و`ToggleReward.php` خلف `REWARDS_MANAGE` — ⚠️ **و`monthly_cap` إلزاميٌّ للنوع النقديّ مفروضاً في الـAction لا في `FormRequest` وحدَه** (`FR-031`)، **وسقفُه الأعلى رقمٌ منصّيّ**: سقفٌ يضعه المدرّسُ لنفسه ليس ضابطاً
- [X] T103 [US4] نفّذ `Actions/RedeemReward.php` — **الحجزُ جملةٌ شرطيّةٌ واحدة** تحمل `stock > 0` و`is_active` و`(month_key <> :k OR monthly_cap IS NULL OR month_redeemed < monthly_cap)`. ⚠️ **وبلا `monthly_cap IS NULL` يُرفَض كلُّ استبدالٍ بعد الأوّل في الشهر على مكافأةٍ بلا سقف** — ومنها «درعُ الحماية» نفسُه (§R7)
- [X] T104 [US4] حُلَّ `{reward}` **بالـuuid داخل الـAction بعد فحص التسجيل**، لا بربطٍ ضمنيّ — ⚠️ **`WorkspaceScope` عديمُ الأثر للطالب**: الطالبُ ليس عضواً في أيّ مساحةِ عملٍ أبداً (`AcceptInvitation` و`CreateWorkspace` وحدَهما يكتبان المحور)، فـ`WorkspaceContext::id()` تُرجع `null` و`WorkspaceScope::apply()` يخرج بلا شرط (`WorkspaceScope.php:26-30`) — فربطٌ ضمنيٌّ يحلّ **مكافأةَ أيّ مدرّس** (`FR-037`)
- [X] T105 [US4] نفّذ خصمَ العملات في `RedeemReward` بجملةٍ شرطيّةٍ على `coin_balances` بمعرّف مساحةِ العمل الصحيحة (`FR-028ج` · `FR-034`) — و`claimed_month_key` يُختَم على `redemptions`
- [X] T106 [US4] نفّذ نطقَ سببِ الرفض (`FR-033`) — 🔴 **مُشتقّاً باستعلامٍ تشخيصيٍّ بعد الرفض، لا بفحوصٍ قبل المطالبة**: الفحصُ المسبق يعيد الحارسَ قراءةً ثمّ كتابةً، وهو ما وُجدت المطالبةُ الشرطيةُ لتحلّ محلَّه
- [X] T107 [US4] نفّذ `Actions/FulfillRedemption.php` و`RejectRedemption.php` خلف `REDEMPTIONS_FULFILL` — **يبدآن بمطالبةٍ ذرّيةٍ `WHERE id = ? AND status = 'pending'`**. ⚠️ **بدونها نقرتان تردّان العملاتِ مرّتين: عملاتٌ من العدم**، وهي الجهةُ التي لا يحرسها `FR-034`
- [X] T108 [US4] نفّذ الإفراجَ في `RejectRedemption` **جملتَين**: المخزونُ بلا شرط، والعدّادُ بـ`claimed_month_key` و`month_redeemed > 0` — ⚠️ **بجملةٍ واحدةٍ مشروطةٍ بالشهر، رفضٌ بعد انقلاب الشهر يُفقد وحدةَ المخزونِ نهائياً** لمكافأةٍ لم يستلمها أحد (§R7)
- [X] T109 [US4] قيِّد «درعَ الحماية» عند الاستبدال بزيادة `shield_count` على `student_progress` (`FR-016` · الدرعُ **عمودٌ لا جدول** — مِثليٌّ، فعدَدٌ يجيب كلَّ سؤال)
- [X] T110 [US4] أضِف مسارات المتجر إلى `routes/api.php` خلف `throttle:gamification-write`: `GET /shop?workspace={uuid}` (‏🔴 **التسجيلُ النشطُ مفحوصٌ صراحةً** بـ`EnrollmentDirectory`) · `POST /rewards/{reward}/redeem` · `GET /redemptions` (‏🔴 **فرعُ الطالب يصفّي بـ`user_id` صراحةً** — بدونه يُرجع طلباتِ المنصّةِ كلَّها) · `POST …/fulfill` · `POST …/reject`
- [X] T111 [US4] أضِف نوعَ `reward_redeemed` إلى `NotificationType` **مستهدِفاً وليَّ الأمر** (‏قد يكون خصماً على حصّة) وقالبَه إلى `NotificationTemplateSeeder`، وأطلِق `RewardRedeemed` بـ`afterCommit`
- [X] T112 [US4] حدِّث عدَّ `backend/tests/Feature/Notifications/WhatsAppDefaultsTest.php` من **١٨ إلى ١٩** وتعليقَ `NotificationTemplateSeeder` — ⚠️ **وهو الاختبارُ الذي وُجد ليمنع انزلاقَ نوعٍ إلى المجموعة سهواً**، فتحديثُه جزءٌ من المهمّة لا التفافٌ عليه
- [ ] T113 [US4] [P] أنشئ `frontend/src/app/(app)/shop/page.tsx` و`frontend/src/app/(app)/manage/rewards/page.tsx` — **ولا رقمَ نقديٌّ في أيّ حمولة**: العملاتُ ليست مالاً وقاعدةُ ٠٠٦ سارية
- [ ] T114 [US4] [P] اكتب `frontend/src/components/gamification/RedeemButton.test.tsx` (‏vitest): نقرتان **لا تُرسلان مرّتين** — نفسُ عائلةِ العيبِ الذي حوّل إجابةً صحيحةً إلى صفرٍ في ٠٠٨
- [X] T115 [US4] [P] `RedemptionConcurrencyTest.php`: طلبان متزامنان على **آخر وحدةِ مخزون** ⇒ ينجح واحدٌ فقط، والمخزونُ صفرٌ لا سالب، والرصيدُ لا يقلّ عن صفر (`SC-004`) — ⚠️ **ولا `lockForUpdate()` في المسار**
- [X] T116 [US4] [P] `MonthlyCapTest.php`: مكافأةٌ **بلا سقف** تُستبدَل مرّتين في شهرٍ واحدٍ بنجاح (‏حارسُ `T103`) · ومكافأةٌ بسقف ٢ تُرفَض في الثالثة
- [X] T117 [US4] [P] `RedemptionReleaseTest.php`: استبدل مكافأةً سقفُها ٢ ثمّ **ارفض** الطلبَين ⇒ العملاتُ عادت كاملةً **والمكافأةُ ما زالت قابلةً للاستبدال** (`SC-013`) · **ورفضٌ بعد انقلاب الشهر يعيد وحدةَ المخزون** (‏حارسُ `T108`)
- [X] T118 [US4] [P] `RedemptionDoubleDecideTest.php`: نداءان لـ`reject` على نفس الطلب ⇒ العملاتُ تعود **مرّةً واحدة** (‏حارسُ `T107`)
- [ ] T119 [US4] [P] أضِف حالاتِ `rewards` و`redemptions` إلى `WorkspaceIsolationTest.php`، وحالةَ «طالبٌ يفتح متجرَ مدرّسٍ لا يدرس عنده ⇒ 403» (`SC-019`)

**Checkpoint**: النقاطُ صار لها وجهُ إنفاق — **والقصصُ الأربع الأولى تُسلَّم معاً أو لا تُسلَّم** (‏تحذيرُ الوثيقة).

---

## Phase 7: User Story 5 — مؤقّتُ التركيز (Priority: P5)

**Goal**: جلسةُ مذاكرةٍ داخل المنصّة تُكتم فيها الإشعاراتُ غيرُ الإلزامية وتُمنح خبرةً بسقفٍ يوميّ.

**Independent Test**: ابدأ جلسةً وأنهِها وتحقّق من الخبرة وسقفِها؛ وأطلِق تنبيهاً أمنياً داخلها.

- [ ] T120 [US5] نفّذ `Actions/StartFocusSession.php` و`EndFocusSession.php` — 🔴 **والاكتمالُ يقرّره الخادم** من `now() - started_at`، لا العميل: وإلا فجلسةُ «١٢٠ دقيقة» في خمس ثوانٍ (`FR-040`)
- [ ] T121 [US5] حُدَّ `minutes` **في `FormRequest` وفي الـAction** بحدٍّ من `GamificationSettings` — ⚠️ **بلا حدٍّ كان `100000` يكتم كلَّ إشعارٍ اختياريٍّ إلى الأبد**
- [ ] T122 [US5] نفّذ `FocusState` (`T036`) وأضِف **شرطاً جديداً في `DispatchNotification`** يتخطّى غيرَ الإلزاميّ لطالبٍ في جلسةٍ جارية — 🔴 **لا في `QuietHours`**: تلك تخرج فوراً لغير القنوات الخارجية، **والجرسُ هو السطحُ الوحيدُ الذي يراه طالبٌ يذاكر**، فالوصلُ بها يشحن ميزةً لا تفعل شيئاً. و`isMandatory()` تبقى الصمّامَ (`FR-039`)
- [ ] T123 [US5] امنح خبرةَ الجلسة المكتملة عبر `AwardPoints` بفعلٍ ذي سقفٍ يوميّ — والمقطوعةُ **لا تمنح كاملاً** (`FR-040`)
- [ ] T124 [US5] أضِف `POST /gamification/focus` و`POST /gamification/focus/{session}/end` خلف `throttle:gamification-write`
- [ ] T125 [US5] [P] أنشئ `frontend/src/components/gamification/FocusTimer.tsx` وادمجه في صفحة التقدّم
- [ ] T126 [US5] [P] اكتب `FocusTimer.test.tsx` (‏vitest): بدءٌ · قطعٌ · اكتمال — **منطقُ حالةٍ في المتصفّح لا يُرى من الخلفية**
- [ ] T127 [US5] [P] `FocusMuteTest.php`: تنبيهٌ أمنيٌّ داخل جلسةٍ جارية ⇒ **يصل** (`SC-022`) · وإشعارٌ اختياريٌّ ⇒ يُتخطّى — ⚠️ **والاختبارُ يمرّ بالجرس لا بقناةٍ خارجية**، وإلا اختبر `QuietHours` وأثبت صفراً عن `FR-039`
- [ ] T128 [US5] [P] `FocusDurationTest.php`: إنهاءٌ بعد خمس ثوانٍ على جلسةِ ١٢٠ دقيقة ⇒ **لا خبرةً كاملة** (‏حارسُ `T120`) · و`minutes` فوق الحدّ ⇒ ٤٢٢

**Checkpoint**: القصصُ الخمسُ كاملة.

---

## Phase 8: Polish & Cross-Cutting

- [ ] T129 [P] `QueryBudgetTest.php` لـ`GET /gamification/me` و`GET /gamification/leaderboard` — بميزانيةٍ مكتوبةٍ ضدّ **ضِعف** حجم التجهيزة (‏نمط ٠٠٥): ميزانيةٌ ضدّ حجمٍ واحدٍ تمرّ على N+1
- [ ] T130 [P] `AwardLatencyTest.php`: زمنُ العملية التي أطلقت المنحَ لا يزيد بقدرٍ يُقاس مع التلعيب مفعَّلاً (`SC-016`)
- [ ] T131 [P] أضِف جدولَ الوحدة وصلاحياتِها ومساراتِها إلى `docs/README.md`، وسطرَ التلعيب إلى `docs/roadmap.md`
- [ ] T132 [P] أضِف مسارات هذه المرحلة الحرجةَ إلى قائمة `AGENTS.md` — عزلُ مساحات العمل · ملكيةُ المنصّة · فرضُ الصلاحيات المنصّية
- [ ] T133 [P] أضِف إلى `CLAUDE.md` الأربعةَ التي يمرّ خطؤها أخضر: `reversal_of_id` في مفتاح الإدماج · `WorkspaceScope` عديمُ الأثر للطالب · التقويمُ في موضعٍ واحدٍ بلا `whereDate`/`CONVERT_TZ` · بذرُ الفهرس في `tests/Pest.php`
- [ ] T134 نفّذ العشرةَ تحقّقاتٍ في [quickstart.md](./quickstart.md) يدوياً على الخادم المحلّيّ
- [ ] T135 شغّل البوّاباتِ الأربع: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` (‏**بلا baseline جديد ولا `@phpstan-ignore`**) · `npx tsc --noEmit` — و`npm test` خامساً (`SC-020`)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (١)** — بلا تبعيّة. `T006` **حاجبٌ للبذر كلِّه**.
- **Foundational (٢)** — يعتمد على ١، و**يحجب كلَّ القصص**. `T021 → T022` ترتيبٌ إلزاميّ (‏التوحيدُ قبل الفهرس)، و`T042` يحجب كلَّ تأكيدِ منحٍ في المجموعة.
- **US1 (٣)** — يعتمد على ٢. **المِفصلُ**: `T046`–`T050` هي الآلة، وكلُّ ما بعدها يقرأ سجلَّها.
- **US2 (٤)** — يعتمد على `T046` (‏التجميعُ يُحرَّك من `AwardPoints`).
- **US3 (٥)** — يعتمد على `T012` (‏`level_band` في القيد) و`T029` (‏مفتاحُ الأسبوع).
- **US4 (٦)** — يعتمد على `T015` و`T050` (‏العملاتُ موجَّهةٌ بالسياق). `T109` يلمس `student_progress` من `US2`.
- **US5 (٧)** — يعتمد على `T046` وحدَه للخبرة، و`T036` للكتم. **الأكثرُ استقلالاً**.
- **Polish (٨)** — بعد ما يُراد تسليمه.

### Parallel Opportunities

- `T003` · `T004` · `T007` · `T008` معاً بعد `T001`.
- هجرات `T011` و`T013`–`T020` كلُّها متوازية (‏ملفّاتٌ مختلفة) — و`T010` و`T012` قبلها لأن غيرَهما يشير إليهما.
- النماذجُ `T024`–`T027` معاً، والدعمُ `T031`–`T034` و`T036`–`T040` معاً.
- داخل كلّ قصّة: كلُّ الاختبارات المعلَّمة `[P]` معاً بعد تنفيذها.
- بفريق: `US3` و`US4` و`US5` بالتوازي بعد خضرة `US1`.

### Parallel Example: Foundational

```bash
Task: "هجرةُ award_daily_counters في Database/Migrations/"      # T013
Task: "هجرةُ student_progress بلا workspace_id"                  # T014
Task: "هجرةُ coin_balances بـunique(user_id, workspace_id)"      # T015
Task: "هجرةُ badge_awards بـunique(user_id, badge_key)"          # T016
```

---

## Implementation Strategy

### MVP (‏US1 وحدها)

١ ← ٢ ← ٣، ثمّ **قِف وتحقّق**: طالبٌ يرى رصيدَه ينمو، بسقفٍ لا يُتجاوَز وقيدٍ لا يتكرّر.

### التسليم التدريجيّ

⚠️ **لكنّ الوثيقة تحذّر صراحةً**: «النقاطُ بلا متجر تفقد قيمتها خلال أسبوعين»، و`spec.md` يقول
**القصصُ الأربع الأولى تُسلَّم معاً أو لا تُسلَّم**. فالتدرّجُ هنا تدرّجُ **تطويرٍ** لا تدرّجُ
إطلاق: `US1` → `US2` → `US3` → `US4` تُطلَق دفعةً واحدة، و`US5` وحدَها تصلح إطلاقاً لاحقاً.

---

## Notes

- `[P]` = ملفّاتٌ مختلفةٌ بلا تبعيّة · `[Story]` يربط المهمّة بقصّتها.
- **صفرُ `lockForUpdate()`** في أيّ مسارٍ من هذه المرحلة — يمرّ أخضرَ على SQLite ويحرس لا شيءَ على MySQL.
- **صفرُ `WorkspaceContext::set()`** في أيّ مستمعٍ أو وظيفة — `forWorkspace()` وحدَها.
- **صفرُ حدثٍ جديدٍ في أيّ وحدةٍ قائمة**، وصفرُ نداءٍ من `Gamification` إلى `Action` في وحدةٍ أخرى.
- أوقِف عند أيّ `Checkpoint` للتحقّق من القصّة وحدَها.
