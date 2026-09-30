<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\ConceptStat;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\Question;
use App\Modules\Assessments\Models\QuestionStat;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on item analysis. `analytics.view` is on the DEFAULT
| assistant role, so this was live for every assistant: the question list and
| the concept list were the whole workspace's, and every concept row names a
| lesson — the titles of lessons in courses the assistant is refused everywhere
| else.
|
| A confined assistant now reads the questions whose lesson is in one of their
| courses or that sit in an exam of one, and the concept rows of their own
| lessons; the «overall» row (an aggregate over every course) is not shown to
| them. Everybody else is unchanged — both directions in every test.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->nearCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->farCourse = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->nearLesson = Lesson::factory()->create(['workspace_id' => $this->workspace->getKey(), 'course_id' => $this->nearCourse->getKey()]);
    $this->farLesson = Lesson::factory()->create(['workspace_id' => $this->workspace->getKey(), 'course_id' => $this->farCourse->getKey()]);

    $ws = $this->workspace;

    // Five questions, one per way a question can relate to a course.
    $this->qNearLesson = anStat($ws, bankQuestion($ws, null, ['lesson_id' => $this->nearLesson->getKey()]));
    $this->qFarLesson = anStat($ws, bankQuestion($ws, null, ['lesson_id' => $this->farLesson->getKey()]));
    $this->qNearExam = anStat($ws, bankQuestion($ws, Exam::factory()->create(['workspace_id' => $ws->getKey(), 'course_id' => $this->nearCourse->getKey()])));
    $this->qFarExam = anStat($ws, bankQuestion($ws, Exam::factory()->create(['workspace_id' => $ws->getKey(), 'course_id' => $this->farCourse->getKey()])));
    $this->qLoose = anStat($ws, bankQuestion($ws));

    $concept = $this->qNearLesson->concept_id;

    foreach ([ConceptStat::OVERALL, $this->nearLesson->getKey(), $this->farLesson->getKey()] as $lessonId) {
        ConceptStat::factory()->create([
            'workspace_id' => $ws->getKey(),
            'concept_id' => $concept,
            'lesson_id' => $lessonId,
        ]);
    }

    // Another teacher's workspace, with a stat of its own that nobody here sees.
    [$other] = $this->createWorkspaceWithOwner();
    $this->qOther = anStat($other, bankQuestion($other));
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function anStat(Workspace $workspace, Question $question): Question
{
    QuestionStat::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'question_id' => $question->getKey(),
    ]);

    return $question;
}

function anConfine(): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => test()->nearCourse->getKey(),
    ]);

    app()->forgetScopedInstances();
}

/** @return list<string> question uuids on the list, as this reader */
function anQuestions(User $reader): array
{
    Sanctum::actingAs($reader);

    return collect(test()->getJson('/api/v1/manage/analytics/questions?per_page=100')->assertOk()->json('data'))
        ->pluck('question.uuid')->all();
}

/** @return list<string> «overall» or a lesson uuid, one per concept row */
function anConceptRows(User $reader): array
{
    Sanctum::actingAs($reader);

    return collect(test()->getJson('/api/v1/manage/analytics/concepts')->assertOk()->json('data'))
        ->map(fn (array $row): string => $row['is_overall'] ? 'overall' : (string) $row['lesson']['uuid'])
        ->all();
}

it('shows a confined assistant the questions of their own courses and none of the others', function (): void {
    anConfine();

    expect(anQuestions($this->assistant))->toEqualCanonicalizing([
        $this->qNearLesson->uuid,
        $this->qNearExam->uuid,
    ]);
});

it('shows a confined assistant the concept rows of their own lessons, and no overall row', function (): void {
    anConfine();

    expect(anConceptRows($this->assistant))->toBe([(string) $this->nearLesson->uuid]);
});

it('leaves an unconfined assistant and the owner the whole workspace, and never another one', function (): void {
    $all = [
        $this->qNearLesson->uuid,
        $this->qFarLesson->uuid,
        $this->qNearExam->uuid,
        $this->qFarExam->uuid,
        $this->qLoose->uuid,
    ];

    $rows = ['overall', (string) $this->nearLesson->uuid, (string) $this->farLesson->uuid];

    foreach ([$this->assistant, $this->owner] as $reader) {
        expect(anQuestions($reader))->toEqualCanonicalizing($all)
            ->not->toContain($this->qOther->uuid)
            ->and(anConceptRows($reader))->toEqualCanonicalizing($rows);
    }
});
