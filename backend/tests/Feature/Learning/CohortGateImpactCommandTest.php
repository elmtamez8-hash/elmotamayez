<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Learning\Models\CohortMembership;
use App\Modules\Learning\Models\CohortMembershipEvent;
use App\Modules\Learning\Support\CohortMembershipWriter;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;

/*
| ٠٣٦ · T090…T093 · FR-010 — the pre-release guard, and it has to BITE.
|
| ⛔ IT ANSWERS ONE QUESTION: how many groups with people already in them lose
| their place in the offer the moment the price gate bites. Zero is the only
| acceptable answer, and a non-zero EXIT CODE is what makes it a guard rather
| than a line that scrolls past in a deploy log and is read afterwards.
|
| ⚠️ «WITH MEMBERS» IS THE WHOLE FILTER, AND BOTH SIDES OF IT ARE MEASURED. An
| empty unlisted group takes nothing from anybody — counting one would stop a
| release over a room nobody was ever offered and nobody is in.
|
| ⚠️ AND THE GUARD WRITES NOTHING. A guard that repairs what it finds hides the
| very thing it was run to show; the membership count is asserted unchanged after
| the run.
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

function cohortWithMembers(string $name, int $members = 1, array $attrs = []): Cohort
{
    $cohort = Cohort::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => test()->course->getKey(),
        'created_by' => test()->teacher->getKey(),
        'name' => $name,
        ...$attrs,
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

it('stops the release when a group with members would lose its place', function (): void {
    cohortWithMembers('مجموعة السبت', 2);

    $this->artisan('cohorts:gate-impact')
        ->expectsOutputToContain('مجموعات فيها أعضاء ستخرج من العرض: 1')
        // ⛔ THE EXIT CODE IS THE GUARD. Printed and exiting zero, this would be
        // read after the students had already lost their group.
        ->assertExitCode(1);
});

it('passes when the group is priced', function (): void {
    cohortWithMembers('مجموعة السبت', 2);

    // ⚠️ THE POSITIVE CONTROL. Without it «it failed» is equally true of a
    // command that fails on every database there is.
    groupPriceFor($this->course);

    $this->artisan('cohorts:gate-impact')->assertExitCode(0);
});

it('ignores an unlisted group that nobody is in', function (): void {
    Cohort::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'course_id' => $this->course->getKey(),
        'created_by' => $this->teacher->getKey(),
        'name' => 'مجموعة فارغة',
    ]);

    // Unlisted, and it takes nothing from anybody: a release must not be stopped
    // over a room that was never offered and holds nobody.
    $this->artisan('cohorts:gate-impact')->assertExitCode(0);
});

it('ignores an archived group, which is already out of every list', function (): void {
    /*
    | ⚠️ ARCHIVED **AFTER** THE MEMBER JOINS, because the writer refuses an
    | archived group outright — which is also the only order this row can reach
    | in production: a group is archived with people in it, not born that way.
    */
    $cohort = cohortWithMembers('مجموعة منتهية');

    $cohort->forceFill(['status' => Cohort::ARCHIVED, 'archived_at' => now()])->save();

    $this->artisan('cohorts:gate-impact')->assertExitCode(0);
});

it('changes nothing it looked at', function (): void {
    $cohort = cohortWithMembers('مجموعة السبت', 2);

    $this->artisan('cohorts:gate-impact')->assertExitCode(1);

    // ⛔ READ-ONLY. A guard that «fixes» what it finds reports a zero over a
    // decision nobody took.
    expect(CohortMembership::query()->withoutWorkspaceScope()->whereNull('closed_at')->count())->toBe(2)
        ->and((int) $cohort->refresh()->members_count)->toBe(2)
        ->and($cohort->status)->toBe(Cohort::OPEN);
});

it('counts a group whose OWN plan is switched off, live course plan or not', function (): void {
    /*
    | ⛔ T094'S THIRD CASE, AND ITS EXPECTATION WAS WRONG — MEASURED, NOT
    | ARGUED. The task asked for ZERO here on the reasoning that the course's
    | live plan still covers the group. It does not, and that is the whole of the
    | overrule rule: a group with a plan OF ITS OWN does not inherit, whether or
    | not that plan can be bought. `CohortPlanReach`'s own header spells out why
    | the alternative collapses — «own-sellable OR (no own-sellable AND
    | inherited-sellable)» is the first clause negated, so it reduces to «own OR
    | inherited», and «باقتها بانتظار التسعير» becomes a sentence no group on the
    | platform can ever be in.
    |
    | ⚠️ SO THE GUARD IS RIGHT TO STOP THE RELEASE, and the teacher's remedy is
    | the actionable one `gapFrom()` picks: switch their own plan back on. A case
    | written to the task's number would have pinned the collapse instead.
    */
    $cohort = cohortWithMembers('مجموعة السبت');

    Plan::factory()->group()->inactive()->forCohort((string) $cohort->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    // The course IS priced, and it changes nothing for a group that has its own.
    Plan::factory()->group()->forCourse((string) $this->course->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->artisan('cohorts:gate-impact')
        ->expectsOutputToContain('مجموعات فيها أعضاء ستخرج من العرض: 1')
        ->assertExitCode(1);
});

it('lets a group with no plan of its own inherit the price of its course', function (): void {
    /*
    | ⚠️ THE OTHER HALF OF THE SAME RULE, AND WITHOUT IT THE CASE ABOVE IS
    | EQUALLY TRUE OF A BRIDGE THAT NEVER INHERITS AT ALL. No own plan ⇒ the
    | course's live one reaches the group ⇒ the release takes nothing.
    */
    cohortWithMembers('مجموعة الأحد');

    Plan::factory()->group()->forCourse((string) $this->course->uuid)->create([
        'workspace_id' => $this->workspace->getKey(),
    ]);

    $this->artisan('cohorts:gate-impact')->assertExitCode(0);
});
