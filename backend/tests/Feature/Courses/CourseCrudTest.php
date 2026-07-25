<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use Laravel\Sanctum\Sanctum;

describe('course CRUD', function (): void {
    it('lists courses', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        Course::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Test Course']);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonPath('0.title', 'Test Course');
    });

    it('shows a single course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/courses/{$course->uuid}")
            ->assertOk()
            ->assertJsonPath('uuid', $course->uuid);
    });

    it('creates a course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/courses', [
            'title' => 'New Course',
            'description' => 'A test course',
            'price' => 49.99,
            'currency' => 'USD',
            'is_sequential' => true,
        ])->assertCreated()
            ->assertJsonPath('title', 'New Course')
            ->assertJsonPath('status', 'draft');

        expect(Course::where('title', 'New Course')->exists())->toBeTrue();
    });

    it('updates a course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Old']);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$course->uuid}", ['title' => 'Updated Title', 'price' => 99.99])
            ->assertOk()
            ->assertJsonPath('title', 'Updated Title')
            ->assertJsonPath('price', 99.99);
    });

    it('publishes a course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id, 'status' => 'draft']);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$course->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('is_published', true);
    });

    it('deletes a course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/courses/{$course->uuid}")->assertNoContent();
        expect(Course::where('id', $course->id)->exists())->toBeFalse();
    });

    it('denies course creation to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->postJson('/api/v1/courses', ['title' => 'X'])->assertForbidden();
    });

    it('denies course deletion to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->deleteJson("/api/v1/courses/{$course->uuid}")->assertForbidden();
        expect(Course::where('id', $course->id)->exists())->toBeTrue();
    });
});
