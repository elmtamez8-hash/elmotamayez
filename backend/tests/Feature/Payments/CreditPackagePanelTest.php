<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Modules\Payments\Filament\Resources\CreditPackageResource;
use App\Modules\Payments\Models\CreditPackage;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;

/*
| The catalogue's panel screen — who may open it, and what it refuses to do.
|
| Filament, not Next.js: the panel is where the platform's own reference data is
| already administered (teacher applications, orders, message templates), and a
| second admin surface in a second stack would be a second login and a second
| permission check to keep in step.
|
| ⚠️ THE WORKSPACE OWNER MUST FAIL. It is the highest tenant role there is, and
| it fails here for the same reason it fails on the API: a package a teacher can
| define is a sale price a teacher sets (FR-016 · FR-021ب), and spec 014 pays
| that same teacher out of the credits their students consume. The party who is
| paid cannot be the party who prices.
*/

beforeEach(function (): void {
    [$this->workspace, $this->owner] = $this->createWorkspaceWithOwner();

    $this->platform = User::factory()->create(['is_super_admin' => true]);
});

it('opens for the platform and for nobody in a tenant role', function (): void {
    $teacher = $this->addWorkspaceMember($this->workspace, Roles::TEACHER);
    $student = $this->addWorkspaceMember($this->workspace, Roles::STUDENT);

    foreach ([$this->owner, $teacher, $student] as $tenant) {
        $this->actingAs($tenant);
        app(WorkspaceContext::class)->set($this->workspace);

        expect(CreditPackageResource::canViewAny())->toBeFalse()
            ->and(CreditPackageResource::canCreate())->toBeFalse();
    }

    $this->actingAs($this->platform);

    expect(CreditPackageResource::canViewAny())->toBeTrue()
        ->and(CreditPackageResource::canCreate())->toBeTrue();
});

it('never offers to delete a package, even to the platform', function (): void {
    // Retire, never erase. Every purchase ever made points at this row, and the
    // credits bought from it are still being consumed — deleting it would leave
    // those receipts pointing at nothing. `routes/api.php` refuses a DELETE for
    // the same reason; a button here would be the second answer.
    $package = CreditPackage::query()->create([
        'name' => 'أربع حصص فردية',
        'credits' => 4,
        'session_type' => ClassSessionType::Individual,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->platform);

    expect(CreditPackageResource::canDelete($package))->toBeFalse()
        ->and(CreditPackageResource::canDeleteAny())->toBeFalse()
        ->and(CreditPackageResource::canForceDelete($package))->toBeFalse();
});

it('is registered on the panel', function (): void {
    // The resource file existing is not the same as the screen existing: the
    // panel discovers module resources by an explicit per-module line, so a new
    // module's screens are invisible until that line is added — silently, with
    // no error anywhere.
    $this->actingAs($this->platform);

    expect(filament()->getPanel('admin')->getResources())
        ->toContain(CreditPackageResource::class);
});
