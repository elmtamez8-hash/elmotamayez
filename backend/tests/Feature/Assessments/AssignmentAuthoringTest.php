<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Assignment;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Models\ClassSession;
use Laravel\Sanctum\Sanctum;

/*
| The teacher's authoring path for homework (FR-043), from the screen that now
| calls it.
|
| ⚠️ THE COURSE TRAVELS AS A UUID. The route used to take `course_id` — a row id
| no payload in the product exposes — so the one screen that could ever call it
| had nothing to send, and `/manage/assignments` told teachers to create homework
| from a page that did not exist.
*/

it('creates a draft against a course named by its uuid', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

    $response = $this->postJson('/api/v1/manage/assignments', [
        'title' => 'واجب الفصل الأول',
        'course_uuid' => $course->uuid,
        'submission_type' => 'file',
        'late_policy' => 'penalty',
        'late_penalty_pct_per_day' => 10,
        'late_penalty_cap_pct' => 50,
    ])->assertCreated();

    $assignment = Assignment::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

    expect($assignment->course_id)->toBe($course->getKey())
        ->and($assignment->status)->toBe(Assignment::STATUS_DRAFT)
        ->and($response->json('data.course.uuid'))->toBe($course->uuid);
});

it('refuses another workspace\'s course under the field it belongs to', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    [$other] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $foreign = Course::factory()->create(['workspace_id' => $other->getKey()]);

    $this->postJson('/api/v1/manage/assignments', [
        'title' => 'واجب',
        'course_uuid' => $foreign->uuid,
    ])->assertStatus(422)->assertJsonValidationErrors('course_uuid');

    expect(Assignment::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('keeps the session link an edit from the form does not carry', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $session = ClassSession::factory()->create(['workspace_id' => $workspace->getKey()]);
    $assignment = Assignment::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'class_session_id' => $session->getKey(),
        'created_by' => $owner->getKey(),
        'status' => Assignment::STATUS_DRAFT,
        'published_at' => null,
    ]);

    $this->patchJson('/api/v1/manage/assignments/'.$assignment->uuid, [
        'title' => 'عنوان جديد',
        'course_uuid' => null,
    ])->assertOk();

    $assignment->refresh();

    expect($assignment->title)->toBe('عنوان جديد')
        ->and($assignment->class_session_id)->toBe($session->getKey());
});

it('refuses to publish without a deadline and says why', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);
    Sanctum::actingAs($owner);

    $uuid = $this->postJson('/api/v1/manage/assignments', ['title' => 'بلا موعد'])
        ->assertCreated()
        ->json('data.uuid');

    $this->postJson('/api/v1/manage/assignments/'.$uuid.'/publish')
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'بلا موعد'));
});
