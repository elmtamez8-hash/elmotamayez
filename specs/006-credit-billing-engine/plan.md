# Implementation Plan: محرّك الأرصدة والتحصيل (Credit-Based Billing Engine)

**Branch**: `006-credit-billing-engine` | **Date**: 2026-08-08 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/006-credit-billing-engine/spec.md`

## Summary

> **مُراجَعة 2026-08-08 بخمس مراجعات وكلاء** (تعارض · معمارية · N+1 · حماية · تزامن). ما
> صحّحته مُعلَّم بـ⚠️ في كل مصنوعة، ولم يُمحَ — قرارٌ نُقض بلا أثر يُقترَح ثانيةً. وثلاثة
> منها كانت **أخطاءً في وقائع الكود** لا في التصميم.

الطالب يملك **حساب أرصدة واحداً على مستوى المنصة**، وأرصدةً متعدّدة **بحسب الكورس**. كل تغيّر
قيدٌ مضاف لا يُعدَّل، والرصيد الظاهر رقمٌ مادّي يساوي مجموع قيوده — ادّعاءٌ يحرسه اختبار
مطابقة بعد ١٠٬٠٠٠ معاملة، لا بنيةٌ تجعله صحيحاً مجاناً.

**الزناد `SessionDelivered` والمقعد المُجمَّد** — لا الحضور. السبيك يحمل تناقضاً بين جلستَي
توضيحه (`FR-022` مقابل `FR-025أ`/`FR-025د`)، والمتأخّر ينسخ المتقدّم. ⚠️ **و`AttendanceConfirmed`
موجود**: نسخةٌ أولى من هذه الخطة قالت إنه «اسمٌ لا حدثَ له» — خطأ صُحِّح. والحجّة الصحيحة
مكتوبة أصلاً في `AccrueUnitsOnDelivery`: حدث الحضور يُطلَق **بلا شرط** حتى لحصةٍ لم تُدرَّس،
ولا يحمل عدد المقاعد. ويترتّب على الاختيار أن ثلاثة متطلبات — لا استهلاك على ملغاة، ولا على
غير منفَّذة، ولا على إلغاء مبكر — **شروط إطلاق الحدث نفسه في 005**، لا فحوصاً تُكتب هنا.
⚠️ **ولا توسعة على الحدث**: 014 تقرأ الحجوزات للهوية وتأخذ العدد من الحدث، و006 تنسخ القسمة.

**السعر تملكه المنصة** بمعادلة `cost-plus`: سعر المدرّس المعتمَد من 014 + رسم تشغيل ثابت لنوع
الحصة + نسبة البوابة. المدرّس لا يعرّف سعر بيع ولا يراه؛ الطالب يرى **إجمالياً واحداً** ثم لا
يرى مالاً بعدها — سطحه مُقوَّم بالأرصدة. وكل شراء يحمل **لقطة مكوّناته**، لأن دفاتر 015
تُولَّد منها بأثر رجعي ولا يمكن استرجاع ما لم يُلتقط.

**والسعر مثبَّت على الكورس قبل الحجز** (`Q-7`): الطالب لا يحجز في المطلق بل يحجز حصص كورس
معلوم السعر، فالرصيد يُشترى لكورس ويُستهلك في حصصه — وبذلك يُحلّ الطرفان بنفس المُدخَلات
فيتساوى المحصَّل والمدفوع. ⚠️ **والتساوي لا يقوم بلا ثلاثة أضافتها المراجعة**:
`courses.teacher_profile_id` (بدونه العقد **غير قابل للتنفيذ**: `RateResolver` يبدأ من
مفتاح المدرّس، و`courses` تحمل `created_by` القابل للإفراغ فقط)، وتمرير `grade_level` في
`AccrueTeachingUnits` (يمرّر أربعة وسائط اليوم، فكل سعر مخصَّص بصفّ **غير مرئي عند التسوية**)،
ونسخُ المادة والصفّ إلى الحصة عند الجدولة.

**والمرحلة تمسّ كوداً منشوراً على نطاق أوسع ممّا قدّرتُ**: `FR-021و` تُخرج `hourly_rate` من
الأسطح العامة — ⚠️ **١٩ موضعاً** لا أحد عشر، منها ثلاث ذاكرات مخبّأة لحمولات مُصاغة، واختبارٌ
وسبيك Playwright يؤكّدان التسريب اليوم. ⚠️ **والحارس الذي استندتُ إليه لا يحرس**:
`PublicExposureTest` قائمة **منع** لا قائمة سماح، فحذف الحقل من المسموح لا يغيّر تأكيداً واحداً.

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 (أحادية معيارية) · TypeScript / Next.js 15 App Router

**Primary Dependencies**: لا تبعية جديدة. `spatie/laravel-activitylog` (مستعمل منذ 014) يحمل
تاريخ الحد الائتماني بدل جدول ثالث لنفس الفكرة.

**Storage**: MySQL إنتاجاً · SQLite محلياً وفي الاختبارات. الأرصدة أعداد صحيحة بوحدة «حصة»،
والمبالغ أعداد صحيحة بالوحدة الصغرى مع عملتها.

**Testing**: Pest — `tests/Feature/Payments/` شبكة الأمان، مع اختبارات وحدة لترتيب الاستهلاك
واحتساب `cost-plus` وقرار النمط (`NFR-007`).

**Target Platform**: خادم Linux · متصفّح

**Performance Goals**: لوحة مالية لمدرّس بـ٥٠٠ طالب خلال **٨٠٠ مللي ثانية p95** بعدد استعلامات
**ثابت لا يتجاوز ١٥** (`NFR-012` · `SC-017`).

**Constraints**: صفر تعديل أو حذف لقيد · صفر استهلاك مضاعف أو مفقود تحت التزامن · صفر هبوط
تحت الحدّ · صفر مبلغ نقدي في أي حمولة تصل مدرّساً عن طالب · صفر سعر مدرّس في أي سطح عام.

**Scale/Scope**: **توسعة `Payments`** بـ٩ جداول (⚠️ `credit_lots` أُضيف)، ~١٣ فعلاً، ٣ عقود
مشتركة (اثنان جديدان و`GuardianDirectory` يُوسَّع)، ١٤ مساراً، وثلاث شاشات — إضافةً إلى
**عشرة تغييرات على كود منشور** ([data-model.md §٣](./data-model.md))، أثقلها إخراج
`hourly_rate` من ١٩ موضعاً، وسدّ ثغرة اعتماد المدرّس لشراء الأرصدة.

## Constitution Check

*بوابة: تُفحص قبل البحث، وتُعاد بعد التصميم.*

| المبدأ | الحالة | كيف |
|---|---|---|
| **I — طبقات الملكية الثلاث** | ⚠️ **مخالفة واحدة** | كل جدول مُصنَّف في `data-model.md §1`، وجداول الجسر تحمل السمة بتجاوزٍ صريح واحد (سابقة `Enrollment`). **لكن الدستور سطر ٥٢ يعدّ «حزم الأرصدة» مملوكةً لمساحة العمل، و`FR-016` تقول عكسه نصّاً** — راجع `Complexity Tracking` |
| **I — حارس رؤية المدرّس** | ✅ | كل قراءة يجريها مدرّس تمرّ بـ`EnrollmentDirectory::hasActiveEnrollmentInWorkspace()` قبل الصلاحية (`FR-055` · `NFR-001أ`)، واختبار الملكية المنصّية يمشي الاتجاهين (`NFR-001ب`) |
| **II — المنطق في Actions** | ✅ | `FormRequest → DTO → Action → Resource`. الحدّ الأدنى للرصيد وسبب التسوية وشرط الاعتماد **داخل** الـAction لا في التحقّق — وهي المداخل نفسها التي يستعملها البذر واللوحة |
| **III — التكامل بالأحداث** | ✅ | `Event::listen()` في `PaymentsServiceProvider::boot()`. لا وحدة جديدة ⇐ لا سطر في `phpstan.neon`. والقراءتان اللتان لا يصلح لهما حدث — سعر المدرّس المعتمَد، وحالة الحجب لخط الوسائط — عقدان في `Shared\Contracts`، بسابقتَي `EnrollmentDirectory` و`ProgressImpact` |
| **IV — البوابات الأربع** | ✅ | `SC-021`، و[quickstart.md](./quickstart.md) يسجّل نتائجها |
| **V — الصلاحيات من الثوابت** | ✅ | ستّ صلاحيات جديدة في `Permissions` ([contracts/api.md §1](./contracts/api.md)) |
| **VI — العقود الظاهرة** | ✅ | `HasUuid` · uuid فقط · `strict_types` · DTO يرث `DataTransferObject` |

**نتيجة إعادة الفحص بعد التصميم**: ⚠️ **مخالفة واحدة، مسجَّلة** — لا `Complexity Tracking`
فارغاً كما كتبتُ أولاً.

**التجريدان الجديدان، وتبريرهما**:

- **`BillingSettings`** — المصدر الواحد لقرار النمط (`FR-013`). النمط لكل **مساحة عمل**
  (`workspaces.settings`)، والتسعير للمنصة (`platform_settings`)؛ الفصل مفروض لأن
  `PlatformSettings` منصّي بحكم بنيته، فوضع النمط فيه ينقض `FR-011` نفسها.
- **`credit_allocations`** — جدول لأن الاشتقاق **خاطئ** لا لأنه بطيء: ترتيب «الأقرب انتهاءً
  أولاً» يجعل حزمةً اشتُريت متأخرة تتخطّى الطابور، فيتغيّر بأثر رجعي أي دفعةٍ دفعت أي استهلاك
  ماضٍ. التفصيل في `research.md › R10` و`data-model.md §2`.

**وتجريد رُفض**: جدول `access_holds`. الحجب **مشتقّ** من الرصيد والحدّ والنمط، فـ`FR-033`
(«يُرفع فوراً بلا تدخّل يدوي») تصير صحيحة بالبناء بدل أن تكون مهمّةً تُنسى — نفس منطق
`is_publicly_listed` ودرجة الثقة.

## Project Structure

### Documentation (this feature)

```text
specs/006-credit-billing-engine/
├── plan.md              # هذا الملف
├── research.md          # ١٥ قراراً، كلها مُتحقَّق منها في الكود المنشور
├── data-model.md        # ٨ جداول + طبقات الملكية + ما لا جدول له عمداً
├── quickstart.md        # ١٢ سيناريو تحقّق + قيمتان تحتاجان قراراً تشغيلياً
├── contracts/
│   ├── api.md           # المسارات والصلاحيات والحقول المصرّح بها والعقود المشتركة
│   └── events.md        # المستهلَك والمُطلَق، والحدود مع 005 و014 و007
├── checklists/requirements.md
└── tasks.md             # ناتج /speckit-tasks — لا يُنشئه هذا الأمر
```

### Source Code (repository root)

```text
backend/app/Modules/Payments/          # توسعة، لا وحدة جديدة
├── PaymentsServiceProvider.php        # + Event::listen(SessionDelivered) و(PaymentApproved)
├── Actions/
│   ├── RecordCreditPurchase.php       # ← PaymentApproved — الموضع الوحيد الذي يضيف رصيداً بمقابل
│   │                                  #   (اسم فعلٍ، ولا يصطدم بـModels\CreditPurchase)
│   ├── ChargeSessionSeats.php         # ← SessionDelivered
│   ├── AdjustCredits.php              # bonus · adjustment، بسبب إلزامي
│   ├── EvaluateCreditLimit.php
│   ├── RecordTermsConsent.php
│   └── ManageExamModeWindow.php
├── Contracts/                         # PaymentProviderInterface (قائم)
├── Database/Migrations/               # M كبيرة
├── Enums/CreditTransactionType.php · BillingMode.php · ZeroBalanceBehavior.php
├── Events/                            # CreditsPurchased · CreditConsumed · AccessWithheld · …
├── Http/{Controllers,Requests,Resources}/
├── Jobs/                              # forWorkspace() — لا WorkspaceContext::set()
├── Models/                            # StudentCreditAccount · CreditBalance · CreditTransaction · …
├── Policies/
└── Support/
    ├── BillingSettings.php            # المصدر الواحد للنمط والعتبات (FR-013)
    ├── CostPlusPricing.php            # المعادلة، في موضع واحد
    ├── CreditLedger.php               # الخصم الذرّي وترتيب الاستهلاك
    └── StudentBalanceAllowlist.php    # حارس حمولة المدرّس

backend/app/Shared/Contracts/
├── ApprovedRateDirectory.php          # جديد — تنفّذه Settlement
└── AccountStanding.php                # جديد — تنفّذه Payments، تستدعيها Media

backend/app/Modules/LiveSessions/
├── Support/BookingEligibility.php      # ⚠️ حارس الحجز — الموضع الذي يُنادي AccountStanding.
│                                       #   بدونه SC-010 وSC-013 بلا تنفيذ: العقد كان
│                                       #   مُسنَداً إلى Media وحدها، وMedia لا تحجز
├── Actions/ScheduleClassSession.php    # ينسخ subject/grade من الكورس
└── Models/ClassSession.php             # + charged_at (R17)

backend/app/Modules/Settlement/Actions/AccrueTeachingUnits.php  # ⚠️ يمرّر grade_level
backend/app/Modules/Courses/…                                   # + is_high_value · subject_id
                                                                #   · grade_level · teacher_profile_id
backend/app/Modules/Tenancy/Support/RolePermissionMatrix.php    # ⚠️ اعتماد شراء الأرصدة
backend/app/Modules/Marketplace/…                               # − hourly_rate (١٩ موضعاً)

backend/tests/Feature/Payments/        # ومعها حالات في WorkspaceIsolationTest و PublicExposureTest
backend/database/factories/Modules/Payments/

frontend/src/
├── app/(app)/(shell)/billing/page.tsx              # رصيد الطالب بسياقاته
├── app/(app)/(shell)/manage/billing/page.tsx       # لوحة المدرّس — بالأرصدة، بلا مال
├── app/(public)/pricing/page.tsx                   # يُعاد بناؤها على أسعار الحزم
├── components/billing/
└── lib/billing.ts
```

**Structure Decision**: **توسعة `Payments`**، عكسَ ما فعلته 014 عمداً. الحارس المعماري نفسه
هو السبب: `ContextIsolationTest` يعرّف «سياق فوترة الطالب» بأنه `tablesCreatedBy('Payments')`،
وتعليقه ينصّ على أنه يشتقّ القوائم لئلا **«تبلى يوم تضيف المرحلة 006 جداول أرصدتها — وتبلى
بصمت»**. فالجداول تحت `Payments` تدخل الحارس يوم كتابتها بلا سطر؛ وتحت وحدة جديدة تخرج منه
كلها ويبقى الاختبار أخضر فوق فصلٍ لم يعد محروساً. والأرصدة **هي** مال الطالب، لا سياق ثالث.
التفصيل والبديل المرفوض في [research.md › R1](./research.md).

## Complexity Tracking

| المخالفة | لماذا | البديل الأبسط ولماذا رُفض |
|---|---|---|
| **`credit_packages` مملوكة للمنصة** بينما الدستور v1.1.0 سطر ٥٢ يعدّ «حزم الأرصدة» مملوكةً لمساحة العمل | `FR-016` و`Q-1`: التسعير `cost-plus` تملكه المنصة، والمدرّس **يُمنع** من تعريف سعر أو رؤيته. حزمةٌ يملكها المدرّس تنقض ذلك في جذره | إبقاؤها لمساحة العمل — مرفوض لأنه يعيد سعر البيع إلى يد المدرّس، وهو ما بُنيت `Q-1` كلّها لمنعه. **الحسم إجرائي**: تعديل الدستور إلى v1.2.0 بإجراء الحوكمة في نفس الـPR، أو إعادة التصنيف صراحةً — لا تمريره بادّعاء «لا مخالفة» |

**وثلاثة قرارات مفتوحة لا تحجب التصميم** ومحلّها [research.md](./research.md): فرق السعر بين
الشراء والتنفيذ (يُعرَض قبل الاعتماد بدل أن يُكتشَف عند الإقفال) · مصير `courses.price`
القائم · وحسم بقايا `class_sessions.course_id` غير القابلة للتعبئة.
