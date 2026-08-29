<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Jobs;

use App\Modules\Analytics\Models\PlatformMetricDaily;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Learning\Enums\EnrollmentStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Payments\Enums\OrderStatus;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Models\Order;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's source, computed once a night (spec 011 · FR-043 · FR-044).
 *
 * ⚠️ `forWorkspace()`, NEVER `set()`. `WorkspaceContext` is an application-wide
 * singleton that caches its resolution, so a `set()` in a job leaks the last
 * workspace it touched into whatever that worker handles next — Constitution I,
 * and `TrustScoreJobIsolationTest` fails the build over one anywhere under a
 * module's `Jobs/` directory.
 *
 * ⚠️ THE DAY'S WINDOW IS HALF-OPEN AND COMES FROM `GamificationCalendar`. Not
 * `whereDate()` — a function around the column throws away the index it was given
 * — and not `CONVERT_TZ()`, which returns NULL on any MySQL whose timezone tables
 * were never loaded and does not exist in SQLite at all: the predicate would then
 * match nothing, the rollup would write zeros, and no local test could see it.
 * The calendar is also the platform's ONE declaration of where midnight is; a
 * second one here would drift away from the class schedule.
 *
 * ⚠️ THE PLATFORM ROW IS COMPUTED, NOT SUMMED. `workspace_id = 0` runs the same
 * queries with `withoutWorkspaceScope()`; adding up the per-workspace numbers
 * would count a student enrolled with two teachers twice and report more students
 * than the platform has.
 *
 * ⚠️ AND THE REGIONS ARE ONE `GROUP BY` PER WORKSPACE, never a query per region.
 * The walk is already `W × M`; a loop over regions makes it `W × R × M`.
 *
 * Idempotent by key: `upsert()` on `(date, metric_key, workspace_id, region_id)`,
 * so a second run of the same day rewrites the same rows rather than adding a
 * second set — `RollupIdempotencyTest` runs it twice for exactly that reason.
 */
class RollUpPlatformMetricsJob implements ShouldQueue
{
    use Queueable;

    /** @param  string|null  $day  `Y-m-d`, defaulting to today in the platform's timezone. */
    public function __construct(private readonly ?string $day = null) {}

    public function handle(WorkspaceContext $context, GamificationCalendar $calendar): void
    {
        $dayKey = $this->day ?? $calendar->dayKey();
        [$start, $end] = $calendar->dayBounds($dayKey);

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        // The platform's own totals first, outside every workspace.
        foreach ($this->metrics($start, $end, platform: true) as $key => [$numerator, $denominator]) {
            $rows[] = $this->row($dayKey, $key, 0, 0, $numerator, $denominator);
        }

        foreach ($this->regionCounts(platform: true) as $regionId => $count) {
            $rows[] = $this->row($dayKey, MetricKey::StudentsByRegion->value, 0, $regionId, $count, 0);
        }

        Workspace::query()->chunkById(100, function ($workspaces) use ($context, $start, $end, $dayKey, &$rows): void {
            foreach ($workspaces as $workspace) {
                $workspaceId = (int) $workspace->getKey();

                $context->forWorkspace($workspace, function () use ($start, $end, $dayKey, $workspaceId, &$rows): void {
                    foreach ($this->metrics($start, $end, platform: false) as $key => [$numerator, $denominator]) {
                        $rows[] = $this->row($dayKey, $key, $workspaceId, 0, $numerator, $denominator);
                    }

                    foreach ($this->regionCounts(platform: false) as $regionId => $count) {
                        $rows[] = $this->row($dayKey, MetricKey::StudentsByRegion->value, $workspaceId, $regionId, $count, 0);
                    }
                });
            }
        });

        foreach (array_chunk($rows, 200) as $chunk) {
            PlatformMetricDaily::query()->upsert(
                $chunk,
                ['date', 'metric_key', 'workspace_id', 'region_id'],
                ['numerator', 'denominator', 'computed_at', 'updated_at'],
            );
        }
    }

    /**
     * The five scalar metrics FR-040 names, for whichever scope is in force.
     *
     * @return array<string, array{0: int, 1: int}> metric key => [numerator, denominator]
     */
    private function metrics(CarbonImmutable $start, CarbonImmutable $end, bool $platform): array
    {
        $before = $end->toDateTimeString();

        $students = (int) $this->enrollments($platform)
            ->where('status', EnrollmentStatus::Active->value)
            ->where('created_at', '<', $before)
            ->distinct()
            ->count('student_user_id');

        $teachers = (int) ($platform ? TeacherProfile::query()->withoutWorkspaceScope() : TeacherProfile::query())
            ->where('created_at', '<', $before)
            ->count();

        /*
        | ⚠️ THE SIGN IS FLIPPED ONCE, HERE. A balance holds a debt as a NEGATIVE
        | remainder; «المستحقات المتأخرة» is read as a positive amount owed, and a
        | reader left to remember which way it points gets it wrong on the first
        | screen that shows both.
        */
        $owed = (int) ($platform ? CreditBalance::query()->withoutWorkspaceScope() : CreditBalance::query())
            ->where('remaining_credits', '<', 0)
            ->sum('remaining_credits');

        $ordersRaised = (int) $this->orders($platform, $start, $end)->count();
        $ordersCollected = (int) $this->orders($platform, $start, $end)
            ->where('status', OrderStatus::Approved->value)
            ->count();

        $enrolled = (int) $this->enrollments($platform)->where('created_at', '<', $before)->count();
        $dropped = (int) $this->enrollments($platform)
            ->where('created_at', '<', $before)
            ->whereIn('status', [EnrollmentStatus::Cancelled->value, EnrollmentStatus::Expired->value])
            ->count();

        return [
            MetricKey::StudentsActive->value => [$students, 0],
            MetricKey::TeachersActive->value => [$teachers, 0],
            MetricKey::DuesOverdue->value => [-$owed, 0],
            MetricKey::CollectionRate->value => [$ordersCollected, $ordersRaised],
            MetricKey::DropoutRate->value => [$dropped, $enrolled],
        ];
    }

    /**
     * Students per region, in ONE grouped query.
     *
     * A region nobody lives in is simply absent from this result; the reader
     * starts from `regions` and joins left, so it still appears with a zero
     * rather than dropping off the report.
     *
     * @return array<int, int> region id => student count (0 = «never asked»)
     */
    private function regionCounts(bool $platform): array
    {
        $rows = $this->enrollments($platform)
            ->join('student_profiles', 'student_profiles.user_id', '=', 'enrollments.student_user_id')
            ->where('enrollments.status', EnrollmentStatus::Active->value)
            ->groupBy('student_profiles.region_id')
            ->select([
                DB::raw('COALESCE(student_profiles.region_id, 0) as region'),
                DB::raw('COUNT(DISTINCT enrollments.student_user_id) as students'),
            ])
            ->get();

        /** @var array<int, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->getAttribute('region')] = (int) $row->getAttribute('students');
        }

        return $counts;
    }

    /** @return Builder<Enrollment> */
    private function enrollments(bool $platform): Builder
    {
        return $platform ? Enrollment::query()->withoutWorkspaceScope() : Enrollment::query();
    }

    /** @return Builder<Order> */
    private function orders(bool $platform, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return ($platform ? Order::query()->withoutWorkspaceScope() : Order::query())
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<', $end->toDateTimeString());
    }

    /** @return array<string, mixed> */
    private function row(string $day, string $key, int $workspaceId, int $regionId, int $numerator, int $denominator): array
    {
        $now = CarbonImmutable::now();

        return [
            'date' => $day,
            'metric_key' => $key,
            'workspace_id' => $workspaceId,
            'region_id' => $regionId,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
