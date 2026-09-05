# Implementation Plan: الاشتراكُ من صفحةِ الكورس — مجموعةٌ أو حصصٌ خاصّة

**Branch**: `027-subscribe-group-or-private` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/027-subscribe-group-or-private/spec.md`

---

## Summary

المطلوبُ بابٌ يُشترَكُ منه من صفحةِ الكورس (مجموعةٌ أو حصصٌ خاصّة)، وطابورٌ يقرأُ فيه
الموظّفُ الطلبَ كاملاً، وقبولٌ يضعُ الطالبَ في مجموعتِه ويحجزُ له حصصَها ويُخبرُه.

**والنتيجةُ التي خرجَ بها البحث: لا جدولَ جديدَ ولا عمودَ جديدَ في هذه الميزةِ كلِّها.**
كلُّ ما تحتاجُه قائمٌ، ومعظمُ العملِ **وصلُ أطرافٍ مبنيّةٍ ولا يصلُ بينَها شيء**:

| ما تحتاجُه المواصفة | ما هو قائمٌ اليوم | الفجوة |
|---|---|---|
| نيّةُ الاشتراكِ على الطلب | `orders.metadata` — و`PurchaseSubscription` يكتبُ فيه `plan_uuid` بتعليقٍ يشرحُ لماذا لا عمود | مفاتيحُ جديدةٌ في الـJSON نفسِه |
| «مجموعةٌ أو برايفت» | `plans.session_type: individual\|group` | فلترةٌ وحارسُ تطابُق |
| «مدّةُ الاشتراك» | `plans.duration_days` | لقطةٌ على الطلب |
| اعتمادٌ برأيَينِ و٢FA | `OrderResource::approveAction()` / `rejectAction()` — **`public static`** | تُعادُ استخدامُها كما هي |
| تسجيلٌ عندَ القبول | `ActivateSubscription` يُسجّلُ فعلاً | يُضافُ وضعُه في المجموعة |
| مقعدٌ بلا خصمِ رصيد | `ChargeSessionSeats` + `SubscriptionEligibility` يكتبانِ صفراً | لا شيء |
| «لا مقعدَينِ لطالبٍ واحد» | `unique(class_session_id, student_user_id)` | لا شيء |
| «المقعدُ الملغى لا يُعادُ حجزُه» | الإلغاءُ **يغيّرُ الحالةَ ولا يحذفُ الصفّ**، فالفهرسُ الفريدُ يرفضُ العودة | لا شيء |
| رابطُ الغرفة | `/sessions/[uuid]/room` | يُضافُ إلى قائمةِ الوجهاتِ المسموحة |

**الطريقةُ التقنيّةُ باختصار**: تُوسَّعُ أربعةُ أفعالٍ قائمة (`PurchaseSubscription` ·
`ApproveOrder` · `ActivateSubscription` · `BookSeat`)، ويُضافُ مستمعٌ واحدٌ لحدثٍ قائمٍ
(`SessionScheduled`) ليبقى الحجزُ سلوكاً مستمرّاً لا كنسةً تُشغَّلُ مرّة. وفي طبقةِ العقود
**عقدٌ جديدٌ واحدٌ فقط** (`SubscriberDirectory`) ودالّتانِ تُضافانِ إلى عقدَينِ **قائمَين**
(`CohortDirectory::isJoinable()` · `CohortScheduleDirectory::nextSessionFor()`) — القياسُ
أظهرَ أنّ `app/Shared/Contracts/` يحملُ سبعةَ عشرَ عقداً منها اثنانِ للمجموعاتِ بالفعل. وفي
الواجهةِ شاشةُ اشتراكٍ واحدةٌ ودعواتٌ على صفحةِ الكورس، و`?next=` على الدخولِ والتسجيل.

**⚠️ وموضعُ إعادةِ التحقّقِ (FR-026) قرارٌ لا تفصيل**: فحصٌ تمهيديٌّ **داخلَ `ApproveOrder`
قبلَ الادّعاءِ الشرطيّ**، لا داخلَ المستمعِ المصفوفِ بعدَه — ذاك يعملُ بعدَ الالتزام، فرفضُه
يتركُ طلباً معتمَداً وطالباً خارجَ المجموعة، وهي الحالةُ التي كُتِبَ FR-026 لمنعِها. راجعْ
`research.md` §R6 و`data-model.md` §٣ب.

---

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) · TypeScript 5 (Next.js 15, App Router)

**Primary Dependencies**: Filament 5.7 (لوحةُ `/admin`) · spatie/permission (وضعُ الفرق) ·
Laravel Sanctum · Tailwind 4 · Vitest + Playwright

**Storage**: MySQL في الإنتاج · SQLite في التطويرِ والاختبار. **لا جدولَ جديدَ ولا عمودَ
جديد.** والهجراتُ ثلاثٌ ولا تُنشِئُ بياناً: هجرةُ ردمٍ لصفَّي قالبِ إشعارٍ (`seedMissing()`)،
وهجرةُ **ثلاثةِ فهارس** كشفَها قياسُ المسارات — `orders(kind, status, created_at)` (الطابورُ
المنصّيُّ يُسقِطُ العمودَ الأيسرَ من الفهرسَينِ المركَّبَين فيمسحُ الجدولَ مرّتَينِ لكلِّ
عرض)، و`class_sessions(cohort_id, starts_at)` (يخدمُ ثلاثةَ قرّاءٍ منها صفحةُ الكورسِ العامّةُ
**غيرُ المخزَّنةِ مؤقّتاً**)، و`subscriptions(workspace_id, status, effective_ends_on)`.

**Testing**: Pest (Feature أوّلاً) · Vitest للواجهة · Playwright للمسارِ الكامل

**Target Platform**: خادمُ Linux خلفَ nginx · متصفّحاتُ سطحِ المكتبِ والهاتف

**Project Type**: تطبيقُ ويبٍ — خلفيّةُ API معياريّةٌ + واجهةُ Next.js + لوحةُ Filament

**Performance Goals**: الطابورُ عندَ الموظّفِ **صفحةٌ واحدةٌ بميزانيّةِ استعلاماتٍ ثابتة** لا
تنمو بعددِ الصفوف (`whenLoaded` + تحميلٌ مسبَقٌ صريح). الحجزُ التلقائيُّ **مؤجَّلٌ إلى
الطابور**، فلا يدخلُ زمنَ استجابةِ ضغطةِ «اعتمد».

**Constraints**:
- المقعدُ يُدَّعى بـ`UPDATE … WHERE seats_taken < seats_total` وحدَه. **`lockForUpdate()`
  ممنوعٌ** — عديمُ الأثرِ على SQLite فيمرُّ الاختبارُ محليّاً ولا يُثبتُ شيئاً عن الإنتاج.
- `WorkspaceScope` عديمُ الأثرِ للطالبِ (عضوُ لا مساحة). كلُّ قراءةٍ على مسارِه تُقيَّدُ
  صراحةً، وكلُّ قراءةٍ منصّيّةٍ تُعلِنُ `withoutWorkspaceScope()` **وتكرّرُها في كلِّ
  تحميلٍ مسبَق**.
- لوحةُ `/admin` بالجلساتِ ولا تمرُّ بـ`2fa.required`؛ الحارسُ يُسألُ في الشاشة.
- `queue` مطلوبٌ فعلاً هنا (المستمعونَ مصفوفون)؛ `sync` يخفي ترتيبَ الأحداث.

**Scale/Scope**: ٣ أفعالٍ مُوسَّعة · ٢ مستمع/وظيفة · ١ عقدٌ مشترك · ١ جدولُ Filament على
صفحةٍ قائمة · ٢ نوعُ إشعارٍ جديد · ١ شاشةٌ أماميّةٌ جديدة · ٤ ملفّاتِ واجهةٍ مُعدَّلة.

---

## Constitution Check

*GATE: قبلَ المرحلةِ صفر، ويُعادُ بعدَ تصميمِ المرحلةِ الأولى.*

### قبلَ المرحلةِ صفر

| المبدأ | الحكم | التبرير |
|---|---|---|
| **I — عزلُ المستأجرين** | ✅ يمرّ | **لا كيانَ جديد**، فلا تصنيفَ جديداً مطلوباً. الكياناتُ الملموسةُ مصنَّفةٌ سلفاً: `Order` و`SessionBooking` و`Enrollment` و`CohortMembership` **جسور**؛ `Plan` و`ClassSession` و`Cohort` **مملوكةٌ لمساحةِ العمل**؛ `Subscription` **جسر**. طابورُ الموظّفِ قراءةٌ منصّيّةٌ تُعلِنُ `withoutWorkspaceScope()` وتكرّرُها في تحميلِ العلاقات. |
| **II — المنطقُ في الـActions** | ✅ يمرّ | لا منطقَ في صفحةِ Filament (تستدعي `ApproveOrder`/`RejectOrder` القائمَينِ حرفيّاً) ولا في المتحكّمات. الحجزُ التلقائيُّ فعلٌ (`ClaimSubscriptionSeats`) تستدعيه وظيفةٌ ومستمع. |
| **III — استقلالُ الوحدات** | ⚠️ يمرُّ بشرطٍ مكتوب | LiveSessions يحتاجُ حقيقةً من Payments. الحلُّ عقدٌ مشتركٌ ضيّقٌ (`App\Shared\Contracts\SubscriberDirectory`) على مثالِ `AccountStanding` و`EnrollmentDirectory` و`UnlockDirectory` التي يسألُها `BookingEligibility` اليوم — لا استيرادَ من وحدةٍ إلى أخرى. **راجعْ «Complexity Tracking».** |
| **IV — البوّاباتُ خضراء** | ✅ يمرّ | pest · pint · phpstan L8 · tsc. والمسارُ الحرجُ الثامنُ («اعتمادُ الدفع ← إنشاءُ التسجيل») **يتوسّعُ في هذه الميزة**، فاختباراتُه تُحدَّثُ لا تُستبدَل. |
| **V — التفويضُ بالسياسات** | ✅ يمرّ | لا إذنَ جديد. الطابورُ يسألُ `Permissions::BILLING_PURCHASE_APPROVE` القائم، والقرارُ يمرُّ بـ`OrderPolicy` عبرَ `->authorize()` المكتوبِ في الفعلَينِ المُعادِ استخدامُهما. |
| **VI — العقودُ الظاهرة** | ✅ يمرّ | uuid وحدَه في كلِّ حمولة. الرابطُ في الإشعارِ `‎/sessions/{uuid}/room`، ويُضافُ إلى قائمةِ الوجهاتِ في `notification-links.test.ts` — وإلّا فهو رابطٌ ميّتٌ لا يراه إلّا من يضغط. |

### بعدَ تصميمِ المرحلةِ الأولى

أُعيدَ الفحصُ على الوثائقِ المنتَجَة (`data-model.md` · `contracts/api.md`): **لا تغيير**.
لم يظهرْ كيانٌ جديدٌ ولا إذنٌ جديدٌ ولا تجاوزُ نطاقٍ بلا تعليقٍ واختبار. البندُ الوحيدُ
المسجَّلُ في «Complexity Tracking» هو العقدُ المشترك، وهو **يقلّلُ** الاقترانَ لا يزيدُه.

---

## Project Structure

### Documentation (this feature)

```text
specs/027-subscribe-group-or-private/
├── plan.md              # هذا الملفّ
├── research.md          # المرحلة ٠ — القراراتُ التقنيّةُ التسعة
├── data-model.md        # المرحلة ١ — الكياناتُ وحقولُ النيّةِ وانتقالاتُ الحالة
├── quickstart.md        # المرحلة ١ — كيف يُثبَتُ أنّها تعمل
├── contracts/api.md     # المرحلة ١ — نقاطُ النهايةِ والحمولاتُ والرفض
├── checklists/          # من /speckit-specify
└── tasks.md             # ينتجُه /speckit-tasks — ليس من هنا
```

### Source Code (repository root)

```text
backend/app/
├── Shared/Contracts/
│   ├── SubscriberDirectory.php                    # جديد — العقدُ الوحيدُ الجديد
│   ├── CohortDirectory.php                        # قائم — + isJoinable(int): bool
│   └── CohortScheduleDirectory.php                # قائم — + nextSessionFor(int): ?array
├── Modules/Payments/
│   ├── Actions/
│   │   ├── PurchaseSubscription.php               # يقبلُ النيّةَ ويحرسُ تطابُقَها
│   │   ├── ApproveOrder.php                       # + الفحصُ التمهيديُّ قبلَ الادّعاء (FR-026)
│   │   └── ReadSubscriptionIntent.php             # جديد — قارئُ اللقطةِ الواحد
│   ├── Listeners/ActivateSubscription.php         # يضعُ في المجموعةِ ويطلقُ الحجز
│   ├── Support/
│   │   ├── SubscriptionEligibility.php            # يُنفِّذُ العقدَ الجديد
│   │   └── SubscriptionIntent.php                 # جديد — DTO للنيّة
│   ├── Filament/Pages/GrantCreditSubscription.php # + جدولُ الطابور
│   └── Http/Controllers/SubscriptionController.php# `?session_type=` على الباقات
├── Modules/LiveSessions/
│   ├── Actions/
│   │   ├── BookSeat.php                           # تعليقٌ يُوسَّع — claimGrantedSeat يُعادُ استخدامُه
│   │   ├── CancelBooking.php                      # + release() — يكتبُ Released لا cancelled_*
│   │   ├── ClaimSubscriptionSeats.php             # جديد — الحاجزُ الجَمعيّ (يُحيي Released)
│   │   ├── AssignSessionsToCohort.php             # + إطلاقُ SessionsAssignedToCohort
│   │   └── CloseClassSession.php                  # + subscriptionSeats على SessionDelivered
│   ├── Events/{SessionsAssignedToCohort,SessionDelivered}.php  # جديد · مُوسَّع
│   ├── Jobs/ClaimSubscriptionSeatsJob.php         # جديد
│   └── Listeners/
│       ├── BookSubscribersOnScheduled.php         # جديد — الحدثانِ معاً
│       └── ReleaseSeatsOnSubscriptionEnd.php      # جديد — FR-045
├── Modules/Settlement/Actions/AccrueTeachingUnits.php          # تسعيرُ مقعدِ المشترِك
├── Modules/Learning/
│   ├── Http/Controllers/EnrollmentController.php  # FR-004
│   ├── Actions/JoinCohort.php                     # التجديدُ ليس فشلاً
│   └── Support/EloquentCohortDirectory.php        # isJoinable · ->group() في resolveCohortId
├── Modules/Courses/Models/Course.php                           # requiresPurchase()
├── Modules/Marketplace/
│   ├── Actions/Public/ReadPublicCourse.php        # الحقلانِ العامّان
│   └── Support/PublicFieldAllowlist.php           # COHORT + COURSE_DETAIL
└── Modules/Notifications/Support/NotificationType.php          # نوعان جديدان

backend/database/migrations/                       # ٣ فهارس — لا جدولَ ولا عمود

backend/resources/views/filament/pages/grant-credit-subscription.blade.php  # {{ $this->table }}
backend/database/seeders/NotificationTemplateSeeder.php                     # صفّان + هجرةُ ردم
backend/lang/ar/validation.php                                             # حقولُ الشاشةِ الجديدة

frontend/src/
├── app/(public)/courses/[uuid]/page.tsx            # دعوتا الاشتراك
├── app/(app)/(shell)/subscribe/page.tsx            # جديد — الشاشةُ الواحدة
├── app/(app)/login/page.tsx · register/page.tsx    # ?next=
├── app/(public)/signup/student/page.tsx            # ?next= يمرُّ خلالَه
├── components/marketplace/CohortList.tsx           # زرٌّ على القابلةِ للانضمام
├── components/courses/PrivateSessionRequestForm.tsx# فرعُ «غير مسجَّل» يصيرُ دعوة
└── lib/{plans,subscribe,safe-next}.ts              # عميلُ الشاشةِ وحارسُ المسار
```

**Structure Decision**: بنيةُ تطبيقِ الويبِ القائمةُ (`backend/` + `frontend/`) بلا تغيير.
كلُّ ملفٍّ جديدٍ يسكنُ وحدتَه: الاشتراكُ والطلبُ في `Payments`، والمقعدُ في `LiveSessions`،
والعضويّةُ في `Learning` — والعبورُ بينَها بحدثٍ وعقدٍ مشترك، لا باستيراد.

---

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| **عقدٌ مشتركٌ جديدٌ واحد** `App\Shared\Contracts\SubscriberDirectory` | الحجزُ التلقائيُّ فعلٌ في LiveSessions، وشرطُه («أيُّ هؤلاءِ يحملُ اشتراكاً حيّاً يغطّي هذا الكورسَ بهذا النوعِ في تلك اللحظة؟») حقيقةٌ في Payments. **ولا عقدَ قائمٌ يجيبُه** — قِيسَتِ العقودُ السبعةَ عشرَ كلُّها. | **البديلُ الأوّل**: يستوردُ LiveSessions صنفَ `SubscriptionEligibility` — يمنعُه المبدأُ III، ويجعلُ وحدةَ المقاعدِ تعرفُ جداولَ المال. **البديلُ الثاني**: يستدعي Payments فعلَ `BookSeat` مباشرةً — يقلبُ الاتّجاهَ ولا يُصلحُه. **البديلُ الثالث**: توسيعُ `AccountStanding` — ذاك عقدُ «هل الوصولُ محجوب»، فيصيرُ عقدٌ واحدٌ يجيبُ سؤالَين. العقدُ الضيّقُ هو ما يفعلُه هذا المستودعُ سلفاً ثلاثَ مرّاتٍ لهذا الغرضِ بالذات (`EnrollmentDirectory` · `AccountStanding` · `UnlockDirectory`، وكلُّها يسألُها `BookingEligibility`). |
| **توسيعُ `SessionDelivered` بقائمةِ حاملي مقاعدِ الاشتراك** | قرارُ صاحبِ المنتَجِ (٢٠٢٦-٠٩-٠٥): يُسعَّرُ مقعدُ المشترِكِ في التسويةِ على حدة. و`AccrueTeachingUnits.php:66` يكتبُ وحدةَ تدريسٍ **لكلِّ مقعد**، فالحجزُ التلقائيُّ بلا هذا يضاعِفُ ما تدفعُه المنصّةُ بعددِ الحصصِ لا بعددِ الاشتراكات. ⚠️ **قائمةُ معرّفاتٍ لا عدد** — صُحِّحَ بالقياس: `accrueOne()` يكتبُ صفّاً **لكلِّ طالبٍ** (`:66-67`, `:114`, فريدٌ على `(session, student, reversal_of_id)`)، فعددٌ مجرَّدٌ لا يقولُ **أيَّ** الصفوفِ يأخذُ سعرَ المشترِك. وقائمةُ أرقامٍ لا تسمّي صنفاً من وحدةِ المالِ ولا جدولاً، فـFR-048أ محفوظٌ كما هو. | **أن تسألَ Settlement عن الاشتراكِ بنفسِها** — `ContextIsolationTest:141` (المِكنسةُ `:169`، التوكيدُ `:179`) يُسقِطُ البناءَ على أوّلِ `use App\Modules\Payments` تحتَ `Modules/Settlement/`، وهو الحارسُ الذي **يعملُ فعلاً**. ⚠️ وكانَ مكتوباً هنا `:277` وهو **الاتّجاهُ المعاكسُ** (Settlement داخلَ Payments) — خطأٌ نشأَ في هذا الملفِّ ونُسِخَ إلى `tasks.md`. **أن يُترَكَ الأمرُ للتسعيرِ الإداريّ** — باقةٌ مسعَّرةٌ خطأً تُخسِّرُ المنصّةَ في صمتٍ بلا رقمٍ يكشفُه. الحدثُ هو الجسرُ الوحيدُ المسموحُ بينَ السياقَين، وذلك التوكيدُ نفسُه يقولُه: «bridges to the rest of the product through `SessionDelivered` alone» (`:425`، التوكيدُ `:438`). |
| **دالّتانِ تُضافانِ إلى عقدَينِ قائمَين** — `CohortDirectory::isJoinable()` و`CohortScheduleDirectory::nextSessionFor()` | FR-026 يحتاجُ سؤالَ قابليّةِ الانضمامِ من Payments، وFR-029 يحتاجُ أقربَ حصّةٍ من Payments. | **عقدٌ ثالثٌ ورابع** — قِيسَ أنّ العقدَينِ موجودانِ ويملكُهما مالكاهما الصحيحان (Learning للمجموعة، LiveSessions للجدول). عقدٌ جديدٌ بجوارِهما تهجئةٌ ثانيةٌ لسؤالٍ عن الشيءِ نفسِه. **وهذا ليس تعقيداً بل تقليلُه** — مسجَّلٌ هنا لأنّه توسيعُ سطحٍ مشتركٍ لا أكثر. |
**ولا بندَ رابع.** لا جدولَ جديدَ ولا عمودَ جديدَ ولا إذنَ جديدَ ولا تبعيّةَ جديدة — وهذا
مقيسٌ لا مُدَّعى: راجعْ `research.md` §R1 و§R5.

**⛔ وبندٌ حُذِف ثمّ عادَ أضيقَ ممّا كان: «مدخلٌ ثالثٌ مُسمّى على `BookSeat`».**

حُذِفَ أوّلاً لأنّ مبرِّرَه كانَ «شرطُ الرفضِ **للحجز** يختلف»، والقياسُ يقولُ إنّه لا يختلف:
`claimGrantedSeat()` القائمُ (`BookSeat.php:69-74`) يسألُ `refusalReason()` بجسدٍ مطابقٍ حرفاً
بحرف. ذلك الحذفُ صحيحٌ ويبقى.

وعادَ لعمليّةٍ **أخرى**: `reviveReleasedSeat()`. FR-045أ يطلبُ إحياءَ صفٍّ `released` قائم،
وذاك ليس حجزاً — الفهرسُ الفريدُ `unique(class_session_id, student_user_id)`
(`create_live_sessions_tables.php:90`) يرفضُ الإدخالَ الثاني، فيردُّ `BookSeat.php:118`
«لديك مقعد محجوز في هذه الحصة بالفعل»: جملةٌ **كاذبةٌ** لصفٍّ محرَّرٍ ولا مخرجَ منها.
**والبديلُ هو التهجئةُ الثانية**: ادّعاءُ السَّعةِ `private` (`BookSeat::claim():94-97`)
ولا زيادةَ محروسةٌ أخرى لـ`seats_taken` في الشجرةِ كلِّها — قِيسَ، فالكتّابُ الأربعةُ
الآخرونَ يُنقِصون فقط. فكتابةُ `UPDATE` داخلَ `ClaimSubscriptionSeats` تُنشئُ ادّعاءَ مقعدٍ
ثانياً، وهو العطلُ الذي حُذِفَ البندُ الأوّلُ لتجنّبِه بعينِه. مدخلٌ مُسمّى على المالكِ نفسِه
يُبقي الادّعاءَ تهجئةً واحدةً ويخدمُ البابَينِ (التفعيلَ والجدولة).

---

## ⛔ تصحيحاتُ مراجعةِ الوكلاءِ — ٢٠٢٦-٠٩-٠٥

خمسةُ وكلاءَ (تناقضات · بنية · استعلامات · حماية · تزامن) على الوثائقِ الخمس. ما يلي مقيسٌ في
الشجرةِ لا منقولٌ عن تقرير، ويُلزِمُ `tasks.md`:

| ما كانَ مكتوباً | ما قِيس | الأثر |
|---|---|---|
| «الاستيرادُ المباشرُ يكسرُ `ContextIsolationTest`» (كانَ في السطرِ ١٦٨) | ذلك التوكيدُ يفحصُ Settlement ⇄ Payments/Store/Community/Compliance **فقط** — لا LiveSessions ولا Learning في أيِّ اتّجاه. وثلاثةُ استيراداتٍ عابرةٍ قائمةٌ اليوم: `ActivateSubscription.php:8` · `SubscriptionEligibility.php:8` · `Plan.php:8` | **حُسِمَ في T071 بالتوسيع** (٢٠٢٦-٠٩-٠٥): توكيدٌ جديدٌ في ذيلِ الملفِّ نفسِه يفحصُ الوحدتَين، **في اتّجاهٍ واحدٍ لأنّ العكسَ هو التصميم** — `PaymentApproved → CreateEnrollmentFromOrder` منذُ ٠٠١، و`ChargeSessionSeats` تقرأُ `ClassSession`، و`Plan` يُصنِّفُ تغطيتَه بـ`ClassSessionType`. المسموحُ **سطرٌ واحدٌ بعينِه** لا فضاءُ أسماء: `use App\Modules\Payments\Events\SubscriptionEnded;` (FR-045)، فمستمعٌ على `PaymentApproved` غداً بناءٌ أحمرُ لا استثناءٌ مارٌّ سلفاً. ويُجرِّدُ التعليقاتِ لأنّ `CloseClassSession.php` يحملُ الإبرةَ نفسَها في شرحٍ مكتوب. ذيلُ الملفِّ عمداً: `plan.md` يستشهدُ بأرقامِ أسطرٍ (`:141` · `:169` · `:179` · `:425` · `:438`) يُزيحُها أيُّ إدراجٍ أعلى |
| `SubscriberDirectory::coversCourse(User, int, string)` | الاسمُ مأخوذٌ على الصنفِ المُنفِّذِ نفسِه بتوقيعٍ آخرَ ومستدعٍ حيّ (`SubscriptionEligibility.php:72`) | **العقدُ لا يُترجَم**. أُعيدَ تصميمُه: `subscriberIdsAmong(ids, courseId, sessionType, moment)` |
| نافذةُ الحجزِ حتّى `subscriptions.ends_on` | العمودُ «لا يتحرّكُ أبداً»؛ و`effective_ends_on` هو «الوحيدُ الذي يقرؤه أيُّ شرط» | تهجئتانِ لنافذةٍ واحدةٍ تختلفانِ بطولِ التجميد |
| «يُتخطّى ما له صفٌّ **بأيِّ حالة**» | `BookingStatus::Released` قائمةٌ ومعناها «الطالبُ لم يفعلْ شيئاً» | التجديدُ بعدَ انقطاعٍ كانَ سيفقدُ تلك الحصصَ **إلى الأبد** |
| الوظيفةُ «خارجَ المعاملة» | `after_commit => false` على الاتّصالاتِ الأربعة | بلا `DB::afterCommit()` تُرفَضُ **كلُّ** حصّةٍ لعدمِ وجودِ تسجيلٍ ملتزَم |
| FR-004 بـ`Course::isFree()` | `price_minor` يُسعِّرُ الشراءَ المفرَدَ وحدَه و`default(0)` | البابُ يبقى مفتوحاً و`SC-008` يُصادِقُ عليه |
| «التجديدُ يُقبَل» (FR-028 · US4·٤) | `sameCohort()` + `alreadyMember()` يمنعانِه، والمستمعُ بعدَ الالتزام | مالٌ مأخوذٌ وكلُّ شيءٍ مرتدّ، وصمتٌ على شاشةِ الموظّف |
| الافتراضُ ٦ «النقلُ يبقى بطلبٍ» | `CohortMembershipWriter:91-95` يُغلِقُ القديمةَ ويفتحُ الجديدةَ بلا قرارِ مدرّس | الشراءُ صارَ نقلاً بلا موافقة |
| `is_joinable` حقلٌ يُضاف | `PublicFieldAllowlist.php:231` لا يحملُه | بناءٌ أحمرُ في `PublicExposureTest` |
| «الطابورُ يقرأُ `->with([...])`» | `OrderResource.php:431` يكتبُها هكذا **اليومَ** فوقَ تجاوزٍ في السطرِ ٤٢٨ | عمودُ الكورسِ فارغٌ في صفوفِ الطابورِ المنصّيّ — الطبقةُ الخامسةُ من عطلِ ٠٢٤ |

**وقراران لصاحبِ المنتَجِ سُجِّلا في الجلسةِ نفسِها**: مقعدُ المشترِكِ **يُسعَّرُ في التسويةِ
على حدة** (بندٌ في الجدولِ أعلاه)، والحصّةُ التي جُمِّدَ عددُ مقاعدِها المحاسَبِ عليها
**تُتخطّى ويُخبَرُ الطرفان** (§٤ من `data-model.md`).
