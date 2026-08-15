# API Contract — 008

---

## ١ — الصلاحيات الجديدة

| الثابت | يصل إلى | الطبقة |
|---|---|---|
| `bank.manage` ★ | إنشاء أسئلة البنك ووسمها واستيرادها وتعطيلها | مستأجر — المدرّس |
| `bank.view` ★ | تصفّح البنك والبحث فيه بلا تعديل | مستأجر — المدرّس والمساعد |
| `grading.perform` ★ | تصحيح المقاليّ وكتابة التعليق | مستأجر — **المدرّس، والمساعد إن مُنح** (`FR-031`) |
| `grading.revise` ★ | تعديل درجةٍ مصحَّحة بسببٍ مسجَّل | مستأجر — المدرّس وحده |
| `analytics.questions.view` ★ | نِسَب الخطأ داخل مساحة العمل | مستأجر — المدرّس |
| `analytics.cross_teacher.view` ★ | التحليل عابراً للمدرّسين (`FR-015`) | **منصّة** — لا يحمله دورٌ مستأجر |
| `assignments.manage` ★ | إنشاء الواجبات ونشرها وضبط سياستها | مستأجر — المدرّس |
| `submissions.grade` ★ | اعتماد درجة تسليم | مستأجر — المدرّس والمساعد إن مُنح |
| `accommodations.manage` ★ | منح تسهيلٍ أو سحبه | مستأجر — المدرّس |
| `unlock_rules.manage` ★ | ضبط شرط فتح الحصة والاستثناءات | مستأجر — المدرّس |

⚠️ **الثمانية المستأجرة تدخل `RolePermissionMatrix` فتظهر في شاشة الأدوار تلقائياً** —
وهي الآن قناة تسليم `FR-031`: المدرّس يمنح مساعده التصحيح **بلا نشرة**. والمنصّية تبقى خارج
كل دورٍ مستأجر، فيرفضها مالك المساحة كما ترفض الشاشةُ عرضَها.

---

## ٢ — المسارات

### ٢أ — البنك (المدرّس)

| Method | Path | الصلاحية |
|---|---|---|
| `GET` | `/api/v1/manage/bank/questions` | `bank.view` — ترشيح بالفكرة والدرس والصعوبة والمستوى والنصّ |
| `POST` · `PATCH` | `/api/v1/manage/bank/questions[/{uuid}]` | `bank.manage` |
| `DELETE` | `/api/v1/manage/bank/questions/{uuid}` | `bank.manage` — **تعطيل، لا حذف** إن كانت له محاولات (`FR-005`) |
| `GET` · `POST` | `/api/v1/manage/bank/concepts` | `bank.manage` |
| `POST` | `/api/v1/manage/bank/imports` | `bank.manage` — يردّ `202` ومعرّف الاستيراد |
| `GET` | `/api/v1/manage/bank/imports/{uuid}` | `bank.manage` — التقرير صفّاً صفّاً |
| `GET` · `PUT` | `/api/v1/manage/exams/{uuid}/items` | `exams.update` — ضمّ أسئلة البنك وترتيبها |

⚠️ **`PUT` للعناصر يرسل القائمة كاملة**، على سابقة إعادة ترتيب الشجرة في ‎016‎: إرسال
«العنصر س صار في الموضع ٣» لا يقول ماذا حلّ بجاره، والقائمة الكاملة هي الجواب الوحيد الذي
لا يحتاج تخميناً.

### ٢ب — التصحيح

| Method | Path | الصلاحية |
|---|---|---|
| `GET` | `/api/v1/manage/grading/queue` | `grading.perform` — المنتظر، مُرشَّحاً ومرتَّباً (`FR-027`) |
| `GET` | `/api/v1/manage/grading/attempts/{uuid}` | `grading.perform` — الإجابات ولقطاتها ومعاييرها |
| `POST` | `/api/v1/manage/grading/answers/{uuid}` | `grading.perform` — درجات المعايير وتعليق |
| `POST` | `/api/v1/manage/grading/answers/{uuid}/revise` | `grading.revise` — **السبب إلزامي** |

⚠️ **إخفاء الهوية خيارُ إعدادٍ على المساحة (`FR-033`)، ويُطبَّق في الـResource لا في
الواجهة**: صفحةٌ تخفي الاسم بينما الحمولة تحمله هي إخفاءٌ يكشفه فتحُ أدوات المطوّر.

### ٢ج — الاختبار الذاتي ودفتر الأخطاء (الطالب)

| Method | Path | الصلاحية |
|---|---|---|
| `GET` | `/api/v1/mistakes` | ملكية الصفّ — أخطاؤه هو، مرشَّحةً بالفكرة والدرس والفترة |
| `POST` | `/api/v1/practice/from-mistakes` | `attempts.submit` + `throttle:practice` |
| `POST` | `/api/v1/practice/exams` | `attempts.submit` + `throttle:practice` — فكرة وصعوبة وعدد ومدّة |

⚠️ **`throttle:practice` محدِّدٌ مسمّى جديد** (`FR-026`). إنشاء `throttle:5,1` مضمَّن ممنوع:
`ThrottleRequests` يفهرس الضيف بـ`domain|ip` بلا المسار، فكل حدٍّ مضمَّن يتشارك عدّاداً
واحداً ويفوز أشدّها.

⚠️ **ولا اختبار ذاتي يدخل كشف التقديرات** (`FR-025` · `SC-009`): المحاولة تُوسَم `is_practice`
والكشف يُرشِّحها — والوسم **على المحاولة لا على الاختبار**، لأن الاختبار نفسه قد يُحلّ رسمياً
وتدريباً.

### ٢د — الواجبات

| Method | Path | الصلاحية |
|---|---|---|
| `GET` · `POST` · `PATCH` | `/api/v1/manage/assignments[/{uuid}]` | `assignments.manage` |
| `GET` | `/api/v1/assignments` | التسجيل — **المنشور وحده** |
| `POST` | `/api/v1/assignments/{uuid}/submissions` | التسجيل + `throttle:uploads` |
| `GET` | `/api/v1/submissions/{uuid}/file` | **`middleware('signed')`** — قصير العمر، يُوقَّع لقارئٍ اجتازت سياستُه |
| `POST` | `/api/v1/manage/submissions/{uuid}/grade` | `submissions.grade` |
| `POST` · `DELETE` | `/api/v1/manage/accommodations` | `accommodations.manage` |

### ٢هـ — شرط الفتح والتحليل

| Method | Path | الصلاحية |
|---|---|---|
| `GET` · `PUT` | `/api/v1/manage/unlock-rules` | `unlock_rules.manage` |
| `POST` | `/api/v1/manage/unlock-exemptions` | `unlock_rules.manage` — **السبب إلزامي** |
| `GET` | `/api/v1/manage/analytics/questions` | `analytics.questions.view` — من التجميع |
| `GET` | `/api/v1/sessions/{uuid}/eligibility` | التسجيل — **ما ينقص الطالب بالضبط** (`FR-038`) |

⚠️ **`eligibility` مسارٌ قائم بذاته لأن الرفض يجب أن يقول ماذا ينقص.** «غير متاح» بلا سبب
يحوّل ميزةَ تحفيزٍ إلى عطلٍ يراسل الطالبُ مدرّسَه عنه.

---

## ٣ — الحمولات: ما يُمنع ظهوره

- **إجابةُ طالبٍ أو درجتُه في حمولةٍ يقرؤها طالبٌ آخر** (`FR-020` · `FR-049`)
- **وجود تسهيلٍ لطالب، في أي حمولةٍ يقرؤها زميلٌ له** (`FR-056`)
- **هوية الطالب في حمولة التصحيح متى فُعّل الإخفاء** (`FR-033`)
- **الإجابة الصحيحة قبل التسليم** — لقطة `attempt_items` تُقدَّم للطالب **بلا مجموعة الصحيح**
- **رابط ملف تسليمٍ دائم** (`FR-048`)

**والحارس الآليّ** على شاكلة `PublicFieldAllowlist` و`StudentBalanceAllowlist` المشحونين:
`AssessmentFieldAllowlist` ★ يمشي قائمةً **مُعدَّدة** من حمولات المرحلة — ويكتب في ترويسته
ما لا يحرسه، لأن حارساً موصوفاً بلا كتابةٍ يُقرأ كتغطية.

---

## ٤ — الأحداث

**يُطلَق:** `QuestionImported` ★ · `AttemptPendingGrading` ★ · `AttemptFinalized` ★ ·
`AssignmentSubmitted` ★ · `SubmissionGraded` ★ · `MistakeResolved` ★ — و`ExamPassed`/
`ExamFailed` القائمان **يُؤجَّلان** إلى اكتمال الدرجة.

**يُستهلَك:** `SessionDelivered` (‏005) لا يُلمس هنا؛ شرط الفتح **يقرأ** بعقد ولا يشترك في
حدث. والتفاصيل في [`events.md`](./events.md).
