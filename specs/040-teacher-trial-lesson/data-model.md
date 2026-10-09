# Data Model: الحصة التجريبية للمدرّس

## `teacher_profiles` (قائم، مملوك للمنصة: ملف السوق)

| عمود | نوع | قيود | ملاحظة |
|---|---|---|---|
| `trial_lesson_id` | unsigned bigint, nullable | FK → `lessons.id`, `nullOnDelete`, index | **جديد.** «حصتي التجريبية». يُكتب عبر `SetTrialLesson` فقط. يُضاف إلى `$fillable`، لأن عموداً لا يُضاف إليها لا يُكتب أبداً (database.md) |

**العلاقة**: `TeacherProfile::trialLesson(): BelongsTo<Lesson>`، وتُقرأ دائماً `withoutWorkspaceScope()`. الدرس مملوك لمكان عمل، والقارئ قد يكون زائراً، أو مدرّساً من مكان عمل آخر.

**التصنيف** (المبدأ I): جسر قراءة من ملف السوق إلى درس مملوك لمكان عمل. لا يحمل `workspace_id`، ولا يُقرأ إلا عبر `TrialLessonRule`، والقاعدة تتحقّق أن صاحب كورس الدرس هو صاحب الملف.

## القواعد (`TrialLessonRule`)

### عند التعليم: `SetTrialLesson(actor, lessonUuid|null)`

| الشرط | الرفض |
|---|---|
| `actor` ليس صاحب ملف معتمد (`teacher_profiles.user_id`) | 403: «الحصة التجريبية يختارها المدرّس صاحب الملف.» |
| الدرس غير موجود، أو صاحب كورسه (`Course::teacherUser()`) ليس `actor` | 422: «اختر درساً من كورساتك.» |
| النوع ليس `embed` ولا `video` | 422: «الحصة التجريبية فيديو: مضمَّن من يوتيوب أو فيميو، أو مرفوع على المنصة.» |
| `class_session_id` ليس فارغاً | 422: «تسجيلات الحصص المباشرة لا تكون حصة تجريبية.» |
| `release_session_id`، أو صفوف في `lesson_cohort_scopes` | 422: «هذا الدرس مقصور على مجموعة أو حصة؛ اختر درساً لكل الطلاب.» |
| `is_high_value` | 422: «الدروس المعلَّمة عالية القيمة لا تكون حصة تجريبية.» |
| `null` | يمسح العلامة (FR-001 إلغاء) |

النجاح يكتب `trial_lesson_id = lesson.id`. هذا استبدال، والقديم يسقط تلقائياً (FR-002).

**ما لا يُشترط عند التعليم**: النشر وجاهزية الفيديو. المدرّس قد يعلّم درساً قبل نشره أو قبل اكتمال تجهيزه، والقراءة هي التي تقرّر العرض (FR-007). والمحرّر يُظهر «لن تظهر للزوار حتى …» بحسب الحالة.

### عند القراءة: `currentFor(teacher): ?Lesson`

يُعيد الدرس **فقط** إن تحقّقت كلها:

- الملف `publiclyListed()`.
- الشروط الثابتة أعلاه ما زالت صحيحة، لأن الدرس قد يُعدَّل بعد التعليم.
- `visibleToStudents()` على الدرس وفصله وقسمه.
- الكورس يحلّه `ReadPublicCourse`.
- للنوع `video`: أصله الأساسي kind video وجاهز للتشغيل.

## الحمولة العامة: `trial_lesson`

```text
null | {
  course_slug: string,
  lesson_uuid: string,
  title: string,
  kind: "embed" | "video",
  duration_seconds?: int   // يُحذف إن كان 0
}
```

`PublicFieldAllowlist::TRIAL_LESSON` يُحدَّث بهذه المفاتيح.

## الحالة المعروضة في المحرّر

`is_trial: boolean`، و`can_set_trial: boolean`، و`trial_status: "visible" | "unpublished" | "processing" | null`. الأخير لرسالة «لن تظهر للزوار حتى …».
