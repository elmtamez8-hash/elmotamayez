<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Events\TeacherApproved;
use App\Modules\Marketplace\Models\TeacherApplication;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| An invited co-teacher applies for a profile of their own, in the workspace
| that invited them (co-teacher journey 2026-10-22, owner decision «ب»), and
| on approval their courses move to it — they are paid for their own work.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->coTeacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $this->coTeacher->forceFill(['platform_role' => 'teacher'])->save();
});

it('starts a draft at step two in the inviting workspace, once', function (): void {
    $this->setCurrentWorkspace($this->workspace, $this->coTeacher);
    Sanctum::actingAs($this->coTeacher);

    $first = $this->postJson('/api/v1/teacher/application')->assertOk()->json('application.uuid');
    $again = $this->postJson('/api/v1/teacher/application')->assertOk()->json('application.uuid');

    $application = TeacherApplication::query()->withoutWorkspaceScope()->where('user_id', $this->coTeacher->getKey())->sole();

    expect($again)->toBe($first)
        ->and((int) $application->workspace_id)->toBe((int) $this->workspace->getKey())
        ->and($application->current_step)->toBe(2)
        ->and($application->status)->toBe(TeacherApplication::STATUS_DRAFT);
});

it('is the invited teacher\'s door, not an assistant\'s', function (): void {
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->setCurrentWorkspace($this->workspace, $assistant);
    Sanctum::actingAs($assistant);

    $this->postJson('/api/v1/teacher/application')->assertUnprocessable();

    expect(TeacherApplication::query()->withoutWorkspaceScope()->where('user_id', $assistant->getKey())->exists())->toBeFalse();
});

it('moves the co-teacher\'s courses to their profile on approval, and no colleague\'s', function (): void {
    $ownerProfile = TeacherProfile::factory()->published()->create([
        'user_id' => $this->owner->getKey(),
        'workspace_id' => $this->workspace->getKey(),
    ]);

    [$theirs, $ownersCourse] = app(WorkspaceContext::class)->forWorkspace($this->workspace, fn (): array => [
        Course::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->coTeacher->getKey(),
            'teacher_profile_id' => $ownerProfile->getKey(),
        ]),
        Course::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->owner->getKey(),
            'teacher_profile_id' => $ownerProfile->getKey(),
        ]),
    ]);

    $profile = TeacherProfile::factory()->published()->create([
        'user_id' => $this->coTeacher->getKey(),
        'workspace_id' => $this->workspace->getKey(),
    ]);

    event(new TeacherApproved($profile));

    expect((int) $theirs->refresh()->teacher_profile_id)->toBe((int) $profile->getKey())
        ->and((int) $ownersCourse->refresh()->teacher_profile_id)->toBe((int) $ownerProfile->getKey());
});
