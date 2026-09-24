<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeSubmission;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Enums\ImportStatus;
use App\Modules\Assessments\Jobs\ImportQuestionsJob;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Notifications\Support\NotificationType;
use App\Shared\Support\GuardianPermission;

/*
| Every notification the Assessments module produces, from the event that
| really produces it (`EveryNotificationTypeIsTestedTest`).
|
| ⚠️ Each case drives the Action or job the product runs — never
| `DispatchNotification` directly — because the defects this guards against
| live in the WIRING: a listener that is not registered, a payload missing a
| template variable (dropped in silence by `TemplateRenderer`), a relation that
| resolves null. A test that dispatches the type itself proves the dispatcher.
*/

it('tells the uploader a question import finished, with its counts', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $import = startImport((int) $workspace->getKey(), (int) $owner->getKey(), importFile(mcqRow('سؤالٌ للإشعار؟')));

    ImportQuestionsJob::dispatchSync((int) $import->getKey());

    expect($import->refresh()->status)->toBe(ImportStatus::Done);

    assertNotifiedOnce($owner, NotificationType::QuestionImportReady);

    expect(wasNotified($owner, NotificationType::QuestionImportFailed))->toBeFalse();
});

it('tells the uploader a question import failed, with the reason', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // A path with no file behind it: the importer cannot open it, so the import
    // itself fails rather than any one row.
    importFile('');
    $import = startImport((int) $workspace->getKey(), (int) $owner->getKey(), 'imports/missing.csv');

    ImportQuestionsJob::dispatchSync((int) $import->getKey());

    expect($import->refresh()->status)->toBe(ImportStatus::Failed);

    assertNotifiedOnce($owner, NotificationType::QuestionImportFailed);

    expect(wasNotified($owner, NotificationType::QuestionImportReady))->toBeFalse();
});

it('tells a student their paper is waiting for a person to mark the essay', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    [, $attempt] = sitEssayExam($workspace, $student);

    expect($attempt->status)->toBe(Attempt::STATUS_PENDING_GRADING);

    assertNotifiedOnce($student, NotificationType::ExamPendingGrading);
});

it('tells the author an assignment was handed in', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay()]);

    app(SubmitAssignment::class)->handle($assignment, $student, 'الحلّ.');

    assertNotifiedOnce($owner, NotificationType::AssignmentSubmitted);

    expect(wasNotified($student, NotificationType::AssignmentSubmitted))->toBeFalse();
});

it('tells the student their mark, and the guardian entitled to results — not one without', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $resultsGuardian = guardianOf($student, [GuardianPermission::Results]);
    $attendanceGuardian = guardianOf($student, [GuardianPermission::Attendance]);

    [$assignment] = courseAssignment($workspace, $owner, $student, ['due_at' => now()->addDay()]);

    $submission = app(SubmitAssignment::class)->handle($assignment, $student, 'الحلّ.');
    app(GradeSubmission::class)->handle($submission, $owner, 8.0);

    // The student's row first — without it, the guardian's absence below would be
    // true of a build that sent nothing to anybody.
    assertNotifiedOnce($student, NotificationType::AssignmentGraded);
    assertNotifiedOnce($resultsGuardian, NotificationType::AssignmentGraded);

    expect(wasNotified($attendanceGuardian, NotificationType::AssignmentGraded))->toBeFalse();
});
