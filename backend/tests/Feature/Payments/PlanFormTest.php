<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Actions\SetPlanPrice;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
| ٠٣٦ · US2 — the vessel a teacher writes a plan's SHAPE into.
|
| ⛔ THE ACTION IS CALLED DIRECTLY, NEVER THE HTTP ROUTE, AND THAT IS THE WHOLE
| POINT OF THE FILE. `SavePlanRequest` carries `min:1` on the duration, so a
| network measurement of «a duration of zero is refused» measures the validation
| that has been there since ٠١١ — green before this spec and green after it,
| about a rule it never touched. And it does not declare `price_minor` at all, so
| that key never reaches the Action over the wire: T062 measured through a
| request would pass against a build with the permission check deleted.
|
| The Action is also the one entrance the panel, the API and every seeder share,
| which is why the rule lives there rather than in a form.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();

    [$this->course, $this->cohort] = app(WorkspaceContext::class)->forWorkspace(
        $this->workspace,
        function (): array {
            $course = Course::factory()->published()->create([
                'workspace_id' => $this->workspace->getKey(),
                'created_by' => $this->teacher->getKey(),
                'course_type' => Course::TYPE_GROUP,
            ]);

            return [$course, Cohort::factory()->create([
                'workspace_id' => $this->workspace->getKey(),
                'course_id' => $course->getKey(),
                'created_by' => $this->teacher->getKey(),
                'name' => 'مجموعة السبت',
            ])];
        },
    );

    $this->officer = makePlatformStaff(Roles::FINANCE_ADMIN);
});

/**
 * One call to the Action, under the teacher's own workspace context.
 *
 * ⚠️ `forWorkspace`, NOT `set`. spatie runs in team mode, so a permission check
 * with no team id answers false for EVERYTHING — and the price refusal below
 * would then fire because the writer holds no roles at all rather than because
 * they lack this one. The positive control in that case names the difference.
 *
 * @param  array<string, mixed>  $overrides
 */
function savePlanAs(?User $author = null, array $overrides = [], ?Plan $plan = null): Plan
{
    $author ??= test()->teacher;

    return app(WorkspaceContext::class)->forWorkspace(
        test()->workspace,
        fn (): Plan => app(SavePlan::class)->handle(
            $author,
            (int) test()->workspace->getKey(),
            array_merge([
                'title' => 'باقة الشهر',
                'session_type' => ClassSessionType::Group,
                'coverage_type' => PlanCoverage::Course,
                'coverage_uuid' => (string) test()->course->uuid,
            ], $overrides),
            $plan,
        ),
    );
}

function planRowCount(): int
{
    return Plan::query()->withoutWorkspaceScope()->count();
}

it('refuses both shapes at once, and says which two fields disagree', function (): void {
    /*
    | ⛔ AND THE SENTENCE IS MATCHED, NOT JUST THE CLASS. All three refusals here
    | are one exception type, so a case asserting «it threw» passes when the wrong
    | branch fired — and «both shapes» falling into the «neither» branch is
    | exactly the failure a careless `positiveOrNull` produces.
    */
    expect(fn () => savePlanAs(overrides: ['duration_days' => 30, 'session_count' => 12]))
        ->toThrow(DomainException::class, 'لا الاثنين معاً');

    expect(planRowCount())->toBe(0);
});

it('refuses a plan with no shape at all', function (): void {
    expect(fn () => savePlanAs())
        ->toThrow(DomainException::class, 'حدّد مدّة الباقة أو عدد حصصها.');

    expect(planRowCount())->toBe(0);
});

it('reads a zero as absent rather than as a shape', function (): void {
    /*
    | ⛔ ZERO IS THE CASE THAT USED TO SAVE. `(int) $data['duration_days']` turned
    | a missing duration into `0`, and a zero written to the column is a window
    | whose end equals its start: an order approved, money taken, a subscription
    | expired the instant it was activated, every screen correct and nothing
    | logged. Zero sessions is the same row wearing the other shape — a balance
    | the student paid for that can buy no seat.
    |
    | So a zero is not a third state: it is the field left empty, and a plan with
    | nothing but zeros is refused as a plan with no shape.
    */
    expect(fn () => savePlanAs(overrides: ['duration_days' => 0]))
        ->toThrow(DomainException::class, 'حدّد مدّة الباقة أو عدد حصصها.');

    expect(fn () => savePlanAs(overrides: ['session_count' => 0]))
        ->toThrow(DomainException::class, 'حدّد مدّة الباقة أو عدد حصصها.');

    // ⚠️ AND A ZERO BESIDE A REAL VALUE IS NOT «both shapes» — it is the one
    // shape that was written. Without this the fix could be «any two keys
    // present ⇒ refuse», which would reject the ordinary form the panel submits.
    expect(savePlanAs(overrides: ['duration_days' => 30, 'session_count' => 0])->session_count)->toBeNull();

    expect(planRowCount())->toBe(1);
});

it('writes each shape on its own', function (): void {
    // The positive control the four refusals above are measured against: without
    // it «the Action throws» is equally true of a build that refuses everything.
    $months = savePlanAs(overrides: ['duration_days' => 30, 'title' => 'شهر']);

    $sessions = savePlanAs(overrides: [
        'session_count' => 12,
        'title' => 'اثنتا عشرة حصّة',
        'coverage_type' => PlanCoverage::Cohort,
        'coverage_uuid' => (string) $this->cohort->uuid,
    ]);

    expect((int) $months->duration_days)->toBe(30)
        ->and($months->session_count)->toBeNull()
        ->and((int) $sessions->session_count)->toBe(12)
        ->and($sessions->duration_days)->toBeNull();
});

it('refuses sessions sold over the whole workspace', function (): void {
    /*
    | ⛔ FR-022. Workspace coverage names no single course, and ٠٣٥ keeps one
    | credit balance PER COURSE — «+10 in maths and −6 in physics» is not +4 — so
    | there is nowhere for these sessions to land. Saved, the row is a student
    | paying for a balance that no seat anywhere can be bought with.
    */
    expect(fn () => savePlanAs(overrides: [
        'session_count' => 12,
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]))->toThrow(DomainException::class, 'باقة الحصص تخصّ كورساً أو مجموعة');

    expect(planRowCount())->toBe(0);

    // ⚠️ THE TWIN THAT PASSES, and it is what stops this reading as «workspace
    // coverage is refused». A plan sold by the MONTH over the whole workspace is
    // the ordinary shape ٠١١ shipped, and it must keep working.
    expect(savePlanAs(overrides: [
        'duration_days' => 30,
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ])->exists)->toBeTrue();
});

it('refuses a price written by the teacher, and takes one from the platform', function (): void {
    /*
    | ⚠️ THE WRITER'S ROLES RESOLVE — proved one line down, and the proof is the
    | point. A permission check with no spatie team id is false for every name,
    | so without it this case would pass against a build with no permission check
    | in it at all, for a reason that has nothing to do with pricing.
    */
    app(WorkspaceContext::class)->forWorkspace($this->workspace, function (): void {
        expect($this->teacher->can(Permissions::PLANS_MANAGE))->toBeTrue()
            ->and($this->teacher->can(Permissions::PLANS_PRICE))->toBeFalse();
    });

    /*
    | ⛔ REFUSED WITH A SENTENCE, NEVER FILTERED OUT IN SILENCE. A teacher who
    | types 300, is told nothing, and finds out when a student cannot buy is worse
    | off than one who is refused: they believe the plan is priced.
    */
    expect(fn () => savePlanAs(overrides: ['duration_days' => 30, 'price_minor' => 30_000]))
        ->toThrow(DomainException::class, 'سعر الباقة تحدّده المنصّة');

    expect(planRowCount())->toBe(0);

    // The platform's half of the same row: the officer carries the key through.
    expect(savePlanAs($this->officer, ['duration_days' => 30, 'price_minor' => 30_000])->exists)->toBeTrue();
});

it('refuses to move what the platform has already priced', function (): void {
    /*
    | ⛔ THE MONEY HOLE, MEASURED. A teacher wrote a ONE-session plan, the officer
    | read «حصّة واحدة» on the pricing screen and put 100 on it — and a second
    | request to the route the teacher already holds turned it into 200 sessions,
    | still on sale at 100. The plan stays sellable throughout, so nothing
    | anywhere fails; the next buyer simply has two hundred sessions poured into
    | ٠٣٥'s ledger for the price of one. The teacher is still paid per delivered
    | session at their own approved rate, so the gap is the platform's.
    */
    $plan = savePlanAs(overrides: ['session_count' => 1, 'title' => 'حصّة واحدة']);

    app(SetPlanPrice::class)->handle($plan, 10_000);

    foreach ([
        'the count widens' => ['session_count' => 200],
        'the shape flips' => ['duration_days' => 30, 'session_count' => null],
        // ⚠️ التغطيةُ تبقى كورساً: حصصٌ + تغطيةُ مساحةِ عملٍ يرفضُها حارسُ
        // الشكلِ قبلَ هذا الحارسِ أصلاً، فتأتي الجملةُ من بابٍ آخر.
        'the room size changes' => ['session_type' => ClassSessionType::Individual],
        'the coverage moves to a group' => ['coverage_type' => PlanCoverage::Cohort, 'coverage_uuid' => (string) test()->cohort->uuid],
    ] as $what => $overrides) {
        expect(fn () => savePlanAs(overrides: array_merge(['session_count' => 1], $overrides), plan: $plan->fresh()))
            ->toThrow(DomainException::class, 'بطلب تعديل', $what);
    }

    expect((int) $plan->fresh()->session_count)->toBe(1);
});

it('leaves the title and the on-off switch to the teacher', function (): void {
    /*
    | ⚠️ THE OTHER DIRECTION, AND IT IS WHAT STOPS THE GUARD ABOVE FROM BECOMING
    | «a priced plan is frozen». Neither field moves what was priced, and a
    | teacher who cannot stop selling a plan this minute without filing a request
    | is a teacher whose only remaining instrument is asking a student not to buy.
    */
    $plan = savePlanAs(overrides: ['session_count' => 1, 'title' => 'حصّة واحدة']);

    app(SetPlanPrice::class)->handle($plan, 10_000);

    $saved = savePlanAs(overrides: [
        'session_count' => 1,
        'title' => 'حصّة تجريبيّة',
        'is_active' => false,
    ], plan: $plan->fresh());

    expect($saved->title)->toBe('حصّة تجريبيّة')
        ->and($saved->is_active)->toBeFalse()
        ->and((int) $saved->price_minor)->toBe(10_000);
});

it('lets the platform itself move a priced plan', function (): void {
    // مَن يُسعِّرُ هو مَن يملكُ تغييرَ ما سُعِّر — وهو الطرفُ الذي يقرّرُ طلبَ
    // التعديل، فلو مُنِعَ لصارَ الطلبُ طابوراً لا مخرجَ له.
    $plan = savePlanAs(overrides: ['session_count' => 1]);

    app(SetPlanPrice::class)->handle($plan, 10_000);

    expect((int) savePlanAs($this->officer, ['session_count' => 5], plan: $plan->fresh())->session_count)->toBe(5);
});
