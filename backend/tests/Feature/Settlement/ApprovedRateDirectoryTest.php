<?php

declare(strict_types=1);

use App\Modules\LiveSessions\Enums\ClassSessionType;
use App\Shared\Contracts\ApprovedRateDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;

/*
| The two properties the implementation exists for, neither of which the binding
| test can see.
|
| Both failure modes are silent by construction: the contract answers `null` for
| "no approved rate", and a caller turns that into an empty package list with no
| error. So a broken lookup does not raise anything — it just stops selling.
*/

// `courseWithRate()` lives in tests/Pest.php: US3's purchase suite needs the same
// fixture, and a function declared in a test file exists only for the files Pest
// happens to load after it.

it('prices a course from another workspace than the reader is currently in', function (): void {
    [$mine] = $this->createWorkspaceWithOwner(['name' => 'رياضيات']);
    [$theirs] = $this->createWorkspaceWithOwner(['name' => 'فيزياء']);

    $course = courseWithRate((int) $theirs->getKey(), 7500);

    // A student studying with two teachers has ONE current workspace. Resolve the
    // rate under the scope and the physics teacher's rate is invisible from the
    // maths context — read as "no approved rate", shown as an empty package list,
    // with nothing logged anywhere.
    app(WorkspaceContext::class)->set($mine);

    expect(app(ApprovedRateDirectory::class)->approvedRateMinorForCourse(
        (int) $course->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
    ))->toBe(7500);
});

it('resolves the rate under a null context without reaching across workspaces', function (): void {
    [$a] = $this->createWorkspaceWithOwner(['name' => 'أ']);
    [$b] = $this->createWorkspaceWithOwner(['name' => 'ب']);

    $courseA = courseWithRate((int) $a->getKey(), 3000);
    courseWithRate((int) $b->getKey(), 9000);

    // The charge path is a queued listener, where WorkspaceContext::id() is null
    // and WorkspaceScope adds no condition at all. Without the forWorkspace wrap
    // `settlement_rates` is searched platform-wide.
    app()->forgetInstance(WorkspaceContext::class);
    app()->instance(WorkspaceContext::class, new WorkspaceContext);

    expect(app(WorkspaceContext::class)->id())->toBeNull();

    $directory = app(ApprovedRateDirectory::class);

    expect($directory->approvedRateMinorForCourse(
        (int) $courseA->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
    ))->toBe(3000)
        // And the worker is handed back the way it was found.
        ->and(app(WorkspaceContext::class)->id())->toBeNull();
});

it('answers null for a course with no approved rate', function (): void {
    [$workspace] = $this->createWorkspaceWithOwner();

    $course = courseWithRate((int) $workspace->getKey(), null);

    expect(app(ApprovedRateDirectory::class)->approvedRateMinorForCourse(
        (int) $course->getKey(),
        ClassSessionType::Individual,
        CarbonImmutable::now(),
    ))->toBeNull();
});

it('answers null for a course id that names nothing', function (): void {
    expect(app(ApprovedRateDirectory::class)->approvedRateMinorForCourse(
        999_999,
        ClassSessionType::Individual,
        CarbonImmutable::now(),
    ))->toBeNull();
});
