# Contract — Public Marketplace API

**Base**: `/api/v1` · **Auth**: لا شيء (مسارات عامة) · **Methods**: قراءة فقط

> ⚠️ كل مسار هنا يعمل **بلا مستخدم مصادَق عليه**، وبالتالي `WorkspaceScope` معطّل
> بالكامل عليه (`WorkspaceScope.php:28`). الحارس الوحيد هو نطاق `publiclyListed()`.
> إضافة مسار في هذا الملف بلا ذلك النطاق = تسريب بيانات عبر كل مساحات العمل.

## قاعدة الحقول العامة (تحكم كل استجابة أدناه)

**مسموح**: `uuid`, الاسم المعروض, الصورة, النصوص التحريرية, التصنيفات, السعر والعملة,
التقييم والعدّادات, درجة الثقة وعواملها, أوقات التوفّر.

**ممنوع منعاً باتاً** (FR-005): `id` التسلسلي · `email` · `phone` · `workspace_id`
أو اسم مساحة العمل · `step_data` أو أي بيانات مستندات · الملاحظات الداخلية ·
`reviewed_by` · `rejection_reason` · `created_by` كمعرّف مستخدم خام.

كل مورد استجابة يُبنى من قائمة صريحة في `Support/PublicFieldAllowlist.php`،
ويفشل `PublicExposureTest` عند ظهور أي مفتاح خارجها.

---

## `GET /marketplace/teachers`

قائمة المدرّسين المنشورين عبر كل مساحات العمل المشتركة.

**Query**: `subject` (slug) · `grade_level` (slug) · `price_min` · `price_max` ·
`min_rating` (1–5) · `language` · `available_now` (bool) · `q` (بحث بالاسم) ·
`sort` (`rating_desc` \| `price_asc`) · `page` · `per_page` (افتراضي 12، أقصى 48)

**200**:

```json
{
  "data": [{
    "uuid": "…", "name": "…", "headline": "…", "photo_url": "…",
    "subjects": [{"slug": "math", "name_ar": "الرياضيات"}],
    "grade_levels": [{"slug": "secondary", "name_ar": "الثانوية"}],
    "years_experience": 8, "teaching_languages": ["ar", "en"],
    "hourly_rate": "120.00", "currency": "QAR",
    "average_rating": 4.7, "reviews_count": 32,
    "trust_score": 86, "trust_score_band": "high",
    "is_verified": true, "available_now": false
  }],
  "meta": {"current_page": 1, "per_page": 12, "total": 0, "last_page": 1},
  "links": {"next": null, "prev": null}
}
```

- `trust_score` = `null` و`trust_score_band` = `"building"` عند حالة "قيد التكوين" (FR-024).
- النتائج الفارغة تُرجع `data: []` بحالة **200** لا 404 (FR-078).
- كل معاملات الاستعلام تُعاد في `meta.filters` لتغذية الوسوم القابلة للإزالة (FR-051).

**السلوك**: مخزّن مؤقتاً 60 ثانية بمفتاح مشتق من كل المعاملات (R7 / SC-010).

---

## `GET /marketplace/teachers/{uuid}`

**200**: كل حقول البطاقة أعلاه، زائد:

```json
{
  "bio": "…", "qualifications": ["…"],
  "stats": {"students_taught": 210, "completed_sessions": 640,
            "response_rate": 95, "attendance_rate": 98},
  "trust_score_factors": {
    "student_rating": 94, "punctuality": 88, "completion": 91,
    "tenure": 100, "complaints_penalty": 0
  },
  "courses": [ /* بطاقات كورسات هذا المدرّس */ ],
  "reviews": {
    "average": 4.7, "total": 32,
    "distribution": {"5": 21, "4": 8, "3": 2, "2": 1, "1": 0},
    "items": [{"student_display_name": "أحمد م.", "rating": 5,
               "comment": "…", "created_at": "2026-07-12"}]
  },
  "availability": [{"day_of_week": 0, "start_time": "16:00", "end_time": "18:00"}],
  "faqs": [{"question": "…", "answer": "…"}]
}
```

- `student_display_name` اسم مختصر لا هوية كاملة (FR-021).
- `availability` بتوقيت **UTC**؛ التحويل لتوقيت الزائر في الواجهة (FR-029).
- بلا تخزين مؤقت (R7).

**404**: المدرّس غير موجود، أو غير معتمد، أو غير منشور، أو مساحة عمله غير مشتركة
(FR-002) — **نفس الاستجابة في الحالات الأربع** حتى لا يكشف الفرق وجود مدرّس غير منشور.

---

## `GET /marketplace/courses`

**Query**: `subject` · `grade_level` · `type` (`private` \| `group` \| `recorded`) ·
`price_min` · `price_max` · `min_rating` · `sort` · `page` · `per_page`

**200**: نفس شكل الترقيم، وكل عنصر:

```json
{
  "uuid": "…", "title": "…", "cover_url": "…",
  "teacher": {"uuid": "…", "name": "…", "photo_url": "…"},
  "type": "group", "lessons_count": 24, "duration_seconds": 43200,
  "price": "300.00", "price_before_discount": "450.00", "currency": "QAR",
  "average_rating": 4.5, "enrolled_count": 180, "is_bestseller": true
}
```

`price_before_discount` = `null` عند غياب الخصم (FR-053).

---

## `GET /marketplace/stats`

**200**: `{"students": 0, "teachers": 0, "sessions": 0, "satisfaction_rate": 0}`

أرقام محتسبة حقيقية لا ثابتة (FR-038). مخزّنة مؤقتاً 60 ثانية.

---

## `GET /marketplace/subjects`

**200**: `[{"slug": "math", "name_ar": "الرياضيات", "icon": "…", "teachers_count": 0}]`

تُرجع المواد التي لها مدرّس منشور واحد على الأقل، لأن مادة بلا مدرّسين تقود إلى قائمة فارغة.

---

## `GET /marketplace/home`

استجابة مجمّعة للصفحة الرئيسية: `stats` + `featured_teachers` (6) +
`featured_courses` (6) + `subjects` + `testimonials` + `faqs`.

**السبب**: الصفحة الرئيسية تحتاج ستة مصادر؛ نداء واحد على الخادم شرط عملي لـ SC-007
(محتوى مرئي خلال ثانيتين).

---

## أخطاء موحّدة

| الحالة | الرمز | الجسم |
|---|---|---|
| مورد غير موجود أو غير منشور | 404 | `{"message": "غير متاح"}` |
| معامل استعلام غير صالح | 422 | `{"message": "…", "errors": {…}}` |
| تجاوز حد المعدّل | 429 | `{"message": "…"}` |

**حد المعدّل**: المسارات العامة غير مصادَق عليها، فتُقيَّد بعنوان الشبكة
(60 طلباً/دقيقة للقوائم) لمنع استخراج قاعدة المدرّسين بالكامل.
