# Phase 1 — Data Model: تسوية المدرّس ومستحقاته

**Feature**: `014-teacher-settlement` · **Date**: 2026-08-06

كل جدول تحت `backend/app/Modules/Settlement/Database/Migrations/`. كل نموذج يستعمل `HasUuid`
و`BelongsToWorkspace`، وكل ملف يبدأ بـ`declare(strict_types=1);`.

## طبقات الملكية (الدستور — المبدأ الأول)

| الكيان | الطبقة | الحارس |
|---|---|---|
| `TeachingUnit` | **جسر** | `workspace_id` للسياق + `student_user_id` يشير إلى الطالب المملوك للمنصة. نظير `Attendance` حرفياً |
| `SettlementRate` · `RateChangeRequest` · `LedgerEntry` · `SettlementPeriod` · `TeacherPayout` | **مملوك لمساحة العمل** | `BelongsToWorkspace` — لا يشير أيٌّ منها إلى طالب |

كلها تُضاف لها حالة في `tests/Feature/Tenancy/WorkspaceIsolationTest.php` في نفس الـ PR.

**قاعدة تسري على كل جدول هنا**: **يُمنع** أي مفتاح خارجي إلى `orders` · `payments` ·
`credit_*` أو أي جدول في سياق فوترة الطالب (`FR-030`). الاختبار المعماري يفحصها.

---

## `settlement_rates` — سعر مُصدَّر بنسخ

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` · `workspace_id` | | |
| `teacher_profile_id` | FK → `teacher_profiles` | |
| `session_type` | enum(`individual`,`group`) | **إلزامي** — لكل مدرّس سعران (`FR-014`) |
| `subject_id` | FK nullable | `null` = يسري على كل المواد |
| `grade_level` | string nullable | `null` = يسري على كل المراحل |
| `amount_minor` | `unsignedBigInteger` | مبلغ ثابت لكل وحدة. **يُمنع** عمود نسبة (`FR-009`) |
| `currency` | char(3) | |
| `effective_from` | `datetime` | |
| `approved_by` · `rate_change_request_id` | | من اعتمده وبأي طلب |

**فهارس**: `(workspace_id, teacher_profile_id, session_type, effective_from)` — مسار الاختيار.

**قواعد**: الصف **يُضاف ولا يُعدَّل** (`FR-010`, `FR-011`). لا `updated_at` معنوي، والتصحيح
سعرٌ جديد. اختيار السعر المنطبق: الأخصّ (مادة+مرحلة ← مادة ← عام) ثم أحدث `effective_from`
لا يتجاوز بدء الحصة (`FR-014ب`).

---

## `rate_change_requests` — طلب المدرّس

| العمود | النوع |
|---|---|
| `id` · `uuid` · `workspace_id` · `teacher_profile_id` | |
| `session_type` · `subject_id` · `grade_level` | نطاق السعر المطلوب |
| `current_amount_minor` · `requested_amount_minor` · `currency` | القيمتان (`FR-012`) |
| `status` | enum(`pending`,`approved`,`rejected`) |
| `requested_by` · `requested_at` | |
| `decided_by` · `decided_at` · `decision_reason` | |

**قواعد**: الاعتماد — ولا شيء غيره — ينشئ صفّ `settlement_rates` (`FR-013`). طلب واحد
`pending` لكل نطاق في المرة. عدد الطلبات محدود في نافذة من `PlatformSettings` (`FR-013ب`).
الرفض يُبقي السعر السابق ويُبلَّغ بسببه (`FR-013أ`).

---

## `teaching_units` — الوحدة (جسر)

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` · `uuid` · `workspace_id` | | |
| `teacher_profile_id` | | **لمن تُنسَب** — المدرّس المُنفِّذ فعلاً، لا مالك الحصة بالضرورة |
| `class_session_id` | FK → `class_sessions` | مسموح: سياق الحصص ليس سياق الفوترة |
| `student_user_id` | FK → `users` | المقعد. **يُمنع** أي حقل مالي عن هذا الطالب |
| `session_type` | enum | كما كانت وقت التنفيذ |
| `settlement_rate_id` · `amount_minor` · `currency` | | المرجع **والمبلغ** معاً (`FR-007ب`) |
| `frozen_seats` | `unsignedInteger` | `billableSeats` كما وصل مع الحدث — لقطة لا تُعاد قراءتها (`FR-007أ`) |
| `basis` | enum(`frozen_seat`,`zero_attendance_compensation`) | أساس التسوية مخزَّن مع الوحدة (`FR-007ب`) |
| `status` | enum(`pending_package`,`accrued`,`disputed`,`settled`,`reversed`) | |
| `pending_reason` | string nullable | ما ينقص بالضبط (`FR-008د`) |
| `recording_fault` | boolean | أُفرِج عنها رغم فشل التسجيل (`FR-008ج`) |
| `delivered_at` · `accrued_at` · `settled_at` | | |
| `settlement_period_id` | FK nullable | تُملأ عند الإغلاق |
| `reversal_of_id` | FK self nullable | القيد العكسي (`FR-006`) |

**فهارس**: `(workspace_id, teacher_profile_id, settlement_period_id, status)` — مسار الكشف
(`NFR-011`) · `(class_session_id, student_user_id)` **فريد** جزئياً على غير العكسيات — هو
ما يجعل التوليد عديم الأثر عند تكرار الحدث (`FR-002`, `SC-002`).

**انتقالات الحالة**:

```
                    ┌── حزمة ناقصة ──→ pending_package ──(اكتملت/فشل تقني)──┐
SessionDelivered ───┤                                                        ├──→ accrued
                    └── حزمة مكتملة أو غير مشترطة ───────────────────────────┘
                                                                     │
accrued ──(شكوى)──→ disputed ──(حُسمت)──→ accrued | reversed         │
accrued ──(إغلاق الفترة)──→ settled  ← نهائية                       │
أي حالة ──(تصحيح حضور)──→ يُنشأ صفّ reversed جديد؛ الأصل لا يُمَسّ ────┘
```

**قواعد**:

- **يُمنع** حقل يشير إلى دفعة أو إيصال أو رصيد (`FR-003`) — لا عموداً ولا `meta` حراً.
- استرداد أو خصم أو كوبون على جانب الطالب **لا يمسّ** هذا الجدول إطلاقاً (`FR-004`).
- الحذف ممنوع؛ التصحيح صفٌّ بـ`reversal_of_id` ومبلغ سالب (`FR-006`).
- `disputed` لا تدخل تسوية (`FR-008`).

---

## `ledger_entries` — الدفتر المضاف

| العمود | النوع |
|---|---|
| `id` · `uuid` · `workspace_id` · `teacher_profile_id` | |
| `type` | enum(`unit`,`reversal`,`deduction`,`bonus`,`payout`,`carry_over`) |
| `amount_minor` | **`bigInteger` مُوقَّع** — السالب مشروع |
| `currency` | char(3) |
| `teaching_unit_id` · `settlement_period_id` · `payout_id` | FK nullable — مصدر القيد |
| `reason` · `created_by` · `created_at` | |

**قاعدة**: **لا `UPDATE` ولا `DELETE` إطلاقاً** (`FR-016`, `SC-013`) — يُفرَض في الـAction
لا بالاتفاق. مجموع القيود = الصافي دائماً (`FR-022`, `NFR-009`).

---

## `settlement_periods` — الفترة

| العمود | النوع |
|---|---|
| `id` · `uuid` · `workspace_id` · `teacher_profile_id` | |
| `starts_on` · `ends_on` | `date` — تُقارَن بنصوص تواريخ لا بـ`whereDate()` |
| `status` | enum(`open`,`closed`,`paid`) |
| `units_count` · `gross_minor` · `deductions_minor` · `net_minor` | مُجمَّدة وقت الإغلاق |
| `carried_in_minor` · `carried_out_minor` | المُرحَّل من/إلى (`FR-024`, `FR-026`) |
| `closed_at` · `closed_by` | |

**قواعد**: الإغلاق `UPDATE … WHERE status = 'open'` ذرّي (`R9`). الوحدة المتأخرة عن فترة
مغلقة تُرحَّل إلى التالية و**يُمنع** إعادة الفتح (`FR-024`). الصافي السالب يُرحَّل ولا يُصرَف
(`FR-026`).

---

## `teacher_payouts` — الصرف

| العمود | النوع |
|---|---|
| `id` · `uuid` · `workspace_id` · `teacher_profile_id` · `settlement_period_id` | |
| `amount_minor` | `unsignedBigInteger` — **موجب حصراً** (`FR-026`, `SC-015`) |
| `currency` · `reference` · `method` | |
| `executed_at` · `executed_by` | (`FR-027`) |

**فريد**: `(settlement_period_id)` — صرف واحد لكل فترة؛ هو ما يجعل `SC-014` بنيوياً لا
اجتهادياً.

---

## `SettlementPolicy` — قيم لا جدول

من `PlatformSettings` بمفاتيح `settlement.*` وارتداد إلى `config/settlement.php`
(`R10`, `FR-013ب`):

| المفتاح | المعنى |
|---|---|
| `settlement.period_days` | دورية الفترة |
| `settlement.required_package_components` | المكوّنات المشترَطة — اليوم `["recording"]` |
| `settlement.zero_attendance_compensation_enabled` | مطفأ افتراضاً |
| `settlement.zero_attendance_compensation_percent` | حتى ١٠٠ |
| `settlement.rate_requests_per_window` · `settlement.rate_request_window_days` | الحدّ الترددي |

**السبب**: عدد لا يتغيّر إلا بنشر كود هو عدد لا يُضبَط أبداً — القاعدة نفسها التي أخرجت حدّ
الأجهزة ومهل 005 من `config/`.
