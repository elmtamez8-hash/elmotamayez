<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
| Spec 010 · FR-005 on «فريقك» (audit 2026-09-30).
|
| ⚠️ `workspace_members` CARRIES THE STUDENTS TOO, and the members list answers
| each row's EMAIL. `members.view` is on the assistant role by default, so an
| assistant confined to one course read the name and email of every student of
| every course. A confined reader now sees the staff rows and the students
| `mayActOnStudent()` lets them act on — and the page count says so too.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->near = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->far = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);

    $this->teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->nearStudent = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->farStudent = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    enrolForMembersList($this->near, $this->nearStudent);
    enrolForMembersList($this->far, $this->farStudent);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);

    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function enrolForMembersList(Course $course, User $student): void
{
    Enrollment::create([
        'workspace_id' => $course->workspace_id, 'uuid' => Str::uuid(), 'course_id' => $course->getKey(),
        'student_user_id' => $student->getKey(), 'status' => 'active', 'enrolled_at' => now(),
    ]);
}

/** @return array{uuids: list<string>, emails: list<string>, total: int} */
function readMembersList(): array
{
    $response = test()->getJson('/api/v1/workspaces/'.test()->workspace->uuid.'/members')->assertOk();

    return [
        'uuids' => collect($response->json('data'))->pluck('uuid')->all(),
        'emails' => collect($response->json('data'))->pluck('email')->all(),
        'total' => (int) $response->json('meta.total'),
    ];
}

it('shows a confined assistant the staff and their own students only', function (): void {
    AssistantScope::factory()->create([
        'assistant_assignment_id' => $this->assignment->getKey(),
        'course_id' => $this->near->getKey(),
    ]);
    app()->forgetScopedInstances();

    Sanctum::actingAs($this->assistant);

    $list = readMembersList();

    expect($list['uuids'])->toEqualCanonicalizing([
        $this->owner->uuid,
        $this->teacher->uuid,
        $this->assistant->uuid,
        $this->nearStudent->uuid,
    ])
        ->and($list['emails'])->not->toContain($this->farStudent->email)
        ->and($list['total'])->toBe(4);
});

it('leaves an unconfined assistant and the owner the whole roll', function (): void {
    foreach ([$this->assistant, $this->owner] as $reader) {
        Sanctum::actingAs($reader);

        $list = readMembersList();

        expect($list['uuids'])->toContain($this->nearStudent->uuid, $this->farStudent->uuid)
            ->and($list['total'])->toBe(5);
    }
});
