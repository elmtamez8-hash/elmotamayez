<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Http\Controllers\SubmissionFileController;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on homework. A confined assistant holding
| `assignments.manage` and `submissions.grade` writes, publishes, extends and
| marks the homework of THEIR courses — and nothing else, even given a uuid.
|
| Until this file, `AssignmentPolicy` never asked the scope: the staff LIST was
| the only thing that could hide a far course's homework, and every door behind
| it (edit, publish, the marking list, an extension, a mark, the handed-in file)
| answered 200 to anyone who held the uuid.
|
| ⚠️ BOTH DIRECTIONS IN EVERY TEST: «far is refused» alone is green against an
| assistant who is refused everything.
*/

beforeEach(function (): void {
    Storage::fake('local');

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $this->student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    // Each call builds a course of its own and enrols the student in it.
    [$this->near, $this->nearCourse] = courseAssignment($this->workspace, $this->owner, $this->student, [
        'submission_type' => Assignment::TYPE_FILE,
    ]);
    [$this->far, $this->farCourse] = courseAssignment($this->workspace, $this->owner, $this->student, [
        'submission_type' => Assignment::TYPE_FILE,
    ]);

    // Homework set for the workspace at large — no course to compare a
    // confinement with.
    $this->loose = Assignment::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => null,
        'created_by' => $this->owner->getKey(),
        'submission_type' => Assignment::TYPE_FILE,
    ]);

    $this->nearWork = hwHandIn($this->near, $this->student);
    $this->farWork = hwHandIn($this->far, $this->student);
    $this->looseWork = hwHandIn($this->loose, $this->student);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo(Permissions::ASSIGNMENTS_MANAGE);
    $this->assistant->givePermissionTo(Permissions::SUBMISSIONS_GRADE);

    $this->assistantRow = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function hwHandIn(Assignment $assignment, User $student): Submission
{
    return app(SubmitAssignment::class)->handle(
        $assignment,
        $student,
        null,
        UploadedFile::fake()->create('homework.pdf', 20, 'application/pdf'),
    );
}

function hwConfineTo(Course $course): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assistantRow->getKey(),
        'course_id' => $course->getKey(),
    ]);

    // The directory memoises per request; a fresh container is the next request.
    app()->forgetScopedInstances();
}

/**
 * Every staff door on one assignment and one piece of work handed in for it,
 * as the status each answered.
 *
 * @return array<string, int>
 */
function hwDoors(Assignment $assignment, Submission $submission, User $reader, User $student): array
{
    $test = test();

    return [
        'show' => $test->getJson("/api/v1/assignments/{$assignment->uuid}")->getStatusCode(),
        'update' => $test->patchJson("/api/v1/manage/assignments/{$assignment->uuid}", ['title' => 'عنوان آخر', 'due_at' => now()->addDays(3)->toIso8601String()])->getStatusCode(),
        'publish' => $test->postJson("/api/v1/manage/assignments/{$assignment->uuid}/publish")->getStatusCode(),
        'submissions' => $test->getJson("/api/v1/manage/assignments/{$assignment->uuid}/submissions")->getStatusCode(),
        'extend' => $test->postJson("/api/v1/manage/assignments/{$assignment->uuid}/extensions", [
            'student_uuid' => $student->uuid,
            'until' => now()->addDays(5)->toIso8601String(),
        ])->getStatusCode(),
        'grade' => $test->postJson("/api/v1/manage/submissions/{$submission->uuid}/grade", ['score' => 5])->getStatusCode(),
        'file' => $test->get(SubmissionFileController::linkFor($submission, (string) $reader->uuid))->getStatusCode(),
    ];
}

/** @return array<string, int> */
function hwEvery(int $status): array
{
    return array_fill_keys(['show', 'update', 'publish', 'submissions', 'extend', 'grade', 'file'], $status);
}

it('opens every staff door on a confined assistant\'s own course and refuses every one outside it', function (): void {
    hwConfineTo($this->nearCourse);
    Sanctum::actingAs($this->assistant);

    expect(hwDoors($this->near, $this->nearWork, $this->assistant, $this->student))->toBe(hwEvery(200));

    expect(hwDoors($this->far, $this->farWork, $this->assistant, $this->student))->toBe(hwEvery(403));

    // Nothing was written through the refused doors.
    $far = Assignment::query()->whereKey($this->far->getKey())->sole();
    $farWork = Submission::query()->whereKey($this->farWork->getKey())->sole();

    expect($far->title)->not->toBe('عنوان آخر')
        ->and($farWork->graded_at)->toBeNull()
        ->and($farWork->extension_until)->toBeNull();
});

it('refuses a confined assistant homework set for no course', function (): void {
    hwConfineTo($this->nearCourse);
    Sanctum::actingAs($this->assistant);

    expect(hwDoors($this->loose, $this->looseWork, $this->assistant, $this->student))->toBe(hwEvery(403));
});

it('opens every door, near, far and course-less, to an unconfined assistant and to the owner', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        foreach ([[$this->near, $this->nearWork], [$this->far, $this->farWork], [$this->loose, $this->looseWork]] as [$assignment, $work]) {
            expect(hwDoors($assignment, $work, $reader, $this->student))->toBe(hwEvery(200));
        }
    }
});

it('lets a confined assistant create homework only for a course inside the scope', function (): void {
    hwConfineTo($this->nearCourse);
    Sanctum::actingAs($this->assistant);

    $before = Assignment::query()->count();

    $this->postJson('/api/v1/manage/assignments', ['title' => 'بعيد', 'course_uuid' => $this->farCourse->uuid])
        ->assertForbidden();
    $this->postJson('/api/v1/manage/assignments', ['title' => 'لكل الطلاب'])
        ->assertForbidden();
    $this->postJson('/api/v1/manage/assignments', ['title' => 'لكل الطلاب', 'course_uuid' => null])
        ->assertForbidden();

    expect(Assignment::query()->count())->toBe($before);

    $this->postJson('/api/v1/manage/assignments', ['title' => 'قريب', 'course_uuid' => $this->nearCourse->uuid])
        ->assertCreated();
});

it('lets the owner and an unconfined assistant create homework for any course or none', function (): void {
    foreach ([$this->assistant, $this->owner] as $author) {
        Sanctum::actingAs($author);

        $this->postJson('/api/v1/manage/assignments', ['title' => 'بعيد', 'course_uuid' => $this->farCourse->uuid])
            ->assertCreated();
        $this->postJson('/api/v1/manage/assignments', ['title' => 'لكل الطلاب'])
            ->assertCreated();
    }
});

it('refuses a confined assistant moving their own homework out of the scope', function (): void {
    hwConfineTo($this->nearCourse);
    Sanctum::actingAs($this->assistant);

    $this->patchJson("/api/v1/manage/assignments/{$this->near->uuid}", [
        'title' => 'منقول',
        'course_uuid' => $this->farCourse->uuid,
    ])->assertForbidden();

    $this->patchJson("/api/v1/manage/assignments/{$this->near->uuid}", [
        'title' => 'منقول',
        'course_uuid' => null,
    ])->assertForbidden();

    expect(Assignment::query()->whereKey($this->near->getKey())->value('course_id'))
        ->toBe($this->nearCourse->getKey());
});

it('never opens another workspace\'s homework to the owner or the assistant', function (): void {
    [$other, $otherOwner] = $this->createWorkspaceWithOwner();

    [$foreign, $foreignWork] = app(WorkspaceContext::class)->forWorkspace($other, function () use ($other, $otherOwner): array {
        $student = $this->addWorkspaceMember($other, Roles::STUDENT);
        [$assignment] = courseAssignment($other, $otherOwner, $student, ['submission_type' => Assignment::TYPE_FILE]);

        return [$assignment, hwHandIn($assignment, $student)];
    });

    foreach ([$this->owner, $this->assistant] as $reader) {
        Sanctum::actingAs($reader);

        $this->patchJson("/api/v1/manage/assignments/{$foreign->uuid}", ['title' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/manage/assignments/{$foreign->uuid}/submissions")->assertNotFound();
        $this->postJson("/api/v1/manage/submissions/{$foreignWork->uuid}/grade", ['score' => 5])->assertNotFound();
    }
});

it('leaves the student reading, handing in and opening their own work on every course', function (): void {
    hwConfineTo($this->nearCourse);

    $fresh = Assignment::factory()->published()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->farCourse->getKey(),
        'created_by' => $this->owner->getKey(),
    ]);

    Sanctum::actingAs(User::query()->findOrFail($this->student->getKey()));

    $this->getJson("/api/v1/assignments/{$this->far->uuid}")->assertOk();
    $this->getJson("/api/v1/assignments/{$this->loose->uuid}")->assertOk();

    $this->postJson("/api/v1/assignments/{$fresh->uuid}/submissions", ['answer_text' => 'إجابتي'])
        ->assertCreated();

    $this->get(SubmissionFileController::linkFor($this->farWork, (string) $this->student->uuid))->assertOk();
    $this->get(SubmissionFileController::linkFor($this->looseWork, (string) $this->student->uuid))->assertOk();
});
