<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Actions\GradeAttempt;
use App\Modules\Assessments\Actions\StartAttempt;
use App\Modules\Assessments\Models\Exam;
use App\Modules\Assessments\Models\ExamItem;
use App\Modules\Courses\Models\Course;
use App\Modules\Courses\Models\Lesson;
use App\Shared\Support\WorkspaceContext;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · SC-008 · FR-017 — who may not come in.
|
| ⚠️ THREE CASES, AND THE SECOND AND THIRD ARE THE ONES THAT CATCH ANYTHING. A
| test that only refuses a student from ANOTHER TEACHER passes over both real
| defects, because «an active enrolment in this workspace» is true for both of the
| people below:
|
|  2. `PracticePool::questionsFor()` is bounded by the student's own COURSES, not
|     by the workspace — so a student of «العربي» would join a room built from the
|     «الفيزياء» bank at the same teacher.
|  3. `withheldQuestionIds()` is computed PER STUDENT. A host who has already sat a
|     published exam freezes its questions legitimately, and every joiner who has
|     NOT sat it answers them and is handed the correct option ids and the
|     explanation on the spot — next week's paper with its answers, which is the
|     one thing `PracticePool` exists to prevent.
|
| The refusal never names the exam or the course: FR-017 is about ENTITLEMENT, and
| a refusal that explains itself is an oracle over another student's bank.
*/

it('refuses a student who studies with a different teacher', function (): void {
    $fx = studyRoomFixture();
    $room = openStudyRoom($fx['student'], $fx['workspace']);

    // Their own teacher, their own courses — and none of this room's questions.
    $stranger = studyRoomFixture();

    Sanctum::actingAs($stranger['student']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_eligible');
});

it('refuses a student enrolled in a DIFFERENT course at the same teacher', function (): void {
    $fx = studyRoomFixture();
    $room = openStudyRoom($fx['student'], $fx['workspace']);

    $other = User::factory()->create();

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx, $other): void {
        $second = Course::factory()->published()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'created_by' => $fx['owner']->getKey(),
            'title' => 'العربي',
        ]);

        Lesson::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'course_id' => $second->getKey(),
        ]);

        enrolInCourse($fx['workspace'], $second, $other);
    });

    Sanctum::actingAs($other);
    $this->asGuest();

    /*
    | ⚠️ THIS IS THE CASE «AN ACTIVE ENROLMENT IN THE WORKSPACE» LETS THROUGH. The
    | student is enrolled, at this teacher, right now — and none of the room's
    | questions is in a course of theirs.
    */
    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_eligible');
});

it('refuses a joiner who has not sat the published exam the host already has', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);

    app(WorkspaceContext::class)->forWorkspace($fx['workspace'], function () use ($fx): void {
        $exam = Exam::factory()->create([
            'workspace_id' => $fx['workspace']->getKey(),
            'status' => 'published',
            'max_attempts' => 3,
        ]);

        foreach ($fx['questions']->values() as $index => $question) {
            ExamItem::create([
                'workspace_id' => $fx['workspace']->getKey(),
                'exam_id' => $exam->getKey(),
                'question_id' => $question->getKey(),
                'order' => $index + 1,
            ]);
        }

        // The HOST has sat it, so those questions are back in THEIR pool and the
        // room they build from it is entirely legitimate.
        $attempt = app(StartAttempt::class)->handle($exam, $fx['student']);

        app(GradeAttempt::class)->handle($attempt, $fx['questions']->map(fn ($question): array => [
            'question_id' => (int) $question->getKey(),
            'selected_option_ids' => [adaptiveWrongOption((int) $question->getKey())],
        ])->all());
    });

    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 2]);

    expect($room['question_count'])->toBe(2);

    /*
    | The peer has not sat it. Every question in this room is withheld from THEM,
    | and answering one would hand over `correct_option_ids` and the explanation
    | a week before the paper.
    */
    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")
        ->assertForbidden()
        ->assertJsonPath('code', 'not_eligible');
});

it('admits a peer enrolled in the same course', function (): void {
    /*
    | ⚠️ THE POSITIVE CONTROL, AND WITHOUT IT THE THREE REFUSALS ABOVE ARE ALSO
    | SATISFIED BY A BUILD THAT REFUSES EVERYBODY. Three passing denials and a
    | broken door are indistinguishable from three passing denials and a working
    | one.
    */
    $fx = studyRoomFixture();
    $room = openStudyRoom($fx['student'], $fx['workspace']);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")
        ->assertOk()
        ->assertJsonPath('resumed', false);
});
