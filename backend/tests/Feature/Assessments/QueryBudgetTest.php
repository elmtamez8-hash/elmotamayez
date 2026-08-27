<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\Roles;
use Laravel\Sanctum\Sanctum;

/*
| SC-011 · NFR-010. The three screens of spec 008 that grow without bound.
|
| ⚠️ THIS FILE ASSERTS FLATNESS, AND `EssayGradingTest` ASSERTS THE CONSTANT.
| Neither is enough alone and they are deliberately not merged. A cap of 15 on a
| five-row fixture passes at 15 queries for five rows and again at 15 for five
| hundred — it says the number is small, never that it is CONSTANT. Equality on
| one fixture size says nothing either: a per-row implementation measured twice
| at the same size agrees with itself perfectly. Two sizes and a cap, in two
| places, because they answer two questions.
|
| ⚠️ AND EVERY MEASUREMENT IS PRECEDED BY A WARM-UP REQUEST. spatie's permission
| cache is filled by the first authenticated request in the process, so an
| unwarmed first measurement carries the cache fill and the second does not — the
| bigger page then looks CHEAPER by a fixed handful of queries, and an N+1 five
| rows deep hides inside the difference. The warm-up is what makes the two
| numbers comparable rather than merely both small.
*/

/** Questions in the bank, all active, all in this workspace. */
function bankOf(object $workspace, int $count): void
{
    foreach (range(1, $count) as $index) {
        bankQuestion($workspace, null, ['content' => "سؤال البنك رقم {$index}؟"]);
    }
}

it('lists the bank at a fixed cost', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    bankOf($workspace, 3);

    Sanctum::actingAs($owner);

    // Warm-up. Everything after this measures the page and not the sign-in.
    $this->getJson('/api/v1/manage/bank/questions')->assertOk();

    [$small] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/bank/questions')->assertOk(),
    );

    bankOf($workspace, 17);

    [$large, $response] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/bank/questions')->assertOk(),
    );

    /*
    | `BankQuestionResource` reads the concept, the lesson and how many exams
    | include the question. All three asked per row is four queries times the
    | page — on the screen a teacher opens first, and the only one in this module
    | whose row count has no ceiling.
    */
    expect($large)->toBeLessThanOrEqual($small)
        ->and($response->json('meta.total'))->toBe(20);
});

it('serves the grading queue at a fixed cost', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    foreach (range(1, 2) as $ignored) {
        sitEssayExam($workspace, $this->addWorkspaceMember($workspace));
    }

    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/manage/grading/queue')->assertOk();

    [$small] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/grading/queue')->assertOk(),
    );

    foreach (range(1, 8) as $ignored) {
        $this->setCurrentWorkspace($workspace, $owner);
        sitEssayExam($workspace, $this->addWorkspaceMember($workspace));
    }

    Sanctum::actingAs($owner);

    [$large, $response] = countingQueries(
        fn () => $this->getJson('/api/v1/manage/grading/queue')->assertOk(),
    );

    // Five times the papers, and the exam title, the student's name and the
    // count of unmarked answers still cost what two papers cost.
    expect($large)->toBeLessThanOrEqual($small)
        ->and($response->json('meta.total'))->toBe(10);
});

it('serves the mistake notebook at a fixed cost', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $workspaceId = (int) $workspace->getKey();

    foreach (range(1, 3) as $index) {
        $question = bankQuestion($workspace, null, ['content' => "أخطأتُ في {$index}؟"]);
        answerRow($workspaceId, $student, (int) $question->getKey(), false);
    }

    Sanctum::actingAs($student);
    $this->getJson('/api/v1/mistakes')->assertOk();

    [$small] = countingQueries(fn () => $this->getJson('/api/v1/mistakes')->assertOk());

    $this->setCurrentWorkspace($workspace, $owner);

    foreach (range(4, 20) as $index) {
        $question = bankQuestion($workspace, null, ['content' => "أخطأتُ في {$index}؟"]);
        answerRow($workspaceId, $student, (int) $question->getKey(), false);
    }

    Sanctum::actingAs($student);

    [$large, $response] = countingQueries(fn () => $this->getJson('/api/v1/mistakes')->assertOk());

    /*
    | The notebook is one `GROUP BY` plus one hydration of the questions it
    | named. The shape that breaks it is loading each question inside the loop
    | that renders the rows — which reads as the obvious way to write it, and is
    | invisible on the three-row fixture every other test in this module uses.
    */
    expect($large)->toBeLessThanOrEqual($small)
        ->and($response->json('meta.total'))->toBe(20);
});

/*
| ⚠️ THE FILTER BAR IS FOUR GROUPED QUERIES OVER THE NOTEBOOK'S BASE, AND THE
| BASE GROWS WITH THE STUDENT'S WHOLE HISTORY.
|
| So the number that must not move is the query COUNT, not the row count — the
| shape that breaks it is resolving each facet's labels row by row, which reads
| as the obvious way to write it and is invisible on a three-mistake fixture.
|
| ⚠️ AND IT ASSERTS THE FACETS ARE POPULATED AS WELL AS FLAT. Dropping a join
| makes this endpoint CHEAPER and empty — the regression would read as an
| improvement to a test that counted queries alone, and the bar would quietly
| stop offering anything. Same pair of assertions the curriculum budget carries.
*/
it('builds the mistake filter bar at a fixed cost', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $student = $this->addWorkspaceMember($workspace, Roles::STUDENT);
    $workspaceId = (int) $workspace->getKey();

    $wrong = function (int $from, int $to) use ($workspace, $workspaceId, $student, $owner): void {
        $this->setCurrentWorkspace($workspace, $owner);

        foreach (range($from, $to) as $index) {
            $question = bankQuestion($workspace, null, ['content' => "أخطأتُ في {$index}؟"]);
            answerRow($workspaceId, $student, (int) $question->getKey(), false);
        }

        Sanctum::actingAs($student);
    };

    $wrong(1, 3);

    // Warmed: spatie's permission cache fills on the first authenticated
    // request, and an unwarmed measurement makes the bigger page look cheaper.
    $this->getJson('/api/v1/mistakes/filters')->assertOk();

    [$small] = countingQueries(fn () => $this->getJson('/api/v1/mistakes/filters')->assertOk());

    $wrong(4, 30);

    [$large, $response] = countingQueries(fn () => $this->getJson('/api/v1/mistakes/filters')->assertOk());

    expect($large)->toBeLessThanOrEqual($small)
        // The control: ten times the mistakes, and the bar is still built — an
        // empty answer would satisfy the count assertion above on its own.
        ->and($response->json('teachers'))->not->toBeEmpty()
        ->and($response->json('concepts'))->not->toBeEmpty();
});
