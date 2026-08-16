<?php

declare(strict_types=1);

use App\Modules\Assessments\Actions\GradeSubmission;
use App\Modules\Assessments\Actions\SubmitAssignment;
use App\Modules\Assessments\Models\Assignment;
use App\Modules\Assessments\Models\UnlockRule;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use App\Shared\Contracts\UnlockDirectory;

/*
| FR-037 · quickstart §9. Two tiers, the specific wins, and its absence inherits.
*/

/** Homework on the first session, handed in and marked. */
function gradedHomework(object $workspace, object $owner, object $student, object $first, object $course, float $score): void
{
    $homework = Assignment::factory()->published()->create([
        'workspace_id' => $workspace->getKey(),
        'course_id' => $course->getKey(),
        'class_session_id' => $first->getKey(),
        'created_by' => $owner->getKey(),
        'due_at' => now()->addDay(),
        'points' => 100,
    ]);

    $submission = app(SubmitAssignment::class)->handle($homework, $student, 'حلّي.');
    app(GradeSubmission::class)->handle($submission, $owner, $score);
}

it('opens one course and shuts another for the same score', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    [$firstA, $secondA, $courseA] = gatedPair($workspace, $student);
    [$firstB, $secondB, $courseB] = gatedPair($workspace, $student);

    // The workspace default: 50٪ is enough.
    UnlockRule::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => false,
        'requires_assignment' => true,
        'min_score_pct' => 50,
    ]);

    // And course B asks for 80.
    UnlockRule::factory()->forCourse((int) $courseB->getKey())->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => false,
        'requires_assignment' => true,
        'min_score_pct' => 80,
    ]);

    attendanceRow($workspace, $firstA, $student, AttendanceStatus::Present);
    attendanceRow($workspace, $firstB, $student, AttendanceStatus::Present);

    gradedHomework($workspace, $owner, $student, $firstA, $courseA, 60);
    gradedHomework($workspace, $owner, $student, $firstB, $courseB, 60);

    $directory = app(UnlockDirectory::class);

    /*
    | ⚠️ ONE STUDENT, ONE SCORE, TWO ANSWERS. This is the whole of FR-037: the
    | course rule is not merged with the default, it REPLACES it. A resolver that
    | merged — taking the stricter, say — would give both courses 80 and shut
    | course A on a rule its teacher never wrote.
    */
    expect($directory->refusalFor($student, (int) $secondA->getKey()))->toBeNull()
        ->and($directory->refusalFor($student, (int) $secondB->getKey()))->toContain('80٪');
});

it('inherits the default where no course rule exists, and never reads its absence as open', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second] = gatedPair($workspace, $student);

    UnlockRule::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
    ]);

    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    /*
    | ⚠️ THE ABSENCE OF A COURSE ROW IS INHERITANCE, NEVER PERMISSION. Read as
    | "no condition here", every course a teacher never configured would be a
    | hole in the gate — and the gate would appear to work, because the one
    | course they DID configure behaves.
    */
    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))
        ->toContain('حضور الحصة السابقة');
});

it('lets a course rule switch a component off that the default has on', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second, $course] = gatedPair($workspace, $student);

    UnlockRule::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
    ]);

    /*
    | ⚠️ THE OTHER DIRECTION, AND IT IS THE ONE MERGING BREAKS. A course that
    | does NOT require attendance has to be expressible; merged with a default
    | that does, "off" is a value the teacher can type and the system ignores.
    */
    UnlockRule::factory()->forCourse((int) $course->getKey())->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => false,
        'requires_assignment' => false,
    ]);

    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    expect(app(UnlockDirectory::class)->refusalFor($student, (int) $second->getKey()))->toBeNull();
});

it('names which of the two rules decided', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    [$first, $second, $course] = gatedPair($workspace, $student);

    UnlockRule::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
    ]);

    UnlockRule::factory()->forCourse((int) $course->getKey())->create([
        'workspace_id' => $workspace->getKey(),
        'requires_attendance' => true,
        'requires_assignment' => false,
    ]);

    attendanceRow($workspace, $first, $student, AttendanceStatus::Absent);

    // Precedence is invisible without this: a teacher whose two rules say the
    // same thing today cannot tell which one to edit tomorrow.
    expect(app(UnlockDirectory::class)->explain($student, (int) $second->getKey())['rule_scope'])
        ->toBe('course');
});
