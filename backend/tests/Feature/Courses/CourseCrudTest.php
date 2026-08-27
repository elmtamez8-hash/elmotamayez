<?php

declare(strict_types=1);

use App\Modules\Courses\Models\Course;
use App\Modules\Marketplace\Models\Subject;
use Illuminate\Support\Str;
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

        $subject = Subject::factory()->create(['name_ar' => 'الرياضيات']);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/courses', [
            'title' => 'New Course',
            'description' => 'A test course',
            'price_minor' => 4999,
            'currency' => 'USD',
            'is_sequential' => true,
            'subject' => (string) $subject->uuid,
        ])->assertCreated()
            ->assertJsonPath('title', 'New Course')
            ->assertJsonPath('status', 'draft');

        $created = Course::where('title', 'New Course')->sole();

        // ⚠️ THE COLUMN, NOT THE RESPONSE. `subject_id` was fillable and unwritten
        // for a year — the payload echoing back what was submitted is exactly the
        // assertion that missed it on `student_profiles` in spec 013.
        expect((int) $created->subject_id)->toBe((int) $subject->getKey());
    });

    /*
    | ⚠️ AND A COURSE MAY NOT BE CREATED WITHOUT ONE. The requirement is the whole
    | point: every subject-shaped screen in the product — the marketplace facet,
    | the homework filter, the practice filter — reads a column that was NULL on
    | every row because nothing ever demanded it.
    */
    it('refuses a course with no subject', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/courses', [
            'title' => 'بلا مادّة',
            'price_minor' => 0,
            'currency' => 'QAR',
        ])->assertStatus(422)->assertJsonValidationErrors('subject');

        expect(Course::where('title', 'بلا مادّة')->exists())->toBeFalse();
    });

    it('refuses a subject uuid that names nothing', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/courses', [
            'title' => 'مادّة مجهولة',
            'price_minor' => 0,
            'currency' => 'QAR',
            'subject' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors('subject');
    });

    it('moves a course to another subject', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id]);
        $physics = Subject::factory()->create(['name_ar' => 'الفيزياء']);

        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/courses/'.$course->uuid, [
            'subject' => (string) $physics->uuid,
        ])->assertOk();

        expect((int) $course->fresh()->subject_id)->toBe((int) $physics->getKey());
    });

    it('updates a course', function (): void {
        [$workspace, $owner] = $this->createWorkspaceWithOwner();
        $course = Course::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Old']);

        Sanctum::actingAs($owner);

        $this->putJson("/api/v1/courses/{$course->uuid}", ['title' => 'Updated Title', 'price_minor' => 9999])
            ->assertOk()
            ->assertJsonPath('title', 'Updated Title')
            ->assertJsonPath('price_minor', 9999);
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
