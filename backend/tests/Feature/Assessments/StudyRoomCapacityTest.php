<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Models\Attempt;
use App\Modules\Assessments\Models\StudyRoomParticipant;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · FR-019 and the ceiling beside it.
|
| ⚠️ `max_participants` IS ENFORCED IN THE ACTION, NOT DECORATION ON A FORM. The
| invitation IS the uuid and it forwards freely, so without a ceiling the board is
| N rows broadcast to N subscribers for each of N×M answers.
|
| ⚠️ AND THE TWO LIMITERS ARE MEASURED AS TWO. A named limiter is one bucket per
| user, so reusing one for creating rooms and for answering inside them would cut
| a student off in the middle of a twenty-question paper. The last case below
| exhausts `study-room-write` and then proves `adaptive-step` is untouched —
| which is the whole reason there are two names.
*/

it('refuses the joiner past the ceiling and keeps the ones inside', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 2,
        'max_participants' => 2,
    ]);

    $students = collect(range(1, 3))->map(function () use ($fx): User {
        $student = User::factory()->create();
        enrolInCourse($fx['workspace'], $fx['course'], $student);

        return $student;
    });

    foreach ($students->take(2) as $student) {
        Sanctum::actingAs($student);
        $this->asGuest();

        $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();
    }

    Sanctum::actingAs($students->last());
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")
        ->assertStatus(409)
        ->assertJsonPath('code', 'room_full');

    /*
    | ⚠️ THE REFUSED JOINER LEAVES NOTHING BEHIND. The claim comes before the N
    | items and everything is one transaction — written the other way round, a
    | loser strands an orphan attempt and one item per question with no
    | participant, no sweep and nothing that would notice.
    */
    expect(StudyRoomParticipant::query()->withoutWorkspaceScope()->count())->toBe(2);

    $orphans = Attempt::query()
        ->withoutWorkspaceScope()
        ->where('student_user_id', $students->last()->getKey())
        ->count();

    expect($orphans)->toBe(0);
});

it('lets somebody already inside carry on after the room fills', function (): void {
    // A ceiling is about who may ARRIVE. A build that read it on every request
    // would evict the person sitting in the room when the last seat went.
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 2,
        'max_participants' => 1,
    ]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $question = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->json('data.questions.0.question_id');

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
        'question_id' => $question,
        'option_ids' => [adaptiveRightOption($question)],
    ])->assertOk();

    // And their own re-join is a resume, not an arrival at a full room.
    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")
        ->assertOk()
        ->assertJsonPath('resumed', true);
});

it('rate-limits room creation without touching the adaptive budget', function (): void {
    $fx = studyRoomFixture(['easy', 'easy', 'easy']);

    Sanctum::actingAs($fx['student']);
    $this->asGuest();

    $draft = [
        'teacher' => $fx['workspace']->uuid,
        'question_count' => 2,
        'max_participants' => 5,
        'duration_minutes' => 10,
        'starts_in_minutes' => 0,
    ];

    // `study-room-write` is ten a minute, keyed by user.
    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/v1/study-rooms', $draft)->assertCreated();
    }

    $this->postJson('/api/v1/study-rooms', $draft)->assertStatus(429);

    /*
    | ⚠️ AND THE ADAPTIVE PATH IS UNTOUCHED, WHICH IS THE POINT OF TWO NAMES. One
    | shared limiter here would mean a student who opened ten rooms could not then
    | answer a question in any of them — the shared-counter defect that made
    | inline `throttle:5,1` illegal in this codebase, wearing a name.
    */
    $this->postJson('/api/v1/practice/adaptive', [
        'concept' => $fx['concept']->uuid,
        'teacher' => $fx['workspace']->uuid,
    ])->assertCreated();
});
