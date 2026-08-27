<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| SC-004. The course page is the one screen in this phase whose row count has no
| ceiling — a real course is two hundred items, and the obvious implementation
| asks the gate once per row.
|
| ⚠️ TWO SIZES, NEVER ONE. A cap of 20 on a ten-row fixture passes at 20 for ten
| rows and again at 20 for two hundred: it says the number is small, never that
| it is CONSTANT. And equality at one size says nothing either — a per-row
| implementation measured twice at the same size agrees with itself perfectly.
|
| ⚠️ AND EVERY MEASUREMENT IS WARMED FIRST. spatie's permission cache is filled by
| the first authenticated request in the process, so an unwarmed measurement
| carries the cache fill and a warmed one does not — the bigger page then looks
| CHEAPER by a fixed handful of queries, and an N+1 five rows deep hides inside
| the difference.
|
| ⚠️ AND THE COUNT IS NOT THE WHOLE TEST. Dropping an eager load beside a
| `whenLoaded` produces NO N+1 at all: the key simply goes missing, the page gets
| one query cheaper, and a budget measuring cost alone reports the regression as
| an improvement — then the tree renders with no state on any row. So the fields
| are asserted present in the same breath. That is the pair of opposite mistakes,
| and one assertion cannot guard both.
*/

/** @return array{0: int, 1: array<string, mixed>} */
function measureCurriculum(object $test, array $fixture): array
{
    Sanctum::actingAs($fixture['student']);
    $test->forgetWorkspace();

    $url = '/api/v1/courses/'.$fixture['course']->uuid.'/curriculum';

    // Warm-up. Everything after this measures the page, not the sign-in.
    $test->getJson($url)->assertOk();

    [$count, $response] = countingQueries(fn () => $test->getJson($url)->assertOk());

    return [$count, $response->json()];
}

it('answers a ten-lesson course and a two-hundred-lesson course in the same number of queries', function (): void {
    [$small, $smallPayload] = measureCurriculum($this, $this->curriculumOfSize(10));
    [$large, $largePayload] = measureCurriculum($this, $this->curriculumOfSize(200));

    $rowsIn = function (array $payload): array {
        $rows = [];

        foreach ($payload['sections'] as $section) {
            foreach ($section['chapters'] as $chapter) {
                $rows = [...$rows, ...$chapter['lessons']];
            }
        }

        return $rows;
    };

    // The control: the big course really is twenty times the small one, so the
    // equality below is about the implementation and not about two empty trees.
    expect($rowsIn($smallPayload))->toHaveCount(10)
        ->and($rowsIn($largePayload))->toHaveCount(200);

    expect($large)->toBe(
        $small,
        "curriculum cost {$small} queries for 10 lessons and {$large} for 200 — the gate is being asked per row",
    );
});

it('carries the state, the lock and the cover — the fields a cheaper page would be missing', function (): void {
    [, $payload] = measureCurriculum($this, $this->curriculumOfSize(200));

    expect($payload['course'])->toHaveKeys([
        'cover_url', 'teacher_name', 'progress_pct', 'completed_count', 'countable_count', 'resume_lesson_uuid',
    ]);

    $states = [];

    foreach ($payload['sections'] as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                expect($lesson)->toHaveKeys(['uuid', 'title', 'type', 'family', 'state', 'lock', 'duration_seconds']);

                $states[$lesson['state']] = true;

                if ($lesson['state'] === 'locked') {
                    // A locked row with a null lock is the payload equivalent of
                    // «مقفول» with nothing after it.
                    expect($lesson['lock']['code'] ?? null)->not->toBeNull()
                        ->and($lesson['lock']['message'] ?? null)->not->toBeEmpty();
                }
            }
        }
    }

    // A sequential 200-lesson course the student has not started: one open item
    // and a long tail of locked ones. Both states present means the walk really
    // ran rather than defaulting every row to the same answer.
    expect($states)->toHaveKey('open')
        ->and($states)->toHaveKey('locked');
});
