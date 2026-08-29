<?php

declare(strict_types=1);

use App\Modules\Analytics\Jobs\RollUpPlatformMetricsJob;
use App\Modules\Analytics\Models\PlatformMetricDaily;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;

/*
| FR-044 — the rollup is safe to run twice, and this file RUNS IT TWICE.
|
| ⚠️ ONE RUN IS GREEN FOR EVER AND PROVES THE OPPOSITE. The defect this guards
| against is a unique key that does not bite: `upsert()` then matches nothing,
| INSERTS a second row every night, and after a month the screen shows whichever
| the engine returned first — a number thirty days old beside the correct one,
| with not one error logged. `concept_stats.lesson_id` and `unlock_rules.course_id`
| are the two rows in this tree that got there, both because a nullable column
| sat in the key. Both sentinels here are NOT NULL for that reason, and the only
| way to know is to write the same day twice.
*/

function rollupFixture(int $students): void
{
    $test = test();

    [$workspace] = $test->createWorkspaceWithOwner();
    $region = Region::query()->where('slug', 'doha')->sole();

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($test, $workspace, $students, $region): void {
        $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

        for ($i = 0; $i < $students; $i++) {
            $student = $test->addWorkspaceMember($workspace, Roles::STUDENT);

            StudentProfile::query()->updateOrCreate(
                ['user_id' => $student->getKey()],
                ['region_id' => $region->getKey()],
            );

            Enrollment::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'status' => EnrollmentStatus::Active->value,
            ]);
        }
    });
}

function runRollup(): void
{
    app(RollUpPlatformMetricsJob::class)->handle(
        app(WorkspaceContext::class),
        app(GamificationCalendar::class),
    );
}

it('writes the same rows on a second pass rather than a second set', function (): void {
    rollupFixture(3);

    runRollup();
    $afterFirst = PlatformMetricDaily::query()->count();

    runRollup();

    expect(PlatformMetricDaily::query()->count())->toBe($afterFirst)
        ->and($afterFirst)->toBeGreaterThan(0);
});

it('keeps exactly one row per key, region rows included', function (): void {
    rollupFixture(2);

    runRollup();
    runRollup();
    runRollup();

    $day = app(GamificationCalendar::class)->dayKey();

    $students = PlatformMetricDaily::query()
        ->where('date', $day)
        ->where('metric_key', MetricKey::StudentsActive->value)
        ->where('workspace_id', 0)
        ->count();

    // The region rows share a metric key and differ only by `region_id` — the
    // fourth column of the unique index, and the one most likely to be dropped
    // from it by somebody tidying.
    $regionRows = PlatformMetricDaily::query()
        ->where('date', $day)
        ->where('metric_key', MetricKey::StudentsByRegion->value)
        ->where('workspace_id', 0)
        ->get();

    expect($students)->toBe(1)
        ->and($regionRows)->toHaveCount(1)
        ->and($regionRows->first()?->numerator)->toBe(2);
});

it('updates a number that changed between two passes', function (): void {
    rollupFixture(2);
    runRollup();

    // A third student arrives, and the day is rolled up again — the ordinary
    // case for a re-run after a fix, and the one an INSERT-only path would
    // answer with two rows for one day.
    rollupFixture(1);
    runRollup();

    $day = app(GamificationCalendar::class)->dayKey();

    $row = PlatformMetricDaily::query()
        ->where('date', $day)
        ->where('metric_key', MetricKey::StudentsActive->value)
        ->where('workspace_id', 0)
        ->sole();

    expect($row->numerator)->toBe(3);
});
