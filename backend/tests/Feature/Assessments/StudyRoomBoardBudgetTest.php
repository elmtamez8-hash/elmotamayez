<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Assessments\Events\StudyRoomBoardUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/*
| Spec 012 · SC-006 — the board's cost must not grow with the room.
|
| ⚠️ TWO ASSERTIONS GUARDING TWO OPPOSITE MISTAKES, AND EITHER ALONE IS USELESS.
|
|  · Dropping the eager load is an N+1 — one `users` query per row, rebuilt on
|    EVERY answer, in a room of thirty people watching each other.
|  · Naming `name` in the eager load is worse and a query count cannot see it at
|    all: `users` has NO `name` column — it is an accessor over `first_name` and
|    `last_name` — so `->with('user:id,uuid,name')` selects a column that does not
|    exist, every row renders as an empty string, and the page is one query
|    CHEAPER. A budget test alone would report that regression as an improvement.
|
| That exact spelling has shipped six times in this tree across four modules, and
| the board is the worst place for it: it is pushed to everybody at once, so there
| is no screen on which one reader notices «» first and says so.
*/

/** Queries run while $work executes. */
function boardQueryCount(callable $work): int
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $work();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

it('reads the board at a flat cost and never with an empty name', function (): void {
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], [
        'question_count' => 2,
        'max_participants' => 30,
    ]);

    $join = function (User $student) use ($room): void {
        Sanctum::actingAs($student);
        test()->asGuest();

        test()->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();
    };

    $join($fx['peer']);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $small = boardQueryCount(function () use ($room): void {
        $this->getJson("/api/v1/study-rooms/{$room['uuid']}/board")->assertOk();
    });

    // Nine more people in the same room.
    foreach (range(1, 9) as $index) {
        $student = User::factory()->create(['first_name' => "طالب{$index}", 'last_name' => 'الاختبار']);
        enrolInCourse($fx['workspace'], $fx['course'], $student);
        $join($student);
    }

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $rows = [];

    $large = boardQueryCount(function () use ($room, &$rows): void {
        $rows = $this->getJson("/api/v1/study-rooms/{$room['uuid']}/board")
            ->assertOk()
            ->json('data.rows');
    });

    expect($rows)->toHaveCount(10);

    /*
    | ⚠️ THE DIFFERENCE, NOT A CEILING. A ceiling alone catches a slow endpoint and
    | misses an N+1 entirely — ten rows under a budget of twenty passes, and so
    | does a hundred under a hundred and ten a release later. What an N+1 cannot
    | survive is being asked with ten times the data and having to answer with the
    | same number of queries.
    */
    expect($large)->toBe($small);

    // ⚠️ AND THE NAMES ARE THERE. This is the assertion the budget cannot make.
    foreach ($rows as $row) {
        expect($row['name'])->not->toBe('')
            ->and(trim((string) $row['name']))->not->toBe('');
    }
});

it('sends only the four board fields and never a question', function (): void {
    /*
    | The board is where the licence to broadcast data stops. `uuid` here is the
    | PARTICIPANT row's — a user uuid is that person's identifier across the whole
    | platform, handed to peers who may be children — and no question text, option
    | or correctness ever rides on it.
    */
    $fx = studyRoomFixture(['easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 2]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $board = $this->getJson("/api/v1/study-rooms/{$room['uuid']}/board")->assertOk()->json('data');

    expect(array_keys($board))->toBe(['room_uuid', 'ends_at', 'rows'])
        ->and(array_keys($board['rows'][0]))->toBe(['uuid', 'name', 'score', 'answered'])
        // Not the user's uuid — the participant row's. A leak here would be a
        // platform-wide identifier for a child handed to their classmates.
        ->and($board['rows'][0]['uuid'])->not->toBe((string) $fx['peer']->uuid);
});

/*
| Spec 012 · SC-006 · T095 — the board is actually PUBLISHED, and it is throttled.
|
| ⚠️ WITHOUT THIS CASE THE WHOLE CHANNEL IS A GUARD WITH NO PUBLISHER. Nine test
| files can pass over a build that never dispatches anything: the component test
| mocks `listen`, and the channel test proves only that a subscription is
| authorised. That is the readers-and-no-writer shape this repository keeps paying
| for — `ClassSessionStatus::Interrupted` had three readers and nothing that could
| produce it.
|
| ⚠️ AND IT MEASURES BOTH HALVES OF THE THROTTLE IN ONE WALK. A naive gate drops
| the TRAILING update, and with no later answer to flush it every screen in the
| room shows a stale board for ever — the HTTP board is only the no-socket
| fallback. So: two answers inside one frozen second publish ONCE, and the answer
| that finishes the paper publishes whatever the gate says.
*/
it('publishes the board once a second and always on the answer that finishes', function (): void {
    $fx = studyRoomFixture(['easy', 'easy', 'easy']);
    $room = openStudyRoom($fx['student'], $fx['workspace'], ['question_count' => 3]);

    Sanctum::actingAs($fx['peer']);
    $this->asGuest();

    $this->postJson("/api/v1/study-rooms/{$room['uuid']}/join")->assertOk();

    $questions = $this->getJson("/api/v1/study-rooms/{$room['uuid']}")->json('data.questions');

    // Faked AFTER the fixture, so nothing the bank's own events raise is counted.
    Event::fake([StudyRoomBoardUpdated::class]);

    // ⚠️ THE CLOCK IS FROZEN, so the one-second gate is a fact rather than a race
    // against however long the three requests happen to take.
    Carbon::setTestNow(now());

    $answer = function (array $question) use ($room): void {
        $this->postJson("/api/v1/study-rooms/{$room['uuid']}/answer", [
            'question_id' => $question['question_id'],
            'option_ids' => [adaptiveRightOption($question['question_id'])],
        ])->assertOk();
    };

    $answer($questions[0]);
    // Same second: swallowed by the gate, which is what stops thirty people
    // answering thirty questions being nine hundred frames.
    $answer($questions[1]);
    // The last one COMPLETES the set, so it publishes unconditionally.
    $answer($questions[2]);

    Event::assertDispatchedTimes(StudyRoomBoardUpdated::class, 2);

    Event::assertDispatched(StudyRoomBoardUpdated::class, function (StudyRoomBoardUpdated $event) use ($room): bool {
        return $event->roomUuid === $room['uuid']
            // The channel is named once here and once in `routes/channels.php`.
            && $event->broadcastOn()[0]->name === "private-study-room-board.{$room['uuid']}"
            && $event->broadcastAs() === 'board.updated';
    });

    Carbon::setTestNow();
});
