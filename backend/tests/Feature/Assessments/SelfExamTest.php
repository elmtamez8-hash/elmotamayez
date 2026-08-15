<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\Concept;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use Laravel\Sanctum\Sanctum;

/*
| SC-009 · FR-021 · FR-022 · FR-023. The student sets themselves a paper.
*/

it('builds a paper matching every criterion the student chose', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);

    foreach (range(1, 6) as $index) {
        practiceQuestion($workspace, "سهل {$index}؟", [
            'lesson_id' => $lesson->getKey(),
            'difficulty' => 'easy',
            'concept_name' => 'المشتقّات',
        ]);
    }

    // Same lesson, wrong difficulty and wrong concept — the two the filter is for.
    practiceQuestion($workspace, 'صعب؟', ['lesson_id' => $lesson->getKey(), 'difficulty' => 'hard', 'concept_name' => 'المشتقّات']);
    practiceQuestion($workspace, 'فكرةٌ أخرى؟', ['lesson_id' => $lesson->getKey(), 'difficulty' => 'easy', 'concept_name' => 'التكامل']);

    Sanctum::actingAs($student);

    $concept = Concept::query()
        ->where('workspace_id', $workspace->getKey())->where('name', 'المشتقّات')->sole();

    $response = $this->postJson('/api/v1/practice/exams', [
        'count' => 4,
        'duration_minutes' => 20,
        'difficulty' => 'easy',
        'concept_id' => $concept->uuid,
    ]);

    $response->assertCreated();

    expect($response->json('data.questions'))->toHaveCount(4)
        ->and($response->json('data.duration_minutes'))->toBe(20);

    // 100% match, not "mostly": the paper is what they asked for or it is a
    // different paper wearing their criteria.
    foreach ($response->json('data.questions') as $question) {
        expect($question['content'])->toStartWith('سهل');
    }
});

it('builds with what is there and says how short it fell', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);

    practiceQuestion($workspace, 'الوحيد؟', ['lesson_id' => $lesson->getKey()]);

    Sanctum::actingAs($student);

    $response = $this->postJson('/api/v1/practice/exams', ['count' => 10]);

    $response->assertCreated();

    // FR-023. Both numbers travel: a paper of one against a request of ten is a
    // correct answer, and unsayable if only the delivered count is sent.
    expect($response->json('data.requested_count'))->toBe(10)
        ->and($response->json('data.delivered_count'))->toBe(1)
        ->and($response->json('data.questions'))->toHaveCount(1);
});

it('draws on an active enrolment and not on workspace membership', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    // A member with no enrolment at all — the shape of somebody whose term
    // ended. The membership row survives, because it is how they still read
    // their certificates and their old orders.
    $student = $this->addWorkspaceMember($workspace);

    $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);
    $lesson = Lesson::factory()->create(['workspace_id' => $workspace->getKey(), 'course_id' => $course->getKey()]);

    practiceQuestion($workspace, 'سؤالٌ لا يخوّلني إياه شيء؟', ['lesson_id' => $lesson->getKey()]);

    Sanctum::actingAs($student);

    $this->postJson('/api/v1/practice/exams', ['count' => 5])->assertStatus(422);
});

it('marks the paper on submission and hands back the explanations', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);
    $lesson = enrolledLesson($workspace, $student);

    practiceQuestion($workspace, 'ما ناتج واحدٍ زائد واحد؟', [
        'lesson_id' => $lesson->getKey(),
        'explanation' => 'لأن الجمع يزيد المقدار.',
    ]);

    Sanctum::actingAs($student);

    $built = $this->postJson('/api/v1/practice/exams', ['count' => 1])->json('data');
    $questionId = $built['questions'][0]['id'];
    $wrongOption = collect($built['questions'][0]['options'])->firstWhere('content', 'خطأ')['id'];

    $this->postJson("/api/v1/attempts/{$built['uuid']}/submit", [
        'answers' => [['question_id' => $questionId, 'selected_option_ids' => [$wrongOption]]],
    ])->assertOk();

    $result = $this->getJson("/api/v1/practice/attempts/{$built['uuid']}/result");

    $result->assertOk();

    // FR-024. A percentage with no explanation teaches the score and nothing
    // else — the explanation is why the paper was worth sitting.
    expect($result->json('data.questions.0.is_correct'))->toBeFalse()
        ->and($result->json('data.questions.0.correct_answer'))->toBe(['صح'])
        ->and($result->json('data.questions.0.explanation'))->toBe('لأن الجمع يزيد المقدار.');
});

it('refuses to hand the answer key back for a real exam', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace);

    $attempt = Attempt::create([
        'workspace_id' => $workspace->getKey(),
        'exam_id' => null,
        'student_user_id' => $student->getKey(),
        'status' => Attempt::STATUS_GRADED,
        'is_practice' => false,
        'score' => 0,
        'max_score' => 100,
        'passed' => false,
        'random_seed' => 1,
        'started_at' => now(),
        'submitted_at' => now(),
    ]);

    Sanctum::actingAs($student);

    // Their own attempt, and still refused: the payload carries the correct
    // answer of every question, and on a teacher's paper that is the key to
    // something the rest of the class may not have sat.
    $this->getJson("/api/v1/practice/attempts/{$attempt->uuid}/result")->assertNotFound();
});
