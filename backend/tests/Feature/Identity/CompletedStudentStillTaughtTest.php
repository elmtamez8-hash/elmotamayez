<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Learning\Models\Enrollment;

/*
| A `completed` enrolment is still a student this teacher teaches (owner decision
| 2026-09-23: 100% today is 100% of what has been published so far, and the student
| keeps booking and learning). Three policies asked `status = 'active'` alone, so
| the teacher lost sight of that student's guardians and self-set papers the moment
| the progress bar filled. They read `Enrollment::GRANTING_STATUSES` now.
*/

function selfSetAttempt(int $workspaceId, int $studentId): Attempt
{
    return Attempt::create([
        'workspace_id' => $workspaceId,
        'exam_id' => null,
        'enrollment_id' => null,
        'student_user_id' => $studentId,
        'status' => Attempt::STATUS_GRADED,
        'is_practice' => true,
        'score' => 0,
        'max_score' => 100,
        'passed' => false,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);
}

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->student = $this->addWorkspaceMember($this->workspace);
    Enrollment::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => Course::factory()->create(['workspace_id' => $this->workspace->getKey()])->getKey(),
        'student_user_id' => $this->student->getKey(),
        'status' => 'completed',
    ]);
});

it("lets the teacher see a completed student's guardians and self-set papers", function (): void {
    [$workspace, $owner, $student] = [$this->workspace, $this->owner, $this->student];

    expect(Enrollment::query()->where('student_user_id', $student->getKey())->value('status'))->toBe('completed');

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $student->getKey()]);
    $attempt = selfSetAttempt((int) $workspace->getKey(), (int) $student->getKey());

    expect($owner->can('view', $relation))->toBeTrue()
        ->and($owner->can('view', $attempt))->toBeTrue();
});

it('still refuses a student this workspace never taught', function (): void {
    [$workspace, $owner] = [$this->workspace, $this->owner];

    $stranger = $this->addWorkspaceMember($workspace);

    $relation = ParentStudentRelation::factory()->create(['student_user_id' => $stranger->getKey()]);
    $attempt = selfSetAttempt((int) $workspace->getKey(), (int) $stranger->getKey());

    expect($owner->can('view', $relation))->toBeFalse()
        ->and($owner->can('view', $attempt))->toBeFalse();
});
