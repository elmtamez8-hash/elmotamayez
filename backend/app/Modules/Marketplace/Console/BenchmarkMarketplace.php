<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Console;

use App\Modules\Marketplace\Actions\Public\ListPublicCourses;
use App\Modules\Marketplace\Actions\Public\ListPublicTeachers;
use App\Modules\Marketplace\DTOs\CourseFilterDTO;
use App\Modules\Marketplace\DTOs\TeacherFilterDTO;
use Illuminate\Console\Command;

/**
 * SC-008: marketplace listings answer within one second at 50k teachers and 10k
 * courses. Run after MarketplaceLoadSeeder.
 *
 * Measures the Actions, not the HTTP endpoints, and on purpose: the endpoints
 * put a cache in front, so timing them would mostly time Cache::get and report a
 * number the first visitor of every minute never sees.
 */
class BenchmarkMarketplace extends Command
{
    protected $signature = 'marketplace:benchmark {--runs=5}';

    protected $description = 'Time the public marketplace listing queries against SC-008';

    private const BUDGET_MS = 1000.0;

    public function handle(ListPublicTeachers $teachers, ListPublicCourses $courses): int
    {
        $runs = max(1, (int) $this->option('runs'));

        $cases = [
            'teachers: unfiltered' => fn () => $teachers->handle(TeacherFilterDTO::fromArray([])),
            'teachers: subject filter' => fn () => $teachers->handle(TeacherFilterDTO::fromArray(['subject' => 'math'])),
            'teachers: sort by trust' => fn () => $teachers->handle(TeacherFilterDTO::fromArray(['sort' => TeacherFilterDTO::SORT_TRUST])),
            'teachers: deep page' => fn () => $teachers->handle(TeacherFilterDTO::fromArray(['page' => 200])),
            'teachers: name search' => fn () => $teachers->handle(TeacherFilterDTO::fromArray(['q' => 'مدرّس'])),
            'courses: unfiltered' => fn () => $courses->handle(CourseFilterDTO::fromArray([])),
            'courses: type filter' => fn () => $courses->handle(CourseFilterDTO::fromArray(['type' => 'group'])),
        ];

        $rows = [];
        $failed = false;

        foreach ($cases as $label => $case) {
            $timings = [];

            for ($i = 0; $i < $runs; $i++) {
                $start = hrtime(true);
                $case();
                $timings[] = (hrtime(true) - $start) / 1_000_000;
            }

            sort($timings);
            // The worst run, not the average: SC-008 is a promise to every
            // visitor, and an average hides the one who waited three seconds.
            $worst = end($timings);
            $ok = $worst <= self::BUDGET_MS;
            $failed = $failed || ! $ok;

            $rows[] = [
                $label,
                sprintf('%.0f ms', $timings[intdiv(count($timings), 2)]),
                sprintf('%.0f ms', $worst),
                $ok ? 'PASS' : 'FAIL',
            ];
        }

        $this->table(['query', 'median', 'worst', 'SC-008'], $rows);

        if ($failed) {
            $this->error('At least one listing exceeded the 1s budget.');

            return self::FAILURE;
        }

        $this->info('All listings within the 1s budget.');

        return self::SUCCESS;
    }
}
