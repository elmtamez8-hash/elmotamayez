<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Answer;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Support\GradingSettings;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on the grading board. A confined assistant holding
| `grading.perform` sees, counts, opens and marks the papers of their own
| courses — and `meta.total` is the dashboard's «بانتظار التصحيح», so a queue
| that listed the rest was a number that never reached zero.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST: «far is absent» alone is green against an
| assistant who sees nothing at all.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    // Each sitting builds a course of its own, so two give a near and a far.
    [$this->nearExam, $this->nearPaper] = sitEssayExam($this->workspace, $this->student);
    [$this->farExam, $this->farPaper] = sitEssayExam($this->workspace, $this->student);

    $this->near = Course::query()->findOrFail($this->nearExam->course_id);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::GRADING_PERFORM);
    $this->assistant->givePermissionTo(Permissions::ATTEMPTS_VIEW_ALL);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function confineGraderTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

/** @return array{uuids: list<string>, total: int} */
function gradingQueueRead(): array
{
    $response = test()->getJson('/api/v1/manage/grading/queue')->assertOk();

    return [
        'uuids' => collect($response->json('data'))->pluck('uuid')->all(),
        'total' => (int) $response->json('meta.total'),
    ];
}

function essayAnswerOf(Attempt $attempt): Answer
{
    return Answer::query()
        ->where('attempt_id', $attempt->getKey())
        ->where('requires_grading', true)
        ->sole();
}

it('queues and counts only a confined assistant\'s own courses', function (): void {
    confineGraderTo($this->near);
    Sanctum::actingAs($this->assistant);

    expect(gradingQueueRead())->toBe(['uuids' => [$this->nearPaper->uuid], 'total' => 1]);
});

it('queues every paper for an unconfined assistant and for the owner', function (): void {
    foreach ([$this->assistant, $this->owner] as $grader) {
        Sanctum::actingAs($grader);

        $read = gradingQueueRead();

        expect($read['uuids'])->toEqualCanonicalizing([$this->nearPaper->uuid, $this->farPaper->uuid])
            ->and($read['total'])->toBe(2);
    }
});

it('leaves an exam set for no course out of a confined queue and in an unconfined one', function (): void {
    $loose = Exam::query()->whereKey($this->farExam->getKey())->sole();
    $loose->forceFill(['course_id' => null])->save();

    Sanctum::actingAs($this->assistant);
    expect(gradingQueueRead()['total'])->toBe(2);

    confineGraderTo($this->near);
    Sanctum::actingAs($this->assistant->refresh());
    expect(gradingQueueRead())->toBe(['uuids' => [$this->nearPaper->uuid], 'total' => 1]);

    $this->getJson("/api/v1/manage/grading/attempts/{$this->farPaper->uuid}")->assertForbidden();
});

it('opens and marks a paper inside the scope and refuses both outside it', function (): void {
    confineGraderTo($this->near);
    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/manage/grading/attempts/{$this->nearPaper->uuid}")->assertOk();
    $this->getJson("/api/v1/manage/grading/attempts/{$this->farPaper->uuid}")->assertForbidden();

    // The student-facing read of the same paper is the same policy.
    $this->getJson("/api/v1/attempts/{$this->farPaper->uuid}")->assertForbidden();

    $this->postJson('/api/v1/manage/grading/answers/'.essayAnswerOf($this->farPaper)->uuid, [
        'marks' => [['points' => 5]],
    ])->assertForbidden();

    $this->postJson('/api/v1/manage/grading/answers/'.essayAnswerOf($this->nearPaper)->uuid, [
        'marks' => [['points' => 5]],
    ])->assertOk();
});

it('lets an unconfined assistant open the far paper', function (): void {
    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/manage/grading/attempts/{$this->farPaper->uuid}")->assertOk();
});

it('keeps the confined queue anonymous when grading is', function (): void {
    app(GradingSettings::class)->setAnonymous($this->workspace, true);
    confineGraderTo($this->near);
    Sanctum::actingAs($this->assistant);

    $response = $this->getJson('/api/v1/manage/grading/queue')->assertOk();

    expect($response->json('meta.anonymous'))->toBeTrue()
        ->and($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0'))->not->toHaveKey('student');

    $this->getJson("/api/v1/manage/grading/attempts/{$this->nearPaper->uuid}")
        ->assertOk()
        ->assertJsonPath('data.student', null);
});

it('never queues or opens another workspace\'s paper', function (): void {
    [$other] = $this->createWorkspaceWithOwner();

    $foreign = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other): Attempt {
        $student = $this->addWorkspaceMember($other, Roles::STUDENT);

        return sitEssayExam($other, $student)[1];
    });

    foreach ([$this->owner, $this->assistant] as $grader) {
        Sanctum::actingAs($grader);

        expect(gradingQueueRead()['uuids'])->not->toContain($foreign->uuid);
        $this->getJson("/api/v1/manage/grading/attempts/{$foreign->uuid}")->assertNotFound();
    }
});

it('leaves a student\'s own paper readable to them', function (): void {
    confineGraderTo($this->near);

    Sanctum::actingAs(User::query()->findOrFail($this->student->getKey()));

    $this->getJson("/api/v1/attempts/{$this->farPaper->uuid}")->assertOk();
});
