<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
| `GET /exams` AND `GET /assignments` HAD NO TENANT CONDITION AT ALL FOR A
| STUDENT — and both answered with every published paper and every published
| deadline on the PLATFORM.
|
| ⚠️ NOT A POLICY BUG AND NOT A MISSING SCOPE: `ExamPolicy::viewAny()` allows any
| member deliberately (what they get back is meant to be narrowed by the query),
| both models carry `BelongsToWorkspace`, and `WorkspaceScope::apply()` simply
| ADDS NO CONDITION when `WorkspaceContext::id()` is null — which it always is
| for a student. Nothing on their path writes `users.last_workspace_id`:
| enrolling writes nothing and signing in writes nothing, and its only writers
| are `CreateWorkspace` and `WorkspaceContext::set()`, both about workspace
| MEMBERS. So the query the scope was trusted to narrow ran unnarrowed.
|
| ⚠️ AND ONLY A TWO-WORKSPACE FIXTURE CAN SEE IT. Every existing test of these
| lists builds one workspace, where «everything» and «this teacher's» are the
| same rows — the same rule the audit chain already wrote down for platform-wide
| reads, reached from the other side.
|
| ⚠️ THE ASCII SENTINELS ARE DELIBERATE. `getContent()` escapes everything
| outside ASCII, so an Arabic needle in an exposure assertion is vacuously
| absent; these assertions read the decoded payload, and the titles are Latin so
| a future `assertDontSee` written beside them cannot lie either.
*/

/** @return array{student: User, mine: Course, foreignWorkspace: Workspace} */
function exposureFixture(): array
{
    /** @var TestCase $test */
    $test = test();

    [$mineWorkspace, $mineOwner] = $test->createWorkspaceWithOwner();
    [$foreignWorkspace, $foreignOwner] = $test->createWorkspaceWithOwner();
    $student = User::factory()->create();

    $seed = function (Workspace $workspace, User $owner, string $tag, ?User $enrol): Course {
        $course = Course::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'title' => $tag.'-COURSE',
        ]);

        if ($enrol !== null) {
            Enrollment::create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $enrol->getKey(),
                'source' => 'manual',
                'status' => 'active',
                'progress_pct' => 0,
                'enrolled_at' => now(),
            ]);
        }

        Exam::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'title' => $tag.'-EXAM',
        ]);

        Assignment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => $course->getKey(),
            'created_by' => $owner->getKey(),
            'title' => $tag.'-HOMEWORK',
            'points' => 10,
            'submission_type' => 'text',
            'status' => 'published',
            'published_at' => now(),
        ]);

        /*
         | ⚠️ AND THE COURSE-LESS PAIR, WHICH IS THE OTHER HALF OF THE FIX.
         | `course_id` is NULLABLE on both tables and `SaveAssignmentRequest`
         | allows a null explicitly — a teacher may set one paper for all their
         | students. A guard written as «course must be one of mine» alone hides
         | these from exactly the people they were written for, which is the
         | mirror-image defect and just as silent.
         */
        Exam::factory()->published()->create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => null,
            'title' => $tag.'-GENERAL-EXAM',
        ]);

        Assignment::create([
            'workspace_id' => $workspace->getKey(),
            'course_id' => null,
            'created_by' => $owner->getKey(),
            'title' => $tag.'-GENERAL-HOMEWORK',
            'points' => 10,
            'submission_type' => 'text',
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $course;
    };

    $mine = app(WorkspaceContext::class)->forWorkspace(
        $mineWorkspace,
        fn (): Course => $seed($mineWorkspace, $mineOwner, 'MINE', $student),
    );

    app(WorkspaceContext::class)->forWorkspace(
        $foreignWorkspace,
        fn (): Course => $seed($foreignWorkspace, $foreignOwner, 'FOREIGN', null),
    );

    return ['student' => $student, 'mine' => $mine, 'foreignWorkspace' => $foreignWorkspace];
}

/** Every title in a list response, however the module wraps its collection. */
function exposureTitles(array $payload): array
{
    $rows = $payload['data'] ?? $payload;

    return array_map(static fn (array $row): string => (string) $row['title'], array_values($rows));
}

beforeEach(function (): void {
    $this->fx = exposureFixture();

    Sanctum::actingAs($this->fx['student']);
    // ⚠️ The context production actually gives this student: none. A helper that
    // stamps `last_workspace_id` measures a person who does not exist.
    $this->asGuest();
});

it('does not hand a student another teacher\'s exams', function (): void {
    $titles = exposureTitles($this->getJson('/api/v1/exams')->assertOk()->json());

    expect($titles)->toContain('MINE-EXAM')
        // The teacher's paper for all their own students, which no course names.
        ->toContain('MINE-GENERAL-EXAM')
        ->not->toContain('FOREIGN-EXAM')
        ->not->toContain('FOREIGN-GENERAL-EXAM');
});

it('does not hand a student another teacher\'s homework', function (): void {
    $titles = exposureTitles($this->getJson('/api/v1/assignments')->assertOk()->json());

    expect($titles)->toContain('MINE-HOMEWORK')
        ->toContain('MINE-GENERAL-HOMEWORK')
        ->not->toContain('FOREIGN-HOMEWORK')
        ->not->toContain('FOREIGN-GENERAL-HOMEWORK');
});

/*
| ⚠️ A PERSON WITH NO ENROLMENT SEES NOTHING, NOT EVERYTHING. `whereIn(…, [])`
| matches no row, which is the direction a filter has to fail in — the same
| reason the `?course=` filter resolves an unknown uuid to an empty list rather
| than to the unfiltered one.
*/
it('shows a signed-in stranger nothing at all', function (): void {
    Sanctum::actingAs(User::factory()->create());
    $this->asGuest();

    expect(exposureTitles($this->getJson('/api/v1/exams')->assertOk()->json()))->toBeEmpty()
        ->and(exposureTitles($this->getJson('/api/v1/assignments')->assertOk()->json()))->toBeEmpty();
});

/*
| ⚠️ AND THE TEACHER'S OWN LIST IS UNTOUCHED. The narrowing hangs off «does not
| hold the manage permission», so a reader who does keeps `WorkspaceScope` as
| their guard — which works for them, because a member HAS a context. Narrowing
| both readers would have hidden every draft from the person writing it.
*/
it('leaves the teacher\'s own list scoped by the workspace as before', function (): void {
    $workspace = Workspace::query()->findOrFail($this->fx['mine']->workspace_id);
    $owner = User::query()->findOrFail($this->fx['mine']->created_by);

    Sanctum::actingAs($owner);
    $this->setCurrentWorkspace($workspace, $owner);

    $titles = exposureTitles($this->getJson('/api/v1/exams')->assertOk()->json());

    expect($titles)->toContain('MINE-EXAM')
        ->toContain('MINE-GENERAL-EXAM')
        ->not->toContain('FOREIGN-EXAM');
});

/*
| ⛔ AND THE DOOR, WHICH STAYED OPEN FOR A YEAR AFTER THE LIST WAS CLOSED.
|
| The four cases above narrow a QUERY. `ExamPolicy::view()` and
| `AssignmentPolicy::view()` read «published ⇒ allow» with no condition about the
| caller at all — `belongsToCurrentWorkspace()` correctly raises no objection on
| the null context every student has — so a uuid was the whole entitlement.
|
| ⚠️ THE EXAM CASE IS NOT MERELY A READ. `AttemptController::start()` authorises
| `view` and nothing else, and `StartAttempt` asks for no enrolment and writes a
| nullable `enrollment_id`, so the response carried every question's content and
| options. The realistic reader is not a stranger across the platform but a
| classmate in the SAME workspace sitting the final of a course they never bought.
|
| ⚠️ AND THE HOMEWORK CASE IS A WRITE. `submit()` authorises `view` too, so a
| stranger's uploaded file landed in a paying teacher's marking queue.
|
| Both now ask `StudentScope::permits()` — the same class the lists above use,
| because two spellings of one entitlement is how this gap opened.
*/

/** @return array{exam: Exam, homework: Assignment} */
function foreignPapers(): array
{
    return [
        'exam' => Exam::query()->withoutWorkspaceScope()->where('title', 'FOREIGN-EXAM')->firstOrFail(),
        'homework' => Assignment::query()->withoutWorkspaceScope()->where('title', 'FOREIGN-HOMEWORK')->firstOrFail(),
    ];
}

it('refuses a student the exam of a course they are not enrolled in', function (): void {
    $this->getJson('/api/v1/exams/'.foreignPapers()['exam']->uuid)->assertForbidden();
});

it('refuses to start an attempt on another teacher\'s exam', function (): void {
    $this->postJson('/api/v1/exams/'.foreignPapers()['exam']->uuid.'/attempts')->assertForbidden();

    // The paper stays unwritten: a refusal that still created the row would have
    // burnt an attempt allowance and frozen a snapshot of somebody else's bank.
    expect(Attempt::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('still lets the same student sit their own course\'s exam', function (): void {
    $mine = Exam::query()->withoutWorkspaceScope()->where('title', 'MINE-EXAM')->firstOrFail();

    $this->postJson('/api/v1/exams/'.$mine->uuid.'/attempts')->assertCreated();

    /*
    | ⚠️ THE POSITIVE CONTROL IS THE HALF THAT MATTERS. A guard written as «deny
    | unless the context matches» passes every negation above and locks every real
    | student out of the paper they are studying for — which is `belongsTo\
    | CurrentWorkspace()` denying on a null context, the defect this repository
    | already paid for once across five endpoints.
    */
})->group('positive-control');

it('refuses a student the homework of a course they are not enrolled in', function (): void {
    $this->getJson('/api/v1/assignments/'.foreignPapers()['homework']->uuid)->assertForbidden();
});

it('refuses a submission into another teacher\'s marking queue', function (): void {
    $this->postJson(
        '/api/v1/assignments/'.foreignPapers()['homework']->uuid.'/submissions',
        ['answer_text' => 'STRANGER-SUBMISSION'],
    )->assertForbidden();

    expect(Submission::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('still accepts a submission to the student\'s own homework', function (): void {
    $mine = Assignment::query()->withoutWorkspaceScope()->where('title', 'MINE-HOMEWORK')->firstOrFail();

    $this->postJson(
        '/api/v1/assignments/'.$mine->uuid.'/submissions',
        ['answer_text' => 'MY-SUBMISSION'],
    )->assertCreated();
})->group('positive-control');
