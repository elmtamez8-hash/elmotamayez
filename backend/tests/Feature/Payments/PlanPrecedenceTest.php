<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Payments\Actions\ListPlans;
use App\Modules\Payments\Actions\PurchaseSubscription;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Order;
use App\Modules\Payments\Models\Plan;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| ٠٣٦ · US3 — A GROUP'S OWN PLAN REPLACES ITS COURSE'S, AND THE SCREEN SAYS SO.
|
| A teacher may price one group apart from the rest of its course — «مجموعة
| الجمعة» is four students and costs double. When they have, that price is what
| the buyer standing on that group is shown, INSTEAD of the course's, and the
| purchase door agrees with the screen.
|
| ⛔ EVERY PLAN HERE SAYS `->group()` EXPLICITLY. The factory default is
| `individual`, and `guardModeMatchesPlan()` runs BEFORE the group is resolved —
| so a fixture that forgets it is refused one door early, and every case below
| would pass for a reason that has nothing to do with coverage.
|
| ⚠️ AND «THE LIST IS EMPTY» IS NEVER MEASURED ALONE. Each replacement case is
| paired with the plan that SHOULD be there, because an empty list is equally
| true of a build that lists nothing at all.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    [$this->course, $this->saturday, $this->friday] = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        function (): array {
            $course = Course::factory()->published()->create([
                'workspace_id' => $this->workspace->getKey(),
                'created_by' => $this->teacher->getKey(),
                'course_type' => Course::TYPE_GROUP,
                'title' => 'التفاضل',
            ]);

            $group = fn (string $name): Cohort => Cohort::factory()->create([
                'workspace_id' => $this->workspace->getKey(),
                'course_id' => $course->getKey(),
                'created_by' => $this->teacher->getKey(),
                'name' => $name,
            ]);

            return [$course, $group('مجموعة السبت'), $group('مجموعة الجمعة')];
        },
    );

    // باقةُ الكورسِ كلِّه — ما تَرِثُه كلُّ مجموعةٍ ما لم يكنْ لها ما يخصُّها.
    $this->coursePlan = Plan::factory()->group()->create([
        'workspace_id' => $this->workspace->getKey(),
        'title' => 'شهر التفاضل',
        'coverage_type' => PlanCoverage::Course,
        'coverage_uuid' => (string) $this->course->uuid,
        'price_minor' => 30_000,
    ]);
});

/** ما تعرِضُه الشاشةُ لمشترٍ واقفٍ على هذه المجموعة. */
function offeredFor(Cohort $cohort): array
{
    return app(ListPlans::class)
        ->handle((string) test()->course->uuid, 'group', (string) $cohort->uuid)
        ->pluck('title')
        ->all();
}

/** باقةٌ تخصُّ هذه المجموعةَ وحدَها. */
function planNaming(Cohort $cohort, string $title, ?int $priceMinor = 60_000, bool $active = true): Plan
{
    return Plan::factory()->group()->create([
        'workspace_id' => test()->workspace->getKey(),
        'title' => $title,
        'coverage_type' => PlanCoverage::Cohort,
        'coverage_uuid' => (string) $cohort->uuid,
        'price_minor' => $priceMinor,
        'is_active' => $active,
    ]);
}

/**
 * ⚠️ A FRESH BUYER EVERY TIME, and it is not tidiness. `PurchaseSubscription`
 * allows ONE pending subscription order per buyer per teacher, so a second
 * purchase by the same student measures that guard instead of coverage — and
 * would pass with the coverage rule deleted entirely.
 */
function buys(Plan $plan, Cohort $cohort): Order
{
    return app(PurchaseSubscription::class)->handle(
        User::factory()->create(['last_workspace_id' => null]),
        (string) $plan->uuid,
        'cohort',
        (string) $cohort->uuid,
    );
}

it('offers the course plan to a group that has none of its own', function (): void {
    expect(offeredFor($this->saturday))->toBe(['شهر التفاضل'])
        ->and(offeredFor($this->friday))->toBe(['شهر التفاضل']);
});

it('replaces the course plan with the group\'s own, and only for that group', function (): void {
    planNaming($this->friday, 'الجمعة المكثّفة');

    /*
    | ⛔ «REPLACES», NOT «IS ADDED TO». Listed beside the course's, the buyer on
    | the intensive group picks the ordinary month because it is cheaper — which
    | is the whole reason the teacher priced that group apart.
    */
    expect(offeredFor($this->friday))->toBe(['الجمعة المكثّفة'])
        // والمجموعةُ الأخرى لم تتأثّر: الاستثناءُ يخصُّ من كُتِبَ له.
        ->and(offeredFor($this->saturday))->toBe(['شهر التفاضل']);
});

it('refuses the inherited plan at the door for a group that was priced apart', function (): void {
    $own = planNaming($this->friday, 'الجمعة المكثّفة');

    expect(fn () => buys($this->coursePlan, $this->friday))
        ->toThrow(DomainException::class, 'هذه المجموعة لم تعد متاحة للانضمام.');

    /*
    | ⛔ THE POSITIVE CONTROL, AND HERE IT IS LOAD-BEARING RATHER THAN POLITE.
    | `resolveCohort()` throws that ONE sentence for four different reasons —
    | «does not exist», «another teacher's», «a private 1:1 room», «full» — so a
    | case asserting the refusal alone passes against a fixture that was simply
    | broken. The group's own plan going through is what proves the group was
    | fine and the COVERAGE was the refusal.
    */
    expect(buys($own, $this->friday)->exists)->toBeTrue();

    // والمجموعةُ الأخرى ما زالَت تَرِثُ: الاستثناءُ لم يُغلِقْ بابَ الكورس.
    expect(buys($this->coursePlan, $this->saturday)->exists)->toBeTrue();
});

it('refuses a group\'s own plan on a different group of the same course', function (): void {
    /*
    | ⛔ T085 -- THE ARM THAT DID NOT EXIST, AND IT FAILED IN BOTH DIRECTIONS.
    | A cohort-covered plan resolves to its group's COURSE for enrolment, and the
    | door compared against that: so «الجمعة المكثّفة» at double the price was
    | buyable by anyone standing on «مجموعة السبت» -- and the ordinary month, once
    | it too named a group, could be had for the intensive one. Neither raised an
    | error, and the order's snapshot named the group actually chosen.
    */
    $own = planNaming($this->friday, 'الجمعة المكثّفة');

    expect(fn () => buys($own, $this->saturday))
        ->toThrow(DomainException::class, 'هذه المجموعة لم تعد متاحة للانضمام.');

    // ⚠️ AND THE SAME PLAN ON ITS OWN GROUP GOES THROUGH -- one sentence covers
    // four different refusals here, so without this the case proves nothing.
    expect(buys($own, $this->friday)->exists)->toBeTrue();
});

it('falls back to the course plan when the group\'s own is switched off', function (): void {
    $own = planNaming($this->friday, 'الجمعة المكثّفة');

    expect(offeredFor($this->friday))->toBe(['الجمعة المكثّفة']);

    $own->forceFill(['is_active' => false])->save();

    /*
    | ⛔ SWITCHED OFF IS NOT «NEVER WRITTEN», AND THE ANSWER IS AN EMPTY LIST.
    | The gate is EXISTENCE: a group whose own plan is written stops inheriting,
    | full stop. Falling back here would sell the intensive group at the ordinary
    | group's price the moment the teacher switched their own plan off to edit
    | it — silently, at half the price they had just set.
    */
    expect(offeredFor($this->friday))->toBe([])
        // ⚠️ AND THE COURSE PLAN IS STILL THERE, measured on the other group:
        // «empty» would otherwise be equally true of a build that lists nothing.
        ->and(offeredFor($this->saturday))->toBe(['شهر التفاضل']);
});

it('does not treat an unpriced group plan as no plan at all', function (): void {
    /*
    | ⛔ T083. A plan the platform has not priced yet cannot be bought — but it
    | EXISTS, and existence is the gate. Read as sellability, this group quietly
    | inherits its course's price, is listed, and «باقتها بانتظار التسعير»
    | becomes a state no group on the platform can ever be in: the teacher wrote
    | a price for this group and their students are sold the other one.
    */
    planNaming($this->friday, 'الجمعة المكثّفة', priceMinor: null);

    expect(offeredFor($this->friday))->toBe([])
        ->and(offeredFor($this->saturday))->toBe(['شهر التفاضل']);
});

it('sells every plan it offers, on every group of the course', function (): void {
    /*
    | ⛔ SC-007, WALKED — EVERY OFFERED OPTION TAKEN TO THE DOOR, not a sample.
    | The screen and the door are two readers of one rule, and the failure this
    | guards is a plan that is listed and then refused: a buyer who picks what
    | they were shown and is told no.
    |
    | ⚠️ MEASURED BY THE ROW THAT GETS WRITTEN, never by a refusal key. The plans
    | reader answers a sentence with no machine-readable code behind it, so
    | «no exception» plus «an order exists» is the only honest assertion.
    */
    planNaming($this->friday, 'الجمعة المكثّفة');

    $walked = 0;

    foreach ([$this->saturday, $this->friday] as $cohort) {
        $offered = app(ListPlans::class)
            ->handle((string) $this->course->uuid, 'group', (string) $cohort->uuid);

        // ⚠️ AND THE LIST IS NOT EMPTY. A walk over nothing refuses nothing and
        // passes over any build at all.
        expect($offered)->not->toBeEmpty();

        foreach ($offered as $plan) {
            expect(buys($plan, $cohort)->exists)->toBeTrue();
            $walked++;
        }
    }

    expect($walked)->toBe(2);
});
