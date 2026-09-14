<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Exam;
use App\Modules\Certificates\Models\Certificate;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| التراجعُ عن الإتمام — طلبُ المالكِ ٢٠٢٦-٠٩-١٤.
|
| ⚠️ **والشهادةُ تبقى، ولا تصدرُ ثانيةً أبداً.** الضمانُ ثلاثيٌّ وقِيسَ لا
| افتُرِض: فهرسٌ فريدٌ على `(workspace_id, enrollment_id, course_id)`، و
| `IssueCertificate` يعودُ عندَ وجودِ صفٍّ **قبلَ** سطرِ `event()`، ومستمعُ
| `CertificateIssued` الوحيدُ هو الإشعار. فإعادةُ الإتمامِ لا تُنتِجُ صفّاً ولا
| حدثاً ولا إشعاراً — وهذا ما يقيسُه هذا الملفُّ بالعدِّ وبالمعرّفِ معاً.
|
| ⚠️ **والكورسُ متسلسلٌ عمداً**: القفلُ الراجعُ بعدَ التراجعِ هو أهمُّ نتيجةٍ في
| هذه الميزةِ وأكثرُها مفاجأةً للطالب، فلا بدَّ أن يُقاسَ لا أن يُوصَف.
*/

function resetCourseFixture(int $workspaceId): Course
{
    $course = Course::factory()->create([
        'workspace_id' => $workspaceId,
        'status' => 'published',
        // متسلسلٌ: فرفعُ الإتمامِ يُعيدُ قفلَ ما بعدَه.
        'is_sequential' => true,
        'price_minor' => 0,
    ]);

    $section = Section::create([
        'workspace_id' => $workspaceId,
        'course_id' => $course->id,
        'title' => 'Section 1',
        'status' => ContentStatus::Published,
        'order' => 1,
    ]);

    $chapter = Chapter::create([
        'workspace_id' => $workspaceId,
        'course_id' => $course->id,
        'section_id' => $section->id,
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
    $this->course = resetCourseFixture($this->workspace->id);
    $this->student = $this->addWorkspaceMember($this->workspace, 'student');

    $this->enrollment = Enrollment::create([
        'workspace_id' => $this->workspace->id,
        'uuid' => Str::uuid(),
        'course_id' => $this->course->id,
        'student_user_id' => $this->student->id,
        'status' => 'active',
        'enrolled_at' => now(),
    ]);

    $this->lessons = Lesson::query()->where('course_id', $this->course->id)
        ->orderBy('order')->get();

    Sanctum::actingAs($this->student);
});

/** يُتِمُّ الدرسَينِ فيُنهي الكورسَ وتصدرُ الشهادة. */
function finishTheCourse(): void
{
    $uuid = test()->enrollment->uuid;

    foreach (test()->lessons as $lesson) {
        test()->postJson('/api/v1/enrollments/'.$uuid.'/lessons/'.$lesson->uuid.'/complete')
            ->assertOk();
    }
}

it('lets a student who FINISHED the course start it again', function (): void {
    /*
    | ⚠️ **الحالةُ التي كانت سترُدُّ ٤٠٣.** `completeLessons` تشترطُ
    | `isActive()`، وحالةُ من أنهى الكورسَ `completed` — فإعادةُ استعمالِ تلكَ
    | القدرةِ للتراجعِ كانت ستمنعُ الشخصَ الوحيدَ الذي تعنيه الميزة. ولهذا
    | `resetProgress` قدرةٌ مستقلّة، وهذه الحالةُ هي برهانُها.
    */
    finishTheCourse();

    expect($this->enrollment->refresh()->status)->toBe('completed');

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/reset')
        ->assertOk()
        ->assertJsonPath('reset_count', 2)
        ->assertJsonPath('progress_pct', 0)
        ->assertJsonPath('course_completed', false);

    // ⚠️ والحالةُ ترجعُ `active`: `CourseProgress::sync()` كانَ يمشي في اتّجاهٍ
    // واحدٍ فقط، فصفٌّ يقولُ «مكتمل» و«٠٪» معاً.
    expect($this->enrollment->refresh()->status)->toBe('active')
        ->and($this->enrollment->completed_at)->toBeNull();
});

it('keeps the certificate, and never issues a second one however often the course is re-finished', function (): void {
    finishTheCourse();

    $first = Certificate::query()->where('enrollment_id', $this->enrollment->id)->firstOrFail();

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/reset')->assertOk();

    // الشهادةُ واقعةٌ حدثت. التراجعُ يُرجِعُ التقدّمَ ولا يسحبُ ما كُسِبَ مرّةً.
    expect(Certificate::query()->where('enrollment_id', $this->enrollment->id)->count())->toBe(1);

    finishTheCourse();

    $after = Certificate::query()->where('enrollment_id', $this->enrollment->id)->get();

    /*
    | ⚠️ المعرّفُ **والعدد** معاً. عدٌّ وحدَه يمرُّ فوقَ بناءٍ يحذفُ القديمةَ
    | ويُنشئُ جديدة — وهي شهادةٌ برقمٍ وتاريخٍ مختلفَين، أي كذبٌ على كلِّ من
    | يحملُ الرابطَ القديم.
    */
    expect($after)->toHaveCount(1)
        ->and($after->first()->uuid)->toBe($first->uuid)
        ->and($after->first()->certificate_number)->toBe($first->certificate_number);
});

it('re-locks what comes after, because that IS the sequence rule', function (): void {
    finishTheCourse();

    $first = $this->lessons[0];
    $second = $this->lessons[1];

    // مفتوحٌ بعدَ الإتمام.
    $this->getJson('/api/v1/learn/lessons/'.$second->uuid)
        ->assertOk()
        ->assertJsonPath('can_access', true);

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/lessons/'.$first->uuid.'/reset')
        ->assertOk()
        ->assertJsonPath('reset_count', 1);

    /*
    | ⚠️ ليس أثراً جانبيّاً بل القانونُ نفسُه: `accessTo()` يشتقُّ المفتوحَ من
    | حالةِ الإتمام. والشاشةُ تقولُ ذلك في سؤالِ التأكيدِ **قبلَ** الضغط —
    | وإخفاؤه يجعلُ الطالبَ يظنُّ أنّه كسرَ الكورس.
    */
    $this->getJson('/api/v1/learn/lessons/'.$second->uuid)
        ->assertOk()
        ->assertJsonPath('can_access', false)
        ->assertJsonPath('blocked_reason', 'sequence');
});

it('still opens the course to the student who FINISHED it', function (): void {
    /*
    | ⚠️ **عطبٌ قائمٌ كشفَته هذه الميزة، وليسَ من صنعِها.** البوّابةُ كانت تسألُ
    | `isActive()` وحدَها، والحالةُ تصيرُ `completed` لحظةَ إتمامِ آخرِ درس — فكلُّ
    | درسٍ في الكورسِ يردُّ «تسجيلك في هذا الكورس غير نشط حالياً». أي أنّ جائزةَ
    | إنهاءِ الكورسِ كانت فقدانَه، وزرُّ «راجعِ الكورس» في «تعلّمي» يقودُ إلى منهجٍ
    | مقفولٍ بالكامل. قِيسَ على الإنتاجِ ٢٠٢٦-٠٩-١٤: تسجيلٌ واحدٌ في هذه الحالة.
    */
    finishTheCourse();

    expect($this->enrollment->refresh()->status)->toBe('completed');

    foreach ($this->lessons as $lesson) {
        $this->getJson('/api/v1/learn/lessons/'.$lesson->uuid)
            ->assertOk()
            ->assertJsonPath('can_access', true);
    }

    // والشجرةُ كذلك: البوّابةُ تُسألُ في موضعَينِ ويجبُ أن يتحرّكا معاً.
    $body = $this->getJson('/api/v1/courses/'.$this->course->uuid.'/curriculum')->assertOk()->json();
    $states = collect($body['sections'])
        ->flatMap(fn (array $s) => collect($s['chapters'])->flatMap(fn (array $c) => $c['lessons']))
        ->pluck('state');

    expect($states)->not->toContain('locked');
});

it('touches only the lesson it was given', function (): void {
    finishTheCourse();

    $this->postJson(
        '/api/v1/enrollments/'.$this->enrollment->uuid.'/lessons/'.$this->lessons[1]->uuid.'/reset'
    )->assertOk()->assertJsonPath('reset_count', 1);

    // الأوّلُ ما زالَ مكتملاً — والنسبةُ نصفٌ لا صفر.
    $this->getJson('/api/v1/learn/lessons/'.$this->lessons[0]->uuid)
        ->assertOk()
        ->assertJsonPath('lesson.is_completed', true);

    expect($this->enrollment->refresh()->progress_pct)->toBe(50);
});

it('writes the reset into the history it already keeps, with its scope', function (): void {
    // ⚠️ تحديثٌ لا حذف: `progress_history.lesson_progress_id` بلا مفتاحٍ أجنبيّ،
    // فحذفُ صفِّ التقدّمِ يُيتِّمُ سجلَّه في صمت.
    finishTheCourse();
    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/reset')->assertOk();

    $events = DB::table('lesson_progress_history')->where('event', 'reset')->get();

    expect($events)->toHaveCount(2)
        ->and(json_decode((string) $events->first()->payload, true)['scope'])->toBe('course');

    // وسجلُّ الإتمامِ الأصليُّ باقٍ: التاريخُ يُضافُ إليه ولا يُمحى.
    expect(DB::table('lesson_progress_history')->where('event', 'completed')->count())->toBe(2);
});

it('refuses a suspended enrolment, so widening the state is one state not a hole', function (): void {
    $this->enrollment->update(['status' => 'suspended']);

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/reset')->assertForbidden();
});

it('refuses somebody else entirely', function (): void {
    $other = $this->addWorkspaceMember($this->workspace, 'student');
    Sanctum::actingAs($other);

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/reset')->assertForbidden();
});

it('leaves an exam item alone, because nothing the student can press re-completes it', function (): void {
    /*
    | ⛔ **أخطرُ ما في هذه الميزة، وقِيسَ لا افتُرِض.** صفُّ تقدّمِ الاختبارِ يكتبُه
    | `CompleteExamLessonOnSubmission` عندَ تسليمِ الورقة، و`StartAttempt` يرفضُ
    | محاولةً بعدَ `exams.max_attempts`. فرفعُ ذلك الإتمامِ عن طالبٍ استنفدَ
    | محاولاتِه يضعُ في المقامِ عنصراً لا يُتَمُّ أبداً — أي ‏١٠٠٪ لا تُبلَغُ مهما
    | فعل، وهي عائلةُ العطبِ التي يُسجّلُها هذا المستودعُ ستَّ مرّات.
    */
    $exam = Exam::factory()->create([
        'workspace_id' => $this->workspace->id,
        'course_id' => $this->course->id,
    ]);

    $examLesson = Lesson::create([
        'workspace_id' => $this->workspace->id,
        'course_id' => $this->course->id,
        'section_id' => $this->lessons[0]->section_id,
        'chapter_id' => $this->lessons[0]->chapter_id,
        'uuid' => Str::uuid(),
        'title' => 'Quiz',
        'type' => 'exam',
        'reference_id' => $exam->id,
        'status' => ContentStatus::Published,
        'order' => 3,
    ]);

    // يُكتَبُ كما يكتبُه المستمعُ لا كما يضغطُه الطالب — فالبابُ يرفضُ ذلك أصلاً.
    LessonProgress::create([
        'workspace_id' => $this->workspace->id,
        'enrollment_id' => $this->enrollment->id,
        'lesson_id' => $examLesson->id,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    finishTheCourse();

    $this->postJson('/api/v1/enrollments/'.$this->enrollment->uuid.'/reset')
        ->assertOk()
        // الدرسانِ العاديّانِ وحدَهما. والاختبارُ ليسَ في العدّ.
        ->assertJsonPath('reset_count', 2);

    expect(
        LessonProgress::query()
            ->where('enrollment_id', $this->enrollment->id)
            ->where('lesson_id', $examLesson->id)
            ->value('status')
    )->toBe('completed');
});

it('refuses a single-lesson reset of an exam item, and says why', function (): void {
    // ردٌّ بـ`reset_count: 0` على ضغطةٍ مقصودةٍ رفضٌ لا يعرفُ صاحبُه أنّه رفض.
    $exam = Exam::factory()->create([
        'workspace_id' => $this->workspace->id,
        'course_id' => $this->course->id,
    ]);

    $examLesson = Lesson::create([
        'workspace_id' => $this->workspace->id,
        'course_id' => $this->course->id,
        'section_id' => $this->lessons[0]->section_id,
        'chapter_id' => $this->lessons[0]->chapter_id,
        'uuid' => Str::uuid(),
        'title' => 'Quiz',
        'type' => 'exam',
        'reference_id' => $exam->id,
        'status' => ContentStatus::Published,
        'order' => 3,
    ]);

    $this->postJson(
        '/api/v1/enrollments/'.$this->enrollment->uuid.'/lessons/'.$examLesson->uuid.'/reset'
    )->assertStatus(422);
});
