# Data Model: الحصة التجريبية لكل كورس

## `courses` (قائم، مملوك لمكان العمل)

| عمود | نوع | قيود | ملاحظة |
|---|---|---|---|
| `trial_lesson_id` | unsigned bigint, nullable | FK → `lessons.id`، `nullOnDelete`، باسم قيد صريح ≤ ٦٤ حرفاً | **جديد.** يُكتب عبر `SetCourseTrialLesson` بـ`forceFill` وحده، **وليس** في `$fillable` |

**العلاقة**: `Course::trialLesson(): BelongsTo<Lesson>`. الدرس والكورس في مكان العمل نفسه دائماً، والـ Action يتحقّق أن `lesson.course_id = course.id`.

**التصنيف** (المبدأ I): عمود على كيان مملوك لمكان العمل، يشير إلى كيان من مكان العمل نفسه. لا كيان جديد، فلا حالة جديدة في `WorkspaceIsolationTest`. لكن يُضاف اختبار: درس من كورس آخر (ولو في مكان عمل آخر) يُرفَض.

## الشروط الثابتة: `TrialLessonRule` (تهجئة واحدة)

| الشرط | رسالة الرفض عند التعليم |
|---|---|
| `lesson.course_id = course.id` | «اختر درساً من هذا الكورس.» |
| `type IN (embed, video)` | «الحصة التجريبية فيديو: مضمَّن من يوتيوب أو فيميو، أو مرفوع على المنصة.» |
| `class_session_id IS NULL` | «تسجيلات الحصص المباشرة لا تكون حصة تجريبية.» |
| `release_session_id IS NULL` و`NOT EXISTS lesson_cohort_scopes` | «هذا الدرس مقصور على مجموعة أو حصة؛ اختر درساً لكل الطلاب.» |
| `is_high_value = false` | «الدروس المعلَّمة عالية القيمة لا تكون حصة تجريبية.» |
| للنوع `video`: أصل أساسي `kind = video` و`provider = bunny` | «هذا الفيديو مخزَّن بالطريقة القديمة؛ ارفعه من جديد ليصلح حصة تجريبية.» |

## شروط القراءة الإضافية (FR-008)

- `visibleToStudents()`: الدرس وفصله وقسمه منشورة.
- الكورس `publiclyListed()`، ويشمل غير المحذوف والعام ومدرّساً معروضاً ومكان عمل مشاركاً.
- للفيديو: الأصل جاهز للتشغيل.

**ما لا يُشترط عند التعليم**: النشر والجاهزية. المدرّس قد يعلّم درساً قبل نشره، والمحرّر يُظهر الحالة.

## الكتابة: `SetCourseTrialLesson(SetTrialLessonData)`

- **التعليم**: `lesson` uuid. الـ Action يفحص الشروط الثابتة، ثم `forceFill(['trial_lesson_id' => $lesson->id])->save()`.
- **الإلغاء**: `lesson = null` مع `replacing` = uuid. يُنفَّذ بـ`UPDATE … WHERE id = ? AND trial_lesson_id = ?`. صفر صفوف تأثّرت معناه أن الحصة تغيّرت منذ فتح الصفحة، فتُعاد الحالة الحالية دون خطأ.
- **الإذن**: `CoursePolicy::chooseTrialLesson` (= `changePricing`).

## حالة المحرّر

- `trial_status`: `"visible" | "unpublished" | "processing" | "unavailable" | null`.
- `LessonResource.is_trial`، و`LessonResource.trial_refusal`.
- `CourseResource.can_choose_trial`.

## الحمولات العامة

- **كارت الكورس**: `has_trial: bool`.
- **تفاصيل الكورس**: `trial: null | { title, kind: "embed"|"video", duration_seconds? }`.
- **تفاصيل المدرّس**: `trial_lessons: [{ course_slug, course_title, subject, grade_level, lesson_title, kind }]`، ويحلّ محلّ `trial_lesson` من #375.
- كلها تُضاف إلى `PublicFieldAllowlist`.
