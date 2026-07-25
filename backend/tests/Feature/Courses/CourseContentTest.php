<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Chapter;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Modules\Courses\Models\Section;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

describe('course sections', function (): void {
    it('lists sections of a course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'title' => 'S1', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/courses/{$course->uuid}/sections")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.title', 'S1');
    });

    it('creates a section', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$course->uuid}/sections", [
            'title' => 'New Section',
            'order' => 2,
        ])->assertCreated()->assertJsonPath('title', 'New Section');

        expect(Section::where('course_id', $course->id)->count())->toBe(1)
            ->and(Section::first()->workspace_id)->toBe($workspace->id);
    });

    it('updates a section', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'title' => 'Old', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$course->uuid}/sections/{$section->id}", ['title' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('title', 'Updated');
    });

    it('deletes a section', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'title' => 'S', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/courses/{$course->uuid}/sections/{$section->id}")->assertNoContent();

        expect(Section::where('id', $section->id)->exists())->toBeFalse();
    });

    it('denies section management to students', function (): void {
        [$workspace] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);

        $student = $this->addWorkspaceMember($workspace, 'student');
        Sanctum::actingAs($student);

        $this->postJson("/api/v1/courses/{$course->uuid}/sections", ['title' => 'X'])->assertForbidden();
    });
});

describe('course chapters', function (): void {
    it('creates a chapter under a section', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'title' => 'S', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$course->uuid}/chapters", [
            'section_id' => $section->id,
            'title' => 'Chapter 1',
        ])->assertCreated()->assertJsonPath('title', 'Chapter 1');

        expect(Chapter::where('course_id', $course->id)->count())->toBe(1);
    });

    it('updates and deletes a chapter', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'title' => 'S', 'order' => 1,
        ]);
        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'C', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->id}", ['title' => 'Updated'])
            ->assertOk()->assertJsonPath('title', 'Updated');

        $this->deleteJson("/api/v1/courses/{$course->uuid}/chapters/{$chapter->id}")->assertNoContent();
    });
});

describe('course lessons', function (): void {
    it('creates, updates, and deletes a lesson', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $course->id, 'title' => 'S', 'order' => 1,
        ]);
        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $course->id,
            'title' => 'C', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $resp = $this->postJson("/api/v1/courses/{$course->uuid}/lessons", [
            'section_id' => $section->id,
            'chapter_id' => $chapter->id,
            'title' => 'Lesson 1',
            'type' => 'article',
            'content' => 'Body',
            'order' => 1,
        ])->assertCreated()->assertJsonPath('title', 'Lesson 1');

        $lessonUuid = $resp->json('uuid');

        $this->putJson("/api/v1/courses/{$course->uuid}/lessons/{$lessonUuid}", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('title', 'Renamed');

        $this->deleteJson("/api/v1/courses/{$course->uuid}/lessons/{$lessonUuid}")->assertNoContent();

        expect(Lesson::where('course_id', $course->id)->count())->toBe(0);
    });
});

describe('cross-course ownership enforcement', function (): void {
    it('prevents updating a section that belongs to a different course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $courseA = Course::factory()->create(['workspace_id' => $workspace->id]);
        $courseB = Course::factory()->create(['workspace_id' => $workspace->id]);
        $sectionOfB = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $courseB->id, 'title' => 'B Section', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$courseA->uuid}/sections/{$sectionOfB->id}", ['title' => 'Hijacked'])
            ->assertNotFound();

        expect(Section::find($sectionOfB->id)->title)->toBe('B Section');
    });

    it('prevents deleting a lesson that belongs to a different course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $courseA = Course::factory()->create(['workspace_id' => $workspace->id]);
        $courseB = Course::factory()->create(['workspace_id' => $workspace->id]);
        $section = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $courseB->id, 'title' => 'S', 'order' => 1,
        ]);
        $chapter = Chapter::create([
            'workspace_id' => $workspace->id, 'section_id' => $section->id, 'course_id' => $courseB->id, 'title' => 'C', 'order' => 1,
        ]);
        $lesson = Lesson::create([
            'workspace_id' => $workspace->id, 'course_id' => $courseB->id, 'section_id' => $section->id, 'chapter_id' => $chapter->id,
            'uuid' => Str::uuid(), 'title' => 'L', 'type' => 'article', 'content' => 'x', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/courses/{$courseA->uuid}/lessons/{$lesson->uuid}")->assertNotFound();
        expect(Lesson::where('id', $lesson->id)->exists())->toBeTrue();
    });

    it('rejects creating a chapter with a section_id from a different course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $courseA = Course::factory()->create(['workspace_id' => $workspace->id]);
        $courseB = Course::factory()->create(['workspace_id' => $workspace->id]);
        $sectionOfB = Section::create([
            'workspace_id' => $workspace->id, 'course_id' => $courseB->id, 'title' => 'B Section', 'order' => 1,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/courses/{$courseA->uuid}/chapters", [
            'section_id' => $sectionOfB->id,
            'title' => 'Bad Chapter',
        ])->assertStatus(422);
    });
});
