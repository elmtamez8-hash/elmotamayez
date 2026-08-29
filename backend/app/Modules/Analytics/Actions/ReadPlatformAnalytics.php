<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Actions;

use App\Modules\Analytics\Models\PlatformMetricDaily;
use App\Modules\Analytics\Support\MetricKey;
use App\Modules\Gamification\Models\LeaderboardEntry;
use App\Modules\Gamification\Support\GamificationCalendar;
use App\Modules\Marketplace\Models\Region;
use App\Modules\Marketplace\Models\TeacherProfile;
use App\Modules\Tenancy\Support\PlatformSettings;
use App\Shared\Actions\Action;
use App\Shared\Support\DisplayName;

/**
 * The platform dashboard, read from the rollup and never from the sources
 * (spec 011 · FR-040 · FR-041 · FR-042 · FR-044).
 *
 * ⚠️ EVERY TENANT-OWNED READ HERE DECLARES `withoutWorkspaceScope()`, AND SO
 * DOES EVERY EAGER LOAD BENEATH IT. `WorkspaceContext::id()` falls back to
 * `users.last_workspace_id` for EVERY user including a super admin, so a
 * platform report left scoped shows one teacher's numbers as the platform's
 * total — and passes its own test on a single-workspace fixture. The bypass is
 * per model: a relation query runs the related model's global scopes of its own,
 * which is how the audit chain once answered «nothing was bought» with a 200.
 *
 * ⚠️ THE REGION REPORT STARTS FROM `regions` AND JOINS LEFT. The rollup writes
 * only what it counted, so a region nobody has registered from has no row at all
 * — and a report built from the metric rows alone would DROP it rather than show
 * the zero that is the actual answer. FR-042's edge case, spelled as a join.
 *
 * ⚠️ AND NOTHING HERE COMPUTES A NUMBER FROM A SOURCE TABLE. FR-044 forbids
 * scanning the records on every view, and `SC-012`'s zero-difference comparison
 * is only meaningful if the screen and the rollup read the same row.
 */
class ReadPlatformAnalytics extends Action
{
    public function __construct(private readonly GamificationCalendar $calendar) {}

    /**
     * @param  string|null  $day  `Y-m-d`; today in the platform's timezone by default.
     * @return array<string, mixed>
     */
    public function handle(?string $day = null): array
    {
        $date = $day ?? $this->calendar->dayKey();

        $rows = PlatformMetricDaily::query()
            ->where('date', $date)
            ->where('workspace_id', 0)
            ->get();

        $metrics = [];

        foreach (MetricKey::cases() as $key) {
            if ($key === MetricKey::StudentsByRegion) {
                continue;
            }

            /*
            | A metric with no row is a day the rollup has not reached yet, and
            | zeros with today's date on them are the honest answer — computing
            | it here from the source tables is the scan FR-044 forbids.
            */
            $row = $rows->first(fn (PlatformMetricDaily $metric): bool => $metric->metric_key === $key->value);

            $metrics[] = [
                'key' => $key->value,
                'label' => $key->label(),
                'is_ratio' => $key->isRatio(),
                // Numerator and denominator BOTH travel. A screen that received a
                // rounded percentage could not be compared against the source at
                // all, which is what SC-012 asks for.
                'numerator' => $row === null ? 0 : $row->numerator,
                'denominator' => $row === null ? 0 : $row->denominator,
                'value' => $row === null ? 0.0 : $row->value(),
            ];
        }

        return [
            'date' => $date,
            'metrics' => $metrics,
            'regions' => $this->regions($date),
            'top_teachers' => $this->topTeachers(),
            'top_students' => $this->topStudents(),
        ];
    }

    /**
     * Students per region — every ACTIVE region, including the empty ones.
     *
     * @return list<array{slug: string, name_ar: string, students: int}>
     */
    private function regions(string $date): array
    {
        $counts = PlatformMetricDaily::query()
            ->where('date', $date)
            ->where('workspace_id', 0)
            ->where('metric_key', MetricKey::StudentsByRegion->value)
            ->pluck('numerator', 'region_id');

        /** @var list<array{slug: string, name_ar: string, students: int}> $report */
        $report = [];

        foreach (Region::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $region) {
            $report[] = [
                'slug' => $region->slug,
                'name_ar' => $region->name_ar,
                'students' => (int) ($counts[$region->getKey()] ?? 0),
            ];
        }

        /*
        | ⚠️ AND THE STUDENTS WHO WERE NEVER ASKED GET A LINE OF THEIR OWN.
        | `region_id` is nullable for every account created before the field
        | existed, and the rollup files those under the `0` sentinel. Folding them
        | into a region would be an invention; dropping them makes the column sum
        | to less than the student count with nothing on the screen explaining the
        | difference.
        */
        $unknown = (int) ($counts[0] ?? 0);

        if ($unknown > 0) {
            $report[] = ['slug' => 'unknown', 'name_ar' => 'غير محدَّدة', 'students' => $unknown];
        }

        return $report;
    }

    /**
     * The best-rated teachers, with a minimum review count (FR-041 · SC-013).
     *
     * ⚠️ THE THRESHOLD IS THE WHOLE REQUIREMENT. One five-star review outranks a
     * teacher with two hundred reviews averaging 4.8 — a ranking that rewards
     * being new, which is the bias the minimum exists to remove. It is a
     * `platform_settings` row rather than a constant, because a number that can
     * only change by shipping code is a number nobody ever tunes.
     *
     * @return list<array{uuid: string, name: string, average_rating: float, reviews_count: int}>
     */
    private function topTeachers(): array
    {
        $minimum = (int) PlatformSettings::get('analytics.min_reviews', 5);

        // array_values, because a keyed collection is not a list and the payload
        // must be a JSON array rather than an object with numeric keys.
        return array_values(TeacherProfile::query()
            ->withoutWorkspaceScope()
            ->where('reviews_count', '>=', $minimum)
            ->whereNotNull('average_rating')
            /*
            | ⚠️ THE COLUMN LIST NAMES WHAT THE ACCESSOR READS, NOT WHAT THE
            | PAYLOAD PRINTS. `users` HAS NO `name` COLUMN — it is an accessor over
            | `first_name`/`last_name` — so `user:id,uuid,name` selects a column
            | that does not exist and renders an EMPTY name with a 200. Spec 010
            | shipped that spelling six times across four modules.
            */
            ->with(['user:id,uuid,first_name,last_name'])
            ->orderByDesc('average_rating')
            ->orderByDesc('reviews_count')
            ->limit(10)
            ->get()
            ->map(fn (TeacherProfile $teacher): array => [
                'uuid' => $teacher->uuid,
                'name' => trim((string) $teacher->user?->name),
                'average_rating' => (float) $teacher->average_rating,
                'reviews_count' => (int) $teacher->reviews_count,
            ])
            ->all());
    }

    /**
     * The top students of the current week, platform-wide.
     *
     * The board is rolled up nightly by 009's own job; this reads it rather than
     * ranking again, for the reason above — two rankings of one week are two
     * answers to one question.
     *
     * @return list<array{display_name: string, points: int, level: int}>
     */
    private function topStudents(): array
    {
        return array_values(LeaderboardEntry::query()
            ->where('scope_key', 'platform')
            ->where('period_key', $this->calendar->weekKey())
            ->orderBy('rank')
            ->with(['user:id,uuid,first_name,last_name'])
            ->limit(10)
            ->get()
            ->map(fn (LeaderboardEntry $entry): array => [
                // The abbreviated form, as everywhere else a student's name
                // crosses a workspace boundary — this board is platform-wide.
                'display_name' => DisplayName::forStudent($entry->user),
                'points' => (int) $entry->points,
                'level' => (int) $entry->level_band,
            ])
            ->all());
    }
}
