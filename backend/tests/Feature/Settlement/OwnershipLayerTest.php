<?php

declare(strict_types=1);

use App\Modules\Settlement\Models\LedgerEntry;
use App\Modules\Settlement\Models\RateChangeRequest;
use App\Modules\Settlement\Models\SettlementPeriod;
use App\Modules\Settlement\Models\SettlementRate;
use App\Modules\Settlement\Models\TeacherPayout;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Shared\Support\WorkspaceContext;
use App\Shared\Traits\BelongsToWorkspace;

/*
| Constitution I asks three questions of every new entity, by hand, in review:
| which layer is it in, is that written down in the spec, and — if it is
| platform-owned — is the teacher-visibility guard applied and tested.
|
| This file answers the first for the settlement context, and the answer is not
| uniform: TeachingUnit is a BRIDGE (workspace_id for context, plus a pointer at
| the platform-owned student, exactly like Attendance), while the other five are
| workspace-owned because none of them mentions a student at all.
*/

it('keeps every settlement entity on the workspace layer', function (): void {
    $models = [
        SettlementRate::class,
        RateChangeRequest::class,
        TeachingUnit::class,
        LedgerEntry::class,
        SettlementPeriod::class,
        TeacherPayout::class,
    ];

    foreach ($models as $model) {
        expect(in_array(BelongsToWorkspace::class, class_uses_recursive($model), true))
            ->toBeTrue("{$model} must use BelongsToWorkspace");
    }
});

it('makes the teaching unit a bridge and the rest free of any student', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $unit = TeachingUnit::factory()->create();

    // The bridge half: it points at the platform-owned student, because the
    // statement counts students and a correction has to find the seat it
    // corrects.
    expect($unit->student_user_id)->not->toBeNull()
        ->and($unit->workspace_id)->toBe($workspace->getKey());

    // The other five carry no student column at all. Not "guarded" — absent.
    // FR-003 is stronger than a guard: there is nothing here to leak.
    $studentFree = [
        SettlementRate::class,
        RateChangeRequest::class,
        LedgerEntry::class,
        SettlementPeriod::class,
        TeacherPayout::class,
    ];

    foreach ($studentFree as $model) {
        expect(in_array('student_user_id', (new $model)->getFillable(), true))
            ->toBeFalse("{$model} must not reference a student");
    }
});

it('refuses one teacher the units of another', function (): void {
    [$workspaceA, $ownerA] = $this->createWorkspaceWithOwner(['name' => 'Academy A']);
    [$workspaceB] = $this->createWorkspaceWithOwner(['name' => 'Academy B']);

    app(WorkspaceContext::class)
        ->forWorkspace($workspaceB, fn () => TeachingUnit::factory()->count(3)->create());

    // The teacher in A sees nothing of B's — including the fact that it exists
    // (FR-019 · SC-011).
    $this->setCurrentWorkspace($workspaceA, $ownerA);

    expect(TeachingUnit::query()->count())->toBe(0);
});

it('refuses to update or delete a ledger entry', function (): void {
    [$workspace, $owner] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($workspace, $owner);

    $entry = LedgerEntry::factory()->create(['amount_minor' => 5000]);

    // Enforced on the model, not only in the Action: the Action is the path the
    // API takes, while a seeder, a Filament resource or a console command all
    // reach the model directly. A ledger with one honest path and three quiet
    // ones is not a ledger (FR-016 · SC-013).
    expect(fn () => $entry->update(['amount_minor' => 1]))->toThrow(RuntimeException::class);
    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);

    expect($entry->fresh()?->amount_minor)->toBe(5000);
});
