<?php

declare(strict_types=1);

namespace App\Modules\Community\Jobs;

use App\Shared\Contracts\EnrollmentDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Build every student's cumulative report card for one period (FR-036, FR-041).
 *
 * ⚠️ A PLATFORM JOB, AND THERE IS NO `POST /manage/report-cards`. A card spans
 * teachers, so a teacher pressing "generate" either reads a colleague's grades —
 * NFR-001أ — or produces a card with one segment that is then displayed as the
 * student's whole record, which passes `SC-012` green on any single-workspace
 * installation. Two teachers would also collide on the unique key with two
 * different period ends.
 *
 * ⚠️ THIS JOB ONLY ENUMERATES; {@see BuildStudentReportCardsJob} BUILDS. It used
 * to walk every (student, workspace) pair of the platform inside one job, on a
 * supervisor that kills a job at 300 seconds with `tries: 1` — so past a few
 * thousand students the monthly build was killed part-way, every month, and
 * nothing re-ran it: the students after the kill point simply had no card. Now
 * the one query that lists the pairs runs here and the work is dispatched in
 * chunks of {@see self::STUDENTS_PER_JOB} students, each chunk a job of its own
 * that finishes far inside the timeout.
 *
 * ⚠️ AND THE CHUNK BOUNDARY IS A STUDENT, NEVER A (STUDENT, WORKSPACE) PAIR. The
 * card's totals are computed inside `publish()`'s conditional claim over ALL of
 * the student's segments; a student whose workspaces were split across two jobs
 * would be claimed by whichever finished first, with a teacher missing, and a
 * published card is never rebuilt — so the omission would be permanent.
 *
 * Re-dispatching this job for the same period is safe, which is what makes a
 * failed chunk recoverable: every card already published is skipped by the
 * `published_at` guard, and the rest are claimed by their unique key.
 */
class BuildReportCardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Far inside the 300-second timeout: a student costs a handful of queries per
     * teacher they study with, so a chunk is a few thousand statements at most.
     */
    public const STUDENTS_PER_JOB = 200;

    public function __construct(
        private readonly string $periodStart,
        private readonly string $periodEnd,
    ) {
        $this->onQueue('report-cards');
    }

    public function handle(EnrollmentDirectory $enrollments): void
    {
        $from = CarbonImmutable::parse($this->periodStart);
        $to = CarbonImmutable::parse($this->periodEnd);

        /** @var array<int, list<int>> $byStudent */
        $byStudent = [];

        foreach ($enrollments->enrolledPairsInPeriod($from, $to) as $pair) {
            $byStudent[$pair['student_user_id']][] = $pair['workspace_id'];
        }

        // `preserve_keys`, or every chunk after the first is re-indexed from zero
        // and the student ids — the keys — are lost.
        foreach (array_chunk($byStudent, self::STUDENTS_PER_JOB, true) as $chunk) {
            BuildStudentReportCardsJob::dispatch($this->periodStart, $this->periodEnd, $chunk);
        }
    }
}
