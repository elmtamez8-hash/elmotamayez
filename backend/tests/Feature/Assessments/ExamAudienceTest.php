<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Support\PracticePool;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\LessonCohortScope;
use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ScopedTreeFixture;

uses(ScopedTreeFixture::class);

/*
| ⛔ ٠٢٦ · US4 · FR-018 · FR-019 · FR-020 — **الامتحاناتُ تتبعُ القاعدةَ نفسَها.**
|
| الحكمُ واحدٌ يُسأَلُ من أربعةِ أبواب، وهذانِ بابانِ منها: بدءُ المحاولةِ وفهرسُ
| الاختبارات. وبِركةُ التدريبِ هي البابُ الجانبيُّ الذي يُسلِّمُ السؤالَ **ومعه
| شرحُه** لمَن أُخفيَ عنه الدرس.
|
| ⚠️ **والرابطُ درسٌ لا عمودٌ على الامتحان.** `exams` لا تحملُ مجموعةً ولا حصّةً؛
| يُبلَغُ الامتحانُ من درسٍ نوعُه `exam` يُسمّيه في `reference_id`، وذلكَ الدرسُ
| هو الذي يحملُ المحورَين.
|
| ⚠️ **والتوكيدُ على عناوينَ لاتينيّةٍ ومعرّفاتٍ لا على نصٍّ عربيّ**:
| `getContent()` يهربُ ما خرجَ عن ASCII، فإبرةٌ عربيّةٌ في توكيدِ تسرُّبٍ غائبةٌ
| بلا معنى.
*/

beforeEach(function (): void {
    $this->tree = $this->scopedTree();

    $this->fx = app(WorkspaceContext::class)->forWorkspace($this->tree['workspace'], function (): array {
        $tree = $this->tree;
        $workspaceId = (int) $tree['workspace']->getKey();
        $courseId = (int) $tree['course']->getKey();
        $order = 10;

        $paper = function (string $title) use ($tree, $workspaceId, $courseId, &$order): array {
            $exam = Exam::factory()->published()->create([
                'workspace_id' => $workspaceId,
                'course_id' => $courseId,
                'title' => $title,
            ]);

            $lesson = Lesson::create([
                'workspace_id' => $workspaceId,
                'course_id' => $courseId,
                'section_id' => $tree['chapter']->section_id,
                'chapter_id' => $tree['chapter']->getKey(),
                'uuid' => Str::uuid(),
                'title' => $title,
                'type' => 'exam',
                'reference_id' => $exam->getKey(),
                'status' => ContentStatus::Published,
                'order' => ++$order,
            ]);

            return ['exam' => $exam, 'lesson' => $lesson];
        };

        $shared = $paper('SHARED-EXAM');
        $scoped = $paper('SCOPED-EXAM');
        $waiting = $paper('WAITING-EXAM');

        // ورقةٌ فيها سؤالٌ واحد، وإلّا فلا شيءَ يُجمَّدُ عندَ البدءِ ولا شيءَ
        // يُسلَّمُ عندَ التسليم.
        $shared['question'] = bankQuestion($tree['workspace'], $shared['exam']);

        LessonCohortScope::query()->create([
            'workspace_id' => $workspaceId,
            'lesson_id' => $scoped['lesson']->getKey(),
            'cohort_id' => $tree['theirs']->getKey(),
        ]);

        // مجدولةٌ: لم تُسلَّمْ ولم تُلغَ، فالعنصرُ المربوطُ بها لم يُفرَجْ عنه.
        $session = ClassSession::factory()->create([
            'workspace_id' => $workspaceId,
            'course_id' => $courseId,
        ]);

        $waiting['lesson']->forceFill(['release_session_id' => $session->getKey()])->save();

        return ['shared' => $shared, 'scoped' => $scoped, 'waiting' => $waiting];
    });
});

/**
 * يُوقِعُ الطالبَ في سياقِه الحقيقيّ: بلا مساحةِ عملٍ محلولة.
 *
 * ⚠️ `forgetInstance` لا `forget()` — الثانيةُ تُثبِّتُ السياقَ على الفراغِ
 * فتقيسُ شخصاً لا تُنتِجُه الخدمة.
 */
function actAsAudienceStudent(): void
{
    Sanctum::actingAs(test()->tree['student']);
    app()->forgetInstance(WorkspaceContext::class);
}

/**
 * عناوينُ الاختباراتِ في الفهرس.
 *
 * ⚠️ **وبلا مفتاحِ `data` اليوم**: النقطةُ تُغلِّفُ بـ
 * `response()->json(Resource::collection(...))` فتسقطُ الحمولةُ إلى مصفوفةٍ
 * عارية — عطلٌ قائمٌ خارجَ نطاقِ هذه الشحنة، وإصلاحُه يغيّرُ شكلَ الجواب.
 *
 * @param  array<mixed>  $payload
 * @return list<string>
 */
function examTitles(array $payload): array
{
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $payload['data'] ?? $payload;

    return array_values(array_map(static fn (array $row): string => (string) $row['title'], $rows));
}

/*
| **كيفَ يمسك**: احذفْ `->whereNotIn('id', $this->hiddenExamIds(...))` من
| `ExamController::index()` ⇒ يسقطُ الشقّانِ التاليانِ بـ`SCOPED-EXAM`
| و`WAITING-EXAM` حاضرَينِ في القائمة.
*/
it('does not carry a paper narrowed to another group', function (): void {
    actAsAudienceStudent();

    $titles = examTitles($this->getJson('/api/v1/exams')->assertOk()->json());

    // الضابطُ الموجَب: بدونَه يمرُّ بناءٌ يُرجِعُ قائمةً فارغةً لكلِّ طالب.
    expect($titles)->toContain('SHARED-EXAM')
        ->and($titles)->not->toContain('SCOPED-EXAM');
});

it('does not carry a paper waiting on a session that has not been held', function (): void {
    actAsAudienceStudent();

    $titles = examTitles($this->getJson('/api/v1/exams')->assertOk()->json());

    expect($titles)->toContain('SHARED-EXAM')
        ->and($titles)->not->toContain('WAITING-EXAM');
});

/*
| ⛔ **وشاشةٌ تُخفي زرّاً ليست حارساً.** `StartAttempt` هو المدخلُ الواحدُ الذي
| تشترِكُ فيه البذورُ ولوحةُ الإدارةِ وواجهةُ البرمجة، والمعرّفُ يُطرَقُ مباشرةً.
|
| **كيفَ يمسك**: احذفْ فرعَ `LessonAudience::hiddenFor()` من
| `StartAttempt::guardSessionContent()` ⇒ يسقطُ الشقّانِ بـ«٢٠١ بدلَ ٤٢٢».
*/
it('refuses to hand over a paper narrowed to another group', function (): void {
    actAsAudienceStudent();

    $this->postJson('/api/v1/exams/'.$this->fx['scoped']['exam']->uuid.'/attempts')
        ->assertStatus(422);

    expect(Attempt::query()->where('exam_id', $this->fx['scoped']['exam']->getKey())->count())->toBe(0);
});

it('refuses to hand over a paper waiting on a session that has not been held', function (): void {
    actAsAudienceStudent();

    $this->postJson('/api/v1/exams/'.$this->fx['waiting']['exam']->uuid.'/attempts')
        ->assertStatus(422);

    expect(Attempt::query()->where('exam_id', $this->fx['waiting']['exam']->getKey())->count())->toBe(0);
});

/*
| ⛔ FR-018 — **والمعرّفُ بابٌ كالقائمة.**
|
| الفهرسُ يُسقِطُ الورقةَ وبدءُ المحاولةِ يرفضُها، و`GET /exams/{uuid}` كانت
| تردُّ عنوانَها ووصفَها ومدّتَها وعددَ أسئلتِها لمن يحملُ المعرّف.
|
| ⚠️ **و٤٠٤ لا ٤٠٣**: أنّ ورقةً بهذا المعرّفِ موجودةٌ هو نفسُه خبرٌ.
|
| **كيفَ يمسك**: احذفْ `abort_if` من `ExamController::show()` ⇒ يسقطُ بـ«٢٠٠
| بدلَ ٤٠٤»، ويسقطُ معه توكيدُ أنّ العنوانَ لم يُرسَل.
*/
it('does not answer a narrowed paper to whoever knows its id', function (): void {
    actAsAudienceStudent();

    $response = $this->getJson('/api/v1/exams/'.$this->fx['scoped']['exam']->uuid);

    $response->assertNotFound();

    expect($response->json('title'))->toBeNull();
});

it('still answers the shared paper by id', function (): void {
    actAsAudienceStudent();

    $this->getJson('/api/v1/exams/'.$this->fx['shared']['exam']->uuid)
        ->assertOk()
        ->assertJsonPath('title', 'SHARED-EXAM');
});

/*
| ⛔ FR-020 — **وبِركةُ التدريبِ بابٌ يُسلِّمُ المادّةَ ومعها شرحُها.**
|
| ⚠️ **والسؤالانِ خارجَ أيِّ امتحانٍ منشور، عمداً.** `withheldQuestionIdsQuery()`
| يطرحُ أصلاً كلَّ سؤالٍ في ورقةٍ منشورةٍ لم يجلسْها الطالب — فسؤالٌ داخلَ
| الورقةِ المقصورةِ يُستبعَدُ **للسببِ الآخر**، ويبقى التوكيدُ أخضرَ ولو حُذِفَ
| حارسُ هذا البندِ كلَّه. فهما معلَّقانِ بدرسَينِ عاديَّينِ من الشجرة: أحدُهما
| مشتركٌ والآخرُ مقصورٌ على مجموعةٍ أخرى.
|
| **كيفَ يمسك**: احذفْ `->whereNotIn('lesson_id', ...)` من
| `PracticePool::questionsFor()` ⇒ يسقطُ بـ«المقصورُ حاضرٌ في البِركة».
*/
it('draws no practice question from a lesson narrowed to another group', function (): void {
    $workspace = $this->tree['workspace'];

    [$open, $narrowed] = app(WorkspaceContext::class)->forWorkspace($workspace, fn (): array => [
        practiceQuestion($workspace, 'OPEN?', ['lesson_id' => $this->tree['lessons']['shared_last']->getKey()]),
        practiceQuestion($workspace, 'NARROWED?', ['lesson_id' => $this->tree['lessons']['scoped_away']->getKey()]),
    ]);

    actAsAudienceStudent();

    $pool = app(PracticePool::class)
        ->questionsFor((int) $workspace->getKey(), $this->tree['student'])
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();

    expect($pool)->toContain((int) $open->getKey())
        ->and($pool)->not->toContain((int) $narrowed->getKey());
});

/*
| ⛔ FR-020 — **ودفترُ الأخطاءِ بابٌ ثانٍ للبِركةِ نفسِها.**
|
| `BuildPracticeFromMistakes` يبني ورقتَه من معرّفاتِ الدفترِ مباشرةً ولا يمرُّ
| من `questionsFor()` إطلاقاً — فبلا استبعادٍ مكتوبٍ هناك كذلك صارَ للبِركةِ
| بابانِ أحدُهما مفتوح، ويُسلَّمُ السؤالُ المقصورُ **ومعه شرحُه** لمَن أُخفيَ
| عنه درسُه.
|
| ⚠️ **والخطأُ قائمٌ في الحالتَين**: الطالبُ أخطأَ في السؤالَين، فلا يسقطُ
| المقصورُ لأنّه أُصلِح. والضابطُ الموجَبُ هو المشترَكُ في الورقة، وإلّا مرَّ
| بناءٌ يرفضُ بناءَ أيِّ ورقةٍ إطلاقاً.
|
| **كيفَ يمسك**: احذفْ فرعَ `$hiddenLessons` من
| `BuildPracticeFromMistakes::handle()` ⇒ يسقطُ بـ«المقصورُ حاضرٌ في الورقة».
*/
it('does not put a mistake from a narrowed lesson back in front of the student', function (): void {
    $workspace = $this->tree['workspace'];
    $student = $this->tree['student'];

    [$open, $narrowed] = app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $student): array {
        $open = practiceQuestion($workspace, 'OPEN-MISTAKE?', [
            'lesson_id' => $this->tree['lessons']['shared_last']->getKey(),
        ]);
        $narrowed = practiceQuestion($workspace, 'NARROWED-MISTAKE?', [
            'lesson_id' => $this->tree['lessons']['scoped_away']->getKey(),
        ]);

        answerRow((int) $workspace->getKey(), $student, (int) $open->getKey(), correct: false);
        answerRow((int) $workspace->getKey(), $student, (int) $narrowed->getKey(), correct: false);

        return [$open, $narrowed];
    });

    actAsAudienceStudent();

    $response = $this->postJson('/api/v1/practice/from-mistakes', ['count' => 10])->assertCreated();

    $attempt = Attempt::query()->where('uuid', $response->json('data.uuid'))->sole();

    $asked = $attempt->items()->pluck('question_id')->map(static fn (mixed $id): int => (int) $id)->all();

    expect($asked)->toContain((int) $open->getKey())
        ->and($asked)->not->toContain((int) $narrowed->getKey());
});

/*
| ⛔ FR-019 — **ورقةٌ بدأت قبلَ التضييقِ تُكمَلُ وتُحفَظُ درجتُها.**
|
| التضييقُ يقولُ «لا تُعطِ هذه الورقةَ لهذا الطالب»، ولا يقولُ «امحُ ما جلسَه»؛
| ومحاولةٌ تُرفَضُ عندَ التسليمِ إجاباتٌ ضاعَت بعدَ أن كُتِبَت، ولا بابَ لها
| يُعاد — والمحاولةُ نفسُها قد أُنفِقَت من `max_attempts` عندَ البدء.
|
| **كيفَ يمسك**: أضِفْ سؤالَ `LessonAudience` إلى `GradeAttempt` أو إلى
| `AttemptController::submit()` ⇒ يسقطُ هذا الشقُّ بـ«٤٢٢ بدلَ ٢٠٠».
*/
it('lets an attempt begun before the narrowing finish and keep its score', function (): void {
    actAsAudienceStudent();

    $this->postJson('/api/v1/exams/'.$this->fx['shared']['exam']->uuid.'/attempts')
        ->assertCreated();

    $attempt = Attempt::query()
        ->where('exam_id', $this->fx['shared']['exam']->getKey())
        ->where('student_user_id', $this->tree['student']->getKey())
        ->sole();

    // ثمّ يُقصَرُ العنصرُ على مجموعةٍ أخرى، والورقةُ في يدِ الطالب.
    LessonCohortScope::query()->create([
        'workspace_id' => $this->tree['workspace']->getKey(),
        'lesson_id' => $this->fx['shared']['lesson']->getKey(),
        'cohort_id' => $this->tree['theirs']->getKey(),
    ]);

    $this->postJson('/api/v1/attempts/'.$attempt->uuid.'/submit', [
        'answers' => [[
            'question_id' => (int) $this->fx['shared']['question']->getKey(),
            'selected_option_ids' => [],
        ]],
    ])->assertOk();

    $graded = $attempt->fresh();

    expect($graded->status)->not->toBe('in_progress')
        ->and($graded->submitted_at)->not->toBeNull();
});
