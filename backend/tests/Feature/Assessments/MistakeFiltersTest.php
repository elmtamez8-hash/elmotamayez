<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Enums\ContentStatus;
use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| دفترُ الأخطاءِ ومرشِّحاتُه (spec 008 · FR-016 · FR-017 · FR-020).
|
| ⚠️ `GET /mistakes` كان يُجيبُ **كلَّ** طالبٍ حقيقيٍّ `422`.
|
| The controller refused to answer without a workspace context, and a student
| never has one: they are a member of no workspace, nothing on their path writes
| `users.last_workspace_id`, so `WorkspaceContext::id()` is null for every one of
| them. The notebook did not exist for anybody it was written for — and every
| test in `MistakeNotebookTest` is green, because they all build their student
| with `addWorkspaceMember()`, which stamps that column and measures a person
| production never creates. This file builds the person production DOES create.
|
| ⚠️ AND THE PICKER IS WALKED THROUGH THE READER. Spec 009 shipped a leaderboard
| picker assembled beside the authoriser rather than derived from it, and it
| offered boards the API answered `403` while hiding boards it allowed. So every
| option here is put through `GET /mistakes` and must come back with a row.
*/

/**
 * @return array{
 *     student: User,
 *     ws: Workspace, other: Workspace,
 *     course: Course, otherCourse: Course,
 *     exam: Exam,
 *     q: array<string, int>,
 * }
 */
function mistakeFilterFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    [$ws, $owner] = $test->createWorkspaceWithOwner();
    [$other, $otherOwner] = $test->createWorkspaceWithOwner();

    // ⚠️ NOT `addWorkspaceMember()`. That stamps `last_workspace_id` and hands
    // the test a context the product never grants a student — the fixture defect
    // that hid this endpoint being dead for an entire release.
    $student = User::factory()->create();

    $q = [];

    $build = function (Workspace $workspace, User $owner, string $tag) use ($student, &$q): array {
        return app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($workspace, $owner, $student, $tag, &$q): array {
            $course = Course::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'created_by' => $owner->getKey(),
                'title' => $tag.'-COURSE',
            ]);

            Enrollment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);

            $section = Section::create([
                'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                'title' => 'وحدة', 'status' => ContentStatus::Published, 'order' => 1,
            ]);
            $chapter = Chapter::create([
                'workspace_id' => $workspace->getKey(), 'section_id' => $section->getKey(),
                'course_id' => $course->getKey(), 'title' => 'فصل',
                'status' => ContentStatus::Published, 'order' => 1,
            ]);
            $lesson = Lesson::create([
                'workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey(),
                'section_id' => $section->getKey(), 'chapter_id' => $chapter->getKey(),
                'uuid' => Str::uuid(), 'title' => $tag.'-LESSON', 'type' => 'article',
                'status' => ContentStatus::Published, 'order' => 1, 'content' => 'نصّ',
            ]);

            $exam = Exam::factory()->published()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'title' => $tag.'-EXAM',
            ]);

            // Wrong, inside that exam, on a question tagged to that lesson.
            $question = bankQuestion($workspace, null, [
                'content' => $tag.'-Q',
                'lesson_id' => $lesson->getKey(),
            ]);

            $attempt = Attempt::create([
                'workspace_id' => $workspace->getKey(),
                'exam_id' => $exam->getKey(),
                'student_user_id' => $student->getKey(),
                'status' => Attempt::STATUS_GRADED,
                'is_practice' => false,
                'score' => 0, 'max_score' => 100, 'passed' => false, 'random_seed' => 1,
                'started_at' => now(), 'submitted_at' => now(),
            ]);

            Answer::create([
                'workspace_id' => $workspace->getKey(),
                'attempt_id' => $attempt->getKey(),
                'question_id' => $question->getKey(),
                'student_user_id' => $student->getKey(),
                'selected_option_ids' => [],
                'is_correct' => false,
                'points' => 0,
                'requires_grading' => false,
            ]);

            $q[$tag] = (int) $question->getKey();

            return ['course' => $course, 'exam' => $exam, 'lesson' => $lesson];
        });
    };

    $mine = $build($ws, $owner, 'MINE');
    $build($other, $otherOwner, 'THEIRS');

    // A course in the SAME workspace the student is enrolled in, so the course
    // facet has something to exclude that a workspace filter alone would keep.
    $otherCourse = app(WorkspaceContext::class)->forWorkspace($ws, function () use ($ws, $owner): Course {
        return Course::factory()->published()->create([
            'workspace_id' => $ws->getKey(),
            'created_by' => $owner->getKey(),
            'title' => 'ANOTHER-COURSE',
        ]);
    });

    return [
        'student' => $student,
        'ws' => $ws,
        'other' => $other,
        'course' => $mine['course'],
        'otherCourse' => $otherCourse,
        'exam' => $mine['exam'],
        'q' => $q,
    ];
}

/** Every question's text in a notebook response. */
function notebookQuestions(array $payload): array
{
    return array_map(
        static fn (array $row): string => (string) ($row['question']['content'] ?? ''),
        $payload['data'] ?? [],
    );
}

beforeEach(function (): void {
    $this->fx = mistakeFilterFixture();

    Sanctum::actingAs($this->fx['student']);
    // The context production actually gives this student: none.
    $this->asGuest();
});

/*
| ⚠️ THE MEASUREMENT THAT FOUND IT. Before this change the assertion below read
| `422` — «اختر مساحة عمل لعرض دفتر أخطائك» — for the only person the notebook
| is for.
*/
it('opens for a real student, who has no workspace context at all', function (): void {
    $payload = $this->getJson('/api/v1/mistakes')->assertOk()->json();

    expect(notebookQuestions($payload))->toContain('MINE-Q');
});

it('spans every teacher the student studies with, and names whose each row is', function (): void {
    $rows = $this->getJson('/api/v1/mistakes')->assertOk()->json('data');

    $names = array_map(static fn (array $row): string => (string) ($row['teacher']['name'] ?? ''), $rows);

    // Both teachers' mistakes, and neither anonymous — a list that spans
    // teachers silently is what FR-016أ forbids; one that says whose is whose
    // is what it protects.
    expect(notebookQuestions(['data' => $rows]))->toContain('MINE-Q')->toContain('THEIRS-Q')
        ->and($names)->not->toContain('');
});

it('narrows to one teacher, and never widens past the ones it may read', function (): void {
    $mine = $this->getJson('/api/v1/mistakes?teacher='.$this->fx['ws']->uuid)->assertOk()->json();

    expect(notebookQuestions($mine))->toBe(['MINE-Q']);

    // A workspace the reader studies with nobody in: empty, never everything.
    [$stranger] = $this->createWorkspaceWithOwner();
    $this->asGuest();

    expect(notebookQuestions($this->getJson('/api/v1/mistakes?teacher='.$stranger->uuid)->assertOk()->json()))
        ->toBeEmpty();
});

it('narrows to one course through the question\'s own lesson', function (): void {
    $payload = $this->getJson('/api/v1/mistakes?course='.$this->fx['course']->uuid)->assertOk()->json();

    expect(notebookQuestions($payload))->toBe(['MINE-Q']);

    // A course of the same teacher with no mistakes in it — empty, and the
    // filter is not silently dropped.
    expect(notebookQuestions($this->getJson('/api/v1/mistakes?course='.$this->fx['otherCourse']->uuid)->assertOk()->json()))
        ->toBeEmpty();
});

it('answers a uuid it cannot resolve with an empty notebook rather than the whole one', function (): void {
    foreach (['teacher', 'course', 'exam', 'concept', 'lesson'] as $filter) {
        $payload = $this->getJson('/api/v1/mistakes?'.$filter.'='.Str::uuid())->assertOk()->json();

        expect(notebookQuestions($payload))->toBeEmpty();
    }
});

/*
| ⚠️ THE EXAM FILTER IS A `HAVING`, AND THIS IS THE CASE THAT PROVES IT.
|
| Written as a `WHERE at.exam_id = ?` it removes every answer given anywhere
| else — including the later CORRECT one, which in this product is usually a
| practice run whose attempt has no exam at all. The mistake would then read as
| still standing for ever, and «اختبرني في أخطائي» would keep handing it back.
*/
it('drops a mistake fixed in practice from its exam\'s filter', function (): void {
    $exam = $this->fx['exam']->uuid;

    expect(notebookQuestions($this->getJson('/api/v1/mistakes?exam='.$exam)->assertOk()->json()))
        ->toBe(['MINE-Q']);

    // Answered correctly later, in a practice run — no exam on the attempt.
    answerRow(
        (int) $this->fx['ws']->getKey(),
        $this->fx['student'],
        $this->fx['q']['MINE'],
        correct: true,
        overrides: ['is_practice' => true],
    );

    expect(notebookQuestions($this->getJson('/api/v1/mistakes?exam='.$exam)->assertOk()->json()))
        ->toBeEmpty();

    // And it is still readable when asked for — a notebook, not a to-do list.
    expect(notebookQuestions($this->getJson('/api/v1/mistakes?exam='.$exam.'&include_resolved=1')->assertOk()->json()))
        ->toBe(['MINE-Q']);
});

/*
| ⚠️ EVERY OFFERED OPTION IS WALKED THROUGH THE READER — the `LeaderboardScopesTest`
| shape, and the whole reason this class exists. A picker assembled beside the
| authoriser offers what the authoriser refuses; spec 009 shipped exactly that.
*/
it('offers only options the notebook actually answers', function (): void {
    $options = $this->getJson('/api/v1/mistakes/filters')->assertOk()->json();

    expect($options['teachers'])->not->toBeEmpty()
        ->and($options['courses'])->not->toBeEmpty()
        ->and($options['exams'])->not->toBeEmpty();

    $keys = ['teachers' => 'teacher', 'courses' => 'course', 'exams' => 'exam', 'concepts' => 'concept'];

    foreach ($keys as $facet => $param) {
        foreach ($options[$facet] as $option) {
            expect($option['label'])->not->toBe('');

            $rows = notebookQuestions(
                $this->getJson('/api/v1/mistakes?'.$param.'='.$option['uuid'])->assertOk()->json(),
            );

            expect($rows)->not->toBeEmpty(
                "the {$facet} option «{$option['label']}» is offered and answers nothing",
            );
        }
    }
});

/*
| ⚠️ THE BAR FOLLOWS THE VIEW — AND THIS IS THE CASE A REAL DATABASE FOUND.
|
| Derived from the STANDING set alone, the bar vanished for anybody who had
| fixed everything: «القائم» is empty and rightly shows no bar, but «الكل» then
| listed their whole notebook with nothing to filter it by. The demo data is
| exactly that shape — every seeded mistake has a later correct answer — so the
| screen offered no filters at all, in either view, on the only account anybody
| was looking at.
*/
it('still offers the bar under «الكل» when every mistake has been fixed', function (): void {
    // Fix the one standing mistake, in a practice run.
    answerRow(
        (int) $this->fx['ws']->getKey(),
        $this->fx['student'],
        $this->fx['q']['MINE'],
        correct: true,
        overrides: ['is_practice' => true],
    );
    answerRow(
        (int) $this->fx['other']->getKey(),
        $this->fx['student'],
        $this->fx['q']['THEIRS'],
        correct: true,
        overrides: ['is_practice' => true],
    );

    // Nothing standing: no bar, and nothing to build a paper from.
    $standing = $this->getJson('/api/v1/mistakes/filters')->assertOk()->json();

    expect($standing['teachers'])->toBeEmpty()
        ->and($standing['has_standing'])->toBeFalse();

    // But the notebook still holds them, and «الكل» must be filterable.
    $all = $this->getJson('/api/v1/mistakes/filters?include_resolved=1')->assertOk()->json();

    expect($all['teachers'])->not->toBeEmpty()
        ->and($all['exams'])->not->toBeEmpty()
        // ⚠️ AND STILL FALSE. `has_standing` answers «is there anything to
        // practise», which the view does not change — offering a paper here is
        // a 422 the student cannot act on.
        ->and($all['has_standing'])->toBeFalse();

    // Every option it offers answers a row in the view it was asked for.
    foreach ($all['teachers'] as $option) {
        expect(notebookQuestions(
            $this->getJson('/api/v1/mistakes?include_resolved=1&teacher='.$option['uuid'])->assertOk()->json(),
        ))->not->toBeEmpty();
    }
});

it('offers nothing belonging to a teacher the student does not study with', function (): void {
    $options = $this->getJson('/api/v1/mistakes/filters')->assertOk()->json();

    $labels = array_merge(
        array_column($options['courses'], 'label'),
        array_column($options['exams'], 'label'),
    );

    // The other teacher IS offered — the student studies with them — but a
    // course of theirs the student has no mistake in is not, and neither is the
    // sibling course in their own teacher's workspace.
    expect($labels)->not->toContain('ANOTHER-COURSE');
});

/*
| ⚠️ A PAPER BELONGS TO ONE TEACHER, WHICH THE NOTEBOOK NO LONGER DOES. Building
| one without saying whose would draw questions from whichever bank came first —
| a revision session about the wrong subject, silently.
*/
it('refuses to build a revision paper until a teacher is named', function (): void {
    $this->postJson('/api/v1/practice/from-mistakes', [])
        ->assertStatus(422)
        ->assertJsonPath('message', 'اختر المدرّس أولاً لبناء اختبار من أخطائك.');

    $this->postJson('/api/v1/practice/from-mistakes?teacher='.$this->fx['ws']->uuid, [])
        ->assertCreated();
});
