# Implementation Plan: المجتمع والمساعدون والتقييم المتبادل

**Branch**: `010-community-assistants` | **Date**: 2026-08-22 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/010-community-assistants/spec.md`

> **الإصدارُ الثاني** — أُعيدت كتابةُ هذا الملفِّ بعد مراجعةِ خمسةِ وكلاءَ (‏التعارضات ·
> المعمار · N+1 · الحماية · التزامن) ردّت نحوَ مئةِ نتيجة. الإصدارُ الأوّلُ محفوظٌ في
> `a898fbe`. ما تغيّر جوهرياً مُدرَجٌ في [ما سقط من الإصدار الأوّل](#ما-سقط-من-الإصدار-الأول).

## Summary

ستُّ قصصٍ تتقاطع عند سؤالٍ واحد: **كيف يشتغل مدرّسٌ بمئات الطلاب؟** فريقٌ مُقيَّدٌ بنطاقه،
وقناتان للحديث، وقناةُ واحد-إلى-كثير، وتقييمٌ متبادل، وكشفُ تقديراتٍ بأوزان.

**المدخلُ التقنيُّ الجديدُ الوحيد** هو البثُّ اللحظيّ، ويدخل **بلا تجريدٍ من عندنا**: لارافيل
تملك طبقةَ سائقين للبثّ، و`reverb` سائقٌ فيها. وقاعدةُ البياناتِ هي المصدر — الرسالةُ تُكتب
وتُقرأ منها والمقبسُ مُسرِّعٌ لا غير، وإلا فـ`SC-015` غيرُ قابلٍ للتنفيذ.

**والأثقلُ في هذه المرحلةِ ليس ميزةً بل حائط**: `FR-003` يمنع المساعدَ من كلِّ بياناتٍ ماليّة،
وهو شرطُ قبولِ المرحلةِ كلِّها بنصِّ الوثيقة. وقد وُجد **مكسوراً قبل أن تبدأ** — أُصلح على
`master` في `981ca23`، ويبقى الحائطُ نفسُه أن يُبنى.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript (Next.js 15 App Router)

**Primary Dependencies**: القائمُ كما هو + `laravel/reverb` (‏سائقُ بثٍّ لطبقةِ لارافيل) و
`mpdf/mpdf` (‏توليدُ الكشف). الواجهةُ تُضيف `laravel-echo` و`pusher-js`.

**Storage**: MySQL 8 (‏SQLite محلّياً) — الرسائلُ في القاعدةِ حصراً

**Testing**: Pest · vitest للمكوّنات · Playwright لما لا يُقاس دونه

**Target Platform**: خادمُ لينكس + Horizon + عمليةُ `reverb:start` دائمة

**Performance Goals**: الرسالةُ خلال **٢ ثانية** p95 (`SC-006`) · شاتٌ فيه ١٠٬٠٠٠ رسالةٍ يعود
بالأحدثِ خلال **٥٠٠ مللي** p95 **بعددِ استعلاماتٍ ثابت** (`SC-009`)

**Constraints**: انقطاعُ البثِّ لا يعطّل شيئاً (`SC-015`) · صفر وصولٍ ماليٍّ للمساعد
(`SC-001`) · أرقامُ الكشفِ تطابق المصدرَ بفارقِ صفر (`SC-012`)

**Scale/Scope**: الرسائلُ بالآلافِ لكلّ محادثة · مدرّسٌ بمئاتِ الطلاب · ٦ قصص · ٥٣ متطلَّباً

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| المبدأ | الحالة | كيف |
|---|---|---|
| **I — الطبقاتُ الأربع** | ✅ | الدستورُ **v1.2.0** (‏لا v1.1.0 كما تقول `spec.md:288`) يقسم المملوكَ للمنصّةِ صنفَين. التصنيفُ في [`data-model.md`](./data-model.md) لكلِّ كيان. `report_cards` منصّيٌّ (أ) بلا `workspace_id` |
| **I — حارسُ رؤيةِ المدرّس** | ✅ | كلُّ فعلٍ يسمّي طالباً يسأل `EnrollmentDirectory` أوّلاً — ولا مسارَ يربط نموذجاً ضمنياً على صفٍّ يبلغه طالب |
| **II — المنطق في `Actions/`** | ✅ | ⚠️ **والمعرّفُ يُحلّ داخلَ الفعلِ بعد الفحص** — نمطُ `RedeemReward` من ٠٠٩ |
| **III — التكاملُ بالأحداث** | ✅ | `Community` لا تكتب في جدولِ وحدةٍ أخرى، ولا تستدعي فعلَها. المستمعُ في مزوّدِ **المشترِكة** |
| **IV — البوّاباتُ الأربع** | ✅ | بلا `@phpstan-ignore` وبلا baseline |
| **V — التفويضُ بالثوابت** | ✅ | ⚠️ **بنودُ المساعدِ صلاحيّاتٌ في `Permissions`** كما يوجب `NFR-004` — لا صنفٌ في وحدةٍ أخرى |
| **VI — العقودُ الظاهرة** | ✅ | ⚠️ **والمؤشِّرُ `uuid` لا `id`**: الإصدارُ الأوّلُ كتب `?before={id}` وهو كشفُ معرّفٍ تسلسليّ |
| **تبريرُ التعقيد** | ⚠️ | تبعيّتان — [Complexity Tracking](#complexity-tracking) |

## القراراتُ التي تغيّر النطاق

خمسةٌ، كلُّها **تقليصٌ أو تصحيحٌ** يفرضه كودٌ مشحونٌ أو متطلَّبٌ قائم — لا توسيع.

### ق-١ · بنودُ المساعدِ صلاحيّاتٌ قائمة، لا عمودُ JSON

`RolePermissionMatrix` يقول بنصِّه، في تعليقٍ مكتوبٍ لهذه المرحلة: «**السبعةُ كلُّها على
`$teacher` ولا شيءَ منها على `$assistantTeacher`، وهذا الوضعُ نفسُه هو قناةُ التسليم: المصفوفةُ
تزرع افتراضاً، وشاشةُ الأدوارِ تدع المالكَ يضع أيّاً منها على دورِ مساعدٍ مخصَّص**». وأربعةٌ
من بنودِ `FR-002` الخمسةِ ثوابتُ قائمةٌ بالفعل — `GRADING_PERFORM`/`SUBMISSIONS_GRADE` ·
`ATTENDANCE_OVERRIDE` · `LESSONS_MANAGE`/`CMS_CREATE` · `BILLING_BALANCE_VIEW` — والخامسُ
وحدَه جديد: `CHAT_REPLY`. و`NFR-004` يوجب أن تكون في `Tenancy\Support\Permissions` حصراً.

فعمودُ `abilities` في الإصدارِ الأوّلِ كان **نظامَ صلاحيّاتٍ ثانياً** بجانبِ spatie، ويعني
أن كلَّ `$this->authorize()` قائمٍ في `Assessments` و`Courses` و`LiveSessions` يجيب «نعم» من
الدورِ بينما العمودُ يقول «لا» — وهو بالضبط عطلُ «تهجئتَين لسؤالٍ واحد» الذي تستشهد به هذه
الوثيقةُ نفسُها في موضعَين.

**ما يبقى ليُبنى** هو ما لا يوجد فعلاً: **النطاق** (`assistant_scopes`) والحائطُ الماليّ.

### ق-٢ · الحائطُ الماليُّ عند الفحص، لا على `Role`

`Role::refusePlatformPermissions()` يعتمد على `team_id === null` وحدَه، وكلُّ صلاحيّةِ مالٍ
يريدها مساعدٌ **مستأجرة** — فهي في قائمةِ شاشةِ الأدوارِ التي يملكها المالك. فالحارسُ على
`Role` عاجزٌ بنيوياً: المالكُ يصنع دوراً باسمٍ آخرَ ويضع فيه `payments.approve`.

الحارسُ الذي يصمد `Gate::before` مفتاحُه **صفُّ `assistant_assignments` الحيُّ في مساحةِ
العملِ الحاليّة**، يرفض المجموعةَ الماليّةَ عبرَ **كلِّ** دورٍ يحمله الشخص، ويُرجع **`null`
لا `false`** (‏`false` يقصر كلَّ سياسةٍ خلفه). نمطُ `PlatformStaffDirectory` حرفياً، بحفظٍ
مؤقّتٍ لكلِّ طلب.

**والمجموعةُ مُشتقّةٌ لا مكتوبةٌ بيد**: كلُّ صلاحيّةٍ تبدأ بـ`settlement.` أو `billing.` أو
`payments.` أو `orders.`، **ناقصَ** `billing.balance.view` وحدَها (‏ق-٣). قائمةٌ مكتوبةٌ بيدٍ
لها الخاصّيّةُ المعكوسة: صلاحيّةُ تسويةٍ تُضاف في مرحلةٍ قادمةٍ **ليست** ماليّةً حتى يتذكّرها
أحد — وهو الاشتقاقُ نفسُه الذي يجعل `platformPermissions()` صحيحةً بالبناء.

### ق-٣ · `BILLING_BALANCE_VIEW` يُنقَل إلى `$teacher`، ولا يُحذف

المصفوفاتُ مبنيّةٌ بالوراثة (`$teacher = array_merge($assistantTeacher, …)`)، فحذفُه يسلبه
المدرّسَ ومالكَ المساحةَ ويكسر أربعةَ مواضعَ حيّة. وأسوأُ: صلاحيّةٌ لا يحملها دورٌ مستأجرٌ
تصير **منصّيّةً بالاشتقاق**، فيرمي `Role` على أوّلِ بذرةٍ تمنحها.

⚠️ **وسياستان تركبانه اليومَ وتكشفان مبلغاً**: `CreditPurchasePolicy::view` و
`CreditTransactionPolicy::view` — وإيصالُ شراءٍ «دفعة» بنصِّ `FR-003`. تُنقَلان إلى
`ORDERS_VIEW_ALL`، فيبقى `billing.balance.view` ما يقوله اسمُه: **عددُ أرصدةٍ وحالةُ حجب**.

### ق-٤ · الكشفُ لا يُطلقه مدرّس

`report_cards` منصّيٌّ ويمتدُّ عبرَ مدرّسين. فمدرّسٌ يضغط «أنشئ» إمّا يقرأ درجاتِ زميلِه
(‏خرقُ `NFR-001أ`) أو يُنتج كشفاً بمقطعٍ واحدٍ يُعرَض على أنه سجلُّ الطالب — و`SC-012` يمرّ
أخضرَ على أيِّ تثبيتةٍ بمساحةِ عملٍ واحدة. ويتصادم مدرّسان على المفتاحِ الفريدِ بفترتَين
مختلفتَي النهاية.

فالكشفُ **وظيفةٌ مجدولةٌ على مستوى المنصّة** لفترةٍ قانونيّةٍ واحدة، تعمل خارجَ أيِّ مساحةِ
عملٍ وتدخل كلَّ واحدةٍ بـ`forWorkspace()` لمقطعِها. والطالبُ ووليُّ أمرِه يقرآن؛ والمدرّسُ
يرى **مقطعَه هو**.

### ق-٥ · «المجموعات» خارجُ النطاق، مُعلَناً

`FR-004` و`FR-042` يذكران «مجموعات»، ولا كيانَ لها في المستودعِ إطلاقاً. بناؤها يعني نموذجَ
تجميعٍ للطلابِ يمسّ ٠٠٥ و٠٠٦ و٠٠٨. النطاقُ في هذه المرحلةِ **الكورسُ والحصّةُ وكلُّ الطلاب**،
والمجموعاتُ تنتظر غرفَ المذاكرةِ في ٠١٢. تعديلٌ مُعلَنٌ لا إغفالٌ صامت.

## Project Structure

### Documentation (this feature)

```text
specs/010-community-assistants/
├── plan.md · research.md · data-model.md · quickstart.md
├── contracts/endpoints.md
└── tasks.md   # يُولَّد بـ/speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/Community/
├── Actions/            StartConversation · PostMessage · MarkHelpful · ModerateMessage
│                       AssignAssistant · SetAssistantScope · PublishAnnouncement
│                       SaveGradingScheme · SubmitPeriodicReview · PublishPeriodicReview
├── Data/               DTOs — كان ناقصاً من الإصدار الأوّل رغم NFR-005
├── Database/Migrations/
├── Enums/              ConversationKind · ModerationVerdict · TermPolicy
├── Events/             MessagePosted · HelpfulAnswerMarked · AnnouncementPublished
│                       PeriodicReviewPublished
├── Http/{Controllers,Requests,Resources}/
├── Jobs/               FanOutAnnouncementJob · BuildReportCardsJob · RenderReportCardJob
├── Listeners/
├── Models/
├── Policies/
├── Support/            EloquentAssistantScopeDirectory · TermFilter · GradeWeighting
│                       CommunityPersonalData · CommunityAuditSubjects
├── CommunityServiceProvider.php     # بدونه لا يكتشف ModulesServiceProvider شيئاً
└── routes/api.php

backend/app/Shared/Contracts/AssistantScopeDirectory.php   # اثنتا عشرةَ واجهةً هنا، لا في وحدة
backend/database/factories/Modules/Community/              # guessFactoryName يحلّها هنا
backend/routes/channels.php                                # جديد + withBroadcasting() في bootstrap
backend/config/horizon.php                                 # supervisor-community: defaults + environments + waits
backend/app/Modules/Notifications/Database/Migrations/     # source_type + source_id على notifications
backend/app/Modules/Marketplace/                           # reviews: ثلاثةُ محاور + فترة + بوّابةُ الحصص
backend/app/Modules/Tenancy/                               # CHAT_REPLY · نقلُ BILLING_BALANCE_VIEW · Gate::before

frontend/src/
├── app/(app)/(shell)/messages/ · manage/assistants/ · manage/announcements/ · report-cards/[uuid]/
├── components/community/
└── lib/echo.ts
```

**Structure Decision**: وحدةٌ جديدةٌ واحدة `Community`، وتوسيعاتٌ في `Marketplace` (‏التقييم)
و`Tenancy` (‏الصلاحيّاتُ والحائط) و`Notifications` (‏مفتاحُ المصدر). ⚠️ **والتقييمُ يبقى في
`Marketplace`**: الجدولُ والحدثُ (`ReviewSubmitted`) والمستمعُ (`QueueTrustScoreRecalculation`)
والنقطةُ (`POST /teachers/{uuid}/reviews`) كلُّها مشحونةٌ هناك، فكتابةُ `Community` فيها خرقٌ
للمبدأ الثالث ونقطةٌ ثانيةٌ لسؤالٍ واحد.

## Complexity Tracking

| التبعيّة | الحاجةُ القائمة | البديلُ المرفوض |
|---|---|---|
| `laravel/reverb` | `FR-011`/`SC-006`: ثانيتان بلا إعادةِ تحميل. لا بنيةَ بثٍّ في المشروع إطلاقاً | **الاستطلاعُ الدوريّ** — لبلوغِ ثانيتَين يلزم استطلاعٌ كلَّ ثانيتَين لكلِّ محادثةٍ مفتوحة: عشراتُ الآلافِ من الطلباتِ المُصادَقِ عليها في الدقيقةِ لمدرّسٍ بثلاثِمئةِ طالب. مرفوضٌ بالحجم. ومزوّدٌ مُدارٌ لم يُرفَض — هو سطرُ إعدادٍ متى لزم، وهذا سببُ عدمِ بناءِ تجريد |
| `mpdf/mpdf` | `FR-039`/`SC-013`: ملفٌّ على **الخادم** يُرفَق ويُرسَل لوليِّ أمرٍ لا يفتح المنصّة. ولا مولّدَ PDF في الشجرة | **طباعةُ المتصفّح** — صفرُ تبعيّةٍ وعربيّةٌ مضمونة، ورُفضت بقرارِ المستخدمِ لأنها لا تُنتج ملفاً يُرفَق. و`browsershot` يعني Node وChromium على خادمِ الإنتاج |

## ما سقط من الإصدار الأول

| ما كان | لماذا سقط |
|---|---|
| `assistant_assignments.abilities` (json) + `AssistantAbility` enum | نظامُ صلاحيّاتٍ ثانٍ — ق-١ |
| `POST /manage/assistants` يُنشئ مساعداً | `workspace_members` لا يكتبه إلا `AcceptInvitation` و`CreateWorkspace`. الدعوةُ والقبولُ مشحونان؛ الشاشةُ تركبهما |
| `POST /teachers/{teacher}/rating` | نقطةٌ ثانيةٌ فوق `POST /teachers/{uuid}/reviews` المشحونة — ق (المعمار) |
| `TeacherRated` | `ReviewSubmitted` قائمٌ ومربوطٌ بـ`QueueTrustScoreRecalculation` |
| `RevokeAssistantSessions` | الصلاحيّةُ تُقرأ لكلِّ طلب، فالسحبُ فوريٌّ بالبناء. وحذفُ الرمزِ يُخرج المساعدَ من مدرّسِه الآخر، ويُنتج `401` بينما `quickstart` يطلب `403` |
| `conversations.private_key` محسوب | `unique(workspace_id, student_user_id)` يكفي — NULL لا يصطدم بـNULL |
| `AssistantPayloadAllowlist` | حمولةُ التعييناتِ لا تحمل مالاً أصلاً؛ السؤالُ الحقيقيُّ رفضُ **المسار** |
| `messages.deleted_at` | الاسمُ يجتذب `SoftDeletes` ونطاقُه يحذف الأرشيفَ الذي يعد به `FR-015`. صار `hidden_at` |
| `?before={id}` | كشفُ معرّفٍ تسلسليّ — الدستورُ VI. صار `?before={uuid}` يُحلّ داخلَ الفعل |
| `DELETE /moderation/bans/{ban}` | `moderation_actions` سجلُّ تدقيق؛ الرفعُ صفٌّ جديدٌ لا حذف |

---

## ما انحرف عند التنفيذ

### المرحلةُ الأولى — الإعداد (`T001`–`T017`، ٢٠٢٦-٠٨-٢٢)

| ما قالته الخطّة | ما وجدَه الكود | القرار |
|---|---|---|
| مفتاحُ phpstan `scanDirectories` | المفتاحُ **`databaseMigrationsPath`** | نُفِّذ على الملفِّ لا على النصّ |
| «تحقّق بـ`route:list`» | ملفُّ مساراتٍ فارغٌ يُنتج قائمةً فارغةً سواءٌ حُمِّل أو لا — **تحقُّقٌ أجوفُ بالبناء** | التحقُّقُ بـ`getLoadedProviders()` |
| `composer require` مباشرةً | ‏`laravel/horizon` يشترط `ext-pcntl` وويندوز لا يملكه، فالحلُّ يفشل قبل أن يبدأ | `--ignore-platform-req=ext-pcntl` محلّياً؛ الإنتاجُ لينكس ويملكه |
| `withBroadcasting()` في `bootstrap/app.php` | لارافيل ١١+ تكتبها **`channels:` داخلَ `withRouting()`** | المُثبِّتُ فعلها بنفسِه |
| — | المُثبِّتُ **ألحق `BROADCAST_CONNECTION` ثانيةً** بـ`.env` فوقَ واحدةٍ قائمةٍ بقيمةٍ أخرى | أُزيلت المكرَّرة؛ و`.env.example` يبقى على `log` عمداً — القاعدةُ هي المصدرُ والمقبسُ مُسرِّع، فمستودعٌ لا يشغّل `reverb:start` يعمل كاملاً بلا تسليمٍ لحظيّ |
| «حدودُ الإرسال» صفوفٌ (research §R9) | خمسةَ عشرَ محدِّداً مشحوناً كلُّها حرفيّةٌ بجانبِ منطقِها | **سقفُ `chat-write` وحدَه صفّ** — وهو الرقمُ الذي تحرّكه موجةُ إزعاج؛ والأربعةُ الباقيةُ حرفيّة. انحرافٌ مُعلَنٌ لا إغفال |
| — | `phpunit.xml` **يثبّت `BROADCAST_CONNECTION=null` منذ ٠١٧** | لا تغيير: البثُّ لا يُطلَب في الاختبارات أصلاً |
| ثابتٌ جديدٌ في `Permissions` يكفي | **لا صفَّ له في أيِّ قاعدةٍ قائمة** — المصفوفةُ بذرةٌ تعمل مرّةً عند إنشاءِ المساحة | `T013أ`: هجرةُ منحٍ للأدوارِ القائمة، نمطُ ٠٠٦ و٠٠٨ |

⚠️ **و`T013أ` وجب قبل أن تُشحَن الميزةُ بيومٍ واحد، وهذا ما يجعله مختلفاً عن سابقتَيه.**
مفرداتُ شاشةِ الأدوارِ `tenantPermissions()` مُشتقّةٌ من **الكود** لا من الجدول، فمربّعُ «الردّ —
محادثات الطلاب» يُصيَّر في اللحظةِ التي يُنشَر فيها الثابت، بينما `permissions` لا يحمل الصفّ:
ضغطةٌ واحدةٌ ← `PermissionDoesNotExist` ← **خطأُ ٥٠٠ على شاشةِ إعداداتِ المالك، عن ميزةٍ لم
يُكتب منها سطرٌ بعد**. وهجرتا ٠٠٦ و٠٠٨ كانتا تُصلحان شاشةً **لا تفتح**؛ هذه تمنع شاشةً
**تنكسر**.

⚠️ **وما لم ينكسر يستحقُّ التسجيل أيضاً**: نقلُ `BILLING_BALANCE_VIEW` من `$assistantTeacher`
إلى `$teacher` مرَّ على **١٧٦٣** اختباراً بلا تعديلِ تثبيتةٍ واحدة — لأن **لا اختبارَ في الشجرةِ
كان يُثبِّت مساعداً يقرأ رصيداً**. الغيابُ هو ما جعل النقلَ رخيصاً، وهو نفسُه ما جعل المنحَ
غيرَ ملحوظٍ منذ ٠٠٦: بندٌ ماليٌّ على دورِ المساعدِ بلا حالةٍ واحدةٍ تسأل عنه.

---

## Post-Design Constitution Re-check

- **`report_cards` منصّيٌّ بلا `workspace_id`**، ويُبنى بوظيفةٍ مجدولةٍ تدخل كلَّ مساحةٍ
  بـ`forWorkspace()` لمقطعِها (ق-٤) — فـ`NFR-012` صار قابلاً للتحقُّقِ بدل أن يكون متناقضاً.
- **`Community` تنضمّ إلى ٠١٣**: `CommunityPersonalData` موسومةٌ بـ`compliance.personal_data`،
  وصفوفٌ في `DataCategorySeeder`. بدونها رسائلُ قاصرٍ وتقييماتٌ عنه وملفُّ كشفٍ **بلا مدّةِ
  احتفاظٍ ولا تصديرٍ ولا محو** — بصمت، لأن السجلَّ يُرجع `null` ويمضي الكنس.
- **`supervisor-community` في `defaults` و`environments` و`waits`** — وإلا فوظائفُ التفريعِ
  والتصييرِ تُصَفُّ ولا تُصرَف أبداً، وهو الحادثُ الذي يستشهد به `quickstart` نفسُه.
- **لا حمولةَ على القناة، معرّفٌ فقط** — وهذا حِملٌ مزدوج: يحدُّ أثرَ خللِ التفويضِ (`NFR-008`)
  **ويجعل السحبَ ممكناً أصلاً**، إذ لا يملك البروتوكولُ إلغاءَ اشتراكٍ قائم.
