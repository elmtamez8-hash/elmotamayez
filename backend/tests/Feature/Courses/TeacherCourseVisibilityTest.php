<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| ظهورُ الكورسِ قرارُ المدرّس — قرارُ المالك 2026-09-26.
|
| ⛔ كان `CreateCourseDTO` يفترضُ «خاص» ولا طلبَ يقبلُ `visibility`، فكلُّ كورسٍ
| ينشرُه مدرّسٌ من شاشتِه يُجيبُ ٤٠٤ على صفحتِه العامّةِ وعلى الاشتراك حتى يقلبَه
| موظّفٌ من اللوحة. الآن: عامٌّ افتراضاً، والمدرّسُ يجعلُه خاصّاً ويعيدُه. الكوراسُ
| الموجودةُ تبقى على ظهورِها — لا هجرةَ تقلبُ بيانات.
*/

it('creates a teacher course PUBLIC when nothing is said', function (): void {
    [, $owner] = $this->createWorkspaceWithOwner();
    $subject = Subject::factory()->create();

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/courses', [
        'title' => 'فيزياء ٣',
        'subject' => (string) $subject->uuid,
        'course_type' => Course::TYPE_RECORDED,
    ])->assertCreated()->assertJsonPath('visibility', 'public');

    // The column, not the echo.
    expect(Course::query()->where('title', 'فيزياء ٣')->sole()->visibility)->toBe('public');
});

it('creates a course PRIVATE when the teacher chooses so', function (): void {
    [, $owner] = $this->createWorkspaceWithOwner();
    $subject = Subject::factory()->create();

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/courses', [
        'title' => 'كورس داخلي',
        'subject' => (string) $subject->uuid,
        'course_type' => Course::TYPE_RECORDED,
        'visibility' => 'private',
    ])->assertCreated()->assertJsonPath('visibility', 'private');
});

it('lets the teacher switch a course to private and back', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['visibility' => 'private'])->assertOk();
    expect($course->fresh()?->visibility)->toBe('private');

    $this->putJson("/api/v1/courses/{$course->uuid}", ['visibility' => 'public'])->assertOk();
    expect($course->fresh()?->visibility)->toBe('public');
});

it('refuses «hidden» and anything else — that value is the platform\'s', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['visibility' => 'hidden'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('visibility');

    expect($course->fresh()?->visibility)->toBe('public');
});

it('does not let a student member of the workspace change it', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->published()->create(['workspace_id' => $workspace->getKey()]);
    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);

    app()->forgetInstance(WorkspaceContext::class);
    Sanctum::actingAs($student);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['visibility' => 'private'])->assertForbidden();

    expect($course->fresh()?->visibility)->toBe('public');
});

it('leaves an existing course\'s visibility alone on an edit that does not name it', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $course = Course::factory()->create(['workspace_id' => $workspace->getKey(), 'visibility' => 'private']);

    Sanctum::actingAs($owner);

    $this->putJson("/api/v1/courses/{$course->uuid}", ['title' => 'عنوان جديد'])->assertOk();

    expect($course->fresh()?->visibility)->toBe('private');
});
