<?php

declare(strict_types=1);

use App\Modules\Learning\Actions\JoinCohort;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Support\LessonAccess;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CurriculumFixtures;

uses(CurriculumFixtures::class);

/*
| SC-009أ — ⚠️ THE VALVE (FR-028ب).
|
| Membership is a condition on opening content the student has ALREADY PAID FOR.
| It may therefore only hold while there is something they can do about it: the
| moment the last joinable group fills up, closes or is archived, the whole
| curriculum opens.
|
| A condition no action of the student's can satisfy is a PERMANENT LOCK on paid
| content — the family of the worst defect this repository records, the item that
| enters the denominator and can never be completed, capping every enrolled
| student below 100% for ever so no certificate ever issues.
|
| ⚠️ HOW TO CHECK THIS TEST IS NOT MEASURING SOMETHING ELSE: delete the
| `joinableCohortsExist()` branch from `CohortGate::locks()` and re-run. The
| second case must FAIL. If it still passes, the fixture is not producing a
| gated course at all and every assertion here is vacuous — which is exactly how
| US6 shipped nine green tests of a check somebody had removed.
*/

/** Every lock code the curriculum payload carries, in order. */
function lockCodes(array $payload): array
{
    $codes = [];

    foreach ($payload['sections'] as $section) {
        foreach ($section['chapters'] as $chapter) {
            foreach ($chapter['lessons'] as $lesson) {
                if ($lesson['lock'] !== null) {
                    $codes[] = $lesson['lock']['code'];
                }
            }
        }
    }

    return $codes;
}

function addCohort(array $tree, array $attributes = []): Cohort
{
    return Cohort::factory()->create([
        'workspace_id' => $tree['workspace']->getKey(),
        'course_id' => $tree['course']->getKey(),
        'created_by' => $tree['owner']->getKey(),
        ...$attributes,
    ]);
}

it('shuts the whole tree while there is a group the student could join', function (): void {
    $tree = $this->curriculumTree();
    addCohort($tree, ['name' => 'السبت ٤م']);

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    $payload = $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertOk()->json();

    expect($payload['cohort_gate'])->toMatchArray([
        'required' => true,
        'satisfied' => false,
        'joinable_exists' => true,
    ]);

    expect($payload['cohort_gate']['message'])->not->toBeNull();

    // Every item bar the preview — a free sample is what a course shows before
    // anybody has committed to anything, so it stays open above the gate.
    expect(lockCodes($payload))->each->toBe(LessonAccess::NO_COHORT);
});

it('opens the whole tree the moment nothing is joinable, and says why', function (): void {
    $tree = $this->curriculumTree();

    // Every road to "you cannot join anything", one group each: full, closed to
    // new joins, and over.
    addCohort($tree, ['name' => 'ممتلئة', 'capacity' => 1, 'members_count' => 1]);
    addCohort($tree, ['name' => 'مغلقة', 'status' => Cohort::CLOSED]);
    addCohort($tree, ['name' => 'مؤرشفة', 'status' => Cohort::ARCHIVED, 'archived_at' => now()]);

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    $payload = $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertOk()->json();

    expect($payload['cohort_gate'])->toMatchArray([
        // ⚠️ STILL `required`. The course DOES run in groups — hiding that would
        // leave the student unable to make sense of a picker their classmates
        // used. What changed is that there is nothing to pick.
        'required' => true,
        'satisfied' => false,
        'joinable_exists' => false,
    ]);

    // ⚠️ AND THE SENTENCE NAMES WHO TO ASK. An open course under «لا توجد
    // مجموعة» with nothing after it reads as a fault rather than as a state.
    expect($payload['cohort_gate']['message'])->toContain('مدرّسك');

    expect(lockCodes($payload))->not->toContain(LessonAccess::NO_COHORT);

    // The control: the course's own gates are untouched. A valve that opened
    // everything — sequence, exam, seat — would pass the assertion above while
    // handing out the whole course.
    expect(lockCodes($payload))->toContain(LessonAccess::SEQUENCE);
});

it('leaves a course with no groups at all exactly as it was (FR-036)', function (): void {
    $tree = $this->curriculumTree();

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    $payload = $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertOk()->json();

    expect($payload['cohort_gate'])->toMatchArray([
        'required' => false,
        'satisfied' => true,
        'joinable_exists' => false,
        'message' => null,
    ]);

    expect(lockCodes($payload))->not->toContain(LessonAccess::NO_COHORT);
});

it('lifts the gate for the student the instant they join', function (): void {
    $tree = $this->curriculumTree();
    $cohort = addCohort($tree, ['name' => 'السبت ٤م']);

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    app(JoinCohort::class)->handle($cohort, $tree['student']);

    $payload = $this->getJson('/api/v1/courses/'.$tree['course']->uuid.'/curriculum')->assertOk()->json();

    expect($payload['cohort_gate']['satisfied'])->toBeTrue();
    expect(lockCodes($payload))->not->toContain(LessonAccess::NO_COHORT);
});

/*
| ⚠️ AND THE DOOR AGREES WITH THE SCREEN. `LessonGate::forTree()` builds the
| payload above and `LessonGate::for()` guards `/learn/lessons/{uuid}` — the only
| surface in the product that plays a lesson. Two spellings of one question is
| the defect that let `IssuePlaybackGrant` allow a recording `accessTo()` refused.
*/
it('refuses the lesson itself with the same reason the tree gave', function (): void {
    $tree = $this->curriculumTree();
    addCohort($tree, ['name' => 'السبت ٤م']);

    Sanctum::actingAs($tree['student']);
    $this->forgetWorkspace();

    // ⚠️ A `200` CARRYING A REFUSAL, not a `403` — that is this endpoint's
    // shape: the student gets the item's identity and the REASON, with the body
    // withheld. Asserting on the status alone would have passed against a build
    // that served the whole lesson.
    $this->getJson('/api/v1/learn/lessons/'.$tree['lessons']['open']->uuid)
        ->assertOk()
        ->assertJsonPath('can_access', false)
        ->assertJsonPath('blocked_reason', LessonAccess::NO_COHORT)
        ->assertJsonPath('lesson.content', null);
});
