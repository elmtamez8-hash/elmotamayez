<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Exceptions\PlanWouldHideCohorts;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| ٠٣٦ · T095 · T096 · T119 · FR-013 — «يُنبَّه المدرّس قبلَ التعطيل».
|
| ⛔ THE REQUIREMENT IS «قبلَ التنفيذِ لا بعدَه», SO EVERY CASE HERE ASSERTS
| THAT NOTHING WAS WRITTEN. A report after the save would satisfy the count and
| miss the point: the teacher is told in time to decide otherwise, which means
| the row has to be exactly as it was when they read the sentence.
|
| ⚠️ AND THE SPELLING IS THE GATE'S OWN (T096). The count comes from
| `CohortDirectory::unlistedCohortsWithMembers()`, the same method
| `cohorts:gate-impact` reads — a warning that says a number while the gate does
| otherwise is worse than no warning at all.
|
| ⚠️ MEMBERSHIP IS A REAL ROW IN EVERY FIXTURE, never a bumped `members_count`.
| The counter is a cache of those rows, and a test that moves it alone measures
| whichever column the reader happened to reach for.
*/
beforeEach(function (): void {
    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    $this->course = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->teacher->getKey(),
            'course_type' => Course::TYPE_GROUP,
        ]),
    );
});

function planWarningCourse(): Course
{
    return app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn (): Course => Course::factory()->published()->create([
            'workspace_id' => test()->workspace->getKey(),
            'created_by' => test()->teacher->getKey(),
            'course_type' => Course::TYPE_GROUP,
        ]),
    );
}

function planWarningCohort(string $name, ?Course $course = null, int $members = 1): Cohort
{
    $cohort = Cohort::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => ($course ?? test()->course)->getKey(),
        'created_by' => test()->teacher->getKey(),
        'name' => $name,
    ]);

    for ($i = 0; $i < $members; $i++) {
        CohortMembershipWriter::open(
            $cohort,
            User::factory()->create(['last_workspace_id' => null]),
            CohortMembershipEvent::JOINED,
            test()->teacher,
        );
    }

    return $cohort->refresh();
}

/**
 * The teacher's own edit, through the one Action every door shares.
 *
 * ⚠️ NAMED AFTER THIS FILE'S SUBJECT, NOT AFTER THE DOMAIN NOUN. A Pest helper
 * is a GLOBAL function, and `PlanFormTest` already declares a `savePlanAs()`
 * with a different signature — invisible while each file gets its own process,
 * and a fatal «Cannot redeclare» the moment one worker loads both, which is
 * every `pest --parallel` run.
 *
 * @param  array<string, mixed>  $overrides
 */
function saveWarningPlan(Plan $plan, array $overrides = [], ?User $author = null): Plan
{
    return app(SavePlan::class)->handle(
        $author ?? test()->teacher,
        (int) test()->workspace->getKey(),
        [
            'title' => $plan->title,
            'duration_days' => $plan->duration_days,
            'session_count' => $plan->session_count,
            'session_type' => $plan->session_type,
            'coverage_type' => $plan->coverage_type,
            'coverage_uuid' => $plan->coverage_uuid,
            'is_active' => true,
            ...$overrides,
        ],
        $plan,
    );
}

it('refuses the switch-off with the count, and writes nothing', function (): void {
    planWarningCohort('مجموعة السبت', members: 2);

    $plan = groupPriceFor($this->course);

    saveWarningPlan($plan, ['is_active' => false]);
})
    ->throws(PlanWouldHideCohorts::class, 'مجموعة السبت');

it('leaves the plan switched ON after the refusal', function (): void {
    /*
    | ⛔ THE ROLLBACK IS THE REQUIREMENT, AND IT IS A SEPARATE ASSERTION FROM
    | THE SENTENCE. `SavePlan` performs the write for real — that is the only
    | way to ask the gate about a narrowed coverage without modelling a row it
    | has never seen — so «قبلَ التنفيذِ» is true only because the transaction
    | is rolled back. A build that threw AFTER committing would pass the case
    | above word for word.
    */
    planWarningCohort('مجموعة السبت', members: 2);

    $plan = groupPriceFor($this->course);

    try {
        saveWarningPlan($plan, ['is_active' => false]);
    } catch (PlanWouldHideCohorts) {
        // The sentence is measured above; this case is about the row.
    }

    expect((bool) $plan->fresh()?->is_active)->toBeTrue();
});

it('writes it when the teacher says they know', function (): void {
    planWarningCohort('مجموعة السبت', members: 2);

    $plan = groupPriceFor($this->course);

    saveWarningPlan($plan, ['is_active' => false, 'acknowledge_hidden_cohorts' => true]);

    // ⚠️ THE OTHER HALF OF A QUESTION. FR-013 asks that the teacher be told, not
    // that they be stopped: a plan they still want off goes off.
    expect((bool) $plan->fresh()?->is_active)->toBeFalse();
});

it('says nothing when another live plan still reaches the group', function (): void {
    planWarningCohort('مجموعة السبت', members: 2);

    $plan = groupPriceFor($this->course);

    // A second road to the same group: the course's own price. Switching the
    // workspace plan off costs the group nothing.
    Plan::factory()->group()->forCourse((string) $this->course->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    saveWarningPlan($plan, ['is_active' => false]);

    expect((bool) $plan->fresh()?->is_active)->toBeFalse();
});

it('says nothing about a group that was already dark', function (): void {
    /*
    | ⛔ THE CASE THAT PROVES THE DIFFERENCE WAS TAKEN, AND NOT THE AFTER-READ
    | ALONE. The group carries an unpriced plan OF ITS OWN, so the overrule rule
    | holds it out of every list already — «own plan exists» switches the
    | inheritance off whether that plan can be bought or not. Switching the
    | course plan off changes nothing for it.
    |
    | A build that counted «groups this plan reaches» instead of diffing «dark
    | before» against «dark after» reports one here, and teaches the teacher to
    | click past a warning that is usually wrong.
    */
    $cohort = planWarningCohort('مجموعة السبت', members: 2);

    Plan::factory()->unpriced()->forCohort((string) $cohort->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $coursePlan = Plan::factory()->group()->forCourse((string) $this->course->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    saveWarningPlan($coursePlan, ['is_active' => false]);

    expect((bool) $coursePlan->fresh()?->is_active)->toBeFalse();
});

it('says nothing about a group nobody is in', function (): void {
    // Unlisted and empty takes nothing from anybody — the pre-deploy guard's own
    // filter, which is the whole reason the two read one method.
    Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => 'مجموعة فارغة',
    ]);

    $plan = groupPriceFor($this->course);

    saveWarningPlan($plan, ['is_active' => false]);

    expect((bool) $plan->fresh()?->is_active)->toBeFalse();
});

it('refuses a NARROWED coverage that drops another course\'s group', function (): void {
    /*
    | ⛔ THE SECOND TRIGGER FR-013 NAMES — «أو تضييقِ تغطيتِها» — AND IT IS WHAT
    | DECIDES THE WHOLE DESIGN. «This plan is off» can be simulated by dropping
    | an id out of the sellable set; «this plan now covers one course instead of
    | the whole workspace» cannot, because the thing to ask about is a ROW that
    | does not exist. Running the write and rolling it back is the only reader
    | that answers both — delete this case and the next person simplifies the
    | Action back into a simulation that is silent here.
    |
    | ⚠️ THE AUTHOR IS AN OFFICER, because `guardPricedPlan` refuses a teacher
    | moving what the platform priced. That refusal comes FIRST and would hide
    | this one.
    */
    // ⚠️ THE PERMISSIONS BEHIND `finance-admin` ARE SEEDED HERE AND NOWHERE
    // ELSE. Without them the officer holds nothing, `guardPricedPlan` refuses
    // first, and this case would pass or fail for a reason that is not its own.
    $this->seed(RolesAndPermissionsSeeder::class);

    $officer = makePlatformStaff(Roles::FINANCE_ADMIN);

    planWarningCohort('مجموعة الفيزياء', members: 1);

    $other = planWarningCourse();
    planWarningCohort('مجموعة الكيمياء', $other, members: 2);

    $plan = groupPriceFor($this->course);

    expect(fn () => saveWarningPlan($plan, [
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => (string) $this->course->uuid,
    ], $officer))->toThrow(PlanWouldHideCohorts::class, 'مجموعة الكيمياء');

    // ⚠️ AND THE GROUP THAT STAYS COVERED IS NOT NAMED. A warning that listed
    // every group in the workspace would be read as noise on the first edit.
    expect($plan->fresh()?->coverage_type)->toBe(PlanCoverage::Workspace);
});

it('answers the teacher\'s own screen with a code it can branch on', function (): void {
    /*
    | ⚠️ THE HTTP HALF, AND THE `code` IS THE POINT. Every other refusal from
    | this Action is final — no field makes a platform-priced plan movable — so a
    | screen that matched on the sentence would offer «أعرف، نفّذ» under a
    | message that acknowledging cannot get past.
    */
    planWarningCohort('مجموعة السبت', members: 2);

    $plan = groupPriceFor($this->course);

    Sanctum::actingAs($this->teacher);
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $body = [
        'title' => $plan->title,
        'duration_days' => $plan->duration_days,
        'session_type' => ClassSessionType::Group->value,
        'coverage_type' => PlanCoverage::Workspace->value,
        'is_active' => false,
    ];

    $this->patchJson('/api/v1/manage/plans/'.$plan->uuid, $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'plan_would_hide_cohorts')
        ->assertJsonPath('hidden_cohorts.0', 'مجموعة السبت');

    expect((bool) $plan->fresh()?->is_active)->toBeTrue();

    $this->patchJson('/api/v1/manage/plans/'.$plan->uuid, [...$body, 'acknowledge_hidden_cohorts' => true])
        ->assertOk();

    expect((bool) $plan->fresh()?->is_active)->toBeFalse();
});
