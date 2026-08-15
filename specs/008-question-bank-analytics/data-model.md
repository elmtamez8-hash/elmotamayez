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
| `unlock_rules` | **مساحة عمل** | قاعدةٌ يضعها المدرّس، لا تخصّ شخصاً |
| `exam_attempts` (‏موجود) · `exam_answers` (‏موجود) · `attempt_items` · `grading_records` · `submissions` · `accommodations` · `unlock_exemptions` | **جسر** | تحمل `workspace_id` للسياق وتشير إلى **الطالب العام**: التقييم يقع داخل مساحة عمل، والشخص واحدٌ عبر مدرّسيه |

⚠️ **والجسر ليس ترخيصاً بالقراءة.** `NFR-001أ`: مدرّسٌ لا يقرأ صفّاً يخصّ طالباً بلا تسجيل
نشط في مساحته — والحارس `EnrollmentDirectory` المشحون، لا `workspace_id` وحده.

⚠️ **و`NFR-001ب` كما هو مكتوب لا يحرس هذه المرحلة بحرف.** هو يوجب حالةَ اختبارٍ لكل كيان
**مملوك للمنصّة**، و«لا كيان من الصنف (أ) هنا» — فالالتزام يُستوفى بصفر اختبارات، بينما كل
سطح الخطر في ‎008‎ هو طبقة **الجسر**: سبعة كيانات تشير إلى طالبٍ عام. فيسري على كل جسرٍ منها
اختبارٌ باتجاهين: مدرّسٌ بلا تسجيلٍ نشط **يُمنع**، والطالب **يرى صفّه**.

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
  + uuid ★                          ⚠️ الجدول لا يحمله اليوم، ومسارا التصحيح يربطان به
  + student_user_id ★               ⚠️ مُكرَّر عمداً — plan.md §Complexity
  + answer_text ★ (nullable)        ← المقاليّ
  + requires_grading ★ (bool)
  + graded_at ★ (nullable) · graded_by ★ (nullable)
  + grading_version ★ (int, default 0)   ← رمز المطالبة عند التعديل
  unique(attempt_id, question_id)   ← تسليمٌ مكرَّر لا يضاعف الإجابات
  unique(uuid)
  index(student_user_id, question_id, is_correct, created_at)   ← دفتر الأخطاء
  index(workspace_id, question_id, is_correct)                  ← نسبة الخطأ للسؤال
  index(workspace_id, requires_grading, graded_at)              ← لوحة التصحيح

exam_attempts (قائم)
  + status: يضيف `pending_grading`
  + finalized_at ★ (nullable)       ← متى صارت الدرجة نهائية
  + is_practice ★ (bool, default false)   ← Q4: الوسم على المحاولة لا على الاختبار
  + exam_id: يصير قابلاً للإفراغ    ⚠️ المحاولة التدريبية لا اختبار لها
  index(workspace_id, status, submitted_at)   ← لوحة التصحيح: المنتظر، مرتَّباً
```

⚠️ **`uuid` على `exam_answers` ليس تجميلاً: بدونه لا يوجد مسار تصحيحٍ أصلاً.** الجدول
المشحون (`2026_07_20_000300_create_assessments_tables.php`) `id` بلا `uuid`، والنموذج بلا
`HasUuid` — و`POST /manage/grading/answers/{uuid}` يربط به. الخيار الوحيد الآخر كشفُ المعرّف
المتسلسل، أي خرقُ عقدٍ يسري على كل مسارٍ في المنتج.

⚠️ **و`unique(attempt_id, question_id)` هو الحارس الذي تفتقده `NFR-011` اليوم.** التقديم
المشحون يقرأ `isGraded()` ثم يكتب في حلقة (`AttemptController::submit` ثم `GradeAttempt`)،
وهو تعريف السباق: نقرتان متزامنتان تمرّان كلتاهما فتُكتب مجموعة الإجابات **مرّتين**. والأثر
يمتدّ إلى هذه المرحلة مباشرةً — الخطأ يُعدّ مرّتين في الدفتر، و`wrong_pct` تُحسب على مقامٍ
مضاعَف، أي أن رقماً يقرّر حذف سؤالٍ من البنك مبنيٌّ على تكرار. والقيد وحده لا يكفي:
`SubmitAttempt` يطالب أولاً بـ`UPDATE exam_attempts SET status='grading' WHERE id = ? AND
status = 'in_progress'`، والصفر المُعاد هو الرفض.

⚠️ **وفهرس الدفتر يحمل `question_id` ثانياً، لا ثالثاً.** «مُصلَح» سؤالٌ يُسأل عن **السؤال
نفسه للطالب نفسه لاحقاً** (‏`research.md` §ج) — وفهرسٌ بلا `question_id` يجعل كل صفٍّ في
الدفتر يمسح كل إجاباتِ الطالبِ الصحيحةَ ثم يرشّح. طالبٌ بخمسين خطأً وألفي إجابة صحيحة يدفع
مئة ألف زيارة صفٍّ لفتح صفحة — تنمو مع **تاريخه** لا مع طول الصفحة، وهو نصّ ما يمنعه
`NFR-010`. والقراءة نفسها تُجمَّع في استعلامٍ واحد لكل صفحة، لا استعلامٍ لكل خطأ.

⚠️ **وصفّ الإجابة يُكتب لكل `attempt_item`، لا للمُجاب عنه وحده.** التصحيح المشحون يمشي على
**الحمولة** (`GradeAttempt::handle()` يدور على `$answersPayload`)، فالسؤال الذي تركه الطالب
بلا إجابة **لا صفّ له**. ودفترُ الأخطاء مشتقٌّ من `where is_correct = false`، فالسؤال المتروك
يغيب عنه — **وهو أقوى دليلٍ على فجوةٍ معرفية في الصفحة كلّها**: من ترك سؤالاً لم يعرف من
أين يبدأ، ومن أخطأ فيه عرف وأخطأ. صفٌّ بإجابةٍ فارغة و`is_correct = false` هو الفرق بين
دفترٍ يقول الحقيقة ودفترٍ يجامل.

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
  index(answer_id, graded_at)
```

⚠️ **التعديل قيدٌ جديد يشير إلى ما عدّله**، لا `UPDATE`. `FR-032` يطلب «بمن نفّذه ومتى
وسببه» — وتحديثُ الصفّ يمحو الدرجة الأولى، فيصير السؤال «هل غُيّرت؟» بلا جواب. نفس شكل دفتر
الأرصدة في ‎006‎.

⚠️ **ولا قيدَ فريداً على هذا الجدول، لأن القيد الذي كان مكتوباً هنا لا يعضّ.**
`unique(answer_id, rubric_criterion_id, revision_of)` وعمودان منه قابلان للإفراغ: **NULL لا
يصطدم بـNULL** — لا في MySQL ولا في SQLite. المشروع يعرف هذه الخاصّية ويستعملها **عمداً**
في `captured_order_id` بتعليقٍ يشرحها، وكتابتُها هنا كحارسٍ تجعلها تعمل عكس ما يُظَنّ:
إجابةٌ مقالية **بلا معايير** (‏و`FR-028` يقول «يقبل معايير» لا «يوجبها») تحمل `NULL` في
العمودين، فيُدرج مصحّحان درجتين متعارضتين وكلاهما ينجح. أي أن `FR-035` كان محروساً بقيدٍ
يخرج من الخدمة في الحالة الأساسية بالضبط.

**والمطالبة تقع على `exam_answers` حيث يوجد عمودٌ قابل للإفراغ يصلح رمزاً:**

```
أوّل تصحيح:  UPDATE exam_answers SET graded_at = ?, graded_by = ?
             WHERE id = ? AND graded_at IS NULL
تعديل:       UPDATE exam_answers SET grading_version = grading_version + 1
             WHERE id = ? AND grading_version = ?
```

**والصفر المُعاد هو الكشف** — ثم تُدرَج صفوف `grading_records` داخل المعاملة نفسها. وهذا هو
ما قصده `research.md` §هـ؛ الجدول الذي كُتبت عليه الجملة كان الخطأ، لا الجملة: `grading_records`
إلحاقيّ و`graded_at` فيه غير قابل للإفراغ، فـ`WHERE graded_at IS NULL` عليه **لا يطابق صفّاً
أبداً** ولا يوجد صفٌّ ليُحدَّث قبل الإدراج أصلاً.

---

## هـ — الواجبات والتسليم

```
assignments ★
  id · uuid · workspace_id · course_id · lesson_id (nullable) · class_session_id (nullable)
  title · description · points · due_at
  submission_type (enum: text · file · questions)
  question_set_id (nullable) → exams        ← «مجموعة أسئلة من البنك» هي اختبارٌ بلا كشف
  late_policy (enum: accept · reject · penalise)
  late_penalty_pct_per_day (nullable) · late_penalty_cap_pct (nullable)   ← Q6
  status (draft · published) · published_at
  timestamps
  index(workspace_id, course_id, due_at)

submissions ★                                ← جسر
  id · uuid · workspace_id · assignment_id · student_user_id
  content_text (nullable) · media_asset_id (nullable) · attempt_id (nullable)
  submitted_at (nullable) · state (enum: on_time · late · missed)
  late_by_minutes (nullable)
  score (nullable) · late_penalty_applied_pct (nullable) · feedback (nullable)
  graded_by · graded_at
  extension_until (nullable)                 ← تأجيل لطالبٍ بعينه (FR-047)
  timestamps
  unique(assignment_id, student_user_id)     ← تسليمٌ واحد لكل طالب لكل واجب

accommodations ★                             ← جسر
  id · uuid · workspace_id · student_user_id
  extra_time_pct (nullable) · extended_days (nullable)
  granted_by · granted_at · revoked_by (nullable) · revoked_at (nullable) · reason
  index(workspace_id, student_user_id)
```

⚠️ **والمكنسة تُنشئ الصفّ، ولذلك تمرّر `uuid` صراحةً — وإلّا شلّت الجدول كلّه على MySQL.**
غير المُسلِّم **لا صفّ له**، فمكنسة `FR-051` تُدرج صفّاً لكل طالبٍ مسجَّل لم يسلّم. والشكل
الطبيعي `insertOrIgnore` في دفعة، **وهو لا يُقلع النموذج فلا يعمل `HasUuid`**: يصل `uuid`
فارغاً، فيخفّض MySQL الانتهاك إلى تحذير ويخزّن `''` — وبعدها **كل تسليمٍ لاحق على المنصّة
كلّها** يصطدم بذلك الصفّ على `unique(uuid)`، فيُقرأ «مسجَّل سلفاً» ويُتخطّى بصمت. المكنسة
تبلّغ نجاحاً والطلاب يسلّمون ولا يُحفظ شيء. **وSQLite لا يُظهر هذا إطلاقاً.** فـ`uuid`
و`created_at`/`updated_at` تُمرَّر في مصفوفة الإدراج (‏سابقة `CreditLedger::writeEntry()`)،
ويُقرأ الصفّ بعدها — والصفر يعني **إمّا** تكراراً **وإمّا** فشلاً مبتلَعاً.

⚠️ **والمكنسة لا تكتب فوق تسليمٍ وقع.** طالبٌ يسلّم ‎23:59:58‎ ومكنسةٌ تمشي ‎00:00:00‎ سباقٌ
حقيقي، فالتحديث `WHERE submitted_at IS NULL` لا كتابةٌ عمياء. **وتستشير `accommodations`
قبل أن تحكم**: طالبٌ مُنح يومين إضافيين يُوسَم `missed` في الليلة الأولى فيحجبه شرطُ الفتح —
وهو أوّل من بُني له التسهيل.

⚠️ **والتأجيل يُمنح قبل وجود صفّ.** `extension_until` يعيش على `submissions` و`FR-047` يمنح
التأجيل لطالبٍ **لم يسلّم بعد**، فالمنح يُنشئ الصفّ بـ`submitted_at = NULL` — ومكنسةُ
`missed` تميّز «صفٌّ للتأجيل» من «تسليمٌ وقع» بذلك العمود نفسه، لا بوجود الصفّ.

⚠️ **`state` عمودٌ لا اشتقاق، وهو الاستثناء الوحيد في المرحلة.** «متأخر» يقارن وقت التسليم
بموعدٍ **قابل للتمديد لطالبٍ بعينه**، و`FR-051` يوجب تعليم غير المُسلِّمين آلياً بعد مضيّ
الموعد — أي أن الحالة تتغيّر **بمرور الوقت لا بفعل أحد**، وهو بالضبط ما لا يستطيع الاشتقاق
عند القراءة أن يبلّغ عنه. المكنسة الليلية تكتب `missed`، والتسليم يكتب `on_time`/`late` وقت
وقوعه.

⚠️ **وكل عمود درجةٍ أو نسبةٍ يُعلَن نوعه، وبإشارة حيث يمرّ الحساب بالسالب.** العرف السائد
في هذه الوحدة `unsignedSmallInteger` (‏`questions.points` · `exam_answers.points`)، وخطأٌ
حسابيٌّ واحد يكتب `-5` في `submissions.score`: **SQLite يخزّنه بلا شكوى والاختبار يمرّ**،
وMySQL الصارم يردّ `Out of range`، أو `ERROR 1690` إن وقعت المقارنة داخل SQL — الفخّ الذي
كلّف ‎006‎ ثمنه وحُلّ بـ`CAST(… AS SIGNED)`. فـ`score` و`late_penalty_applied_pct` **بإشارة**،
و`rubric_criteria.max_points` و`grading_records.points` `decimal(5,2)` لأن نصف الدرجة موجود
في التصحيح المقالي — وعمودٌ صحيح يقصّ ‎٢٫٥‎ إلى ‎٢‎ **بعد** أن يمرّ فحصُ `SUM ≤ points` على
الرقم غير المقصوص، فيُخزَّن مجموعٌ يخالف `SC-010`. والنِّسَب الخمس بمدى مُعلَن ‎٠‎–‎١٠٠‎.

⚠️ **و`late_penalty_applied_pct` يُكتب مرّةً وقت الاعتماد، ولا يُشتقّ بعدها** — على سابقة
`billable_seats` في ‎005‎. الخصم مشتقٌّ من سياسة الواجب، **والسياسة عمودٌ قابل للتعديل**: مدرّسٌ
يخفّف سياسته في آخر الفصل يعيد تسعير كل تسليمٍ صُحِّح قبلها لو كان الرقم يُحسب عند القراءة.
والدرجة المعتمَدة جوابٌ عن لحظةٍ مضت. **وقاعُ الصفر يُفرَض في الـAction** (`FR-046أ`) — لا
عمودَ يحرسه.

---

## و — التحليل

```
question_stats ★
  id · workspace_id · question_id
  attempts_count · wrong_count · wrong_pct (nullable)   ← null دون الحدّ الأدنى
  computed_at
  unique(question_id)

concept_stats ★
  id · workspace_id · concept_id · lesson_id (NOT NULL، والصفر يعني «الفكرة إجمالاً»)
  attempts_count · wrong_count · wrong_pct (nullable) · computed_at
  unique(workspace_id, concept_id, lesson_id)
```

⚠️ **`lesson_id = 0` قيمةٌ حارسة، لا `NULL`** — للسبب نفسه في §د. صفّ «الفكرة إجمالاً» هو
بالضبط الصفّ الذي يقرؤه كل شيء، و`NULL` فيه يجعل `upsert()` **لا يطابق شيئاً فيُدرج**: صفٌّ
جديد كل ليلة، ثلاثون صفّاً بعد شهر، والشاشة تعرض ما يعيده أوّل صفٍّ — رقماً عمره ثلاثون
يوماً بينما الحساب الصحيح في صفٍّ آخر. و`FR-014` («تُقرأ من تجميعٍ مُحدَّث») تفشل بلا خطأ
واحد في السجلّ.

⚠️ **واختبارُ هذا يشغّل الوظيفة مرّتين ويؤكّد `count() === 1`.** تشغيلةٌ واحدة تمرّ خضراء
إلى الأبد وتثبت العكس.

⚠️ **والتجميع يستثني `is_practice`.** الوسم على `exam_attempts` والتجميع على `exam_answers`،
فالوصلة إجبارية — وبدونها تخلط نِسَبُ `FR-011` تدريبَ الطالب باختبار المدرّس، ورقمٌ يقرّر
حذف سؤالٍ يُحسب على محاولاتٍ لم تكن اختباراً.

⚠️ **`wrong_pct` قابل للإفراغ، ولا يساوي صفراً أبداً عند نقص البيانات** (‏`FR-013` ·
`research.md` §ك).

```
question_imports ★
  id · uuid · workspace_id · created_by · filename
  total_rows · imported_count · failed_count · skipped_count
  duplicate_policy (enum: skip · create)   ← يُختار عند الرفع (Q7)، لا يُسأل عنه لاحقاً
  report (json)         ← صفٌّ صفّاً: الرقم والسبب
  status (queued · running · done · failed) · timestamps
```

⚠️ **و«تخطٍّ» يحتاج مفتاحاً طبيعياً، وإلّا فهي قراءةٌ ثم كتابة بحكم التعريف.** لا شيء في
`questions` يعبّر عن «مكرّر»، فسياسة `skip` تُنفَّذ حتماً كـ«ابحث ثم أدرج» — نافذتان للمدرّس
نفسه تُدرجان معاً، **وإعادةُ محاولة Horizon بعد مهلةٍ منتصف الملف تُعيد استيراد ما التزم
إدراجه**. فيُضاف `content_hash` على `questions` مع `unique(workspace_id, content_hash)`
فيصير التخطّي حكماً من المحرّك، وتنتقل `status` من `queued` بمطالبة `UPDATE … WHERE status =
'queued'` فتصير الوظيفة غير قابلة لإعادة التنفيذ.

---

## ز — شرط فتح الحصة التالية

```
unlock_rules ★
  id · uuid · workspace_id · course_id (NOT NULL، والصفر = «كل الكورسات»)
  requires_attendance (bool) · requires_assignment (bool) · min_score_pct (nullable)
  is_active · timestamps
  unique(workspace_id, course_id)   ← قاعدةٌ واحدة لكل نطاق، ويعضّ فعلاً

  الأسبقية: كورس ← مساحة العمل (course_id = 0). الأخصّ الموجود يفوز، ولا اندماج.

unlock_exemptions ★
  id · uuid · workspace_id · student_user_id · class_session_id
  granted_by · reason · created_at
  unique(class_session_id, student_user_id)
```

**ولا عمود `is_unlocked` في أي جدول** — `research.md` §ح.

⚠️ **والصفّ `course_id = 0` هو الافتراضي المقصود، لا خطأً** (‏`Q5`). المدرّس يضبط شرطه مرّةً
فيسري على كورساته كلّها، ويخصّص حيث يختلف. وغيابُ صفّ الكورس **رجوعٌ إلى الافتراضي، لا
تعطيلٌ للشرط** — ولو قُرئ الغياب تعطيلاً، لصار كل كورسٍ جديد يُنشئه المدرّس مفتوحاً بلا شرط
وهو يظنّه محروساً. وتعطيلُ الشرط لكورسٍ بعينه فعلٌ صريح: صفٌّ بـ`is_active = false`.

⚠️ **والصفر بدل `NULL` هنا ليس ذوقاً.** الصفّ الافتراضي هو **الصفّ الوحيد الذي تقوم عليه
الأسبقية كلّها**، وقيدٌ فريد يحوي عموداً قابلاً للإفراغ لا يحرسه (‏§د). فمدرّسٌ ينقر «حفظ»
مرّتين يُنشئ افتراضيَّين، والمحلِّل يلتقط أحدهما بترتيبٍ لا يضمنه المحرّك: طالبان بالدرجة
نفسها، يُفتح لأحدهما ويُمنع الآخر — **وجواب `eligibility` يسمّي القاعدة التي حكمت، فيبلّغ
رقمين مختلفين لإعدادٍ واحد**.

⚠️ **ولا مستوى ثالث على مستوى الحصة.** `Q5` و`FR-037` ينصّان على مستويين، وطبقةٌ أخصّ من
الاثنين تُشحن بلا متطلَّبٍ يحكمها ولا اختبارٍ يمسّها — وهي التي تغلبهما حين تُملأ بالخطأ.

---

## ح — الفهارس، مُعلَنةً كما يوجب `NFR-009`

| الجدول | الفهرس | يخدم |
|---|---|---|
| `questions` | `(workspace_id, concept_id, difficulty)` | تصفّح البنك وترشيحه |
| `questions` | `(workspace_id, lesson_id)` | «أسئلة هذا الدرس» |
| `questions` | `unique(uuid)` | المسارات |
| `exam_items` | `unique(exam_id, question_id)` · `(question_id)` | الضمّ · وإعادة الاستعمال |
| `questions` | `unique(workspace_id, content_hash)` | التخطّي عند الاستيراد |
| `exam_answers` | `(student_user_id, question_id, is_correct, created_at)` | دفتر الأخطاء — **الطالب أوّلاً، والسؤال ثانياً** |
| `exam_answers` | `(workspace_id, question_id, is_correct)` | تجميع نسبة الخطأ |
| `exam_answers` | `(workspace_id, requires_grading, graded_at)` | لوحة التصحيح: المنتظر |
| `exam_attempts` | `(workspace_id, status, submitted_at)` | لوحة التصحيح: الترشيح والفرز |
| `submissions` | `unique(assignment_id, student_user_id)` · `(state, assignment_id)` | التسليم · المتأخرون |
| `assignments` | `(workspace_id, course_id, due_at)` | شاشة واجبات الكورس |
| `assignments` | `(status, due_at)` | **مكنسة `FR-051`** — عابرةٌ للمساحات |
| `assignments` | `(class_session_id)` | «ما واجب هذه الحصة؟» — يُسأل عند كل فحص فتح |
| `class_sessions` | `(workspace_id, course_id, starts_at)` ★ | «ما الحصة السابقة في هذا الكورس؟» |
| `accommodations` | `(workspace_id, student_user_id)` | تطبيقٌ آليّ عند كل تقييم |

⚠️ **فهرس دفتر الأخطاء يبدأ بالطالب، ويحمل السؤال ثانياً.** المرشِّح الأول دائماً «أخطاء هذا
الطالب» — لكن اشتقاق «مُصلَح» يسأل عن **السؤال نفسه**، فبدونه يمسح كل صفٍّ إجاباتِ الطالب
الصحيحةَ كلَّها.

⚠️ **وفهرس المكنسة لا يبدأ بمساحة العمل.** مكنسة `FR-051` عابرةٌ للمساحات ومسنودةٌ على
`due_at < now` و`status = published` (‏`FR-042`)، وعمودُ المساحة في المقدّمة يجبرها على
المرور مساحةً مساحة. الفهرس `(workspace_id, course_id, due_at)` صحيحٌ **لشاشة الواجبات**
وخاطئٌ للوصف الذي كان مكتوباً بجانبه — فهما فهرسان لسؤالين.

⚠️ **وفهرسان يخصّان جدولاً ليس لهذه المرحلة.** `class_sessions.course_id` شُحن قابلاً
للإفراغ **بلا فهرس** وليس في مقدّمة أيٍّ من فهارسه الثلاثة، و`assignments.class_session_id`
جديد. وشرطُ الفتح يُقيَّم **عند كل طلب** (‏`FR-041`) ويمرّ بالعمودين معاً — أي أن المدخلين
المفهرسين هما المدخلان اللذان لم تضفهما هذه المرحلة. الهجرة تخصّ ‎005‎ وتُكتب هنا لأن هذه
المرحلة هي التي تجعل العمود ساخناً.

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
| ‎٦‎ | حذف `questions.exam_id` | ⚠️ **نشرةٌ تالية منفصلة** — انظر أدناه |
| ‎٧‎ | `attempt_items` · أعمدة `exam_answers` الجديدة · ملء `student_user_id` و`uuid` و`attempt_items` للمحاولات القائمة | ملءٌ لمرّةٍ واحدة، ثم لا يتغيّر |
| ‎٨‎ | `rubric_criteria` · `grading_records` · حالة `pending_grading` | التصحيح |
| ‎٩‎ | `assignments` · `submissions` · `accommodations` | الواجبات |
| ‎١٠‎ | `question_stats` · `concept_stats` · `question_imports` | التحليل والاستيراد |
| ‎١١‎ | `unlock_rules` · `unlock_exemptions` | يقرأ ‎٩‎، فيأتي بعدها |
| ‎١٢‎ | الفهارس المُعلَنة في §ح | بعد استقرار الأعمدة |

⚠️ **و‎٣‎ قبل ‎٤‎ ليست مصادفة**: نفس درس ‎016‎ — الترقيم قبل الفهرس، وإلّا سقطت النشرة على
صفوفٍ تحمل تكراراً أو فراغاً.

### والترتيب صحيحٌ داخل `migrate` واحد — وهذا ليس كافياً

الهجرة لا تعمل في فراغ: الحاويات القديمة والعمّال القدامى يظلّون قيد التشغيل أثناءها وبعدها
بلحظات. ثلاث خطواتٍ تسقط لذلك:

- **‎٦‎ تُشحن في نشرةٍ تالية، منفصلةً عن ‎١‎–‎٥‎.** حذف `questions.exam_id` بينما عاملُ طابورٍ
  قديم لم يُعَد تشغيله يُنفّذ `$attempt->load('exam.questions.options')` يعني
  `Unknown column 'questions.exam_id'` على **كل** صفحة اختبار وكل تصحيح حتى تكتمل النشرة.
  ‏`Exam::questions()` علاقةٌ على العمود، و`exam_id` في `$fillable`، و`GradeAttempt` يبدأ به.
- **‎٤‎ تحتاج الكود الجديد أوّلاً.** بين نهاية الملء وبداية `NOT NULL`، الكود القديم لا يزال
  يستقبل إنشاء سؤال و`SaveQuestion` القديم لا يعرف `concept_id` — صفٌّ واحد في تلك النافذة
  **يُسقط الهجرة على الإنتاج في منتصف السلسلة**.
- **و‎٤‎ تفصل التحويل عن الفهرس.** تحويلُ عمودٍ قائم إلى `NOT NULL` **يعيد بناء الجدول على
  SQLite**، والمشروع رفض هذا التحويل لهذا السبب بعينه في `add_uuid_to_course_structure`
  («جدولٌ يُعاد بناؤه ويفقد فهرساً بصمت صفقةٌ أسوأ»). فينشأ `unique(uuid)` **بعد** التحويل،
  لا معه.

⚠️ **والخطوة ‎١٢‎ لا تعيد إنشاء ما أُنشئ.** `unique(uuid)` (‏‎٤‎) و`unique(exam_id, question_id)`
(‏‎٥‎) و`unique(assignment_id, student_user_id)` (‏‎٩‎) قيودٌ وُلدت مع جداولها؛ إعادة إعلانها في
‎١٢‎ تعني `Duplicate key name` على MySQL. §ح تُقرأ على قسمين: **قيودٌ تُنشأ مع الجدول**،
و**فهارس أداءٍ تُضاف أخيراً**.

⚠️ **و‎٧‎ تملأ `attempt_items` للمحاولات القائمة، أو تُعلن فرع الرجوع.** بلا ذلك، كل محاولةٍ
مصحَّحة قبل ‎008‎ تُفتح مراجعتها فيجد القارئ `attempt_items` فارغاً — والمقام يصير `ما عُرض`
أي **صفراً**. والدرجة نفسها لم تُفقَد، **فاختبار `SC-015` يمرّ وهو يقيس عمود `score` وحده**:
عائلةُ العيب نفسها التي كلّفت ‎016‎ ثمنها. اللقطة المُعاد بناؤها تُوسَم `backfilled: true`
حتى لا تُقرأ كأنها ما رآه فعلاً.

⚠️ **وحدود التراجع ثلاثٌ لا واحدة**: سؤالٌ في اختبارين لا يتراجع · وسؤالُ بنكٍ في صفر
اختبارات لا قيمة تُعاد إليه · و`exam_id` شُحن `NOT NULL` فلا يمكن للتراجع أن يعيده كذلك.
**فالمخطّط بعد `rollback` ليس المخطّط الذي سبق**، وسكوتُ الوثيقة عن ذلك هو بعينه ما ترفضه.

---

## ي — ما يسقط من المواصفة عمداً

| مرشَّح | الحكم | لماذا |
|---|---|---|
| `mistake_entries` | **يسقط** | مشتقّ — `research.md` §ج |
| `question_versions` | **يسقط** | اللقطة على عناصر المحاولة — §ب |
| `is_unlocked` على أي جدول | **يسقط** | يُحسَب عند كل طلب — §ح |
| جدول «نتيجة» رابع | **يسقط** | الدرجة على المحاولة والتسليم، كما اليوم |
| `grade_entries` / كشف تقديرات | **يسقط** | `Q4` — «رسمي» وسمٌ يُرشَّح (`is_practice`)، والكشف التراكمي وعدُ ‎010‎ يُبنى من هذه الصفوف. جدولٌ ثالث للدرجة يعني ثلاثة أرقامٍ لدرجةٍ واحدة |
