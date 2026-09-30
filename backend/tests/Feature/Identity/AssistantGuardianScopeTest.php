<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Identity\Models\ParentStudentRelation;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| Spec 010 · FR-005 on a student's guardians (audit 2026-09-30).
|
| ⚠️ `ParentStudentRelationPolicy`'s staff branch asked «is this student enrolled
| ANYWHERE in my workspace», and `relations.view.student` is on the assistant role
| by default — so an assistant confined to one course read the guardians (names,
| phone-bearing accounts) of every other course's students. It now also asks
| `mayActOnStudent()`.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->nearStudent = User::factory()->create();
    $this->farStudent = User::factory()->create();

    foreach ([[$this->near, $this->nearStudent], [$this->far, $this->farStudent]] as [$course, $student]) {
        Enrollment::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'course_id' => $course->getKey(),
            'student_user_id' => $student->getKey(),
            'status' => 'active',
        ]);
    }

    $this->nearRelation = ParentStudentRelation::factory()->create(['student_user_id' => $this->nearStudent->getKey()]);
    $this->farRelation = ParentStudentRelation::factory()->create(['student_user_id' => $this->farStudent->getKey()]);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

it('shows a confined assistant the guardians of their own students only', function (): void {
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->near->getKey(),
    ]);
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->assistant);

    $this->getJson("/api/v1/family/relations/{$this->nearRelation->uuid}")->assertOk();
    $this->getJson("/api/v1/family/relations/{$this->farRelation->uuid}")->assertForbidden();
});

it('leaves an unconfined assistant and the owner every enrolled student\'s guardians', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        $this->getJson("/api/v1/family/relations/{$this->farRelation->uuid}")->assertOk();
    }
});

it('leaves the family\'s own reads alone', function (): void {
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->near->getKey(),
    ]);

    $this->asGuest();
    Sanctum::actingAs($this->farStudent);

    $this->getJson("/api/v1/family/relations/{$this->farRelation->uuid}")->assertOk();
});
