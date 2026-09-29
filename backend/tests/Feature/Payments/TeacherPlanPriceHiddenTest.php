<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Enums\PlanCoverage;
use App\Modules\Payments\Models\Plan;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
| ⛔ THE TEACHER DOES NOT SEE THE PLATFORM'S PRICE (owner decision 2026-09-29).
|
| `/manage/plans` answers through `ManagedPlanResource`: no `price_minor`, no
| `currency`, and `is_priced` for the one thing the screen needs from the price.
|
| ⚠️ AND THE BUYER STILL DOES. Every «absent» assertion here has its «present»
| twin on `/billing/plans` — a build that stripped the price from BOTH resources
| would pass the first half and leave a student paying for a number they never
| saw.
|
| The keys are ASCII on purpose: `getContent()` escapes non-ASCII, so a leak
| check with an Arabic needle is vacuously true.
*/
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->owner);

    $this->course = courseWithRate((int) $this->workspace->getKey());

    $this->priced = Plan::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'coverage_type' => PlanCoverage::Workspace,
        'coverage_uuid' => null,
    ]);
    $this->unpriced = Plan::factory()->unpriced()->create(['workspace_id' => $this->workspace->getKey()]);

    Sanctum::actingAs($this->owner);
});

it('lists the teacher\'s plans with no price and no currency, only whether one is set', function (): void {
    expect($this->priced->price_minor)->not->toBeNull();

    $rows = collect($this->getJson('/api/v1/manage/plans')->assertOk()->json('data'))->keyBy('uuid');

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row)->not->toHaveKey('price_minor')
            ->and($row)->not->toHaveKey('currency')
            ->and($row)->not->toHaveKey('price');
    }

    expect($rows[$this->priced->uuid]['is_priced'])->toBeTrue()
        ->and($rows[$this->unpriced->uuid]['is_priced'])->toBeFalse();
});

it('answers a teacher\'s create and edit with no price either', function (): void {
    $created = $this->postJson('/api/v1/manage/plans', [
        'title' => 'شهري',
        'duration_days' => 30,
        'session_type' => ClassSessionType::Individual->value,
        'coverage_type' => PlanCoverage::Workspace->value,
    ])->assertCreated()->json('data');

    expect($created)->not->toHaveKey('price_minor')
        ->and($created)->not->toHaveKey('currency')
        ->and($created['is_priced'])->toBeFalse();

    $edited = $this->patchJson('/api/v1/manage/plans/'.$this->priced->uuid, [
        'title' => 'اسم جديد',
        'duration_days' => $this->priced->duration_days,
        'session_count' => $this->priced->session_count,
        'session_type' => $this->priced->session_type->value,
        'coverage_type' => $this->priced->coverage_type->value,
        'coverage_uuid' => $this->priced->coverage_uuid,
        'is_active' => true,
    ])->assertOk()->json('data');

    expect($edited)->not->toHaveKey('price_minor')
        ->and($edited)->not->toHaveKey('currency')
        ->and($edited['is_priced'])->toBeTrue();
});

it('still shows the buyer the price on the catalogue', function (): void {
    $offered = collect($this->getJson('/api/v1/billing/plans?course='.$this->course->uuid)
        ->assertOk()->json('data'))->keyBy('uuid');

    expect($offered)->toHaveKey($this->priced->uuid)
        ->and($offered[$this->priced->uuid]['price_minor'])->toBe($this->priced->price_minor)
        ->and($offered[$this->priced->uuid])->toHaveKey('currency');
});
