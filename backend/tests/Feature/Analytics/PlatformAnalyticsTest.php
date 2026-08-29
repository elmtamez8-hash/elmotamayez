<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Analytics\Actions\ReadPlatformAnalytics;
use App\Modules\Analytics\Jobs\RollUpPlatformMetricsJob;
use App\Modules\Analytics\Models\PlatformMetricDaily;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Courses\Models\Course;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Identity\Models\StudentProfile;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Support\Roles;
use App\Shared\Support\WorkspaceContext;

/*
| SC-012 — the dashboard's numbers match the source with a difference of zero.
|
| ⚠️ TWO WORKSPACES, NEVER ONE. A read left inside the workspace scope shows one
| teacher's numbers as the platform's total and passes every assertion made
| against a single-workspace fixture — `WorkspaceContext::id()` falls back to
| `users.last_workspace_id` for every user including a super admin, which is how
| the audit chain once answered «nothing was bought» with a 200. The whole point
| of this file is the second academy.
*/

/** A workspace with `$students` active enrolments, filed under `$region`. */
function analyticsWorkspace(string $name, int $students, ?Region $region = null): Workspace
{
    $test = test();

    [$workspace] = $test->createWorkspaceWithOwner(['name' => $name]);

    app(WorkspaceContext::class)->forWorkspace($workspace, function () use ($test, $workspace, $students, $region): void {
        $course = Course::factory()->create(['workspace_id' => $workspace->getKey()]);

        for ($i = 0; $i < $students; $i++) {
            $student = $test->addWorkspaceMember($workspace, Roles::STUDENT);

            StudentProfile::query()->updateOrCreate(
                ['user_id' => $student->getKey()],
                ['region_id' => $region?->getKey()],
            );

            Enrollment::factory()->create([
                'workspace_id' => $workspace->getKey(),
                'course_id' => $course->getKey(),
                'student_user_id' => $student->getKey(),
                'status' => EnrollmentStatus::Active->value,
            ]);
        }
    });

    return $workspace;
}

it('counts the whole platform, not the workspace the reader happens to be in', function (): void {
    $doha = Region::query()->where('slug', 'doha')->sole();

    analyticsWorkspace('Academy A', 2, $doha);
    analyticsWorkspace('Academy B', 3, $doha);

    app(RollUpPlatformMetricsJob::class)->handle(
        app(WorkspaceContext::class),
        app(GamificationCalendar::class),
    );

    $report = app(ReadPlatformAnalytics::class)->handle();

    $students = collect($report['metrics'])->firstWhere('key', MetricKey::StudentsActive->value);

    // Five, not two and not three: the platform row is computed outside every
    // workspace rather than read from whichever one the context resolved to.
    expect($students['numerator'])->toBe(5);
});

it('gives every workspace a row of its own beside the platform total', function (): void {
    $a = analyticsWorkspace('Academy A', 2);
    analyticsWorkspace('Academy B', 3);

    app(RollUpPlatformMetricsJob::class)->handle(
        app(WorkspaceContext::class),
        app(GamificationCalendar::class),
    );

    $day = app(GamificationCalendar::class)->dayKey();

    $perWorkspace = PlatformMetricDaily::query()
        ->where('date', $day)
        ->where('metric_key', MetricKey::StudentsActive->value)
        ->where('workspace_id', $a->getKey())
        ->sole();

    expect($perWorkspace->numerator)->toBe(2);
});

it('shows a region nobody registered from as a zero rather than dropping it', function (): void {
    $doha = Region::query()->where('slug', 'doha')->sole();

    analyticsWorkspace('Academy A', 2, $doha);

    app(RollUpPlatformMetricsJob::class)->handle(
        app(WorkspaceContext::class),
        app(GamificationCalendar::class),
    );

    $regions = collect(app(ReadPlatformAnalytics::class)->handle()['regions']);

    /*
    | The rollup writes only what it counted, so a report built from the metric
    | rows alone would list one region. Starting from `regions` and joining left
    | is what makes «nobody here yet» an answer instead of an absence.
    */
    expect($regions->firstWhere('slug', 'doha')['students'])->toBe(2)
        ->and($regions->firstWhere('slug', 'al-khor')['students'])->toBe(0)
        ->and($regions->count())->toBeGreaterThan(5);
});

it('stores a ratio as a numerator and a denominator, never as a percentage', function (): void {
    analyticsWorkspace('Academy A', 4);

    app(RollUpPlatformMetricsJob::class)->handle(
        app(WorkspaceContext::class),
        app(GamificationCalendar::class),
    );

    $row = PlatformMetricDaily::query()
        ->where('metric_key', MetricKey::DropoutRate->value)
        ->where('workspace_id', 0)
        ->sole();

    // Four enrolments, none of them dropped: 0/4, which a stored percentage
    // could not be compared against the source to a difference of zero.
    expect($row->numerator)->toBe(0)
        ->and($row->denominator)->toBe(4)
        ->and($row->value())->toBe(0.0);
});

it('reads a count with a zero denominator as the count, not as a division', function (): void {
    $row = new PlatformMetricDaily(['numerator' => 7, 'denominator' => 0]);

    expect($row->value())->toBe(7.0);
});

it('leaves the surrounding workspace context exactly as it found it', function (): void {
    $a = analyticsWorkspace('Academy A', 1);
    analyticsWorkspace('Academy B', 1);

    $context = app(WorkspaceContext::class);
    $context->set($a);

    app(RollUpPlatformMetricsJob::class)->handle($context, app(GamificationCalendar::class));

    expect($context->id())->toBe($a->getKey());
});

it('does not exist for a user with no permission at all', function (): void {
    $stranger = User::factory()->create();

    expect($stranger->can('analytics.cross_teacher.view'))->toBeFalse();
});
