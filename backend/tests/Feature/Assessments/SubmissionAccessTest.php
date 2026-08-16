<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Http\Controllers\SubmissionFileController;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\Submission;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| SC-017 · SC-022 · FR-048أ · FR-049. Whose work this is, and who may open it.
*/

it('refuses one student the file of another', function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $classmate = $this->addWorkspaceMember($workspace);

    [$assignment] = courseAssignment($workspace, $owner, $student, [
        'due_at' => now()->addDay(),
        'submission_type' => Assignment::TYPE_FILE,
    ]);

    $submission = app(SubmitAssignment::class)->handle(
        $assignment,
        $student,
        null,
        UploadedFile::fake()->create('homework.pdf', 20, 'application/pdf'),
    );

    expect($submission->file())->not->toBeNull();

    // The classmate cannot even mint a link, and would be refused at the door
    // if they somehow held one.
    expect($classmate->can('view', $submission))->toBeFalse();

    $link = SubmissionFileController::linkFor($submission, (string) $classmate->uuid);

    Sanctum::actingAs($classmate);
    $this->get($link)->assertForbidden();
});

it('refuses a link minted for somebody else, even to a reader who may look', function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $assistant = $this->addWorkspaceMember($workspace);
    $assistant->givePermissionTo(Permissions::SUBMISSIONS_GRADE);

    [$assignment] = courseAssignment($workspace, $owner, $student, [
        'due_at' => now()->addDay(),
        'submission_type' => Assignment::TYPE_FILE,
    ]);

    $submission = app(SubmitAssignment::class)->handle(
        $assignment,
        $student,
        null,
        UploadedFile::fake()->create('homework.pdf', 20, 'application/pdf'),
    );

    // A link the OWNER was given, pasted into a group chat and opened by
    // somebody who is otherwise entitled to read the work.
    $ownersLink = SubmissionFileController::linkFor($submission, (string) $student->uuid);

    Sanctum::actingAs($assistant);

    /*
    | ⚠️ THE SIGNATURE IS VALID AND THE ANSWER IS STILL NO. A url signed over the
    | path alone is a bearer token in a shape people paste: dropped into a group
    | it opens for the whole room, which is the "shareable link" FR-048 forbids.
    | The reader's uuid inside the signature is what makes a pasted link worth
    | nothing to anybody else.
    */
    $this->get($ownersLink)->assertNotFound();

    // Their own link works, because they are entitled to read it.
    $this->get(SubmissionFileController::linkFor($submission, (string) $assistant->uuid))->assertOk();
});

it('stops working the moment the reader loses the right, and again when it expires', function (): void {
    Storage::fake('local');

    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $assistant = $this->addWorkspaceMember($workspace);
    $assistant->givePermissionTo(Permissions::SUBMISSIONS_GRADE);

    [$assignment] = courseAssignment($workspace, $owner, $student, [
        'due_at' => now()->addDay(),
        'submission_type' => Assignment::TYPE_FILE,
    ]);

    $submission = app(SubmitAssignment::class)->handle(
        $assignment,
        $student,
        null,
        UploadedFile::fake()->create('homework.pdf', 20, 'application/pdf'),
    );

    $link = SubmissionFileController::linkFor($submission, (string) $assistant->uuid);

    Sanctum::actingAs($assistant);
    $this->get($link)->assertOk();

    /*
    | ⚠️ THE HALF A SIGNATURE CANNOT EXPRESS. A signature proves who asked for
    | the link; it knows nothing about whether they may still read. Without the
    | policy re-run at open, a withdrawn assistant keeps opening coursework for
    | the rest of the window — and there is no way to close it early.
    */
    $assistant->revokePermissionTo(Permissions::SUBMISSIONS_GRADE);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $assistant->unsetRelation('roles')->unsetRelation('permissions');

    $this->get($link)->assertForbidden();

    // And the window closes on its own for a reader who kept the right.
    $assistant->givePermissionTo(Permissions::SUBMISSIONS_GRADE);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $assistant->unsetRelation('roles')->unsetRelation('permissions');

    $fresh = SubmissionFileController::linkFor($submission, (string) $assistant->uuid);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(SubmissionFileController::TTL_MINUTES + 1));

    $this->get($fresh)->assertForbidden();

    CarbonImmutable::setTestNow();
});

it('keeps the state and timing of one student out of a classmate payload', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $classmate = $this->addWorkspaceMember($workspace);

    [$assignment, $course] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay()]);
    $this->createEnrollment($workspace, $course, $classmate);

    app(SubmitAssignment::class)->handle($assignment, $student, 'إجابتي.');

    Sanctum::actingAs($classmate);

    $payload = $this->getJson("/api/v1/assignments/{$assignment->uuid}")->assertOk()->json('data');

    /*
    | ⚠️ THE CLASSMATE SEES NO ROW AT ALL, which is the first line of FR-049 —
    | and the Resource carries the second: a submission timestamped after the
    | deadline and labelled «في الموعد» tells any reader who can subtract that its
    | owner had an extension. `state` and `submitted_at` are as private as
    | `extension_until` is, because the pair says the same thing.
    */
    expect($payload['my_submission'])->toBeNull();

    expect(Submission::query()
        ->where('assignment_id', $assignment->getKey())
        ->where('student_user_id', $classmate->getKey())
        ->count())->toBe(0);
});

it('refuses a grader the coursework of somebody the workspace no longer teaches', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $assistant = $this->addWorkspaceMember($workspace);
    $assistant->givePermissionTo(Permissions::SUBMISSIONS_GRADE);

    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay()]);

    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'إجابتي.');

    // The term ends: the paper stays readable, because it was handed in under an
    // enrolment on this very course and would otherwise be unmarkable for ever.
    Enrollment::query()->where('student_user_id', $student->getKey())->update(['status' => 'completed']);

    expect($assistant->can('view', $submission))->toBeTrue();

    /*
    | ⚠️ AND A ROW WITH NO ENROLMENT BEHIND IT AT ALL IS REFUSED. `submissions.grade`
    | alone was the whole guard until this test — the same omission `AttemptPolicy`
    | was fixed for, in the same module, on work a student uploaded. And this
    | policy is re-run by `SubmissionFileController` at every file open, so the
    | gap was a permanent read on a former student's uploaded document.
    */
    $stranger = $this->addWorkspaceMember($workspace);

    $orphan = Submission::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'assignment_id' => $assignment->getKey(),
        'student_user_id' => $stranger->getKey(),
    ]);

    expect($assistant->can('view', $orphan))->toBeFalse();
});
