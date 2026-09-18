<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · US1 — a group nobody can be sold a place in is not offered, and the
| REFUSAL is at the door rather than on the screen.
|
| ⚠️ TWO GROUPS IN ONE COURSE, AND THAT IS THE WHOLE FIXTURE. «The picker shows
| one» is a claim about a filter; with a single group in the course it is equally
| true of a build that shows everything and of one that shows nothing. The count
| here is one out of two.
|
| ⛔ AND EVERY REFUSAL IS MEASURED AT THE ROUTE, WITH ITS TWIN THAT ACCEPTS.
| Dropping a card from a list is a courtesy — a uuid typed straight at
| `POST /cohorts/{uuid}/join` is what the gate is for. And a build that refuses
| EVERY join passes the refusal case perfectly, which is why the same student
| joins the priced group in the same test.
|
| ⚠️ THE REFUSAL IS ASSERTED BY ITS CODE, never by its Arabic sentence: prose
| that a test pins can never be improved.
*/
beforeEach(function (): void {
    // `marketplaceWorkspace()`: the public read below answers 404 for a
    // workspace that never opted in, which reads as «the group was filtered»
    // rather than as «the fixture is wrong».
    $this->workspace = marketplaceWorkspace();
    $this->teacher = marketplaceTeacher($this->workspace);

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->user_id,
            'course_type' => Course::TYPE_GROUP,
        ]),
    );

    $make = fn (string $name): Cohort => Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->user_id,
        'name' => $name,
    ]);

    $this->priced = $make('مجموعة السبت');
    $this->unpriced = $make('مجموعة الأحد');

    /*
    | ⛔ THE PLAN NAMES ONE GROUP, AND THAT IS WHY THE OTHER IS UNLISTED. A plan
    | covering the course or the workspace would reach BOTH — the overrule rule
    | only stops a group inheriting when it has a plan of its OWN. Naming the
    | group is the shape that leaves its sibling with nothing at all.
    */
    Plan::factory()->forCohort((string) $this->priced->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->student = User::factory()->create(['last_workspace_id' => null]);

    Enrollment::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'student_user_id' => $this->student->getKey(),
        'source' => 'manual',
        'status' => 'active',
        'progress_pct' => 0,
        'enrolled_at' => now(),
    ]);
});

/** @return list<string> the names the student's picker offers */
function pickerNames(): array
{
    return array_column(
        test()->actingAs(test()->student, 'sanctum')
            ->getJson('/api/v1/courses/'.test()->course->uuid.'/cohorts')
            ->assertOk()
            ->json('cohorts'),
        'name',
    );
}

it('offers the student only the group a price reaches', function (): void {
    // Two groups exist; one is offered. The count is the assertion — "shows the
    // priced one" is also true of a build that shows both.
    expect(pickerNames())->toBe(['مجموعة السبت']);
});

it('publishes only the priced group on the public course page', function (): void {
    $this->asGuest();

    $names = array_column(
        $this->getJson('/api/v1/marketplace/courses/'.$this->course->uuid)->assertOk()->json('data.cohorts'),
        'name',
    );

    // Two doors, and the screen's is the one everybody remembers. This is the
    // other one — a visitor reading the course page before they hold anything.
    expect($names)->toBe(['مجموعة السبت']);
});

it('refuses a join typed straight at the route, and writes nothing', function (): void {
    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->unpriced->uuid.'/join')
        ->assertStatus(422)
        ->assertJsonPath('code', 'cohort_not_listed');

    expect(CohortMembership::query()->withoutWorkspaceScope()->count())->toBe(0);
});

it('lets the same student join the priced group', function (): void {
    /*
    | ⛔ THE TWIN, AND WITHOUT IT THE CASE ABOVE IS GREEN AGAINST A BUILD THAT
    | REFUSES EVERY JOIN ON THE PLATFORM. Same student, same course, same route —
    | the only thing that differs is which group.
    */
    $this->actingAs($this->student, 'sanctum')
        ->postJson('/api/v1/cohorts/'.$this->priced->uuid.'/join')
        ->assertStatus(201);

    expect(CohortMembership::query()->withoutWorkspaceScope()->count())->toBe(1);
});

it('keeps a member seeing their own group after it drops out of the offer', function (): void {
    /*
    | ⛔ THE GATE MUST NOT TAKE ANYTHING FROM ANYBODY. The old picker kept closed
    | groups in the list so «لماذا لا أرى مجموعتي وزملائي فيها؟» stayed
    | answerable; ٠٣٦ drops unlisted groups instead, and the promise is kept by a
    | different route entirely — the reader's own membership is read by a query
    | that never passes through the gate.
    |
    | Precedent this exists over: creating the first group of «Laravel Mastery»
    | shut the curriculum retroactively on four live enrolments, one of them at
    | 100%.
    */
    CohortMembershipWriter::open(
        $this->unpriced,
        $this->student,
        CohortMembershipEvent::JOINED,
        $this->student,
    );

    $payload = $this->actingAs($this->student, 'sanctum')
        ->getJson('/api/v1/courses/'.$this->course->uuid.'/cohorts')
        ->assertOk()
        ->json();

    expect($payload['membership']['cohort_name'])->toBe('مجموعة الأحد')
        // And it is still out of the OFFER — the two facts are separate, and a
        // build that simply stopped filtering would satisfy the line above.
        ->and(array_column($payload['cohorts'], 'name'))->toBe(['مجموعة السبت']);
});

it('stops offering a group whose own plan is waiting to be priced', function (): void {
    /*
    | ⛔ THE OVERRULE RULE, MEASURED FROM THE SCREEN. A group with a plan of its
    | own does NOT fall back on a wider one — so a teacher-wide price covering
    | everything else leaves this group unlisted while its plan sits unpriced.
    | Without the wider plan here, this case is green against a build with no
    | overrule rule at all.
    */
    Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);

    Plan::factory()->forCohort((string) $this->unpriced->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
        'price_minor' => null,
    ]);

    expect(pickerNames())->toBe(['مجموعة السبت']);
});
