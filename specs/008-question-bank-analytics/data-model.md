# Data Model — 008 بنك الأسئلة والتقييم التحليلي

---

## أ — الطبقات الثلاث، مُصنَّفةً قبل أي عمود

الدستور §I يوجب تصنيف كل كيان جديد. **لا كيان من الصنف (أ) في هذه المرحلة**، وهذا قرارٌ
يُكتب لا فراغٌ يُترك — أقربها كان `accommodations` ورُفض بحجّةٍ في `research.md` §و.

| الكيان | الطبقة | لماذا |
|---|---|---|
| `concepts` | **مساحة عمل** | فكرةٌ يعرّفها المدرّس ويوسم بها بنكه |
| `questions` (‏موجود) · `question_options` · `exam_items` | **مساحة عمل** | إنتاج المدرّس |
| `rubric_criteria` | **مساحة عمل** | معيار تقييمٍ لسؤال المدرّس |
| `assignments` | **مساحة عمل** | واجبٌ في كورس المدرّس |
| `question_imports` · `question_stats` · `concept_stats` | **مساحة عمل** | عمل المدرّس ومعرفته عنه |
| `exam_attempts` (‏موجود) · `exam_answers` (‏موجود) · `attempt_items` · `grading_records` · `submissions` · `accommodations` | **جسر** | تحمل `workspace_id` للسياق وتشير إلى **الطالب العام**: التقييم يقع داخل مساحة عمل، والشخص واحدٌ عبر مدرّسيه |

⚠️ **والجسر ليس ترخيصاً بالقراءة.** `NFR-001أ`: مدرّسٌ لا يقرأ صفّاً يخصّ طالباً بلا تسجيل
نشط في مساحته — والحارس `EnrollmentDirectory` المشحون، لا `workspace_id` وحده.

---

## ب — البنك: ما يتغيّر في `questions`

```
questions  (قائم — يُعاد توجيهه)
  id · uuid ★ · workspace_id
  exam_id                          ⚠️ يُحذف بعد ملء exam_items منه
  concept_id ★      → concepts     ⚠️ إلزامي للجديد، ويُملأ للقائم بفكرة «غير مصنّف»
  lesson_id  ★      → lessons (nullable: سؤالٌ لا يخصّ درساً بعينه)
  difficulty (قائم) · bloom_level ★ (تعداد مغلق)
  type · content · points · explanation (قائمة)
  is_active ★  ⚠️ التعطيل بدل الحذف (FR-005)
  timestamps
```

⚠️ **`uuid` يُضاف الآن لأن السؤال صار كياناً يُشار إليه.** كان صفّاً داخلياً لاختبار، فلم
يحتج معرّفاً عاماً؛ صار له شاشةُ تصفّحٍ وبحثٍ ورابط. الإضافة على ثلاث هجرات كما فعلت ‎007‎
مع `payment_transactions`: عمودٌ قابل للإفراغ، ثم ملءٌ **بـ`chunkById` لا `chunk`** (‏شرطٌ
يتقلّص تحت ترقيمٍ بالإزاحة يقفز صفوفاً ويبلّغ نجاحاً)، ثم فهرسٌ فريد.

⚠️ **و«غير مصنّف» فكرةٌ حقيقية تُنشَأ لكل مساحة عمل**، لا `null`. `FR-002` يمنع سؤالاً ناقص
الوسوم، والأسئلة القائمة لا فكرة لها — فإمّا عمودٌ قابل للإفراغ يجعل «الإلزامي» كذبةً في
نصف الصفوف، أو صفٌّ صريح يراه المدرّس ويعرف أن عليه تصنيفه. الثاني.

```
concepts ★
  id · uuid · workspace_id · name · subject_id (nullable) · created_by · timestamps
  unique(workspace_id, name)        ← فكرتان بنفس الاسم عند مدرّسٍ واحد خطأُ إدخال

exam_items ★                        ← ضمّ سؤالٍ إلى اختبار
  id · uuid · workspace_id · exam_id · question_id
  order · points_override (nullable) · timestamps
  unique(exam_id, question_id)      ← السؤال مرّةً واحدة في الاختبار
  index(question_id)                ← «في أي اختباراتٍ يُستعمل هذا السؤال؟»
```

---

## ج — المحاولة: اللقطة والمقام

```
attempt_items ★                     ← ما رآه، مكتوباً عند البدء
  id · workspace_id · attempt_id · question_id
  order · points                    ← درجته في هذا الاختبار وقت البدء
  snapshot (json)                   ← النصّ · الخيارات وترتيبها · مجموعة الصحيح
  timestamps
  unique(attempt_id, question_id)

exam_answers  (قائم)
  + student_user_id ★               ⚠️ مُكرَّر عمداً — plan.md §Complexity
  + answer_text ★ (nullable)        ← المقاليّ
  + requires_grading ★ (bool)
  + graded_at ★ (nullable) · graded_by ★ (nullable)
  index(student_user_id, is_correct, created_at)   ← دفتر الأخطاء
  index(question_id, is_correct)                   ← نسبة الخطأ للسؤال

exam_attempts (قائم)
  + status: يضيف `pending_grading`
  + finalized_at ★ (nullable)       ← متى صارت الدرجة نهائية
```

---

## د — التصحيح المقالي

```
rubric_criteria ★
  id · uuid · workspace_id · question_id · label · max_points · order · timestamps
  ⚠️ مجموع max_points ≤ points السؤال — يُفرَض في الـAction (FR-028 · SC-010)

grading_records ★
  id · uuid · workspace_id · answer_id · rubric_criterion_id (nullable)
  points · comment (nullable)
  graded_by · graded_at
  revision_of (nullable) → grading_records   ← التعديل قيدٌ جديد لا كتابةٌ فوق
  revision_reason (nullable)                 ← إلزامي متى وُجد revision_of (FR-032)
  timestamps
  unique(answer_id, rubric_criterion_id, revision_of)
```

⚠️ **التعديل قيدٌ جديد يشير إلى ما عدّله**، لا `UPDATE`. `FR-032` يطلب «بمن نفّذه ومتى
وسببه» — وتحديثُ الصفّ يمحو الدرجة الأولى، فيصير السؤال «هل غُيّرت؟» بلا جواب. نفس شكل دفتر
الأرصدة في ‎006‎.

---

## هـ — الواجبات والتسليم

```
assignments ★
  id · uuid · workspace_id · course_id · lesson_id (nullable) · class_session_id (nullable)
  title · description · points · due_at
  submission_type (enum: text · file · questions)
  question_set_id (nullable) → exams        ← «مجموعة أسئلة من البنك» هي اختبارٌ بلا كشف
  late_policy (enum: accept · reject · penalise) · late_penalty_pct (nullable)
  status (draft · published) · published_at
  timestamps
  index(workspace_id, course_id, due_at)

submissions ★                                ← جسر
  id · uuid · workspace_id · assignment_id · student_user_id
  content_text (nullable) · media_asset_id (nullable) · attempt_id (nullable)
  submitted_at (nullable) · state (enum: on_time · late · missed)
  late_by_minutes (nullable)
  score (nullable) · feedback (nullable) · graded_by · graded_at
  extension_until (nullable)                 ← تأجيل لطالبٍ بعينه (FR-047)
  timestamps
  unique(assignment_id, student_user_id)     ← تسليمٌ واحد لكل طالب لكل واجب

accommodations ★                             ← جسر
  id · uuid · workspace_id · student_user_id
  extra_time_pct (nullable) · extended_days (nullable)
  granted_by · granted_at · revoked_by (nullable) · revoked_at (nullable) · reason
  index(workspace_id, student_user_id)
```

⚠️ **`state` عمودٌ لا اشتقاق، وهو الاستثناء الوحيد في المرحلة.** «متأخر» يقارن وقت التسليم
بموعدٍ **قابل للتمديد لطالبٍ بعينه**، و`FR-051` يوجب تعليم غير المُسلِّمين آلياً بعد مضيّ
الموعد — أي أن الحالة تتغيّر **بمرور الوقت لا بفعل أحد**، وهو بالضبط ما لا يستطيع الاشتقاق
عند القراءة أن يبلّغ عنه. المكنسة الليلية تكتب `missed`، والتسليم يكتب `on_time`/`late` وقت
وقوعه.

---

## و — التحليل

```
question_stats ★
  id · workspace_id · question_id
  attempts_count · wrong_count · wrong_pct (nullable)   ← null دون الحدّ الأدنى
  computed_at
  unique(question_id)

concept_stats ★
  id · workspace_id · concept_id · lesson_id (nullable)
  attempts_count · wrong_count · wrong_pct (nullable) · computed_at
  unique(workspace_id, concept_id, lesson_id)
```

⚠️ **`wrong_pct` قابل للإفراغ، ولا يساوي صفراً أبداً عند نقص البيانات** (‏`FR-013` ·
`research.md` §ك).

```
question_imports ★
  id · uuid · workspace_id · created_by · filename
  total_rows · imported_count · failed_count
  report (json)         ← صفٌّ صفّاً: الرقم والسبب
  status (queued · running · done · failed) · timestamps
```

---

## ز — شرط فتح الحصة التالية

```
unlock_rules ★
  id · uuid · workspace_id · course_id (nullable) · class_session_id (nullable)
  requires_attendance (bool) · requires_assignment (bool) · min_score_pct (nullable)
  is_active · timestamps
  ⚠️ نطاقٌ واحد على الأقل: كورس أو حصة. الاثنان فارغان قاعدةٌ تحكم كل شيء بلا أن يقصدها أحد.

unlock_exemptions ★
  id · uuid · workspace_id · student_user_id · class_session_id
  granted_by · reason · created_at
  unique(class_session_id, student_user_id)
```

**ولا عمود `is_unlocked` في أي جدول** — `research.md` §ح.

---

## ح — الفهارس، مُعلَنةً كما يوجب `NFR-009`

| الجدول | الفهرس | يخدم |
|---|---|---|
| `questions` | `(workspace_id, concept_id, difficulty)` | تصفّح البنك وترشيحه |
| `questions` | `(workspace_id, lesson_id)` | «أسئلة هذا الدرس» |
| `questions` | `unique(uuid)` | المسارات |
| `exam_items` | `unique(exam_id, question_id)` · `(question_id)` | الضمّ · وإعادة الاستعمال |
| `exam_answers` | `(student_user_id, is_correct, created_at)` | دفتر الأخطاء — **الطالب أوّلاً** |
| `exam_answers` | `(question_id, is_correct)` | تجميع نسبة الخطأ |
| `exam_answers` | `(requires_grading, graded_at)` | لوحة التصحيح: المنتظر |
| `submissions` | `unique(assignment_id, student_user_id)` · `(state, assignment_id)` | التسليم · المتأخرون |
| `assignments` | `(workspace_id, course_id, due_at)` | مكنسة `FR-051` |
| `accommodations` | `(workspace_id, student_user_id)` | تطبيقٌ آليّ عند كل تقييم |

⚠️ **فهرس دفتر الأخطاء يبدأ بالطالب لا بالسؤال.** المرشِّح الأول دائماً «أخطاء هذا الطالب»،
وفهرسٌ يبدأ بـ`question_id` يُقرأ كأنه يخدمه ولا يخدمه.

---

## ط — سلسلة الهجرات، **بهذا الترتيب**

الترتيب جزءٌ من التصميم لا تفصيلُ تنفيذ: خطوةٌ قبل موضعها تُسقط النشرة على بياناتٍ حيّة.

| # | الهجرة | لماذا هنا بالذات |
|---|---|---|
| ‎١‎ | `concepts` + فكرة «غير مصنّف» لكل مساحة عمل قائمة | الوسم الإلزامي يحتاج قيمةً تشير إليها قبل أن يصير إلزامياً |
| ‎٢‎ | `questions`: إضافة `uuid` قابلاً للإفراغ · `concept_id` · `lesson_id` · `bloom_level` · `is_active` | أعمدة بلا قيود بعد |
| ‎٣‎ | ملء `uuid` بـ**`chunkById`** وملء `concept_id` بفكرة «غير مصنّف» | ⚠️ `chunk` يرقّم بالإزاحة والشرط يتقلّص تحته — يقفز صفوفاً ويبلّغ نجاحاً |
| ‎٤‎ | `unique(uuid)` و`concept_id` غير قابل للإفراغ | بعد الملء وحده |
| ‎٥‎ | `exam_items` + ملؤه من `questions.exam_id` | الضمّ يُبنى **قبل** أن يُحذف مصدره |
| ‎٦‎ | حذف `questions.exam_id` | ⚠️ آخر خطوةٍ لا رجعة فيها؛ حدود التراجع مكتوبة في الهجرة |
| ‎٧‎ | `attempt_items` · أعمدة `exam_answers` الجديدة · ملء `student_user_id` من `exam_attempts` | ملءٌ لمرّةٍ واحدة، ثم لا يتغيّر |
| ‎٨‎ | `rubric_criteria` · `grading_records` · حالة `pending_grading` | التصحيح |
| ‎٩‎ | `assignments` · `submissions` · `accommodations` | الواجبات |
| ‎١٠‎ | `question_stats` · `concept_stats` · `question_imports` | التحليل والاستيراد |
| ‎١١‎ | `unlock_rules` · `unlock_exemptions` | يقرأ ‎٩‎، فيأتي بعدها |
| ‎١٢‎ | الفهارس المُعلَنة في §ح | بعد استقرار الأعمدة |

⚠️ **و‎٣‎ قبل ‎٤‎ ليست مصادفة**: نفس درس ‎016‎ — الترقيم قبل الفهرس، وإلّا سقطت النشرة على
صفوفٍ تحمل تكراراً أو فراغاً.

---

## ي — ما يسقط من المواصفة عمداً

| مرشَّح | الحكم | لماذا |
|---|---|---|
| `mistake_entries` | **يسقط** | مشتقّ — `research.md` §ج |
| `question_versions` | **يسقط** | اللقطة على عناصر المحاولة — §ب |
| `is_unlocked` على أي جدول | **يسقط** | يُحسَب عند كل طلب — §ح |
| جدول «نتيجة» رابع | **يسقط** | الدرجة على المحاولة والتسليم، كما اليوم |
