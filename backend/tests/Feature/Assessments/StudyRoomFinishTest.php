<?php

declare(strict_types=1);

use App\Modules\Assessments\Models\StudyRoomParticipant;
use App\Modules\Gamification\Models\AwardEntry;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · FR-018 — finishing the set is an event; running out of time is not.
|
| ⚠️ NO `Queue::fake()` HERE AT ALL, AND THAT IS THE POINT RATHER THAN AN
| OVERSIGHT. `AwardOnStudyRoomFinished` is a QUEUED listener, so a bare
| `Queue::fake()` swallows it and «finishing the set awards points» becomes a
| confident assertion about an empty `award_entries`. There is no timeline job
| behind a study room to fake either — closure is derived from the clock — so the
| honest fixture is the `sync` connection and nothing faked.
|
| ⚠️ AND THE SECOND CASE IS THE ONE THAT CATCHES THE OBVIOUS IMPLEMENTATION.
| Hanging the award on `ends_at` passing makes `study_room_finished` a catalogue
| key with readers and no writer — the `ClassSessionStatus::Interrupted` shape —
| so a room that merely ended must award nothing while still showing its score.
*/

function studyRoomAwards(int $studentId): int
{
    return AwardEntry::query()
        ->withoutGlobalScopes()
        ->where('student_user_id', $studentId)
        ->where('action_key', 'study_room_finished')
        ->count();
}

it('stamps finished_at and awards once when the last question is answered', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 2]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $questions = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data.questions');

    foreach ($questions as $index => $question) {
        $body = $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveRightOption($question['question_id'])],
        ])->assertOk()->json('data');

        // ⚠️ Only the LAST one finishes. An implementation that stamped on every
        // answer would award once per question and pass a test that only looked
        // at the end.
        expect($body['finished'])->toBe($index === count($questions) - 1);
    }

    $participant = StudyRoomParticipant::query()
        ->withoutWorkspaceScope()
        ->where('user_id', $fx['peer']->getKey())
        ->firstOrFail();

    expect($participant->finished_at)->not->toBeNull()
        ->and($participant->answered_count)->toBe(2)
        ->and($participant->score)->toBe(2)
        ->and(studyRoomAwards((int) $fx['peer']->getKey()))->toBe(1);
});

it('shows the score but awards nothing when the clock runs out mid-paper', function (): void {
    $fx = studyRoomFixture(['easy', 'easy', 'easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 4,
        'duration_minutes' => 5,
    ]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $questions = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data.questions');

    // Two of four, then the hour passes. Nobody is «finished».
    foreach (array_slice($questions, 0, 2) as $question) {
        $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveRightOption($question['question_id'])],
        ])->assertOk();
    }

    // ⚠️ NO WORKER RUNS, AND NOTHING IS DISPATCHED. Closure is `now() >= ends_at`
    // and nothing else, which is why moving the clock is the whole of it.
    Carbon::setTestNow(now()->addMinutes(10));

    $view = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->assertOk()->json('data');

    expect($view['room']['state'])->toBe('closed')
        // The result is still shown — FR-016 says the room ENDS by displaying it.
        ->and($view['room']['score'])->toBe(2)
        ->and($view['room']['answered'])->toBe(2)
        ->and($view['room']['finished_at'])->toBeNull()
        ->and(studyRoomAwards((int) $fx['peer']->getKey()))->toBe(0);

    // And a late answer is refused rather than quietly finishing the paper.
    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
        'question_id' => $questions[2]['question_id'],
        'option_ids' => [adaptiveRightOption($questions[2]['question_id'])],
    ])->assertStatus(409)->assertJsonPath('code', 'room_closed');

    Carbon::setTestNow();
});

it('caps the award at two rooms a day', function (): void {
    /*
    | ⚠️ THE CAP IS THE REASON COINS ARE SAFE HERE. Fifteen minutes with friends
    | is worth points; an uncapped one is a student opening rooms for coins rather
    | than for the practice. Three finished rooms, two awards.
    */
    $fx = studyRoomFixture(['easy', 'easy']);

    foreach (range(1, 3) as $ignored) {
        $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 2]);

        Sanctum::actingAs($fx['peer']);
        $this->asGuest();

        $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

        foreach ($this->getJson("/api/v1/study-rooms/{$room['uuid']}")->json('data.questions') as $question) {
            $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
                'question_id' => $question['question_id'],
                'option_ids' => [adaptiveRightOption($question['question_id'])],
            ])->assertOk();
        }
    }

    expect(studyRoomAwards((int) $fx['peer']->getKey()))->toBe(2);
});
