---

description: "Task list — المجتمع والمساعدون والتقييم المتبادل (٠١٠)"
---

# Tasks: المجتمع والمساعدون والتقييم المتبادل (Community, Assistants & Mutual Rating)

**Input**: Design documents from `/specs/010-community-assistants/`

**Prerequisites**: [plan.md](./plan.md) · [spec.md](./spec.md) · [research.md](./research.md) ·
[data-model.md](./data-model.md) · [contracts/endpoints.md](./contracts/endpoints.md) ·
[quickstart.md](./quickstart.md)

**Tests**: **مطلوبةٌ صراحةً** — المواصفةُ تعلّق **١٩** معياراً على حرّاسٍ آليّة، **وتسعةٌ منها
لا تظهر إلّا باختبارٍ مصاغٍ بشكلٍ بعينه** (`SC-001` · `SC-005` · `SC-007` · `SC-009` · `SC-012` ·
`SC-015` · `SC-016` · `FR-019` · `FR-046`). الاختبارُ هنا **تسليمٌ**، لا توثيقُ تسليم.

**Organization**: بالقصص، لتكون كلُّ قصّةٍ زيادةً قابلةً للتسليمِ والاختبارِ وحدَها.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: يمكن التوازي (‏ملفّاتٌ مختلفة، بلا تبعيّة)
- **[Story]**: القصّةُ التي تخدمها المهمّة (`US1`…`US6`)

## Path Conventions

الخلفيّة `backend/` أحاديّةٌ معياريّة · الواجهة `frontend/src/`. الوحدةُ الجديدة
`backend/app/Modules/Community/`، **والمفرداتُ المشتركةُ في `backend/app/Shared/`**، والتقييمُ
يبقى في `backend/app/Modules/Marketplace/`.

---

## ⚠️ اقرأ هذا قبل `T001`

**ثمانيةُ أشياءَ في هذه المرحلة يمرُّ تنفيذُها الخاطئ أخضرَ.** كلٌّ مكتوبٌ في مهمّتِه، وتُجمَع
هنا لأنّ نسيانَ أيٍّ منها يُبطل معياراً كاملاً — وخمسةٌ منها أخطاءٌ **وقعت في الإصدارِ الأوّلِ
من هذا التصميمِ نفسِه** أو وُجدت **مشحونةً** أثناء مراجعتِه، فليست احتمالاتٍ نظريّة.

1. **تثبيتةُ الحائطِ الماليِّ تُحيّد كلَّ سببِ رفضٍ آخر** (`T027`). مساعدٌ خارجَ نطاقِه، أو بلا
   تسجيلٍ، أو في مساحةٍ ثانيةٍ، يُردُّ **لسببٍ ثانٍ** — فيمرُّ الاختبارُ ولو حُذف الحائطُ كلُّه.
   تسعُ حالاتٍ في ٠١٣ ح-٦ كانت خضراءَ لهذا السببِ بالضبط، ولم يكشفها إلّا **حذفُ الحارسِ وإعادةُ
   التشغيل**. افعل ذلك مرّةً قبل تسليمِ `US1`.
2. **الحائطُ لا يُقاس على الـAPI وحدَها** (`T028` · `T047`). `EnsureFilamentAccess` يُدخل
   `assistant-teacher` بالاسم، **وقائمةُ Filament لا تستدعي سياسةَ الصفِّ أبداً** — العطلُ الذي
   وُجد مشحوناً في `OrderResource` وأُصلح في `981ca23`. كلُّ موردٍ يعرض مالاً يحتاج
   `canViewAny()` **وقَطعاً على `getEloquentQuery()`**.
3. **`Queue::fake()` عارياً يبتلع ما يجب أن يعمل** (`T108` · `T166`). التفريعُ والتصييرُ
   مطبوران، فالفَركُ العاري يجعل «الإعلانُ وصل ١٠٠٪ من نطاقِه» جملةً واثقةً عن **جدولٍ فارغ**.
   تُفاك وظائفُ **مُسمّاةٌ** لا فراغ.
4. **تثبيتةُ الترتيبِ تكتب `created_at` متطابقاً** (`T083`). بغيرِه يمرُّ التوكيدُ على ترتيبٍ
   زمنيٍّ ولا يرى شيئاً — و`SC-007` كلُّه عن اللحظةِ التي يتساوى فيها الحقلان.
5. **اختبارُ ميزانيّةِ الاستعلاماتِ يحتاج إحماءً وحضورَ حقلٍ** (`T084`). ذاكرةُ صلاحيّاتِ spatie
   تُملأ بأوّلِ طلبٍ مُصادَقٍ عليه، فالقياسُ الأصغرُ يحمل كلفةً لا يحملها الأكبرُ ويختبئ N+1 في
   الفارق. **ويُؤكَّد حضورُ الاسمِ والرتبة**، وإلّا فحذفُ التحميلِ المسبقِ يُقرأ **تحسيناً**.
6. **كلُّ اختبارٍ لقراءةٍ منصّيّةٍ يحتاج مساحتَي عملٍ** (`T081` · `T150` · `T186`).
   `WorkspaceContext::id()` يرتدّ إلى `last_workspace_id`، والطالبُ عضوٌ في **لا مساحةَ عمل** —
   فتثبيتةٌ بمساحةٍ واحدةٍ خضراءُ على ارتباطٍ ضمنيٍّ يحلُّ كلَّ صفٍّ على المنصّة.
7. **إشعارٌ بلا قالبٍ مُعتمَدٍ يُسقَط بصمت** (`T124` · `T160`). `DispatchNotification` يُسجّل
   ولا يُفشل، و`tests/Pest.php` يزرع القوالبَ قبل كلِّ اختبار — فتوكيدٌ على إشعارٍ بلا قالبٍ
   ينجح **فراغاً**. وكذلك `AwardPoints` بلا صفٍّ في `GamificationCatalogSeeder` (`T101`).
8. **`ReadRanksFor` يقرأ أسبوعاً بعينِه** (`T099`). تثبيتةٌ تمنح نقاطاً وتشغّل الرولَبَ خضراءُ
   عند ١٠٠٪، والإنتاجُ صباحَ الأحدِ عند صفر — ولا صفَّ لمدرّسٍ ولا لمساعدٍ إطلاقاً.

---

## Phase 1: Setup — البنيةُ والأسماءُ والإعدادات

- [X] T001 أنشئ `backend/app/Modules/Community/CommunityServiceProvider.php` يرث `App\Shared\Modules\Module` — يُكتشَف تلقائياً، **ويُمنع** تسجيلُه في `bootstrap/providers.php`
- [X] T002 أنشئ شجرةَ الوحدة `backend/app/Modules/Community/{Actions,Data,Enums,Events,Jobs,Listeners,Models,Policies,Support,Http/{Controllers,Requests,Resources},Database/Migrations,routes}` — ⚠️ `Database/Migrations` بحرف **M** كبير: خطأُ الحالةِ يُحمّل **صفر** هجراتٍ على Linux بصمت
- [X] T003 أضف `app/Modules/Community/Database/Migrations` إلى **`databaseMigrationsPath`** في `backend/phpstan.neon` — بلا ذلك يعود كلُّ نموذجٍ «undefined property» على المستوى ٨. ⚠️ **المفتاحُ ليس `scanDirectories`** كما قال هذا السطرُ أوّلاً؛ الملفُّ هو المرجع
- [X] T004 أنشئ `backend/app/Modules/Community/routes/api.php` وتحقّق من اكتشافِ المزوّدِ بـ`getLoadedProviders()` — ⚠️ **لا بـ`route:list`**: ملفٌّ بلا مسارٍ واحدٍ يُنتج قائمةً فارغةً سواءٌ حُمِّل أو لم يُحمَّل، فالتحقُّقُ الأوّلُ كان أجوفَ بالبناء
- [X] T005 [P] `composer require laravel/reverb mpdf/mpdf` في `backend/` — التبعيّتان الوحيدتان، ومُبرَّرتان في [Complexity Tracking](./plan.md#complexity-tracking). ⚠️ **محلّياً تحتاج `--ignore-platform-req=ext-pcntl`**: ‏`laravel/horizon` يشترطه وويندوز لا يملكه، فالحلُّ يفشل قبل أن يبدأ. استقرّ على `reverb ^1.11` و`mpdf ^8.3`
- [X] T006 [P] شغّل `php artisan install:broadcasting --reverb --without-node --no-interaction` — يُنشئ `backend/config/broadcasting.php` و`backend/routes/channels.php` **ويضيف `channels:` إلى `withRouting()` في `bootstrap/app.php`** (‏وهو شكلُ `withBroadcasting()` في لارافيل ١١+)؛ بدونه **لا يوجد `/broadcasting/auth` أصلاً**. ⚠️ **و`--without-node` ليس ترفاً**: بدونه يُثبّت `laravel-echo` في `backend/package.json` — التطبيقُ الخطأُ في هذا المستودع
- [X] T007 [P] أضف مفاتيحَ `REVERB_*` إلى `backend/.env.example` بقيمٍ فارغة — ⚠️ **السرُّ لا يغادر الخادمَ ولا يدخل المستودع**؛ و`NEXT_PUBLIC_REVERB_APP_KEY` والمضيفُ إعدادٌ **عامٌّ** يحتاجه المتصفّحُ ليتّصل (‏`NFR-009` مُقسَّمٌ في ت-٦). ⚠️ **والمُثبِّتُ ألحق `BROADCAST_CONNECTION` ثانيةً بـ`.env`** فوقَ واحدةٍ قائمةٍ بقيمةٍ أخرى — أُزيلت المكرَّرة، ويبقى `.env.example` على `log` عمداً
- [X] T008 [P] `npm i laravel-echo pusher-js` في `frontend/` — وعددُ التحذيراتِ الأمنيّةِ بقي **ثلاثةً** كما هو موصوفٌ في `CLAUDE.md` (`postcss` · `sharp`)، فالحزمتان لم تُضيفا شيئاً
- [X] T009 [P] أضف `CHAT_REPLY = 'chat.reply'` إلى `backend/app/Modules/Tenancy/Support/Permissions.php` **وإلى `Permissions::all()`** — القائمةُ مكتوبةٌ بيدٍ والاشتقاقُ المنصّيُّ يبدأ منها؛ وهي **الصلاحيّةُ الجديدةُ الوحيدة** (‏الأربعُ الباقيةُ من `FR-002` ثوابتُ قائمةٌ بالفعل — ق-١)
- [X] T010 [P] أضف `chat` إلى `PermissionLabels::SUBJECTS` و`reply` إلى `ACTIONS` في `backend/app/Modules/Tenancy/Support/PermissionLabels.php` — بلا تسميةٍ عربيّةٍ تُصيَّر نصّاً منقّطاً على لوحةٍ عربيّةٍ فقط. الاسمُ **«محادثات الطلاب»** لا «المحادثات»: البندُ يمنح الردَّ في محادثةِ **غيرِه**
- [X] T011 في `backend/app/Modules/Tenancy/Support/RolePermissionMatrix.php`: أضف `CHAT_REPLY` إلى `$teacher` (‏**لا** إلى `$assistantTeacher`) **وانقل `BILLING_BALANCE_VIEW` من `$assistantTeacher` إلى `$teacher`** — ⚠️ **نقلٌ لا حذف**: المصفوفاتُ بالوراثة (`$teacher = array_merge($assistantTeacher, …)`) فالحذفُ يسلبه المدرّسَ والمالك، **وصلاحيّةٌ لا يحملها دورٌ مستأجرٌ تصير منصّيّةً بالاشتقاق** فيرمي `Tenancy\Models\Role` على أوّلِ بذرةٍ تمنحها ويموت `db:seed`
- [X] T012 أنشئ هجرةً عكسيّةً `backend/app/Modules/Tenancy/Database/Migrations/2026_08_22_000100_revoke_assistant_billing_balance_view.php` تحذف صفوفَ `role_has_permissions` لـ`billing.balance.view` على أدوارِ `assistant-teacher` **ذاتِ `team_id` غيرِ المعدوم وحدَها** — `SeedDefaultRoles` يعمل مرّةً عند إنشاءِ المساحةِ ولا يُعاد لمساحةٍ قائمة. ⚠️ **وتُسقِط ذاكرةَ spatie بعدَها** (`forgetCachedPermissions()`): حذفٌ مباشرٌ من الجدولِ المحوريِّ لا يلمس الذاكرةَ المشترَكةَ بين كلِّ عاملٍ وكلِّ طلب، فالمنحُ يبقى حيّاً والحائطُ في `US1` يُختبَر على منحٍ **بائتٍ لا مرفوع**. و`down()` **فارغةٌ عمداً** — البندُ ما زال قابلاً للمنحِ من شاشةِ الأدوار، فإعادتُه للجميعِ تدهس قراراً متعمَّداً
- [X] T013أ أنشئ `backend/app/Modules/Tenancy/Database/Migrations/2026_08_23_000100_grant_chat_reply_to_existing_roles.php` — ⚠️ **مهمّةٌ لم تكن في القائمةِ ووجبت**: `T009` بذرةٌ، و`SeedDefaultRoles` يعمل مرّةً عند إنشاءِ المساحة، فالثابتُ الجديدُ **لا صفَّ له في أيِّ قاعدةٍ قائمة**. ومفرداتُ شاشةِ الأدوارِ مُشتقّةٌ من **الكود** لا من الجدول، فالمربّعُ يُصيَّر فوراً وتُثمر ضغطتُه `PermissionDoesNotExist` — **خطأ ٥٠٠ على شاشةِ المالك، عن ميزةٍ لم تُشحَن بعد**. نمطُ هجرتَي ٠٠٦ و٠٠٨ حرفياً، ولا اختبارَ يراه: كلُّ تثبيتةٍ تُنشئ مساحتَها **بعدَ** التغيير
- [X] T013 انقل `CreditPurchasePolicy::view()` و`CreditTransactionPolicy::view()` من `BILLING_BALANCE_VIEW` إلى `Permissions::ORDERS_VIEW_ALL` — ⚠️ **تكشفان مبلغاً**، وإيصالُ شراءٍ «دفعةٌ» بنصِّ `FR-003`؛ فيبقى `billing.balance.view` ما يقوله اسمُه: **عددُ أرصدةٍ وحالةُ حجب**
- [X] T014 [P] أنشئ `backend/config/community.php` (‏حدُّ الحصصِ الأدنى للتقييم · طولُ فترةِ التقييم · سقفُ الإرسال · حجمُ الصفحة · دفعةُ التفريع) وأضف **ثلاثةً منها** إلى `PlatformSettings::KEYS` — القائمةُ **allowlist صريحة**، ومفتاحٌ خارجها لا يُحرَّر من اللوحةِ ولا يرتدّ إلى `config`. ⚠️ **وحجمُ الصفحةِ ودفعةُ التفريعِ خارجَها عمداً**: كلاهما ثابتٌ هندسيٌّ يغيّر شكلَ استعلامٍ أو ميزانيّةَ مهلةِ وظيفة، لا سياسةٌ لمشغِّلٍ رأيٌ فيها — سابقةُ `ComplianceSettings::batchSize()`
- [X] T015 [P] أنشئ `backend/app/Modules/Community/Support/CommunitySettings.php` على شاكلةِ `ComplianceSettings` — العتباتُ صفوفٌ يحرّرها مشغّلٌ، لا ثوابتُ تُشحَن
- [X] T016 [P] عرِّف خمسةَ محدّداتٍ **مُسمّاةٍ** في `AppServiceProvider::registerRateLimiters()`: `chat-write` · `chat-report` · `announcement-publish` · `moderation-write` · `report-card-render` — ⚠️ `chat-report` **بوعاءٍ مستقلّ** (‏من خُنق عن الكتابةِ يجب أن يستطيع الإبلاغَ عن إساءة)، و`report-card-render` بالدقيقةِ **وباليوم** لأنه يُطبِر تصييرَ PDF. ⚠️ **واسمٌ غيرُ مُسجَّلٍ ليس بلا أثر**: يُقرأ صفراً في الدقيقةِ فيصير `429` صلباً على كلِّ طلب. ⚠️ **وسقفُ `chat-write` وحدَه صفٌّ في `platform_settings`**، والأربعةُ الباقيةُ حرفيّةٌ بجانبِ محدّداتِها كما شُحن كلُّ محدِّدٍ قبلها — انحرافٌ مُعلَنٌ عن «حدودُ الإرسال» بالجمع في research §R9
- [X] T017 [P] أضف مشرفَ طابور `community` إلى `defaults` **و**`environments` في `backend/config/horizon.php` **وأضف `redis:community` إلى `waits`** — التعليقُ هناك يقول بنصِّه إن زوجاً غائباً **لا يُراقَب بحدٍّ افتراضيٍّ بل لا يُراقَب**؛ ١٩٤ وظيفةً تراكمت هكذا في هذه الشجرةِ يومَ ٢٠٢٦-٠٨-١٨. المهلةُ **٣٠٠ ثانيةً يفرضها تصييرُ الـPDF** لا التفريعُ: التفريعُ مُقطَّعٌ ليسع مهلةَ `supervisor-1`، أمّا mPDF بخطٍّ عربيٍّ مُضمَّنٍ فنداءٌ واحدٌ لا يُقسَّم

---

## Phase 2: Foundational — تحجب كلَّ القصص

**⚠️ CRITICAL**: لا تبدأ أيُّ قصّةٍ قبل اكتمالِ هذه المرحلة — صفُّ التعيينِ مفتاحُ الحائطِ
الماليِّ (`US1`) ونطاقِ المحادثةِ (`US2`) وتفويضِ الإشرافِ (`US3`) وحقِّ النشرِ (`US6`) معاً.

- [X] T018 هجرة `backend/app/Modules/Community/Database/Migrations/2026_08_22_000200_create_assistant_assignments_table.php`: `workspace_id` · `assistant_user_id` · `invited_by_user_id` · `revoked_at` (nullable) · `unique(workspace_id, assistant_user_id)` · **`index(assistant_user_id)`** — الفهرسُ يُقرأ عند **كلِّ** فحصِ صلاحيّة، لا مرّةً في الطلب
- [X] T019 هجرة `2026_08_22_000210_create_assistant_scopes_table.php`: `assistant_assignment_id` · `course_id` · `unique(assistant_assignment_id, course_id)` — **غيابُ الصفوفِ = كلُّ الكورسات**، فلا عمودَ «الكلّ»
- [X] T020 [P] أنشئ `Models/AssistantAssignment.php` و`Models/AssistantScope.php` بـ`HasUuid` و`BelongsToWorkspace` (‏على التعيينِ وحدَه — النطاقُ يرث سياقَه) و`declare(strict_types=1);`
- [X] T021 [P] أنشئ المصانعَ في `backend/database/factories/Modules/Community/` — `AppServiceProvider::guessFactoryName()` يحلّها هناك ولا تُكتب داخلَ الوحدة. ⚠️ **ومصنعُ التعيينِ لا يُسمّي `workspace_id`**: ‏`BelongsToWorkspace` يملؤه من السياق، ومصنعٌ يسمّي مساحتَه يدهس ذلك فيُودِع الصفَّ في مساحةٍ **ثالثة** ويعدُّ اختبارُ العزلِ صفراً في الاثنتَين. ⚠️ **و`revoked_at` يمرُّ بـ`afterMaking`** لا بالتعريف: مفتاحٌ غيرُ قابلٍ للإسنادِ يُرمى **بصمت**، فتصير التثبيتةُ مساعداً حيّاً باسمِ مسحوب
- [X] T021أ أصلح `backend/database/factories/Modules/Tenancy/WorkspaceFactory.php` بإعلانِ `protected $model` — ⚠️ **عطلٌ كامنٌ منذ ٠٠١**: مُخمِّنُ لارافيل يجرّد `Database\Factories\` ثمّ يبحث عن `App\Models\Modules\Tenancy\Workspace`، ولا وجودَ له، فيرتدّ إلى **`App\Workspace`** — و`Workspace::factory()` يرمي في كلِّ نداء. بقي خفيّاً لأن المساحاتِ تُبنى بـ`createWorkspaceWithOwner()` لا بالمصنع، **وترك ثلاثةَ مصانعَ مشحونةٍ مُلغَّمة** (`Reward` · `Redemption` · `CoinBalance`) تُعطب أوّلَ مرّةٍ تُنادى بلا `workspace_id`
- [X] T022 [P] أنشئ `backend/app/Shared/Contracts/AssistantScopeDirectory.php` — الواجهةُ الثالثةَ عشرةَ في `Shared/Contracts/`، لا داخلَ `Community`: يقرؤها `Learning` و`Assessments` بلا أن تعرفا الوحدة
- [X] T023 أنشئ `Support/EloquentAssistantScopeDirectory.php` واربطه في `CommunityServiceProvider` — يجيب «هل هذا المستخدمُ مساعدٌ حيٌّ هنا؟» و«على أيِّ كورسات؟»، **ويقاطع `EnrollmentDirectory`** للمحادثةِ الخاصّة: بلا التقاطعِ يقرأ مساعدٌ مقيَّدٌ بكورسٍ واحدٍ **كلَّ** محادثةٍ خاصّةٍ في المساحة
- [X] T024 [P] أنشئ `Enums/{ConversationKind,ModerationVerdict,TermPolicy}.php` — `ConversationKind`: `private` · `session` · `lesson`؛ `TermPolicy`: `block` · `mask` · `review`
- [X] T025 [P] أنشئ `Policies/AssistantAssignmentPolicy.php` وسجّله — **مالكُ المساحةِ وحدَه** (`workspaces.owner_user_id`)، لا «عضوٌ بصلاحيّةٍ واسعة»: مساعدٌ يوسّع نطاقَ مساعدٍ يرفع سقفَ نفسِه
- [X] T026 أضف حالةَ عزلٍ لكلِّ نموذجٍ مملوكٍ لمساحةِ العملِ من هذه المرحلةِ إلى `backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php` (‏وتُملأ الباقيةُ مع كلِّ قصّة) — يوجبه الدستورُ و`SC-014`. ⚠️ **وحالةُ `assistant_scopes` تُثبِت غياباً**: الجدولُ بلا `workspace_id` عمداً وحارسُه الوصلةُ لا النطاق، فالتوكيدُ أن `BelongsToWorkspace` **ليس** عليه — وإلّا «أصلحه» قارئٌ لاحقٌ فتكرَّر قيدُ مساعدٍ واحدٍ لكلِّ مساحة

**Checkpoint**: الوحدةُ مكتشَفةٌ، والتعيينُ قابلٌ للقراءة — تبدأ القصص.

---

## Phase 3: User Story 1 — المدرّس يبني فريقه (Priority: P1) 🎯 MVP

**Goal**: مساعدٌ مُعيَّنٌ بصلاحيّاتٍ من شاشةِ الأدوارِ القائمة، مقيَّدٌ بكورسات، **لا يرى
ريالاً واحداً** — لا من الـAPI ولا من `/admin`.

**Independent Test**: عيّن مساعداً بصلاحيّةِ تصحيحٍ وحدَها؛ يُقبل تصحيحُه، ويُرفض رفعُ المحتوى،
وتُرفض أربعةُ مساراتٍ ماليّةٍ ولوحةُ الطلباتِ معها، ويُرفض عملُه خارجَ كورسِه، والسحبُ يسري على
الطلبِ التالي بلا إعادةِ دخول.

### Tests for User Story 1

- [ ] T027 [P] [US1] `backend/tests/Feature/Community/AssistantFinancialWallTest.php` — `SC-001` عبرَ **HTTP** بحسابِ مساعدٍ حقيقيٍّ على **كلِّ** مسارٍ ماليٍّ قائم (‏كشفُ التسوية · سعرُ التسوية · إيصالُ دفعة · `/manage/billing/students` · شراءُ أرصدة). ⚠️ **التثبيتةُ تُحيّد كلَّ سببِ رفضٍ آخر**: المساعدُ داخلَ نطاقِه، مسجَّلٌ، في المساحةِ الصحيحة — وإلّا مرَّ الاختبارُ ولو حُذف الحائطُ كلُّه. ⚠️ **وحالةٌ تصنع دوراً باسمٍ آخرَ فيه `payments.approve` وتُسنده**: قياسٌ على المصفوفةِ لا يُثبت شيئاً
- [ ] T028 [P] [US1] `backend/tests/Feature/Community/PanelFinancialWallTest.php` — المساعدُ يدخل `/admin` (‏`EnsureFilamentAccess` يُدخله بالاسم) ويُرفَض على كلِّ موردٍ يعرض مالاً. **بالاتّجاهَين**: المالكُ ما زال يرى القائمة — سابقةُ `981ca23`، حيث كان الرفضُ الوحيدُ المفقود
- [ ] T029 [P] [US1] `backend/tests/Feature/Community/AssistantScopeTest.php` — `SC-002`: مقيَّدٌ بكورسٍ يُردُّ خارجَه، **وعلى المحادثةِ الخاصّةِ أيضاً** (‏تقاطعُ التسجيل)
- [ ] T030 [P] [US1] `backend/tests/Feature/Community/AssistantRevocationTest.php` — `SC-003`: السحبُ أثناءَ جلسةٍ فعّالة، فالطلبُ التالي **`403` بلا إعادةِ دخول**. ⚠️ والتثبيتةُ لا تُخالف النطاقَ أيضاً وإلّا أثبتت الحارسَ الآخر
- [ ] T031 [P] [US1] `backend/tests/Feature/Community/AssistantAttributionTest.php` — `SC-004`/`FR-006`/`FR-009`: تصحيحٌ نفّذه مساعدٌ يبقى منسوباً إليه **بعد سحبِه**، ويراه المدرّسُ باسمِه

### Implementation for User Story 1

- [ ] T032 [US1] أنشئ `Listeners/CreateAssistantAssignment.php` وسجّله على حدثِ قبولِ الدعوةِ في `CommunityServiceProvider::boot()` — ⚠️ **لا `POST /manage/assistants`**: `workspace_members` لا يكتبه إلّا `AcceptInvitation` و`CreateWorkspace`، والدعوةُ والقبولُ والأعضاءُ مشحونةٌ كلُّها في `Tenancy` بشاشاتِها
- [ ] T033 [US1] أنشئ `Support/AssistantDirectory.php` — يجيب «هل المستخدمُ مساعدٌ حيٌّ في المساحةِ الحاليّة؟» **بحفظٍ مؤقّتٍ لكلِّ طلب**، نمطُ `PlatformStaffDirectory`: فحصُ الصلاحيّةِ يجري عشراتِ المرّاتِ في الطلبِ الواحد
- [ ] T034 [US1] أنشئ `Support/AssistantForbiddenPermissions.php` — المجموعةُ **مُشتقّةٌ** من البادئاتِ `settlement.` · `billing.` · `payments.` · `orders.` **ناقصَ `billing.balance.view`** (ت-١). ⚠️ **قائمةٌ مكتوبةٌ بيدٍ خاصّيّتُها معكوسة**: صلاحيّةُ تسويةٍ تُضاف غداً **ليست** ماليّةً حتى يتذكّرها أحد — الاشتقاقُ نفسُه الذي يجعل `platformPermissions()` صحيحةً بالبناء
- [ ] T035 [US1] أضف `Gate::before` في `backend/app/Providers/AppServiceProvider.php` بجانبِ هوكِ `PlatformStaffDirectory`: يرفض المجموعةَ الماليّةَ لمن له صفُّ تعيينٍ حيٌّ في المساحةِ الحاليّة، **عبرَ كلِّ دورٍ يحمله**. ⚠️ **يُرجع `null` لا `false`** — `false` يقصر كلَّ سياسةٍ خلفه، والهوكُ يُسأل عن `view`/`update` باستمرارٍ من Filament
- [ ] T036 [US1] أنشئ `Actions/SetAssistantScope.php` + `Http/Requests/SetAssistantScopeRequest.php` — الكورساتُ تُتحقَّق بـ`WorkspaceRules::exists('courses')` لا `exists:courses,id`
- [ ] T037 [US1] أنشئ `Actions/RevokeAssistant.php` — **تحديثٌ شرطيٌّ** `WHERE revoked_at IS NULL` فلا يُنفَّذ أثرُ الإزالةِ مرّتَين (`FR-009`)، ولا حذفَ للصفّ
- [ ] T038 [US1] أنشئ `Actions/ListAssistants.php` + `ReadAssistantAssignment.php` (`/assistants/me`) — الثاني يقرأ التعييناتِ الحيّةَ للمستخدمِ نفسِه عبرَ مساحاتِه
- [ ] T039 [US1] أنشئ `Data/AssistantScopeData.php` يرث `DataTransferObject` — كان `Data/` ناقصاً من الإصدارِ الأوّلِ رغمَ `NFR-005`
- [ ] T040 [US1] أنشئ `Http/Controllers/Manage/AssistantController.php` (`GET /manage/assistants` · `PUT .../{assignment}/scope` · `DELETE .../{assignment}`) و`Http/Controllers/AssistantController.php` (`GET /assistants/me`) وسجّلها في `routes/api.php` بـ`throttle:authoring` على الكتابة
- [ ] T041 [P] [US1] أنشئ `Http/Resources/AssistantAssignmentResource.php` — `uuid` وحدَه، ولا حقلَ مالٍ (‏الحمولةُ لا تحمل مالاً أصلاً؛ **السؤالُ الحقيقيُّ رفضُ المسار**، ولهذا لا `AssistantPayloadAllowlist`)
- [ ] T042 [US1] احذف `revoked_at` من مسارِ القراءةِ في `EloquentAssistantScopeDirectory` بشرطٍ صريح `whereNull('revoked_at')` — السحبُ فوريٌّ **بالبناء** لأن الصلاحيّةَ تُقرأ لكلِّ طلب، فلا `RevokeAssistantSessions` ولا حذفَ رمز (‏حذفُ الرمزِ يُخرج المساعدَ من مدرّسِه الآخر ويُنتج `401` بينما `quickstart` يطلب `403`)
- [ ] T043 [US1] أضف `AssistantScopeDirectory` إلى حرّاسِ التصحيحِ في `Assessments` (‏تصحيحُ ورقةٍ من كورسٍ خارجَ النطاقِ يُرفَض) — `FR-005`
- [ ] T044 [US1] أضف الحارسَ نفسَه إلى رفعِ المحتوى في `Courses`/`CMS` — `FR-005`، والحالةُ ٣ من سيناريوهاتِ `US1`
- [ ] T045 [US1] أضف الحارسَ نفسَه إلى تسجيلِ الحضورِ في `LiveSessions` — `FR-005`
- [ ] T046 [US1] تحقّق أن كلَّ فعلٍ ينفّذه مساعدٌ يكتب `actor` باسمِه في `activity_log` القائم — `FR-006`/`SC-004`؛ لا عمودَ جديد
- [ ] T047 [US1] أضف `canViewAny()` **وقَطعاً على `getEloquentQuery()`** لكلِّ موردِ Filament يعرض مالاً (‏التسوية · الأرصدة · الطلبات) على غرارِ `OrderResource` بعد `981ca23` — ⚠️ **القائمةُ لا تستدعي سياسةَ الصفِّ أبداً**، فسياسةُ `view()` الدقيقةُ لا تحرس شاشةً
- [ ] T048 [P] [US1] أضف حالةَ `assistant_assignments` و`assistant_scopes` إلى `WorkspaceIsolationTest` — `SC-014`/`FR-008`
- [ ] T049 [P] [US1] أنشئ `frontend/src/lib/assistants.ts` — عميلُ النقاطِ الأربع، بـ`uuid` حصراً
- [ ] T050 [US1] أنشئ `frontend/src/app/(app)/(shell)/manage/assistants/page.tsx` — القائمةُ والنطاقُ والسحب، **وتربط إلى شاشةِ الأدوارِ القائمةِ للبنود** لا إلى نموذجٍ ثانٍ
- [ ] T051 [US1] أضف رابطاً إلى `/manage/assistants` من قائمةِ إدارةِ المدرّس — **شاشةٌ بلا رابطٍ داخلٍ ليست مُسلَّمة**
- [ ] T052 [P] [US1] `frontend/src/components/community/AssistantScopeForm.test.tsx` بـvitest — اختيارُ الكورساتِ، وحالةُ «بلا تقييد = الكلّ» مكتوبةً بالعربيّةِ لا مستنتَجةً من قائمةٍ فارغة
- [ ] T053 [US1] راجع نصَّ شاشةِ الأدوارِ لبندِ `chat.reply` وللبنودِ الأربعةِ الأخرى — ⚠️ شاشةٌ يُساء فهمُها تُمنح كاملةً بضغطة، فيسقط `FR-002` **سلوكاً وهو قائمٌ كوداً** (‏بندٌ يدويٌّ في `quickstart` §ج-٥)
- [ ] T054 [US1] **احذف `Gate::before` وأعد تشغيلَ `T027`** — إن بقي أخضرَ فالتثبيتةُ تقيس سبباً آخر. أعِده. هذه المهمّةُ ليست اختياريّة

**Checkpoint**: `US1` قابلةٌ للتسليمِ وحدَها — مساعدٌ يعمل، ولا يرى مالاً من أيِّ باب.

---

## Phase 4: User Story 2 — الشات الخاص (Priority: P2)

**Goal**: محادثةٌ خاصّةٌ بين الطالبِ ومدرّسِه ومن فُوِّض، الرسالةُ تصل لحظياً، وغيرُ المتّصلِ
يُنبَّه — **والقاعدةُ هي المصدر**.

**Independent Test**: افتح محادثةً من حسابَين في متصفّحَين، تبادَل رسائل، ثمّ **أوقِف
`reverb:start`** — الحفظُ والقراءةُ يعملان، والرسالةُ تظهر عند إعادةِ التحميل.

### Tests for User Story 2

- [ ] T055 [P] [US2] `backend/tests/Feature/Community/ConversationAccessTest.php` — `SC-005`: صفرُ قراءةٍ لمن ليس طرفاً، وصفرُ اشتراكٍ ناجحٍ في قناةٍ غيرِ مصرَّحٍ بها. ⚠️ **بمساحتَي عملٍ وبطالبٍ من كلٍّ منهما**
- [ ] T056 [P] [US2] `backend/tests/Feature/Community/MessageOrderingTest.php` — `SC-007`. ⚠️ **التثبيتةُ تكتب `created_at` متطابقاً**؛ بغيرِه يمرُّ على ترتيبٍ زمنيٍّ ولا يرى شيئاً
- [ ] T057 [P] [US2] `backend/tests/Feature/Community/ChatQueryBudgetTest.php` — `SC-009`/`NFR-010` **بحجمَين**، وللرسائلِ **ولقائمةِ المحادثاتِ معاً**، **بإحماءٍ أوّلاً**، **ويؤكّد حضورَ الحقول** لا ثباتَ العددِ وحدَه
- [ ] T058 [P] [US2] `backend/tests/Feature/Community/BroadcastOutageTest.php` — `SC-015`: يُعطّل الخدمةَ فعلاً (‏سائقٌ يرمي) ولا يكتفي بعدمِ تشغيلِها
- [ ] T059 [P] [US2] `backend/tests/Feature/Community/ArchiveAfterEnrollmentEndsTest.php` — `FR-014`: الإرسالُ يُمنع والأرشيفُ يُقرأ؛ **تفويضُ القراءةِ وتفويضُ الكتابةِ سؤالان**

### Implementation for User Story 2

- [ ] T060 [US2] هجرة `2026_08_22_000300_create_conversations_table.php`: `workspace_id` · `kind` · `student_user_id` (nullable) · `class_session_id`/`lesson_id` (nullable) · `last_message_id` (nullable) · `unique(workspace_id, student_user_id)` · `index(workspace_id, last_message_id)` — ⚠️ **عمودان عاديّان بلا عمودٍ محسوب**: `NULL` لا يصطدم بـ`NULL` فالعامّةُ تتعايش والخاصّةُ واحدةٌ لكلِّ طالب
- [ ] T061 [US2] هجرة `2026_08_22_000310_create_messages_table.php`: `workspace_id` · `conversation_id` · `sender_user_id` · `body` · **`hidden_at`** · `is_helpful` · `index(workspace_id, conversation_id, id)` — ⚠️ **`hidden_at` لا `deleted_at`**: الاسمُ الثاني يجتذب `SoftDeletes` ونطاقُه يمحو الأرشيفَ الذي يعد به `FR-015` **ويقصّر كلَّ صفحةِ خمسين** بصمت
- [ ] T062 [US2] هجرة `2026_08_22_000320_create_conversation_participants_table.php`: `conversation_id` · `user_id` · `last_read_message_id` · `unique(conversation_id, user_id)` · **`index(user_id, conversation_id)`** — ⚠️ الفهرسُ الثاني هو الذي يجيب «في أيِّ محادثاتٍ أنا؟»؛ الفريدُ عمودُه القائدُ `conversation_id` فلا يخدمه
- [ ] T063 [P] [US2] أنشئ `Models/{Conversation,Message,ConversationParticipant}.php` — ⚠️ **`messages.workspace_id` يُنسَخ من المحادثةِ صراحةً**: الطالبُ عضوٌ في لا مساحةَ عمل، فتعبئةُ `BelongsToWorkspace` التلقائيّةُ تكتب `null` أو — أسوأُ — `last_workspace_id`
- [ ] T064 [P] [US2] أنشئ مصانعَ المحادثةِ والرسالةِ والمشارِك في `backend/database/factories/Modules/Community/`
- [ ] T065 [US2] أنشئ `Actions/StartConversation.php` — ⚠️ **سباقٌ مُعلَن**: جهازان يفتحان معاً، كلاهما لا يجد شيئاً، كلاهما يُدرج. يُلتقَط خرقُ الفريدِ ويُرجَع الفائز — نمطُ `BookSeat`
- [ ] T066 [US2] أنشئ `Actions/PostMessage.php` — يكتب الصفَّ **ويُرجع الرسالةَ في الاستجابة**؛ البثُّ حدثٌ `ShouldBroadcast` **مطبورٌ و`afterCommit`**. بغيرِ ذلك يصير توقُّفُ `reverb` **فشلَ إرسالِ رسالة** و`SC-015` غيرَ قابلٍ للتنفيذ
- [ ] T067 [US2] في `PostMessage`: اكتب `conversations.last_message_id` بتحديثٍ شرطيّ `WHERE last_message_id IS NULL OR last_message_id < ?` — رسالتان في اللحظةِ نفسِها قد تكتب الأخيرةُ المعرّفَ **الأصغر** فتُرتَّب المحادثةُ برسالةٍ ليست آخرَها **إلى الأبد**
- [ ] T068 [US2] أنشئ `Actions/ReadMessages.php` — `ORDER BY id` وصفحاتٌ بمفتاح، **والمؤشِّرُ `?before={uuid}`** يُحلُّ داخلَ الفعلِ **بعد التحقّقِ أنه لهذه المحادثة** وإلّا صار عرّافَ ترقيم. ⚠️ **ولا `paginate()`**: عدُّه الكاملُ استعلامٌ واحدٌ عند كلِّ حجم، فلا يراه اختبارُ الميزانيّةِ ويُسقط نصفَ `SC-009` الزمنيَّ وحدَه
- [ ] T069 [US2] أنشئ `Actions/ListConversations.php` — ⚠️ **يُصفّي بـ`conversation_participants` صراحةً**: `WorkspaceScope` عديمُ الأثرِ للطالب، فبلا الشرطِ تُرجع محادثاتِ كلِّ مساحةٍ على المنصّة
- [ ] T070 [US2] أنشئ `Actions/HideMessage.php` — `hidden_at` من صاحبِها، والأثرُ يبقى للإشراف (`FR-015`)
- [ ] T071 [US2] أنشئ `Events/MessagePosted.php` بـ`ShouldBroadcast` — الحمولةُ **`{message_uuid, conversation_uuid}` ولا شيءَ غيرها** (`NFR-008`). ⚠️ **وهذا حِملٌ مزدوج**: القناةُ تُفوَّض مرّةً عند الاشتراكِ ولا يملك البروتوكولُ إلغاء، فمساعدٌ مسحوبةٌ صلاحيّتُه وما زال متّصلاً يستمرّ في تلقّي الأحداث — **بمعرّفٍ فقط يمرُّ جلبُه اللاحقُ بالمسارِ المُصادَقِ عليه فيُرفَض**. اكتب السببَ الثاني بجانبِ القاعدةِ وإلّا «بسّطها» قارئٌ لاحق
- [ ] T072 [US2] عرّف قناتَي `private-conversation.{uuid}` و`private-user.{uuid}` في `backend/routes/channels.php` — ⚠️ **التفويضُ يستدعي حارسَ الفعلِ نفسَه** لا شرطاً ثانياً بجانبِه، وهذا **الموضعُ الوحيدُ** الذي تُعدَّد فيه أسماءُ القنوات
- [ ] T073 [US2] أضف محدِّداً مُسمّىً على `/broadcasting/auth` — مسارٌ مُصادَقٌ عليه ومكشوف
- [ ] T074 [US2] أنشئ `Listeners/NotifyOfflineRecipient.php` على `MessagePosted` → `DispatchNotification` — `FR-012`، والقناةُ يقرّرها نوعُ الإشعارِ لا المستمع
- [ ] T075 [P] [US2] أضف نوعَ `chat_message` إلى `NotificationType` **وصفَّه في `NotificationTemplateSeeder`** — ⚠️ نوعٌ بلا قالبٍ يُسقَط بصمتٍ وكلُّ توكيدٍ عنه يمرُّ على مجموعةٍ فارغة
- [ ] T076 [US2] أنشئ `Policies/ConversationPolicy.php` + `MessagePolicy.php` — و**القراءةُ والكتابةُ قدرتان منفصلتان** (`FR-014`)
- [ ] T077 [US2] أنشئ `Http/Controllers/ConversationController.php` و`MessageController.php` وسجّل المسارات بـ`throttle:chat-write` على الكتابة — ⚠️ **كلُّ معرّفٍ يُحلُّ داخلَ الفعلِ بعد فحصِ العضويّة، لا بارتباطٍ ضمنيّ**: نمطُ `RedeemReward` من ٠٠٩ حرفياً
- [ ] T078 [P] [US2] أنشئ `Http/Resources/{ConversationResource,MessageResource}.php`
- [ ] T079 [P] [US2] أنشئ `Data/{StartConversationData,PostMessageData}.php`
- [ ] T080 [US2] أضف مفاتيحَ حقولِ `FormRequest` الجديدةَ إلى `backend/lang/ar/validation.php` تحت `attributes` — بلا صفٍّ يُصيَّر `body` نصّاً إنجليزياً على شاشةٍ عربيّة
- [ ] T081 [P] [US2] أضف حالاتِ `conversations` و`messages` إلى `WorkspaceIsolationTest` — **بمساحتَين وبطالبٍ من كلٍّ منهما**
- [ ] T082 [P] [US2] أنشئ `frontend/src/lib/echo.ts` — يقرأ المفتاحَ والمضيفَ من `NEXT_PUBLIC_*`، **ويُهيَّأ كسولاً** فلا يمنع فشلُ الاتّصالِ تصييرَ الصفحة
- [ ] T083 [US2] أنشئ `frontend/src/app/(app)/(shell)/messages/page.tsx` + `[uuid]/page.tsx` — الأحدثُ أوّلاً، والأقدمُ عند الطلب، **والالتقاطُ بعد الانقطاعِ يعيد جلبَ أحدثِ صفحةٍ** ولا يأخذ مؤشّرَ `after=` (‏ترتيبُ الالتزامِ ليس ترتيبَ الترقيمِ على MySQL)
- [ ] T084 [P] [US2] `frontend/src/components/community/MessageList.test.tsx` بـvitest — الإدراجُ اللحظيُّ لا يُكرّر رسالةً وصلت بالاستجابةِ ثمّ بالمقبس
- [ ] T085 [US2] أضف رابطَ «الرسائل» إلى قائمةِ الطالبِ والمدرّس — **بلا رابطٍ لا تسليم**
- [ ] T086 [US2] عالج فشلَ المقبسِ في الواجهةِ عبرَ `userMessage()` — ⚠️ **ولا `.catch(() => undefined)`**: صفحةٌ بيضاءُ دائمةٌ والسببُ في الاستجابةِ غيرُ مقروء

**Checkpoint**: `US1` و`US2` تعملان مستقلّتَين.

---

## Phase 5: User Story 3 — الشات العام أسفل الحصّة (Priority: P3)

**Goal**: شاتُ حصّةٍ يقرؤه من يحقُّ له حضورُها، الرتبةُ بجوارِ الاسم، ترشيحٌ وإشرافٌ واعتمادُ
إجابةٍ مفيدةٍ يُغذّي التلعيب.

**Independent Test**: طالبان في شاتِ حصّة، **أحدُهما سجّل اليوم** — الرتبةُ لمن له واحدة،
والمستوى للاثنَين، ولا شيءَ يظهر مكسوراً؛ ثمّ اعتمِد إجابةً مرّتَين ← نقاطٌ تُمنَح **مرّةً**.

### Tests for User Story 3

- [ ] T087 [P] [US3] `backend/tests/Feature/Community/SessionChatAccessTest.php` — `FR-018`: من لا يحقُّ له حضورُ الحصّةِ يُمنع قراءةً وكتابة
- [ ] T088 [P] [US3] `backend/tests/Feature/Community/RankFallbackTest.php` — `FR-019` بمُرسِلٍ **بلا صفِّ لوحة**: طالبٌ جديدٌ · مدرّسٌ · مساعد
- [ ] T089 [P] [US3] `backend/tests/Feature/Community/ModerationTest.php` — `FR-021`/`FR-022`: الحظرُ يمنع الكتابةَ في **كلِّ** المساحة، والرفعُ **صفٌّ جديدٌ لا حذف**، وحظرٌ دائمٌ (`expires_at IS NULL`) لا ينتهي فوراً
- [ ] T090 [P] [US3] `backend/tests/Feature/Community/BlockedTermTest.php` — `FR-020`: الترشيحُ على **حدودِ الكلمات** لا بالاحتواء، والسياساتُ الثلاثُ كلٌّ بأثرِها
- [ ] T091 [P] [US3] `backend/tests/Feature/Community/HelpfulAwardTest.php` — `FR-023`: ضغطتان ⇒ **منحٌ واحد**

### Implementation for User Story 3

- [ ] T092 [US3] هجرة `2026_08_22_000400_create_moderation_actions_table.php`: `workspace_id` · `actor_user_id` · `subject_type`+`subject_id` · `verdict` · `reason` · `expires_at` · `index(workspace_id, subject_type, subject_id, verdict)` — ⚠️ **ليس `(workspace_id, created_at)`**: الشرطُ لا يذكر `created_at` فيُستعمَل عمودٌ قائدٌ واحدٌ ويُمسَح تاريخُ الإشرافِ كلُّه عند **كلِّ إرسالِ رسالة**
- [ ] T093 [US3] هجرة `2026_08_22_000410_create_blocked_terms_table.php`: `workspace_id` · `term` · `policy` · `unique(workspace_id, term)`
- [ ] T094 [US3] هجرة تعبئةٍ `2026_08_22_000420_backfill_blocked_terms.php` بـ**`chunkById`** لكلِّ مساحةٍ قائمة — المستمعُ يعمل عند الإنشاءِ وحدَه، **ومرشِّحٌ يسمح بكلِّ شيءٍ بصمتٍ هو أسوأُ أشكالِ الغياب**
- [ ] T095 [P] [US3] أنشئ `Models/{ModerationAction,BlockedTerm}.php` — و`ModerationAction` **مُضافٌ فقط**: لا `update` ولا `delete`، الجدولُ **هو** سجلُّ `FR-021`
- [ ] T096 [US3] أنشئ `Listeners/SeedDefaultBlockedTerms.php` على `WorkspaceCreated` وسجّله في **`CommunityServiceProvider`** — لا داخلَ `SeedDefaultRoles`: ذاك مستمعُ `Tenancy` وكتابتُه في جدولِ `Community` خرقُ المبدأ الثالث
- [ ] T097 [US3] أنشئ `Support/TermFilter.php` — حدودُ كلماتٍ، وثلاثُ سياسات؛ **الحجبُ الزائدُ يعلّم المستخدمَ الالتفاف**
- [ ] T098 [US3] أنشئ `Support/BanReader.php` — «هل هو محظورٌ الآن؟» أحدثُ صفٍّ يفوز، **والشرطُ مُجمَّع** `(expires_at IS NULL OR expires_at > now())`: بلا تجميعٍ `NULL > now()` هو `NULL` فحظرٌ دائمٌ ينتهي فوراً. يُسأل من `PostMessage` **و**`StartConversation` معاً
- [ ] T099 [US3] أنشئ `Support/ChatRankStamper.php` يستدعي `Gamification\Actions\ReadRanksFor` **نداءً واحداً للصفحة** — ⚠️ **والغيابُ حالةٌ لا خطأ**: تُعرَض الرتبةُ لمن له صفٌّ في `leaderboard_entries`، ويُعرَض المستوى من `student_progress` **التراكميِّ** لكلِّ طالب، **ولا شيءَ لمدرّسٍ أو مساعد** — فليس في لوحةٍ أصلاً. ومفتاحُ النطاقِ `LeaderboardScope::keyFor()`، وشاتُ الحصّةِ والدرسِ في المساحةِ نفسِها فالمفتاحُ واحد
- [ ] T100 [US3] أنشئ `Actions/ResolveSessionConversation.php` — يحلُّ محادثةَ الحصّةِ أو يُنشئها، وحقُّ الحضورِ هو الشرط (`FR-017`/`FR-018`)
- [ ] T101 [US3] أنشئ `Actions/MarkHelpful.php` + `Events/HelpfulAnswerMarked.php` — الحمولةُ `student_user_id` · `workspace_id` · **`source_type`+`source_id`**، وتحديثٌ شرطيٌّ `WHERE is_helpful = 0`. ⚠️ **ومعها صفٌّ في `GamificationCatalogSeeder`**: `AwardPoints` يعود صامتاً لفعلٍ بلا صفّ، ومفتاحُ التعامدِ مبنيٌّ على العمودَين فبدونهما تُمنَح النقاطُ مرّتَين على ضغطتَين
- [ ] T102 [US3] أنشئ `Actions/ModerateMessage.php` — الحذفُ والحظرُ والرفعُ **كلُّها صفوفٌ** في `moderation_actions`، ولا `DELETE /moderation/bans/{ban}`
- [ ] T103 [US3] أنشئ `Actions/ReportMessage.php` — `FR-024`: مسارُ بلاغٍ بشريٍّ لما لا تلتقطه القائمة
- [ ] T104 [US3] أنشئ `Http/Controllers/{SessionChatController,ModerationController}.php` وسجّل `POST /messages/{message}/helpful` · `POST /messages/{message}/report` بـ`throttle:chat-report` · `POST /moderation/actions` بـ`throttle:moderation-write`
- [ ] T105 [P] [US3] أنشئ `Policies/ModerationActionPolicy.php` — المدرّسُ **ومن فُوِّض** (‏صلاحيّةٌ من شاشةِ الأدوار)، لا المدرّسُ وحدَه
- [ ] T106 [P] [US3] أنشئ `Data/{ModerationActionData,ReportMessageData}.php`
- [ ] T107 [US3] أنشئ `frontend/src/components/community/SessionChat.tsx` — الرتبةُ والمستوى بجوارِ الاسمِ **وغيابُهما لا يكسر السطر**
- [ ] T108 [US3] أدرِج `SessionChat` أسفلَ صفحةِ الحصّةِ والدرس — الرابطُ الداخل
- [ ] T109 [P] [US3] `frontend/src/components/community/SessionChat.test.tsx` بـvitest — مُرسِلٌ بلا رتبةٍ يُصيَّر سليماً، والاعتمادُ لا يُرسَل مرّتَين بضغطتَين
- [ ] T110 [P] [US3] أضف حالاتِ `moderation_actions` و`blocked_terms` إلى `WorkspaceIsolationTest`
- [ ] T111 [P] [US3] أضف نوعَي إشعارِ الإشرافِ إن لزما إلى `NotificationType` **وقوالبَهما** — أو أعلِن صراحةً في `research` أن الإشرافَ بلا إشعار
- [ ] T112 [US3] أضف مفاتيحَ الحقولِ الجديدةَ إلى `backend/lang/ar/validation.php`

**Checkpoint**: القصصُ الثلاثُ الأولى تعمل مستقلّة.

---

## Phase 6: User Story 4 — التقييم المتبادل (Priority: P4)

**Goal**: تقييمٌ دوريٌّ من المدرّسِ للطالبِ يراه وليُّ الأمر، وتقييمُ الطالبِ لمدرّسِه على
**ثلاثةِ محاورَ** بعد حدٍّ أدنى من الحصصِ **المحتسَبةِ حضوراً** — يُغذّي درجةَ الثقةِ القائمةَ
بلا كسرِ احتسابِها.

**Independent Test**: طالبٌ حضر أقلَّ من الحدِّ يُرفَض **مع بيانِ ما ينقصه**؛ بلغه فيقيّم مرّةً
واحدةً للفترة؛ ودرجةُ الثقةِ قبلَ وبعدَ تُقارَن.

### Tests for User Story 4

- [ ] T113 [P] [US4] `backend/tests/Feature/Community/PeriodicReviewTest.php` — `FR-028`/`FR-029`/`FR-035`: الطالبُ ووليُّ أمرِه يريان، وطالبٌ آخرُ **لا**
- [ ] T114 [P] [US4] `backend/tests/Feature/Marketplace/ReviewEligibilityTest.php` — `SC-010`: صفرُ تقييمٍ دونَ الحدّ، وصفرُ تكرارٍ في الفترة، **والأهليّةُ المعروضةُ تطابق ما يقبله الخادم**
- [ ] T115 [P] [US4] `backend/tests/Feature/Marketplace/TrustScoreUnchangedTest.php` — `SC-011`: مطابقةُ درجةِ الثقةِ قبلَ وبعد، **وصفٌّ قديمٌ بمحاورَ معدومةٍ لا يهبط متوسّطُه إلى صفر**

### Implementation for User Story 4

- [ ] T116 [US4] هجرة `2026_08_22_000500_create_periodic_reviews_table.php`: `workspace_id` · `student_user_id` · `teacher_user_id` · `period_start`/`period_end` · `commitment`/`participation`/`homework`/`improvement` (١–٥) · `note` · `published_at` · `unique(workspace_id, student_user_id, period_start, period_end)`
- [ ] T117 [US4] هجرة `backend/app/Modules/Marketplace/Database/Migrations/2026_08_22_000510_extend_reviews_axes.php`: `punctuality` · `clarity` · `engagement` **قابلةً للعدم** + `period_start` — ⚠️ **`NOT NULL` بلا افتراضٍ يرفضه SQLite على جدولٍ عامرٍ ويقبله MySQL فيملأ صفراً** خارجَ المدى ١–٥، فيصير متوسّطُ كلِّ تقييمٍ سابقٍ صفراً ويتدفّق إلى درجةِ الثقة — وهو بالضبط ما يقيسه `SC-011`
- [ ] T118 [US4] هجرة `2026_08_22_000520_repoint_reviews_unique.php`: **تعبئةُ `period_start` أوّلاً ثمّ** استبدالُ `unique(teacher_profile_id, student_id)` بـ`unique(teacher_profile_id, student_id, period_start)` — ⚠️ ترتيبُ ٠١٦ نفسُه (‏التكثيفُ قبلَ الفهرس، وإلّا فشل النشرُ على بياناتٍ حيّة). والفريدُ القديمُ يعني صفّاً واحداً للأبد، فـ`FR-032` مُرضىً **مجّاناً** ولا يمكن إدخالُ فترةٍ ثانيةٍ إطلاقاً
- [ ] T119 [P] [US4] أنشئ `Models/PeriodicReview.php` ومصنعَه
- [ ] T120 [US4] أنشئ `Actions/SubmitPeriodicReview.php` — ⚠️ **يسأل `EnrollmentDirectory` أوّلاً**: معرّفٌ عارٍ **مسبارُ هويّة** (`NFR-001أ`)، فمعرّفُ أيِّ مستخدمٍ يعيد اسمَه
- [ ] T121 [US4] أنشئ `Actions/PublishPeriodicReview.php` + `Events/PeriodicReviewPublished.php` — **تحديثٌ شرطيٌّ على `published_at`** فيصل الإشعارُ مرّةً
- [ ] T122 [US4] عدّل `Marketplace\Actions\SubmitReview`: **استبدل `hasCompletedSessionWith()` في مكانِه** بعدَّادِ الحصصِ **المحتسَبةِ حضوراً** من `CommunitySettings` — ⚠️ البوّابةُ المشحونةُ تطلب **تسجيلاً مكتملاً**: طالبٌ حضر أربعَ حصصٍ على تسجيلٍ نشطٍ يُرفَض اليوم، وطالبٌ بتسجيلٍ مكتملٍ وصفرِ حصصٍ يُقبَل. **ولا نقطةَ ثانية**
- [ ] T123 [US4] أنشئ `Marketplace\Actions\ReadReviewEligibility.php` + `GET /teachers/{teacher}/reviews/eligibility` — ⚠️ **تُشتقُّ من مسندِ التفويضِ نفسِه**: درسُ `ListLeaderboardScopes` — قائمةٌ مبنيّةٌ بجانبِ الحارسِ تعرض ما يرفضه الخادمُ وتُخفي ما يسمح به
- [ ] T124 [US4] أضف نوعَ `periodic_review_published` إلى `NotificationType` **و`targetsGuardians()` و`requiredGuardianPermission()` في السطرِ نفسِه** — ⚠️ نوعٌ في `targetsGuardians()` بلا صلاحيّةِ وصايةٍ يُلتقَط للواتساب، **يُحاسَب عليه، ولا يصل أحداً**
- [ ] T125 [US4] أضف صفَّه إلى `NotificationTemplateSeeder` **وحدّث العددَ في `WhatsAppDefaultsTest` إلى `23`** — ⚠️ **اقرأ التوكيدَ لا الوثيقةَ عنه**: الإصدارُ الأوّلُ كتب `19` لأنه نُقل عن `CLAUDE.md` وكان ٠١٣ قد حرّكه إلى `22`
- [ ] T126 [P] [US4] أنشئ `Http/Controllers/Manage/PeriodicReviewController.php` و`StudentReviewController.php` (`GET /students/me/reviews`)
- [ ] T127 [P] [US4] أنشئ `Http/Resources/PeriodicReviewResource.php` و`Data/PeriodicReviewData.php` و`Policies/PeriodicReviewPolicy.php`
- [ ] T128 [US4] وسّع نقطةَ `POST /teachers/{teacher}/reviews` المشحونةَ بالمحاورِ الثلاثةِ — ⚠️ **لا نقطةَ ثانية ولا `TeacherRated`**: `ReviewSubmitted` مشحونٌ ومربوطٌ بـ`QueueTrustScoreRecalculation`
- [ ] T129 [US4] أضف مسارَ البلاغِ عن تقييمٍ إلى مسارِ الإشرافِ القائم — `FR-034`
- [ ] T130 [P] [US4] أنشئ شاشتَي `frontend/src/app/(app)/(shell)/manage/students/[uuid]/reviews/page.tsx` و`report`-side للطالب، **مع روابطِها الداخلة**
- [ ] T131 [P] [US4] أضف حالةَ `periodic_reviews` إلى `WorkspaceIsolationTest` ومفاتيحَ الحقولِ إلى `backend/lang/ar/validation.php`

**Checkpoint**: `US4` قابلةٌ للتسليم؛ درجةُ الثقةِ **تُغذّى ولا تُعاد**.

---

## Phase 7: User Story 5 — كشف التقديرات التراكمي (Priority: P5)

**Goal**: كشفٌ واحدٌ للطالبِ عبرَ كلِّ مدرّسيه، مقاطعُهم منفصلة، أرقامُه تطابق المصدرَ **بفارقِ
صفر**، وملفٌّ عربيٌّ سليمُ الاتّصالِ يُحفَظ ويُطبَع.

**Independent Test**: طالبٌ عند مدرّسَين في مساحتَين، وتاريخٌ معروفٌ من الدرجاتِ والحضورِ
والتقييمات — قارِن الكشفَ بالمصدر، ثمّ **افتح الملفَّ بعينِك**.

### Tests for User Story 5

- [ ] T132 [P] [US5] `backend/tests/Feature/Community/ReportCardFidelityTest.php` — `SC-012`/`SC-018` بفارقِ صفر. ⚠️ **بمساحتَي عملٍ ومدرّسَين**: كشفٌ بمقطعٍ واحدٍ يبدو صحيحاً تماماً على تثبيتةٍ بمساحةٍ واحدة. ويشمل **مكوّناً بلا بيانات** (`FR-053`) و**محاولةً تدريبيّة** (`FR-038`)
- [ ] T133 [P] [US5] `backend/tests/Feature/Community/GradingSchemeTest.php` — `SC-017`: صفرُ تركيبةٍ محفوظةٍ لا يبلغ مجموعُها ١٠٠، والفرضُ **في الفعلِ** لا في التحقّقِ وحدَه
- [ ] T134 [P] [US5] `backend/tests/Feature/Community/ReportCardSnapshotTest.php` — `FR-052`: تغييرُ الأوزانِ **لا يعيد حسابَ** كشفٍ نُشر
- [ ] T135 [P] [US5] وسّع `backend/tests/Feature/Notifications/PlatformOwnershipTest.php` بحالتَي `report_cards` — `NFR-001ب` **بالاتّجاهَين**: الكشفُ واحدٌ عبرَ كلِّ المدرّسين، ومدرّسٌ لا يرى كشفَ غيرِ المسجَّلِ عنده

### Implementation for User Story 5

- [ ] T136 [US5] هجرة `2026_08_22_000600_create_grading_schemes_table.php`: `workspace_id` · `course_id` (nullable) · `period_start`/`period_end` · `weights` (json) · فريدٌ رباعيّ — ⚠️ **الفترةُ تاريخان لا نصّ**: الإصدارُ الأوّلُ كتب `period_label` بينما الكشفُ يحمل تاريخَين، فلم يكن للبناءِ طريقٌ مُعرَّفٌ لاختيارِ التركيبة
- [ ] T137 [US5] هجرة `2026_08_22_000610_create_report_cards_table.php`: `student_user_id` · `period_start`/`period_end` · `generated_at` · `overall_pct` · `improvement_index` · `published_at` · `unique(student_user_id, period_start, period_end)` — ⚠️ **لا `workspace_id`**: منصّيٌّ (أ) بنصِّ الدستور. **ولا عمودَ `file_path`**: الملفُّ عبرَ medialibrary، فعمودٌ خامٌّ يتجاوز كنسَ الاحتفاظِ وأرضيّةَ `FR-036` من ٠١٣ — ملفُّ PDF بدرجاتِ قاصرٍ لا يحذفه شيءٌ أبداً
- [ ] T138 [US5] هجرة `2026_08_22_000620_create_report_card_segments_table.php`: `report_card_id` · `workspace_id` · `teacher_user_id` · **`student_user_id`** · `components` (json) · `attendance_pct` · `segment_pct` · `index(report_card_id)` — ⚠️ التكرارُ عمداً: الجسرُ «يحمل `workspace_id` للسياقِ **ويشير إلى المستخدمِ العامّ**»، وبدونه ليس جسراً بل جدولَ تفصيلٍ منصّيٍّ بلا مالك
- [ ] T139 [P] [US5] أنشئ `Models/{GradingScheme,ReportCard,ReportCardSegment}.php` — و`ReportCard` يُنفّذ `HasMedia`
- [ ] T140 [US5] أنشئ `Actions/SaveGradingScheme.php` — **المجموعُ ١٠٠ يُفرَض في الفعل** لا في `FormRequest` وحدَه: الفعلُ هو المدخلُ الذي تشترك فيه البذورُ واللوحةُ والـAPI
- [ ] T141 [US5] أنشئ `Support/GradeWeighting.php` — ⚠️ **`FR-053`**: مكوّنٌ بلا بياناتٍ **يُستبعَد وتُعاد الموازنة**، لا يُحتسَب صفراً. طالبٌ لم يُسند إليه واجبٌ ليس طالباً درجتُه صفر — قرارُ `wrong_pct = NULL` نفسُه
- [ ] T142 [US5] أنشئ `Jobs/BuildReportCardsJob.php` **منصّيّاً مجدولاً** لفترةٍ واحدة — يعمل خارجَ كلِّ مساحةٍ ويدخل كلَّ واحدةٍ بـ**`forWorkspace()`** لمقطعِها. ⚠️ **`WorkspaceContext::set()` ممنوعٌ هنا** (`NFR-012`)، ولا `POST /manage/report-cards`: مدرّسٌ يضغط «أنشئ» إمّا يقرأ درجاتِ زميلِه أو يُنتج مقطعاً واحداً يُعرَض كسجلِّ الطالب
- [ ] T143 [US5] في `BuildReportCardsJob`: الإدراجُ `insertOrIgnore` بـ`uuid` و`created_at` **صراحةً** ثمّ **قراءةٌ راجعةٌ ترمي عند الصفر** — نمطُ `CreditLedger::writeEntry()`: النموذجُ لا يُقلَع فلا يعمل `HasUuid`، وMySQL تخفّض الخرقَ إلى تحذيرٍ وتخزّن `''` فيصطدم كلُّ كشفٍ لاحقٍ على `unique(uuid)` ويُقرأ «مسجَّلٌ سلفاً»
- [ ] T144 [US5] احسب المجاميعَ **داخلَ مطالبةِ النشرِ وحدَها** لا عند كتابةِ كلِّ مقطع — حسابٌ متداخلٌ بين مقطعَين يُنتج مجموعاً لا يطابق أيَّ مجموعةِ مقاطع، ونهائياً
- [ ] T145 [US5] صفِّ محاولاتِ `is_practice` من الدرجاتِ الرسميّة — `FR-038`، العمودُ نفسُه الذي استثناه رولَبُ ٠٠٨
- [ ] T146 [US5] أنشئ `Jobs/RenderReportCardJob.php` بـmPDF على طابور `community` — `SetDirectionality('rtl')` · `autoScriptToLang` + `autoArabic` + `autoLangToFont` · الخطُّ بـ`fontDir` + `fontdata` (**المفتاحُ بأحرفٍ صغيرةٍ حصراً**)
- [ ] T147 [US5] اشحن خطَّ **Cairo** (`.ttf`، OFL) تحت `backend/resources/fonts/` — الواجهةُ تحمّله من Google Fonts وهذا **لا ينفع خادماً بلا متصفّح**
- [ ] T148 [US5] خزّن الملفَّ عبرَ medialibrary على `ReportCard` — لا عمودَ مسار
- [ ] T149 [US5] أنشئ `Http/Controllers/ReportCardController.php`: `GET /report-cards` · `/{card}` · **`/{card}/download` → `302` إلى توقيعٍ قصيرِ العمرِ مربوطٍ بالطالب** بـ`throttle:report-card-render`؛ و`GET /manage/report-cards` **يُرجع مقطعَ المدرّسِ وحدَه**
- [ ] T150 [US5] أنشئ `Policies/ReportCardPolicy.php` — الطالبُ ووليُّ أمرِه **المرتبطُ** (`FR-040`)، والمعرّفُ يُحلُّ داخلَ الفعلِ لا بارتباطٍ ضمنيّ
- [ ] T151 [P] [US5] أنشئ `Http/Resources/{ReportCardResource,ReportCardSegmentResource}.php` و`Data/GradingSchemeData.php`
- [ ] T152 [P] [US5] أنشئ `frontend/src/app/(app)/(shell)/report-cards/page.tsx` + `[uuid]/page.tsx` و`manage/grading-schemes/page.tsx` **مع روابطِها**
- [ ] T153 [P] [US5] `frontend/src/components/community/GradingSchemeForm.test.tsx` بـvitest — المجموعُ ١٠٠ يُمنع حفظُه دونَه، و**حالةُ الفراغِ المفهومةُ** لطالبٍ بلا درجاتٍ بعد
- [ ] T154 [US5] أضف بندَ **«افتح الملفَّ بعينِك»** إلى `quickstart.md` §ج-١ إن لم يكن — ⚠️ `SC-013` **لا يُقاس باستخراجِ النصّ**: الاستخراجُ يقيس التضمينَ والترميزَ ويمرُّ على مستندٍ حروفُه منفصلةٌ معكوسة

**Checkpoint**: خمسُ قصصٍ تعمل.

---

## Phase 8: User Story 6 — الإعلانات والتعميمات (Priority: P6)

**Goal**: إعلانٌ واحدٌ يصل ٣٠٠ طالبٍ داخلَ نطاقِه وصفراً خارجَه، بلا ردٍّ جماعيّ، وبعدّادَين
يطابقان.

**Independent Test**: انشر على كورسٍ من كورسَين ← يصل طلابَه وحدَهم، والعدّادُ يطابق؛ اضغط
مرّتَين ← تفريعٌ **واحد**.

### Tests for User Story 6

- [ ] T155 [P] [US6] `backend/tests/Feature/Community/AnnouncementScopeTest.php` — `SC-016` **على كورسَين**، ⚠️ **وبلا `Queue::fake()` عارٍ**: التفريعُ مطبورٌ فالفَركُ العاري يبتلعه ويصير التوكيدُ جملةً واثقةً عن جدولٍ فارغ. تُفاك وظائفُ الخطِّ الزمنيِّ بالاسمِ وحدَها
- [ ] T156 [P] [US6] `backend/tests/Feature/Community/AnnouncementIdempotencyTest.php` — ضغطتان = تفريعٌ واحد، **وعاملٌ قُتل في المنتصفِ لا يُبلّغ أحداً مرّتَين**
- [ ] T157 [P] [US6] `backend/tests/Feature/Community/AnnouncementStatsTest.php` — `FR-046`: العدّادان يُقرآن بالعمودِ المفهرَس، **وحذفُ إشعارٍ قديمٍ يخفض «من أُبلغوا» ولا يكذب**

### Implementation for User Story 6

- [ ] T158 [US6] هجرة `2026_08_22_000700_create_announcements_table.php`: `workspace_id` · `author_user_id` · `scope` (`all`·`course`·`session`) · `scope_id` · `body` · `is_urgent` · `published_at` · `hidden_at` · `index(workspace_id, published_at)` — **«المجموعات» خارجَ النطاقِ مُعلَناً** (ق-٥/ت-٣)، **ولا مرفقات** (ت-٤): تمرّ بـ`RequestUploadTicket` القائمِ متى طُلبت
- [ ] T159 [US6] هجرة `backend/app/Modules/Notifications/Database/Migrations/2026_08_22_000710_add_source_key_to_notifications.php`: `source_type` (string 64) · `source_id` · `index(source_type, source_id, read_at)` — ⚠️ **`FR-046` يمنع تخزينَ العدد، لا مفتاحاً قابلاً للفهرسة**: بدونه يصير العدُّ `JSON_EXTRACT(payload, …)` — دالّةٌ تلتهم أيَّ فهرس، على **أسرعِ جداولِ المنصّةِ نموّاً**، **في قائمة** فمسحٌ كاملٌ لكلِّ إعلان
- [ ] T160 [P] [US6] أضف نوعَي `announcement` و`announcement_urgent` إلى `NotificationType` والثاني `isMandatory()` **وصفَّيهما في `NotificationTemplateSeeder`** — ⚠️ **ولا يدخلان `targetsGuardians()`**: تفريعُهما إلى الأوصياء رسالةُ واتسابٍ مدفوعةٌ لكلِّ وليِّ أمرٍ عن كلِّ تغييرِ موعد، وهو الطريقُ الذي يُكتَم به الإشعارُ كلُّه **فيسقط معه تنبيهُ الحضور**
- [ ] T161 [P] [US6] أنشئ `Models/Announcement.php` ومصنعَه
- [ ] T162 [US6] أنشئ `Actions/PublishAnnouncement.php` + `Events/AnnouncementPublished.php` — **مطالبةٌ شرطيّةٌ على `published_at`** والحدثُ **للمطالِبِ وحدَه**: ضغطتان تُفرّعان الإعلانَ مرّتَين على ثلاثِمئةِ طالب
- [ ] T163 [US6] أنشئ `Jobs/FanOutAnnouncementJob.php` **مُقطَّعاً بالكورسِ ومُعامَداً لكلِّ مستلِم** — ⚠️ `DispatchNotification` يُصدر ~٦–٨ استعلاماتٍ لكلِّ مستلِمٍ (`TemplateRenderer` بلا حفظٍ مؤقّت)، فثلاثُمئةِ طالبٍ ≈ ٢٤٠٠ استعلامٍ في وظيفةٍ مهلتُها ٦٠ ثانيةً و`tries: 1`؛ وعاملٌ قُتل في المنتصفِ إمّا يُبلّغ الجميعَ مرّتَين أو يترك تسليماً جزئياً صامتاً — و`SC-016` يفشل في الاتّجاهَين
- [ ] T164 [US6] أنشئ `Actions/ReadAnnouncementStats.php` — العدُّ بالعمودِ المفهرَس، **ولا تخزينَ للعدد**
- [ ] T165 [US6] أنشئ `Actions/{UpdateAnnouncement,HideAnnouncement}.php` — `FR-047`: ينعكس على المستلمين ويُسجَّل
- [ ] T166 [US6] أنشئ `Http/Controllers/Manage/AnnouncementController.php` وسجّل المساراتِ بـ`throttle:announcement-publish` — **ولا مسارَ ردٍّ جماعيّ** (`FR-045`): الردودُ تذهب إلى المحادثةِ الخاصّة
- [ ] T167 [P] [US6] أنشئ `Http/Resources/AnnouncementResource.php` · `Data/AnnouncementData.php` · `Policies/AnnouncementPolicy.php` (‏المدرّسُ **ومن فُوِّض**)
- [ ] T168 [P] [US6] أنشئ `frontend/src/app/(app)/(shell)/manage/announcements/page.tsx` **مع رابطِه** ومع عرضِ العدّادَين
- [ ] T169 [P] [US6] `frontend/src/components/community/AnnouncementForm.test.tsx` بـvitest — النطاقُ الثلاثيُّ، وحالةُ «عاجل» مُعلَنةُ الأثر
- [ ] T170 [P] [US6] أضف حالةَ `announcements` إلى `WorkspaceIsolationTest`
- [ ] T171 [P] [US6] أضف مفاتيحَ حقولِ الإعلانِ إلى `backend/lang/ar/validation.php`
- [ ] T172 [US6] تحقّق أن الإعلانَ العاجلَ يتجاوز التجميعَ ونافذةَ الهدوءِ عبرَ `isMandatory()` القائمةِ — `FR-044`، بلا مسارٍ ثانٍ

**Checkpoint**: القصصُ الستُّ كلُّها تعمل.

---

## Phase 9: Polish & Cross-Cutting Concerns

- [ ] T173 أنشئ `backend/app/Modules/Community/Support/CommunityPersonalData.php` يُنفّذ `App\Shared\Contracts\PersonalDataOwner` وسِمْه بـ`compliance.personal_data` — ⚠️ **بلا تنفيذٍ موسومٍ يُرجع السجلُّ `null` ويمضي الكنس**: طلبُ محوٍ يكتمل **أخضرَ** تاركاً كلَّ رسالةٍ عن قاصرٍ في مكانها
- [ ] T174 أضف صفوفَ الفئاتِ إلى `backend/database/seeders/DataCategorySeeder.php` — ⚠️ **الصنفُ `Delete` مُعلَناً** لـ`messages` و`periodic_reviews`: `sender_user_id` و`student_user_id` كلاهما `NOT NULL`، و`Anonymise` تحتاج `->change()` يُعيد بناءَ الجدولِ على SQLite (‏سابقةُ `exam_attempts`)
- [ ] T175 أضف علامةَ أرشفةٍ لملفِّ الكشفِ تحت الصنفِ `Archive` — ⚠️ بلا علامةٍ تُعاد أرشفتُه كلَّ ليلةٍ ويُحذَف الملفُّ عند المزوّدِ مرّةً بعد مرّة (‏سابقةُ `media_assets.archived_at`)
- [ ] T176 أضف المحادثاتِ الخاصّةَ إلى مشيةِ خروجِ المدرّسِ (`FR-037` من ٠١٣) — ⚠️ **لا تعرفها المشيةُ اليوم**: مدرّسٌ يغادر ورسائلُه الخاصّةُ مع قاصرين في لا مسار
- [ ] T177 `backend/tests/Feature/Community/CommunityRetentionTest.php` — رسائلُ ومحادثاتٌ وتقييماتٌ وملفُّ كشفٍ تُصدَّر وتُمحى وتنتهي مدّتُها؛ **والتثبيتةُ تشمل صنفَ `Archive`** لأن `Delete` لا تُظهر عطلَ التعامدِ إطلاقاً (‏الصفُّ المحذوفُ لا يعود)
- [ ] T178 [P] أنشئ `Support/CommunityAuditSubjects.php` إن كُتب شيءٌ إلى `activity_log` من هذه الوحدة — الجدولُ مشترَك، والمرشِّحُ **قائمةُ أصنافٍ** لا حذفُ صفوفٍ بعد الجلب
- [ ] T179 [P] وسّع `backend/tests/Feature/Settlement/ContextIsolationTest.php` بمسحِ `Modules/Community/` — الحائطُ الماليُّ يجب أن يُحرَس مفرداتياً كما يُحرَس سلوكياً
- [ ] T180 [P] حدّث `docs/README.md`: صفُّ وحدةِ `Community` · النقاطُ · الصلاحيّاتُ الجديدة · معنى «بنودُ المساعدِ صلاحيّاتٌ من شاشةِ الأدوار»
- [ ] T181 [P] حدّث `docs/erd.md` بالكياناتِ العشرةِ والتوسيعاتِ الثلاثة **مع طبقةِ كلٍّ منها**
- [ ] T182 [P] حدّث `docs/deployment.md`: عمليّةُ `reverb:start` **الدائمةُ الثالثة**، ومشرفُ طابورِ `community`، ومتغيّراتُ البيئة
- [ ] T183 [P] حدّث `docs/roadmap.md` بعلامةِ ٠١٠ ✅ وبما خرج من النطاقِ مُعلَناً (‏المجموعاتُ · مرفقاتُ الإعلان)
- [ ] T184 [P] أضف إلى `CLAUDE.md` و`AGENTS.md` القواعدَ التي لا تُستنتَج من الكود: الحائطُ عند الفحصِ لا على الدور · `hidden_at` لا `deleted_at` · المعرّفُ على القناةِ سببُه مزدوج · `ReadRanksFor` يقرأ أسبوعاً بعينِه
- [ ] T185 شغّل البوّاباتِ الأربعَ خضراء: `php vendor/bin/pest` · `./vendor/bin/pint --test` · `./vendor/bin/phpstan analyse` · `npx tsc --noEmit` · `npm test` — `SC-019`، **بلا `@phpstan-ignore` وبلا baseline جديد**
- [ ] T186 امشِ `quickstart.md` §ب يدوياً بالاثنتَي عشرةَ خطوة — بما فيها **إيقافُ `reverb`** وإعادةُ لصقِ معرّفِ محادثةٍ ليست للقارئ
- [ ] T187 سجّل نتائجَ `quickstart.md` §ج الخمسِ في جدولِ **Deferred Verification** بتواريخِها — بندٌ يبقى «مُعلَّقاً» بلا سببٍ مكتوبٍ هو بندٌ سقط
- [ ] T188 راجع أن كلَّ شاشةٍ جديدةٍ لها **رابطٌ داخل** من قائمةٍ أو صفحةٍ قائمة — ستُّ شاشاتٍ في هذه المرحلة
- [ ] T189 راجع أن كلَّ خطأٍ يمرُّ بـ`fieldErrors()` أو `userMessage()` — **ولا خطأٍ خامٍّ ولا `.catch(() => undefined)`**
- [ ] T190 حدّث `specs/010-community-assistants/tasks.md` بعلامةِ الإتمامِ وسجّل أيَّ انحرافٍ عن الخطّةِ في `plan.md` بدل تركِه في رسالةِ التزام

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: بلا تبعيّة — يبدأ فوراً. ⚠️ `T011`–`T013` معاً في التزامٍ واحد: نقلُ الصلاحيّةِ بلا نقلِ السياستَين يترك مبلغاً مكشوفاً
- **Foundational (Phase 2)**: بعد Setup — **يحجب كلَّ القصص**
- **US1 (Phase 3)**: بعد Foundational — بلا تبعيّةٍ على قصّةٍ أخرى
- **US2 (Phase 4)**: بعد Foundational؛ تستهلك `AssistantScopeDirectory` من `US1` للتفويضِ على المحادثةِ الخاصّة — قابلةٌ للتسليمِ بمدرّسٍ بلا مساعدٍ إن سُلّمت وحدَها
- **US3 (Phase 5)**: بعد **`US2`** — بنيةُ البثِّ والرسائلِ شرطٌ مسبق (‏بنصِّ المواصفة)
- **US4 · US5 · US6 (Phases 6–8)**: بعد Foundational، **متوازيةٌ فيما بينها**؛ `US5` تقرأ `US4` (‏التقييماتُ الدوريّةُ مكوّنٌ في الكشف) فتُسلَّم بعدها أو بمكوّنٍ فارغٍ مُعلَن
- **Polish (Phase 9)**: بعد كلِّ ما يُراد تسليمُه

### Within Each Story

الاختباراتُ أوّلاً وتفشل · الهجراتُ → النماذجُ → الأفعالُ → النقاطُ → الواجهة · الرابطُ الداخلُ
جزءٌ من الإتمامِ لا بعده.

### Parallel Opportunities

- `T005`–`T010` · `T014`–`T017` معاً
- `T020`–`T025` معاً
- كلُّ اختباراتِ قصّةٍ واحدةٍ معاً (`T027`–`T031` · `T055`–`T059` · `T087`–`T091` · `T113`–`T115` · `T132`–`T135` · `T155`–`T157`)
- `US4` و`US6` بمطوِّرَين مختلفَين بلا تلامس
- كلُّ مهامِّ التوثيقِ `T180`–`T184` معاً

---

## Parallel Example: User Story 1

```bash
# الاختباراتُ الخمسةُ معاً — ملفّاتٌ مختلفة، وكلُّها تفشل قبل T032
Task: "AssistantFinancialWallTest في backend/tests/Feature/Community/"
Task: "PanelFinancialWallTest في backend/tests/Feature/Community/"
Task: "AssistantScopeTest في backend/tests/Feature/Community/"
Task: "AssistantRevocationTest في backend/tests/Feature/Community/"
Task: "AssistantAttributionTest في backend/tests/Feature/Community/"
```

---

## Implementation Strategy

### MVP — `US1` وحدَها

`T001`–`T054`. مساعدٌ يعمل، مقيَّدٌ بنطاقِه، **لا يرى مالاً من الـAPI ولا من `/admin`** — وهي
الفجوةُ التي تصفها الوثيقةُ بالأهمِّ على الإطلاق، وشرطُ قبولِ المرحلةِ كلِّها.

⚠️ **ولا تُسلَّم قبل `T054`**: حذفُ الحارسِ وإعادةُ التشغيلِ هو الشيءُ الوحيدُ الذي يميّز حائطاً
قائماً عن تسعِ حالاتٍ خضراءَ لسببٍ آخر.

### Incremental Delivery

1. Setup + Foundational → الوحدةُ مكتشَفةٌ والتعيينُ مقروء
2. `US1` → **MVP**
3. `US2` → قناةُ المتابعةِ الأساسيّة
4. `US3` → الوجهُ الاجتماعيّ
5. `US4` · `US6` → متوازيتان
6. `US5` → الوثيقةُ التي تجمع كلَّ ما سبق
7. Polish → ٠١٣ والتوثيقُ والبوّابات

---

## Notes

- `[P]` = ملفّاتٌ مختلفةٌ بلا تبعيّة
- كلُّ ملفٍّ جديدٍ `declare(strict_types=1);`، وكلُّ DTO يرث `DataTransferObject`، وكلُّ نموذجٍ `HasUuid`
- **صلاحيّةٌ لا تُقرأ من ثابتٍ في `Tenancy\Support\Permissions` مرفوضةٌ في المراجعة**
- التزم بعد كلِّ مهمّةٍ أو مجموعةٍ منطقيّة، وسجّل سببَ كلِّ انحرافٍ في `plan.md` لا في رسالةِ الالتزام
