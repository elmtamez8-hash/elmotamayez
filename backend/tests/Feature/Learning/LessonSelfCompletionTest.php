<?php

declare(strict_types=1);

use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| ⚠️ «مافيش حاجة بتقول اني اكملت الدرس ولا زرار» — بلاغُ ٢٠٢٦-٠٩-٠٦، والنسبةُ
| واقفةٌ على «أتممتَ ٠ من ٣ — ٠٪».
|
| المسارُ `POST …/lessons/{lesson}/complete` قائمٌ منذُ ٠١٦، و`lessonPayload`
| يحملُ `enrollment_uuid` بتعليقٍ يقولُ صراحةً إنّه «ما تستعملُه شاشةُ الطالبِ
| لتعليمِ العنصرِ مكتملاً» — **ولا ملفَّ واحدٍ في الواجهةِ ينادي ذلك المسار**. فلا
| فيديو ولا مقالٌ يكتملُ أبداً، و`CourseCompleted` لا يُطلَقُ، ولا شهادةَ تصدرُ
| لأحدٍ إطلاقاً. عائلةُ «العنصرُ الذي يدخلُ المقامَ ولا يمكنُ إتمامُه» بعينِها،
| مبلوغةٌ من بابٍ جديد: لا عنصرٌ معطوبٌ بل ضابطٌ غائب.
|
| ⚠️ وهذا الملفُّ يقيسُ **البابَ** لا الزرّ: إخفاءُ ضابطٍ ليس حرساً.
*/
/**
 * كورسٌ منشورٌ بمقالَين غيرِ متسلسلَين.
 *
 * مكتوبٌ هنا لا مستعارٌ من `LearningTest`: دالّتُهُ محلّيّةٌ بذلكَ الملفّ.
 * و`price_minor => 0` صراحةً: المصنعُ يعطي سعراً عشوائيّاً، فتجهيزةٌ تتركُهُ
 * للمصادفةِ تنجحُ وتفشلُ بالمصادفةِ نفسِها.
 */
function selfCompletionCourse(int $workspaceId): Course
{
    $course = Course::factory()->create([
        'workspace_id' => $workspaceId,
        'status' => 'published',
        'is_sequential' => false,
        'price_minor' => 0,
    ]);

    // منشورٌ عندَ كلِّ مستوىً صراحةً: منذُ ٠١٦ ما لا يقولُ ذلكَ مسودّةٌ،
    // والمسودّةُ خارجَ المقامِ وغيرُ مرئيّةٍ للطالب.
    $section = Section::create([
        'workspace_id' => $workspaceId,
        'course_id' => $course->id,
        'title' => 'Section 1',
        'status' => ContentStatus::Published,
        'order' => 1,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId,
        'section_id' => $section->id,
        'course_id' => $course->id,
        'title' => 'Chapter 1',
        'status' => ContentStatus::Published,
        'order' => 1,
    ]);

    foreach ([1, 2] as $i) {
        Lesson::create([
            'workspace_id' => $workspaceId,
            'course_id' => $course->id,
            'section_id' => $section->id,
            'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(),
            'title' => "Lesson {$i}",
            'type' => 'article',
            'status' => ContentStatus::Published,
            'content' => "Content {$i}",
            'order' => $i,
        ]);
    }

    return $course->fresh();
}

beforeEach(function (): void {
    [$this->workspace] = $this->createWorkspaceWithOwner();
    $this->course = selfCompletionCourse($this->workspace->id);
    $this->student = $this->addWorkspaceMember($this->workspace, 'student');

    $this->enrollment = Enrollment::create([
        'workspace_id' => $this->workspace->id,
        'uuid' => Str::uuid(),
        'course_id' => $this->course->id,
        'student_user_id' => $this->student->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    Sanctum::actingAs($this->student);
});

function examLesson(int $workspaceId, int $courseId): Lesson
{
    $sibling = Lesson::query()->where('course_id', $courseId)->orderBy('order')->firstOrFail();

    return Lesson::create([
        'workspace_id' => $workspaceId,
        'course_id' => $courseId,
        'section_id' => $sibling->section_id,
        'chapter_id' => $sibling->chapter_id,
        'uuid' => Str::uuid(),
        'title' => 'اختبار الوحدة',
        'type' => 'exam',
        'status' => ContentStatus::Published,
        'order' => 99,
    ]);
}

it('tells the screen the item is not done yet, and that the reader may say so', function (): void {
    $lesson = Lesson::query()->where('course_id', $this->course->id)->orderBy('order')->firstOrFail();

    $this->getJson("/api/v1/learn/lessons/{$lesson->uuid}")
        ->assertOk()
        ->assertJsonPath('lesson.may_self_complete', true)
        ->assertJsonPath('lesson.is_completed', false)
        // الحقلُ الذي كانَ تعليقُه يصفُ شاشةً غيرَ موجودة.
        ->assertJsonPath('enrollment_uuid', $this->enrollment->uuid);
});

it('says it is done once it is, so the screen has something to show', function (): void {
    $lesson = Lesson::query()->where('course_id', $this->course->id)->orderBy('order')->firstOrFail();

    $this->postJson("/api/v1/enrollments/{$this->enrollment->uuid}/lessons/{$lesson->uuid}/complete")
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        // النسبةُ تتحرّكُ فعلاً — وهي التي كانت واقفةً على صفرٍ في البلاغ.
        ->assertJsonPath('progress_pct', 50);

    $this->getJson("/api/v1/learn/lessons/{$lesson->uuid}")
        ->assertOk()
        ->assertJsonPath('lesson.is_completed', true);
});

it('REFUSES an exam item at the door, not merely on the screen', function (): void {
    /*
    | ⚠️ هذا هو التوكيدُ الذي يعضُّ على البناءِ القائمِ اليوم: المسارُ يفحصُ
    | انتماءَ الدرسِ للكورسِ والوصولَ إليه ولا شيءَ غيرَ ذلك، فأيُّ طالبٍ مسجَّلٍ
    | يستطيعُ بطلبٍ واحدٍ أن يُعلِنَ اجتيازَ عنصرِ اختبارٍ ويحرّكَ نسبتَه بلا أن
    | يجيبَ سؤالاً. وإخفاءُ الزرِّ لا يمسُّ ذلك بشيء.
    */
    $exam = examLesson($this->workspace->id, $this->course->id);

    $this->postJson("/api/v1/enrollments/{$this->enrollment->uuid}/lessons/{$exam->uuid}/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'NOT_SELF_COMPLETABLE');

    expect(LessonProgress::query()->where('lesson_id', $exam->getKey())->count())->toBe(0);
});

it('hides the control for an exam while still saying what completes it', function (): void {
    // `is_completable` يبقى true — العنصرُ في المقامِ ويُكمَلُ فعلاً، لكن بالتسليم.
    $exam = examLesson($this->workspace->id, $this->course->id);

    $this->getJson("/api/v1/learn/lessons/{$exam->uuid}")
        ->assertOk()
        ->assertJsonPath('lesson.is_completable', true)
        ->assertJsonPath('lesson.may_self_complete', false);
});

it('refuses to let an ASSIGNMENT be declared done — the hand-in completes it', function (): void {
    /*
    | ⚠️ كانَ هذا الاختبارُ يقولُ العكس، وكانَ محقّاً وقتَها: لم يكنْ في الشجرةِ
    | ما يُتِمُّ عنصرَ واجب، فرفضُ الإعلانِ اليدويِّ كانَ يجعلُه غيرَ قابلٍ
    | للإتمامِ أبداً. الآن يكتبُه `CompleteAssignmentLessonOnSubmission` عندَ
    | التسليمِ الفعليّ (و`…AlreadySubmitted` لمن سلَّمَ قبلَ وضعِه في الشجرة) —
    | فزرٌّ هنا هو «أنجزتُ واجبي» بلا تسليمِ شيء، كزرِّ الاختبارِ تماماً.
    */
    $sibling = Lesson::query()->where('course_id', $this->course->id)->orderBy('order')->firstOrFail();

    $assignment = Lesson::create([
        'workspace_id' => $this->workspace->id,
        'course_id' => $this->course->id,
        'section_id' => $sibling->section_id,
        'chapter_id' => $sibling->chapter_id,
        'uuid' => Str::uuid(),
        'title' => 'واجب الوحدة',
        'type' => 'assignment',
        'status' => ContentStatus::Published,
        'order' => 98,
    ]);

    $this->postJson("/api/v1/enrollments/{$this->enrollment->uuid}/lessons/{$assignment->uuid}/complete")
        ->assertStatus(422)
        ->assertJsonPath('code', 'NOT_SELF_COMPLETABLE');

    expect(LessonProgress::query()->where('lesson_id', $assignment->getKey())->count())->toBe(0);
});
