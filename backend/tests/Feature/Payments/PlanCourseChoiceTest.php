<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Actions\SavePlan;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Modules\Payments\Models\Plan;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/*
| 2026-09-26 — the teacher's plan form offered every course the workspace ever
| wrote, archived ones included, and the server took it: a subscription sold
| on a course its own teacher withdrew. A DRAFT stays allowed — it is
| publishable, and writing the plan before pressing «انشر» is ordinary.
|
| And `/admin/plans` read «مفعَّلة: نعم» beside a plan the teacher saw as
| «غير معروضة، تنتظر التسعير» — two true columns that together said «on sale».
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);
});

function planOn(User $author, int $workspaceId, Course $course, ?Plan $plan = null, string $title = 'شهري'): Plan
{
    return app(SavePlan::class)->handle($author, $workspaceId, [
        'title' => $title,
        'duration_days' => 30,
        'session_type' => ClassSessionType::Individual->value,
        'coverage_type' => PlanCoverage::Course->value,
        'coverage_uuid' => (string) $course->uuid,
    ], $plan);
}

it('refuses a plan on an archived course and accepts one on a draft', function (): void {
    $archived = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'status' => 'archived']);
    $draft = Course::factory()->create(['workspace_id' => $this->workspace->getKey(), 'status' => 'draft']);

    expect(fn () => planOn($this->owner, (int) $this->workspace->getKey(), $archived))
        ->toThrow(DomainException::class, 'مؤرشف');

    expect(planOn($this->owner, (int) $this->workspace->getKey(), $draft)->coverage_uuid)
        ->toBe((string) $draft->uuid);
});

it('still lets an old plan be edited after its course was archived', function (): void {
    $course = Course::factory()->published()->create(['workspace_id' => $this->workspace->getKey()]);
    $plan = planOn($this->owner, (int) $this->workspace->getKey(), $course);

    $course->forceFill(['status' => 'archived'])->save();

    // The course the plan ALREADY covers is not a new choice.
    $edited = planOn($this->owner, (int) $this->workspace->getKey(), $course, $plan, 'شهري — معدَّل');

    expect($edited->title)->toBe('شهري — معدَّل');
});

it('shows the panel the teacher\'s three states, not «active»', function (): void {
    $unpriced = Plan::factory()->unpriced()->create(['workspace_id' => $this->workspace->getKey()]);
    $onSale = Plan::factory()->create(['workspace_id' => $this->workspace->getKey(), 'price_minor' => 20000, 'is_active' => true]);
    $stopped = Plan::factory()->create(['workspace_id' => $this->workspace->getKey(), 'price_minor' => 20000, 'is_active' => false]);

    Livewire::actingAs(User::factory()->create(['is_super_admin' => true]))
        ->test(ListPlans::class)
        ->loadTable()
        ->assertTableColumnStateSet('sale_state', 'بانتظار التسعير', $unpriced)
        ->assertTableColumnStateSet('sale_state', 'معروضة للبيع', $onSale)
        ->assertTableColumnStateSet('sale_state', 'موقوفة', $stopped);
});
