# Implementation Plan: محرّك الأرصدة والتحصيل (Credit-Based Billing Engine)

**Branch**: `006-credit-billing-engine` | **Date**: 2026-08-08 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/006-credit-billing-engine/spec.md`

## Summary

الطالب يملك **حساب أرصدة واحداً على مستوى المنصة**، وأرصدةً متعدّدة بحسب المدرّس. كل تغيّر
قيدٌ مضاف لا يُعدَّل، والرصيد الظاهر رقمٌ مادّي يساوي مجموع قيوده — ادّعاءٌ يحرسه اختبار
مطابقة بعد ١٠٬٠٠٠ معاملة، لا بنيةٌ تجعله صحيحاً مجاناً.

**الزناد `SessionDelivered` والمقعد المُجمَّد** — لا الحضور. السبيك يحمل تناقضاً بين جلستَي
توضيحه (`FR-022` مقابل `FR-025أ`/`FR-025د`)، والمتأخّر ينسخ المتقدّم، والكود المنشور يقف معه:
الحدث موجود ويحمل `billableSeats`، و`AttendanceConfirmed` اسمٌ لا حدثَ له. ويترتّب على ذلك أن
ثلاثة متطلبات — لا استهلاك على ملغاة، ولا على غير منفَّذة، ولا على إلغاء مبكر — **شروط إطلاق
الحدث نفسه في 005**، لا فحوصاً تُكتب هنا.

**السعر تملكه المنصة** بمعادلة `cost-plus`: سعر المدرّس المعتمَد من 014 + رسم تشغيل ثابت لنوع
الحصة + نسبة البوابة. المدرّس لا يعرّف سعر بيع ولا يراه؛ الطالب يرى **إجمالياً واحداً** ثم لا
يرى مالاً بعدها — سطحه مُقوَّم بالأرصدة. وكل شراء يحمل **لقطة مكوّناته**، لأن دفاتر 015
تُولَّد منها بأثر رجعي ولا يمكن استرجاع ما لم يُلتقط.

**والمرحلة تلمس كوداً منشوراً**: `FR-021و` تُخرج `hourly_rate` من الحقول العامة وتحذف فلتر
السعر وترتيب «الأقل سعراً» من الواجهتين — أحد عشر موضعاً مُحصاة، منها صفحة `‎/pricing` كاملة.

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

**Scale/Scope**: **توسعة `Payments`** بـ٨ جداول جديدة وتعديلين على جدولين قائمين، ~١٢ فعلاً،
٣ عقود مشتركة (اثنان جديدان وواحد يُوسَّع)، ١٣ مساراً، وثلاث شاشات في الواجهة — إضافةً إلى
حذفٍ من كودٍ منشور في 001.

## Constitution Check

*بوابة: تُفحص قبل البحث، وتُعاد بعد التصميم.*

| المبدأ | الحالة | كيف |
|---|---|---|
| **I — طبقات الملكية الثلاث** | ✅ | كل جدول **مُصنَّف صراحةً** في `data-model.md §1`. `student_credit_accounts` و`terms_consents` منصّيان **بلا** `BelongsToWorkspace`؛ `exam_mode_windows` مملوك لمساحة العمل مع حالة في `WorkspaceIsolationTest`؛ الباقي جسور |
| **I — حارس رؤية المدرّس** | ✅ | كل قراءة يجريها مدرّس تمرّ بـ`EnrollmentDirectory::hasActiveEnrollmentInWorkspace()` قبل الصلاحية (`FR-055` · `NFR-001أ`)، واختبار الملكية المنصّية يمشي الاتجاهين (`NFR-001ب`) |
| **II — المنطق في Actions** | ✅ | `FormRequest → DTO → Action → Resource`. الحدّ الأدنى للرصيد وسبب التسوية وشرط الاعتماد **داخل** الـAction لا في التحقّق — وهي المداخل نفسها التي يستعملها البذر واللوحة |
| **III — التكامل بالأحداث** | ✅ | `Event::listen()` في `PaymentsServiceProvider::boot()`. لا وحدة جديدة ⇐ لا سطر في `phpstan.neon`. والقراءتان اللتان لا يصلح لهما حدث — سعر المدرّس المعتمَد، وحالة الحجب لخط الوسائط — عقدان في `Shared\Contracts`، بسابقتَي `EnrollmentDirectory` و`ProgressImpact` |
| **IV — البوابات الأربع** | ✅ | `SC-021`، و[quickstart.md](./quickstart.md) يسجّل نتائجها |
| **V — الصلاحيات من الثوابت** | ✅ | ستّ صلاحيات جديدة في `Permissions` ([contracts/api.md §1](./contracts/api.md)) |
| **VI — العقود الظاهرة** | ✅ | `HasUuid` · uuid فقط · `strict_types` · DTO يرث `DataTransferObject` |

**نتيجة إعادة الفحص بعد التصميم**: **لا مخالفة** — `Complexity Tracking` فارغ.

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
│   ├── CreditPurchase.php             # ← PaymentApproved — الموضع الوحيد الذي يضيف رصيداً بمقابل
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

backend/app/Modules/LiveSessions/Events/SessionDelivered.php   # + billableSeatHolders
backend/app/Modules/Courses/…                                  # + lessons.is_high_value
backend/app/Modules/Marketplace/…                              # − hourly_rate من الأسطح العامة

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

> لا مخالفة دستورية تستدعي تبريراً.
