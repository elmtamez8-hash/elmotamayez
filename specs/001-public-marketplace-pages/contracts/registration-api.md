# Contract — Registration API (أدوار المنصة)

**Base**: `/api/v1` · **Auth**: لا شيء للتسجيل، Sanctum لما بعده

> المسار القائم لإنشاء أكاديمية (`POST /register`) **يبقى كما هو بلا تعديل** (FR-011).
> المسارات أدناه جديدة و**لا تُنشئ مساحة عمل** (FR-010).

---

## `POST /auth/register/student`

```json
{
  "first_name": "…", "last_name": "…", "email": "…",
  "phone": "+974…", "password": "…", "password_confirmation": "…",
  "grade_level_slug": "secondary", "country": "QA",
  "registered_by_parent": false,
  "parent": {"name": "…", "email": "…", "phone": "…"},
  "terms_accepted": true
}
```

**قواعد التحقق**:
- `terms_accepted` **يجب** أن يكون `true` — `accepted` (FR-065). لا قيمة افتراضية.
- `parent` مطلوب فقط عندما `registered_by_parent = true` (FR-064).
- `grade_level_slug` يُتحقق منه مقابل قائمة الـ slugs النشطة — **لا** `exists:grade_levels,id`
  لأن الجدول تابع لمستأجر والقاعدة استعلام خام يتجاوز النطاق (الدستور، المبدأ I).
- `email` فريد على مستوى المنصة.

**201**: `{"user": {"uuid": "…", "name": "…", "platform_role": "student"}, "token": "…"}`

**السلوك**: يضبط `platform_role = student`. **لا** ينشئ مساحة عمل ولا يمنح دوراً داخلها.

**422** عند بريد مكرر: رسالة عربية + إشارة إلى تسجيل الدخول (FR-067).

---

## `POST /auth/register/parent`

```json
{"first_name":"…","last_name":"…","email":"…","phone":"+974…",
 "password":"…","password_confirmation":"…","terms_accepted":true}
```

**201**: نفس الشكل بـ `platform_role = parent`.

### `POST /parent/children` (Sanctum, دور وليّ أمر)

```json
{"child_name": "…", "child_age": 12, "child_grade_level_slug": "preparatory"}
```

**201**: بيانات الابن المرتبط. **403** لأي دور آخر.

### `GET|PUT /parent/notification-preferences` (Sanctum)

```json
{"weekly_reports": true, "session_alerts": true}
```

**سياسة**: وليّ أمر لا يقرأ ولا يعدّل ابناً غير مرتبط بحسابه (FR-075) — **403**.

---

## `POST /auth/register/teacher` — معالج أربع خطوات

### `POST /auth/register/teacher/step-1`

بيانات أساسية → ينشئ المستخدم بـ `platform_role = teacher` و`teacher_applications`
بحالة `draft` و`current_step = 1`، ويعيد رمزاً لمتابعة بقية الخطوات.

### `PUT /teacher/application/step-2` (Sanctum)

```json
{"subjects": ["math","physics"], "grade_levels": ["secondary"],
 "years_experience": 8, "qualifications": ["…"], "teaching_languages": ["ar","en"]}
```

### `PUT /teacher/application/step-3` (Sanctum)

**واجهة فقط** (FR-071). يقبل **إقراراً** لا ملفات:

```json
{"documents_acknowledged": true}
```

> **قيد أمني**: هذه النقطة **ترفض** أي حمولة ملف بحالة 422.
> التحقق الفعلي من الهوية ميزة منفصلة بمواصفة أمنية خاصة (المواصفة، الافتراضات).

### `PUT /teacher/application/step-4` (Sanctum)

```json
{"hourly_rate": "120.00", "currency": "QAR",
 "availability": [{"day_of_week": 0, "start_time": "16:00", "end_time": "18:00"}]}
```

**422** عند تداخل فترتين (FR-028).

### `POST /teacher/application/submit` (Sanctum)

**200**: `{"status": "submitted", "message": "طلبك قيد المراجعة من فريقنا الأكاديمي",
"expected_review_days": 3}`

يُصدر `TeacherApplicationSubmitted`.

### `GET /teacher/application` (Sanctum)

يعيد `current_step` و`step_data` لاستئناف المعالج (FR-070).

---

## قواعد عامة

- **منع الإرسال المزدوج** (FR-067 وحواف المواصفة): كل نقطة إنشاء تقبل ترويسة
  `Idempotency-Key`؛ تكرار المفتاح خلال 10 دقائق يعيد الاستجابة الأولى لا ينشئ سجلاً ثانياً.
- **حد المعدّل**: 5 محاولات تسجيل / دقيقة / عنوان شبكة.
- **التوجيه بعد الدخول** (FR-012): `POST /auth/login` تضيف `platform_role` إلى الاستجابة
  لتقرّر الواجهة الوجهة — لا إعادة توجيه من الخادم.
- كل الحقول الحساسة (`platform_role`, `is_super_admin`) **محجوبة** من كل استجابة.
