# Implementation Plan: سوق المدرّسين العام

**Branch**: `001-public-marketplace-pages` | **Date**: 2026-08-01 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-public-marketplace-pages/spec.md`

## Summary

إضافة **سوق مدرّسين عام** إلى منصة مغلقة اليوم خلف تسجيل الدخول: وحدة نطاق جديدة في الخلفية
(ملف المدرّس ودورة اعتماده، التقييمات، درجة ثقة محتسبة ومخزّنة، التوفّر الأسبوعي، الشكاوى)،
وأدوار على مستوى المنصة (طالب/مدرّس/وليّ أمر)، وسبع صفحات عامة RTL معروضة على الخادم.

**المنهج التقني** المشتق من البحث (`research.md`):

اكتشاف R1 يقلب نموذج الأمان: `WorkspaceScope` **يتخطّى الفلترة بالكامل** عندما لا يوجد
مستخدم مصادَق عليه (`WorkspaceScope.php:28`)، أي أن المسارات العامة **بلا حماية أصلاً** لا أنها
تحتاج تجاوزاً. لذلك الخطة تبني **حارساً بديلاً** — نطاق `publiclyListed()` إلزامي على كل استعلام
عام، وموارد استجابة بقوائم حقول صريحة، واختبار حارس يفشل عند أي تسريب.

بقية القرارات: فلترة بـ SQL مفهرس والبحث بالاسم عبر Scout المقيّد؛ درجة ثقة مخزّنة تُعاد
احتسابها بأحداث نطاق في طابور؛ مجموعة مسارات `(public)` في Next.js بـ RTL على مستوى المجموعة
دون المساس بالتطبيق المحمي؛ عرض على الخادم لتحقيق قابلية الفهرسة.

**التسليم على شرائح**: ست قصص مستقلة، و**P1 وحدها منتج قابل للنشر**.

## Technical Context

**Language/Version**: PHP 8.5 (Laravel 13) — الخلفية · TypeScript 5.7 (Next.js 15, React 19) — الواجهة

**Primary Dependencies**: Laravel Sanctum, spatie/permission (وضع الفرق)، Laravel Scout + Meilisearch، Filament (لوحة الاعتماد)، Tailwind CSS v4، `next/font/google` (Cairo)

**Storage**: MySQL 8 (إنتاج) · SQLite (تطوير) · SQLite في الذاكرة (اختبارات) · Redis (طابور وتخزين مؤقت، إنتاج)

**Testing**: Pest + `RefreshDatabase` + `WithWorkspace` (خلفية) · Playwright + `@axe-core/playwright` للصفحات العامة السبع (واجهة، جديد — راجع R8 و Complexity Tracking)

**Target Platform**: خادم Linux · آخر نسختين من المتصفحات الحديثة على سطح المكتب والجوال

**Project Type**: Web — مستودع أحادي بخلفية وواجهة منفصلتين

**Performance Goals**: قوائم السوق < 1s عند 50,000 مدرّس و10,000 كورس (SC-008) · محتوى الرئيسية مرئي < 2s على اتصال جوال متوسط (SC-007) · انتشار تغيّر الحالة ≤ 60s (SC-010)

**Constraints**: صفر تسريب لبيانات غير منشورة أو حقول خاصة (SC-009) · تباين 4.5:1 / 3:1 في الوضعين (FR-081) · بلا تمرير أفقي عند 360px (SC-013) · المحتوى الأساسي مفهرَس بلا تنفيذ سكربتات (SC-016) · العربية RTL فقط · العملة ريال قطري، التوقيت المرجعي UTC+3

**Scale/Scope**: 7 صفحات عامة · 86 متطلباً وظيفياً · 6 قصص مستخدم · وحدة خلفية جديدة بـ 7 نماذج + 4 تعديلات على وحدات قائمة

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

المرجع: `.specify/memory/constitution.md` v1.0.0

| المبدأ | الحالة (قبل Phase 0) | الحالة (بعد Phase 1) | كيف تتحقّق |
|---|---|---|---|
| **I. عزل المستأجرين** | ⚠️ يتطلّب تصميماً خاصاً | ✅ PASS | كل نموذج جديد عليه `BelongsToWorkspace`؛ حارس `publiclyListed()` إلزامي؛ `WorkspaceRules::exists` في كل تحقق؛ Scout مقيّد؛ الطابور يستخدم `forWorkspace()` لا `set()`؛ حالات في `WorkspaceIsolationTest` + اختبار حارس تسريب |
| **II. المنطق في الـ Actions** | ✅ PASS | ✅ PASS | احتساب درجة الثقة، اعتماد المدرّس، قبول التقييم، تسجيل الأدوار — كلها Actions تتشاركها الـ API و Filament والـ Seeders |
| **III. استقلال الوحدات بالأحداث** | ✅ PASS | ✅ PASS | `Marketplace` تستمع لأحداث `Learning`/`Payments` ولا تستدعي Actions خارجها؛ `Notifications` تستمع لأحداث الاعتماد |
| **IV. البوابات الآلية خضراء** | ✅ PASS | ✅ PASS | الوحدة الجديدة تُضاف إلى `phpstan.neon`؛ الأربعة تبقى خضراء + المسارات الحرجة الثمانية |
| **V. التفويض بالسياسات والثوابت** | ✅ PASS | ✅ PASS | سياسات للمدرّس والتقييم والاعتماد؛ أذونات جديدة في `Permissions`؛ لا نصوص حرفية؛ المسارات العامة بلا مصادقة لكن بحارس نشر |
| **VI. العقود الظاهرة مقصودة** | ✅ PASS | ✅ PASS | `uuid` فقط في كل مسار عام؛ `Public*Resource` بقوائم حقول صريحة؛ `declare(strict_types=1)` في كل ملف؛ DTOs من `DataTransferObject` |

### ملاحظة على المبدأ الأول

التجاوز الذي يفرضه Q2=A **مسموح صراحةً** في نص الدستور
(«أي تجاوز متعمّد للنطاق يجب أن يحمل تعليقاً يشرح السبب، ويجب أن يغطّيه اختبار»)،
فليس مخالفة تُسجَّل في Complexity Tracking. لكنه يظل أخطر جزء في الميزة، ولذلك:

- مسار القراءة العام **معزول في مجلد واحد** (`Marketplace/Actions/Public/`) ليكون قابلاً
  للمراجعة كوحدة واحدة.
- كل ملف فيه يحمل تعليقاً يشرح الحارس البديل ولماذا لا يكفي `WorkspaceScope`.
- اختبار الحارس يفشل عند إضافة مسار عام جديد بلا نطاق نشر.

## Project Structure

### Documentation (this feature)

```text
specs/001-public-marketplace-pages/
├── plan.md              # هذا الملف
├── spec.md              # المواصفة
├── research.md          # Phase 0 ✅
├── data-model.md        # Phase 1 ✅
├── quickstart.md        # Phase 1 ✅
├── contracts/           # Phase 1 ✅
│   ├── public-marketplace-api.md
│   ├── registration-api.md
│   └── teacher-admin-api.md
├── checklists/
│   └── requirements.md
└── tasks.md             # Phase 2 — ينشئه /speckit-tasks
```

### Source Code (repository root)

```text
backend/app/Modules/Marketplace/            # وحدة جديدة (اكتشاف تلقائي)
├── MarketplaceServiceProvider.php          # يربط المستمعين في boot()
├── Models/                                 # TeacherProfile, TeacherApplication,
│                                           # Review, AvailabilitySlot, Complaint,
│                                           # Subject, GradeLevel
├── Actions/
│   ├── Public/                             # ← مسار القراءة العام المعزول
│   │   ├── ListPublicTeachers.php
│   │   ├── ShowPublicTeacher.php
│   │   ├── ListPublicCourses.php
│   │   └── GetMarketplaceStats.php
│   ├── SubmitTeacherApplication.php
│   ├── ApproveTeacherApplication.php
│   ├── RejectTeacherApplication.php
│   ├── SubmitReview.php
│   ├── RecalculateTrustScore.php
│   └── SetAvailability.php
├── Http/
│   ├── Controllers/                        # PublicMarketplaceController, TeacherController, ReviewController
│   ├── Requests/
│   └── Resources/                          # Public*Resource بقوائم حقول صريحة
├── Policies/
├── Events/                                 # TeacherApproved, ReviewSubmitted, TrustScoreRecalculated
├── Listeners/
├── Jobs/                                   # RecalculateTrustScoreJob
├── Support/
│   ├── TrustScoreCalculator.php
│   └── PublicFieldAllowlist.php
├── Filament/                               # موارد مراجعة طلبات المدرّسين
├── Database/
│   ├── Migrations/                         # M كبيرة — إلزامي (الدستور، المبدأ III)
│   └── factories/
└── routes/api.php                          # عام + محمي

backend/app/Shared/Traits/IsPubliclyListed.php   # سمة جديدة: نطاق publiclyListed()
backend/app/Modules/Identity/                    # + platform_role
backend/app/Modules/Tenancy/                     # + اشتراك مساحة العمل في السوق
backend/app/Modules/Courses/                     # إعادة استخدام status/visibility للنشر العام
backend/database/factories/Modules/Marketplace/  # المصانع مركزية (الدستور)

backend/tests/Feature/Marketplace/
├── PublicExposureTest.php                  # ← اختبار الحارس (FR-007, SC-009)
├── TeacherApplicationTest.php
├── ReviewTest.php
├── TrustScoreTest.php
└── AvailabilityTest.php
backend/tests/Feature/Tenancy/WorkspaceIsolationTest.php   # + حالات النماذج الجديدة

frontend/src/app/(public)/                  # مجموعة مسارات جديدة، RTL على مستوى المجموعة
├── layout.tsx                              # <html lang="ar" dir="rtl"> + ترويسة/تذييل
├── page.tsx                                # الرئيسية
├── teachers/page.tsx
├── teachers/[uuid]/page.tsx
├── courses/page.tsx
└── signup/{student,teacher,parent}/page.tsx

frontend/src/components/marketplace/        # مكوّنات قابلة لإعادة الاستخدام (FR-036)
frontend/src/lib/public-api.ts              # عميل خادم بلا localStorage
frontend/src/app/globals.css                # + @theme برموز التصميم
frontend/public/marketplace/                # صور مستضافة ذاتياً + ملف تراخيص
frontend/e2e/                               # Playwright + axe (الصفحات السبع)
```

**Structure Decision**: مستودع أحادي بخلفية وواجهة منفصلتين — الهيكل القائم فعلاً.
النطاق الجديد **وحدة واحدة** `Marketplace` تُكتشف تلقائياً عبر `ModulesServiceProvider`
وتُضاف إلى `databaseMigrationsPath` في `phpstan.neon` (شرط المبدأ III).
الواجهة تضيف مجموعة مسارات `(public)` بجوار `(shell)` القائمة دون تعديل سلوكها،
لأن نقل `dir="rtl"` إلى الجذر يقلب التطبيق المحمي كله (R5).

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| إضافة سلسلة أدوات اختبار للواجهة (Playwright + axe) حيث لا يوجد أي إطار اختبار اليوم | SC-012 يطلب حرفياً فحص إمكانية وصول **آلي** بصفر أخطاء في الوضعين، وSC-013 يطلب التحقق على ثلاثة عروض | الفحص اليدوي بـ Lighthouse لا يتكرّر ولا يفشل في CI ولا يثبت المعيار. النطاق مقيّد بالصفحات السبع فقط — لا اختبارات وحدة للمكوّنات ولا Vitest |
| تخزين مؤقت للقوائم العامة (طبقة إضافية) | التوفيق بين SC-008 (< 1s عند 50k) و SC-010 (انتشار ≤ 60s) | الاعتماد على الفهارس وحدها ممكن لكن بلا هامش عند نمو الحجم؛ عمر التخزين 60s **مشتق من SC-010** لا مختار عشوائياً |

**لا مخالفات دستورية.** البندان أعلاه تعقيد مبرَّر بمعايير نجاح صريحة، وهو ما يشترطه
بند «تبرير التعقيد» في قسم سير العمل بالدستور.
