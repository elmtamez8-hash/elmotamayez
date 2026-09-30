<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Models\AssistantAssignment;
use App\Modules\Community\Models\AssistantScope;
use App\Modules\Courses\Models\Course;
use App\Modules\Learning\Models\Cohort;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use App\Modules\Store\Models\StoreItem;
use App\Modules\Tenancy\Support\Permissions;
use App\Modules\Tenancy\Support\Roles;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/*
| Spec 010 · FR-005 on the two course-bound catalogues a teacher sells from:
| plans (`plans.manage`) and store items (`store.items.manage`). Neither is on
| the default assistant role; an owner who ticked one onto an assistant let
| them sell — and list, and re-price the coverage of — every course in the
| workspace, or the whole workspace at once.
|
| A confined assistant now works on the plans and goods of their own courses
| only. A workspace plan and an item tied to no course are refused to them (the
| directory's rule for anything that hangs off no course). Everybody else is
| unchanged. Shipments and rewards are workspace-level and untouched.
*/

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    $ws = $this->workspace->getKey();

    $this->nearCourse = Course::factory()->create(['workspace_id' => $ws]);
    $this->farCourse = Course::factory()->create(['workspace_id' => $ws]);
    $this->nearCohort = Cohort::factory()->create(['workspace_id' => $ws, 'course_id' => $this->nearCourse->getKey()]);
    $this->farCohort = Cohort::factory()->create(['workspace_id' => $ws, 'course_id' => $this->farCourse->getKey()]);

    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ws);

    $this->assistant = $this->addWorkspaceMember($this->workspace, Roles::ASSISTANT_TEACHER);
    $this->assistant->givePermissionTo([Permissions::PLANS_MANAGE, Permissions::STORE_ITEMS_MANAGE]);
    $this->assignment = AssistantAssignment::factory()->create([
        'assistant_user_id' => $this->assistant->getKey(),
        'invited_by_user_id' => $this->owner->getKey(),
    ]);
});

function psConfine(): void
{
    AssistantScope::factory()->create([
        'assistant_assignment_id' => test()->assignment->getKey(),
        'course_id' => test()->nearCourse->getKey(),
    ]);

    app()->forgetScopedInstances();
}

/** A plan row of this coverage, as the owner would have saved it. */
function psPlan(PlanCoverage $coverage, ?string $uuid): Plan
{
    return Plan::factory()->unpriced()->create([
        'workspace_id' => test()->workspace->getKey(),
        'session_type' => 'group',
        'coverage_type' => $coverage,
        'coverage_uuid' => $uuid,
    ]);
}

/** @return array{near: list<Plan>, far: list<Plan>} */
function psPlans(): array
{
    $t = test();

    return [
        'near' => [psPlan(PlanCoverage::Course, $t->nearCourse->uuid), psPlan(PlanCoverage::Cohort, (string) $t->nearCohort->uuid)],
        'far' => [psPlan(PlanCoverage::Course, $t->farCourse->uuid), psPlan(PlanCoverage::Cohort, (string) $t->farCohort->uuid), psPlan(PlanCoverage::Workspace, null)],
    ];
}

function psCreatePlan(User $actor, PlanCoverage $coverage, ?string $uuid): int
{
    Sanctum::actingAs($actor);

    return test()->postJson('/api/v1/manage/plans', array_filter([
        'title' => 'باقة شهرية',
        'duration_days' => 30,
        'session_type' => 'group',
        'coverage_type' => $coverage->value,
        'coverage_uuid' => $uuid,
        'acknowledge_hidden_cohorts' => true,
    ], fn ($value) => $value !== null))->getStatusCode();
}

/** @return list<string> */
function psListedPlans(User $reader): array
{
    Sanctum::actingAs($reader);

    return collect(test()->getJson('/api/v1/manage/plans')->assertOk()->json('data'))->pluck('uuid')->all();
}

function psItem(?Course $course): StoreItem
{
    return StoreItem::factory()->physical()->create([
        'workspace_id' => test()->workspace->getKey(),
        'course_id' => $course?->getKey(),
    ]);
}

function psCreateItem(User $actor, ?Course $course): int
{
    Sanctum::actingAs($actor);

    return test()->postJson('/api/v1/store/items', array_filter([
        'kind' => 'physical',
        'title' => 'مذكّرة',
        'price_minor' => 5000,
        'stock' => 5,
        'shipping_fee_minor' => 500,
        'course_uuid' => $course?->uuid,
    ], fn ($value) => $value !== null))->getStatusCode();
}

function psUpdateItem(User $actor, StoreItem $item, ?Course $course): int
{
    Sanctum::actingAs($actor);

    return test()->putJson("/api/v1/store/items/{$item->uuid}", array_filter([
        'kind' => 'physical',
        'title' => 'مذكّرة معدّلة',
        'price_minor' => 6000,
        'stock' => 5,
        'shipping_fee_minor' => 500,
        'course_uuid' => $course?->uuid,
    ], fn ($value) => $value !== null))->getStatusCode();
}

/** @return list<string> */
function psListedItems(User $reader): array
{
    Sanctum::actingAs($reader);

    return collect(test()->getJson('/api/v1/store/items')->assertOk()->json('data'))->pluck('uuid')->all();
}

it('lets a confined assistant list, create and edit the plans of their own courses only', function (): void {
    $plans = psPlans();
    psConfine();

    expect(psListedPlans($this->assistant))->toEqualCanonicalizing(collect($plans['near'])->pluck('uuid')->all())
        ->and(psCreatePlan($this->assistant, PlanCoverage::Course, $this->nearCourse->uuid))->toBe(201)
        ->and(psCreatePlan($this->assistant, PlanCoverage::Course, $this->farCourse->uuid))->toBe(403)
        ->and(psCreatePlan($this->assistant, PlanCoverage::Workspace, null))->toBe(403);

    Sanctum::actingAs($this->assistant);

    foreach ($plans['far'] as $plan) {
        $this->patchJson("/api/v1/manage/plans/{$plan->uuid}", [
            'title' => 'عنوان آخر',
            'duration_days' => 30,
            'session_type' => 'group',
            'coverage_type' => PlanCoverage::Course->value,
            'coverage_uuid' => $this->nearCourse->uuid,
        ])->assertForbidden();
    }

    // Moving a near plan onto a far course is the other way round the door.
    $this->patchJson("/api/v1/manage/plans/{$plans['near'][0]->uuid}", [
        'title' => 'عنوان آخر',
        'duration_days' => 30,
        'session_type' => 'group',
        'coverage_type' => PlanCoverage::Course->value,
        'coverage_uuid' => $this->farCourse->uuid,
    ])->assertForbidden();

    $this->patchJson("/api/v1/manage/plans/{$plans['near'][0]->uuid}", [
        'title' => 'عنوان آخر',
        'duration_days' => 30,
        'session_type' => 'group',
        'coverage_type' => PlanCoverage::Course->value,
        'coverage_uuid' => $this->nearCourse->uuid,
    ])->assertOk();
});

it('leaves an unconfined assistant and the owner every plan', function (): void {
    $plans = psPlans();
    $all = collect([...$plans['near'], ...$plans['far']])->pluck('uuid')->all();

    foreach ([$this->assistant, $this->owner] as $actor) {
        expect(psListedPlans($actor))->toEqualCanonicalizing($all);
    }

    foreach ([$this->assistant, $this->owner] as $actor) {
        expect(psCreatePlan($actor, PlanCoverage::Course, $this->farCourse->uuid))->toBe(201)
            ->and(psCreatePlan($actor, PlanCoverage::Workspace, null))->toBe(201);
    }
});

it('lets a confined assistant list, create and edit the goods of their own courses only', function (): void {
    $near = psItem($this->nearCourse);
    $far = psItem($this->farCourse);
    $loose = psItem(null);
    psConfine();

    expect(psListedItems($this->assistant))->toBe([(string) $near->uuid])
        ->and(psCreateItem($this->assistant, $this->nearCourse))->toBe(201)
        ->and(psCreateItem($this->assistant, $this->farCourse))->toBe(403)
        ->and(psCreateItem($this->assistant, null))->toBe(403)
        ->and(psUpdateItem($this->assistant, $far, $this->farCourse))->toBe(403)
        ->and(psUpdateItem($this->assistant, $loose, $this->nearCourse))->toBe(403)
        ->and(psUpdateItem($this->assistant, $near, $this->farCourse))->toBe(403)
        ->and(psUpdateItem($this->assistant, $near, $this->nearCourse))->toBe(200);
});

it('leaves an unconfined assistant and the owner every item, and never another workspace\'s', function (): void {
    $mine = [psItem($this->nearCourse), psItem($this->farCourse), psItem(null)];

    [$other] = $this->createWorkspaceWithOwner();
    StoreItem::factory()->physical()->create(['workspace_id' => $other->getKey()]);
    $this->setCurrentWorkspace($this->workspace, $this->owner);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->getKey());

    foreach ([$this->assistant, $this->owner] as $actor) {
        expect(psListedItems($actor))->toEqualCanonicalizing(collect($mine)->pluck('uuid')->all());
    }

    foreach ([$this->assistant, $this->owner] as $actor) {
        expect(psCreateItem($actor, null))->toBe(201)
            ->and(psUpdateItem($actor, $mine[1], null))->toBe(200);
    }
});
