# Contract — API: تسوية المدرّس

**Feature**: `014-teacher-settlement` · **Date**: 2026-08-06

كل المسارات تحت `/api/v1` بمجموعة `api` وحارس Sanctum. كل معرّف في الطلب والاستجابة هو
`uuid` — **يُمنع** كشف المعرّف التسلسلي (الدستور VI). كل مسار كتابة محدود المعدّل بمحدِّد
**مُسمّى**؛ `throttle:N,M` المضمَّن ممنوع.

## الصلاحيات الجديدة

تُعرَّف كثوابت في `Tenancy\Support\Permissions` (`NFR-004`) — **يُمنع** اسم مكتوب نصاً:

| الثابت | القيمة | من يحملها |
|---|---|---|
| `SETTLEMENT_RATE_REQUEST` | `settlement.rate.request` | المدرّس |
| `SETTLEMENT_RATE_APPROVE` | `settlement.rate.approve` | الإدارة |
| `SETTLEMENT_STATEMENT_VIEW` | `settlement.statement.view` | المدرّس |
| `SETTLEMENT_PERIOD_MANAGE` | `settlement.period.manage` | الإدارة |
| `SETTLEMENT_PAYOUT_EXECUTE` | `settlement.payout.execute` | الإدارة |
| `SETTLEMENT_AUDIT_VIEW` | `settlement.audit.view` | تدقيق مالي على مستوى المنصة (`FR-034`) |

**`SETTLEMENT_STATEMENT_VIEW` ليست صلاحية تشغيل**: مساعد المدرّس قد يحمل كل صلاحيات الحصص
ولا يحمل هذه إطلاقاً (`FR-020`, `SC-010`). فصلهما هو الدرس نفسه الذي فرّق `SESSIONS_VIEW`
عن `ATTENDANCE_VIEW` في 005 — الأولى «تستطيع رؤية حصصك»، والثانية «تستطيع قراءة كشفٍ عن الناس».

---

## المسارات

### `GET /settlement/statement`

كشف المدرّس للفترة (`FR-017`). محروس بـ`SETTLEMENT_STATEMENT_VIEW`، ويعيد **بيانات صاحب
الرمز وحده** — لا معامل `teacher` (`FR-019`).

```jsonc
{
  "period": { "uuid": "…", "starts_on": "…", "ends_on": "…", "status": "open" },
  "students_count": 34,
  "units": { "accrued": 210, "disputed": 3, "pending_package": 5,
             "by_type": { "individual": 120, "group": 90 } },
  "rates": [ { "session_type": "individual", "amount": "…", "currency": "QAR",
               "effective_from": "…" } ],
  "pending_rate_request": { "uuid": "…", "status": "pending", "requested_at": "…" },
  "gross": "…", "deductions": [ { "reason": "…", "amount": "…" } ],
  "net": "…", "carried_in": "…", "next_payout_on": "…"
}
```

**ما لا يعبر السلك، ولا مرة واحدة** (`FR-018`, `SC-007`): ما دفعه أي طالب · عمولة المنصة ·
سعر البيع · أي كوبون أو خصم أو منحة على جانب الطالب · رصيد أي طالب. الحقول المصرّح بها
قائمة مغلقة يفحصها اختبار الحمولة.

### `GET /settlement/statement/export`

الملف نفسه بالبيانات والقيود نفسها (`FR-021`). قائمة الحقول **مشتركة** مع المسار أعلاه —
نسخة ثانية منها هي نسخة تتباعد، والتصدير هو أكثر سطح يُنسى عند إضافة حقل.

### `GET /settlement/units`

وحدات المدرّس مُصفّاة بالفترة والحالة، مُصفّحة. الوحدة المعلَّقة تحمل `pending_reason`
بنصّ ما ينقصها (`FR-008د`).

### `POST /settlement/rate-requests` · `throttle:settlement-write`

يرفع المدرّس سعره. يُرفض بـ422 عند تجاوز الحدّ الترددي مع موعد الإتاحة التالي (`FR-013ب`)،
وعند وجود طلب `pending` على النطاق نفسه.

### `GET /settlement/rate-requests`

طلباته هو، بحالاتها وأسباب قرارها.

### `POST /admin/settlement/rate-requests/{request}/approve` · `/reject`

محروسان بـ`SETTLEMENT_RATE_APPROVE`. الاعتماد — **وحده** — ينشئ صفّ `settlement_rates`
ويُطلق `SettlementRateApproved`. الرفض يُبقي السعر السابق ويوجب سبباً (`FR-013أ`).

### `POST /admin/settlement/units/{unit}/reverse` · `throttle:settlement-write`

القيد العكسي (`FR-006`)، بسبب **إلزامي** ومنفّذ يأتي من الجلسة لا من الحمولة. محروس بـ
`SETTLEMENT_PERIOD_MANAGE` — لا صلاحية سابعة لمسار واحد.

**مساره الوحيد قرار بشري**: نزاع حُسم، أو وحدة نشأت خطأً. **يُمنع** ربطه بتعديل الحضور
(`Q7`) — الوحدة بالمقعد لا بالحضور، و005 تشحن اختباراً يُفشِل البناء على أي أثر مالي للحضور.
الوحدة الأصلية **لا تُمَسّ**؛ يُنشأ صفّ جديد بمبلغ سالب.

### `POST /admin/settlement/periods/{period}/close` · `throttle:settlement-write`

إغلاق ذرّي عديم الأثر عند التكرار (`FR-028`). يعيد الإجماليات المُجمَّدة.

### `POST /admin/settlement/periods/{period}/payouts`

تسجيل صرف بمرجعه. **يُرفض** إن كان الصافي ≤ صفر (`FR-026`) أو إن كان للفترة صرف سابق.

---

## قاعدة تسري على كل استجابة في هذا السياق

**يُمنع** أن تحوي أي حمولة تصل طالباً أو وليّ أمر — من أي مسار في المنتج كله — سعر تسوية أو
نصيب مدرّس أو أي مكوّن سعر (`FR-033`, `SC-008`). هذا قيد على *المنتج* لا على هذه الوحدة،
ويُفحَص في اختبار الحمولة بالنمط الذي يستعمله `PublicExposureTest` القائم.
