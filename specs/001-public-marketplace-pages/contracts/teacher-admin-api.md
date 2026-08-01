# Contract — Teacher Review, Reviews & Marketplace Admin

**Base**: `/api/v1` · **Auth**: Sanctum · **Authorization**: سياسات + ثوابت `Permissions`

---

## مراجعة طلبات المدرّسين (الفريق الأكاديمي)

جميعها تتطلّب إذناً من ثوابت `Tenancy\Support\Permissions` — **لا نصوص حرفية**
(الدستور، المبدأ V). متاحة عبر الـ API وعبر مورد Filament، وكلاهما ينادي **نفس الـ Action**
(المبدأ II).

### `GET /admin/teacher-applications`
`marketplace.teachers.review` · فلترة بـ `status` · مرقّمة.

### `POST /admin/teacher-applications/{uuid}/approve`
`marketplace.teachers.approve`

**200**: `{"approval_status": "approved", "is_publicly_listed": true}`

**السلوك**: `ApproveTeacherApplication` يضبط الحالة، ويشتقّ `is_publicly_listed`
من اشتراك مساحة العمل، ويصدر `TeacherApproved`، ويبطل تخزين القوائم المؤقت.

> `is_publicly_listed` **لا يُضبط يدوياً أبداً** — يُشتق من (الاعتماد × اشتراك المساحة).
> مساحة عمل غير مشتركة ⇒ الاعتماد ينجح والمدرّس يبقى غير منشور. هذا سلوك صحيح لا عطل.

### `POST /admin/teacher-applications/{uuid}/reject`
`marketplace.teachers.approve` · `{"reason": "…"}` → يصدر `TeacherRejected`.

### `POST /admin/teacher-applications/{uuid}/request-changes`
`{"reason": "…"}` → `changes_requested`؛ يستطيع المدرّس التعديل وإعادة الإرسال (FR-072).

### `POST /admin/teachers/{uuid}/suspend` · `/reinstate`
`marketplace.teachers.suspend`

التعليق **يخفي المدرّس من السوق خلال ≤ 60 ثانية** (SC-010) بإبطال التخزين المؤقت فوراً.

---

## التقييمات

### `POST /teachers/{uuid}/reviews` (Sanctum, دور طالب)

```json
{"rating": 5, "comment": "…"}
```

**201** إنشاء · **200** تحديث السجل القائم (FR-019 — سجل فعّال واحد لكل زوج).

**422** إن لم تكن للطالب حصة مكتملة مع المدرّس (FR-018) — القاعدة مفروضة في
`SubmitReview` لا في `FormRequest` فقط، لأن Filament والـ Seeders يمرّان بنفس الـ Action
(الدستور، المبدأ II).

يصدر `ReviewSubmitted` → وظيفة طابور تعيد احتساب درجة الثقة.

### `DELETE /admin/reviews/{uuid}` (إخفاء)
`marketplace.reviews.moderate` — يضبط `is_visible = false` ويعيد الاحتساب.

---

## الشكاوى

### `POST /admin/complaints/{uuid}/confirm` · `/dismiss`
`marketplace.complaints.manage` · التأكيد فقط يخصم من درجة الثقة ويطلق إعادة الاحتساب.

---

## اشتراك مساحة العمل في السوق

### `PUT /workspace/marketplace-participation`
`marketplace.participation.manage`

```json
{"participates": true}
```

**السلوك**: التبديل إلى `false` **يخفي كل مدرّسي وكورسات المساحة من السوق فوراً**
(FR-008 وحواف المواصفة)، دون تغيير `approval_status` لأي مدرّس — الانسحاب قرار مساحة عمل
لا عقوبة على المدرّس.

---

## التوفّر

### `PUT /teacher/availability` (Sanctum, دور مدرّس)

```json
{"slots": [{"day_of_week": 0, "start_time": "16:00", "end_time": "18:00"}]}
```

استبدال كامل للمجموعة. **422** عند تداخل فترتين (FR-028).
الأوقات تُرسل وتُخزَّن بـ **UTC**.

---

## عقد داخلي — `RecalculateTrustScore`

ليس نقطة نهاية؛ عقد Action يُستدعى من وظيفة طابور.

**Input**: `TeacherProfile`
**Output**: `trust_score` (0–100 أو `null`)، `trust_score_factors`، `trust_score_calculated_at`

**الأوزان** من `config/marketplace.php` (FR-022):

```php
'trust_score' => [
    'weights' => ['student_rating' => 35, 'punctuality' => 25,
                  'completion' => 25, 'tenure' => 15],
    'complaint_penalty_per_item' => 5,
    'complaint_penalty_max' => 20,
    'tenure_saturation_months' => 12,
    'minimum_sessions' => 10,
    'minimum_reviews' => 3,
    'bands' => ['high' => 80, 'medium' => 60],
],
```

**قاعدة "قيد التكوين"**: دون `minimum_sessions` **أو** `minimum_reviews` ⇒
`trust_score = null` و`band = "building"` (FR-024). **ليس صفراً.**

**قيود تنفيذية إلزامية**:
- الوظيفة تستخدم `WorkspaceContext::forWorkspace()` — **ممنوع** `set()` في الطابور
  (الدستور، المبدأ I).
- الناتج مقصوص على المدى 0–100 قبل الحفظ، لأن العمود `unsignedTinyInteger`
  (0–255 في MySQL الصارم، وأي قيمة في SQLite — الدستور، قيود البيئة).
