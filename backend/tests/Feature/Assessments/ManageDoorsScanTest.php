<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Exam;
use App\Modules\Courses\Models\Course;
use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| Security scan 2026-10-10 — F9 · F10 · F15.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

it('refuses the grade segments to a student member of the workspace', function (): void {
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    Sanctum::actingAs($student);

    $this->getJson('/api/v1/manage/report-card-segments')->assertForbidden();
});

it('refuses publishing an exam by `status` to a role without exams.publish', function (): void {
    $assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $exam = Exam::factory()->create(['workspace_id' => $this->workspace->getKey(), 'status' => 'draft']);
    Sanctum::actingAs($assistant);

    $this->putJson("/api/v1/exams/{$exam->uuid}", ['status' => 'published'])->assertForbidden();
    $this->postJson('/api/v1/exams', ['title' => 'نهائي', 'status' => 'published'])->assertForbidden();

    expect($exam->fresh()->getRawOriginal('status'))->toBe('draft');
});

it('refuses a student member the exam of a course they are not enrolled in', function (): void {
    $bought = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $notBought = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);
    $this->createEnrollment($this->workspace, $bought, $student);

    $final = Exam::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $notBought->getKey(),
        'status' => 'published',
    ]);

    $this->setCurrentWorkspace($this->workspace, $student);
    Sanctum::actingAs($student);

    $this->postJson("/api/v1/exams/{$final->uuid}/attempts")->assertForbidden();
    expect(collect($this->getJson('/api/v1/exams')->json('data'))->pluck('uuid'))->not->toContain($final->uuid);
});
