# Implementation Plan: تسوية المدرّس ومستحقاته (Teacher Settlement & Payouts)

**Branch**: `014-teacher-settlement` | **Date**: 2026-08-06 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/014-teacher-settlement/spec.md`

## Summary

سياق مالي **معزول تماماً** عن فوترة الطالب: المدرّس مورِّد وحدات تدريس يُسوَّى معه على ما
نفّذه، لا على ما دفعه طلابه. الوحدة هي **(مقعد مُجمَّد × حصة)**، وقيمتها من سعر تسوية مبلغه
ثابت ومُصدَّر بنسخ يرفعه المدرّس وتعتمده الإدارة. الدفتر مضاف لا يُعدَّل، والفترة تُغلَق
بإجماليات مُجمَّدة ثم تُصرَف.

**الجسر حدث واحد**: `SessionDelivered` القادم من 005 — وحده يُطلَق عند تحقّق التنفيذ، ووحده
يحمل `billableSeats`. لا مفتاح خارجي، ولا استعلام يجمع الجدولين، ولا حقل من أحد السياقين في
حمولة الآخر — ويحرس ذلك اختبار معماري بثلاث طبقات فحص، لأن الفصل الذي لا يحرسه اختبار يتآكل.

**هذه المرحلة تسبق 006**: سعر التسوية المعتمَد مُدخَل معادلة `cost-plus`، والعلاقة باتجاه
واحد — التسعير يقرأ، وهذا السياق لا يقرأ سعر بيع ولا رصيد طالب ولا دفعة.

## Technical Context

**Language/Version**: PHP 8.5 · Laravel 13 (أحادية معيارية) · TypeScript / Next.js 15 App Router

**Primary Dependencies**: لا تبعية جديدة. `spatie/laravel-activitylog` **مثبّت وغير مستعمل**
ويُفعَّل هنا عبر `Shared\Traits\LogsActivity` لسجلّ التدقيق (`FR-034`).

**Storage**: MySQL في الإنتاج · SQLite محلياً وفي الاختبارات. المبالغ **أعداد صحيحة بالوحدة
الصغرى** مع عملتها (`R3`).

**Testing**: Pest — `tests/Feature/Settlement/` هي شبكة الأمان، مع اختبارات وحدة لاختيار
السعر واحتساب الصافي (`NFR-007`).

**Target Platform**: خادم Linux · متصفّح

**Project Type**: تطبيق ويب (خلفية + واجهة)

**Performance Goals**: كشف مدرّس بـ١٠٬٠٠٠ وحدة خلال **١ ثانية p95** بعدد استعلامات **ثابت**
لا يتحرّك مع عدد الوحدات (`NFR-011`, `SC-016`).

**Constraints**: صفر مفتاح خارجي بين السياقين · صفر حقل مالي عن الطالب في أي حمولة تصل
مدرّساً · صفر تعديل أو حذف لقيد دفتر · صفر صرف مضاعف أو سالب.

**Scale/Scope**: وحدة خلفية جديدة (~٧ جداول · ٦ Actions · ٥ مسارات مدرّس + ٤ إدارية) وشاشة
كشف واحدة في الواجهة.

## Constitution Check

*بوابة: تُفحص قبل البحث، وتُعاد بعد التصميم.*

| المبدأ | الحالة | كيف |
|---|---|---|
| **I — عزل المستأجرين** | ✅ | كل كيان **مُصنَّف صراحةً** في `spec.md › Q6` و`data-model.md`: `TeachingUnit` جسر، والباقي مملوك لمساحة العمل. كلها `BelongsToWorkspace` + حالة في `WorkspaceIsolationTest` |
| **I — حارس رؤية المدرّس** | ✅ | مُستوفى بما هو أقوى: `FR-003` يمنع الكيان من **حمل** أي بيانات مالية عن الطالب أصلاً، فلا يوجد ما يُحرَس |
| **II — المنطق في Actions** | ✅ | `FormRequest → DTO → Action → Resource`. المنع من الصرف السالب وإغلاق الفترة داخل الـAction لا في التحقّق |
| **III — التكامل بالأحداث** | ✅ | `Event::listen()` في `SettlementServiceProvider::boot()`. الوحدة تُضاف إلى `phpstan.neon`، والهجرات في `Database/Migrations` بحرف M كبير |
| **IV — البوابات الأربع** | ✅ | `SC-018` |
| **V — الصلاحيات من الثوابت** | ✅ | ست صلاحيات جديدة في `Permissions` (`contracts/api.md`)، منفصلة عن صلاحيات التشغيل |
| **VI — العقود الظاهرة** | ✅ | `HasUuid` · uuid فقط · `strict_types` · DTO يرث `DataTransferObject` |

**نتيجة إعادة الفحص بعد التصميم**: **لا مخالفة** — `Complexity Tracking` فارغ.

**تجريد واحد جديد مُبرَّر**: `SettlementPolicy` (قيم من `PlatformSettings`، لا صنف
استراتيجية). البديل — ثوابت في `config/` — يجعل دورية الفترة ونسبة التعويض قيماً لا تُضبط
إلا بنشر كود، وهي القاعدة نفسها التي أخرجت حدّ الأجهزة ومهل 005 من `config/`.

## Project Structure

### Documentation (this feature)

```text
specs/014-teacher-settlement/
├── plan.md              # هذا الملف
├── research.md          # ١١ قراراً، كلها مُتحقَّق منها في الكود
├── data-model.md        # ٦ جداول + طبقات الملكية + انتقالات الحالة
├── quickstart.md        # ٩ سيناريوهات تحقّق
├── contracts/
│   ├── api.md           # المسارات والصلاحيات وقائمة الحقول المصرّح بها
│   └── events.md        # المستهلَك والمُطلَق، ومستهلك 006 المؤجَّل
├── checklists/requirements.md
└── tasks.md             # ناتج /speckit-tasks — لا يُنشئه هذا الأمر
```

### Source Code (repository root)

```text
backend/app/Modules/Settlement/
├── SettlementServiceProvider.php     # Event::listen() — لا EventServiceProvider
├── Actions/
│   ├── AccrueTeachingUnits.php       # ← SessionDelivered
│   ├── ReleasePendingUnits.php       # اكتمال حزمة الحصة
│   ├── ReverseTeachingUnit.php       # ← AttendanceOverridden
│   ├── RequestRateChange.php
│   ├── DecideRateChange.php          # الاعتماد وحده يُنشئ صفّ السعر
│   ├── CloseSettlementPeriod.php     # UPDATE شرطي ذرّي
│   └── RecordTeacherPayout.php
├── Contracts/                        # لا شيء بعد — يُضاف عند أتمتة الصرف (012)
├── Data/                             # DTOs
├── Database/Migrations/              # M كبيرة
├── Enums/                            # UnitStatus · LedgerEntryType · PeriodStatus
├── Events/                           # TeachingUnitAccrued · SettlementRateApproved · …
├── Http/{Controllers,Requests,Resources}/
├── Jobs/                             # forWorkspace() — لا WorkspaceContext::set()
├── Listeners/
├── Models/
├── Policies/
└── Support/
    ├── SettlementSettings.php        # PlatformSettings بمفاتيح settlement.*
    ├── RateResolver.php              # الأخصّ ثم الأحدث
    └── TeacherFieldAllowlist.php     # حارس الحمولة

backend/tests/Feature/Settlement/     # ContextIsolationTest هو الحاكم
backend/database/factories/Modules/Settlement/

frontend/src/
├── app/(app)/(shell)/manage/settlement/page.tsx
├── components/settlement/
└── lib/settlement.ts
```

**Structure Decision**: وحدة `Settlement` **مستقلة**، لا توسعة لـ`Payments`. الفصل هو الميزة
نفسها؛ وضع الدفتر داخل `Payments` يحوّل `FR-030`/`FR-031` إلى اتفاق بين مبرمجين في مجلد
واحد، وأول استعلام يجمع الجدولين سيُكتب لأنه في متناول اليد (`research.md › R2`).

## Complexity Tracking

> لا مخالفة دستورية تستدعي تبريراً.
