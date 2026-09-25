<?php

declare(strict_types=1);

use App\Modules\Community\Jobs\BuildReportCardsJob;
use App\Modules\Community\Jobs\BuildStudentReportCardsJob;
use App\Modules\Community\Jobs\RenderReportCardJob;
use App\Shared\Contracts\EnrollmentDirectory;
use Illuminate\Support\Facades\Queue;

/*
| The monthly build ENUMERATES and dispatches; it does not build.
|
| ⚠️ IT WAS ONE JOB WALKING EVERY STUDENT ON THE PLATFORM, on a supervisor that
| kills a job at 300 seconds with `tries: 1`. Past a few thousand students the
| build was killed part-way every month and nothing re-ran it. The fidelity,
| snapshot and PDF suites all call the parent's `handle()` with a partial fake,
| so the chunk jobs run inline there and those files still measure the cards;
| this file measures the split.
|
| ⚠️ AND THE SPLIT IS BY STUDENT. A student whose two workspaces landed in two
| chunks would be published by whichever chunk finished first, with one teacher
| missing — permanently, since a published card is never rebuilt.
*/

it('dispatches one chunk job per 200 students and never splits a student', function (): void {
    Queue::fake([BuildStudentReportCardsJob::class, RenderReportCardJob::class]);

    // 201 students; the first one studies with two teachers, and their SECOND
    // pair arrives last — after 200 other students — which is exactly the order
    // a naive chunk over pairs would split.
    $pairs = [['student_user_id' => 1, 'workspace_id' => 10]];

    for ($student = 2; $student <= 201; $student++) {
        $pairs[] = ['student_user_id' => $student, 'workspace_id' => 10];
    }

    $pairs[] = ['student_user_id' => 1, 'workspace_id' => 20];

    $this->mock(EnrollmentDirectory::class)
        ->shouldReceive('enrolledPairsInPeriod')
        ->once()
        ->andReturn($pairs);

    app()->call([new BuildReportCardsJob('2026-08-01', '2026-08-31'), 'handle']);

    Queue::assertPushed(BuildStudentReportCardsJob::class, 2);
    Queue::assertPushedOn('report-cards', BuildStudentReportCardsJob::class);

    $chunks = Queue::pushed(BuildStudentReportCardsJob::class)
        ->map(fn (BuildStudentReportCardsJob $job): array => $job->workspaceIdsByStudent)
        ->values();

    expect(count($chunks[0]))->toBe(BuildReportCardsJob::STUDENTS_PER_JOB)
        ->and(count($chunks[1]))->toBe(1)
        // Keys are student ids, preserved across the chunk boundary.
        ->and(array_key_last($chunks[1]))->toBe(201)
        // Both of student 1's teachers travel in the same job.
        ->and($chunks[0][1])->toBe([10, 20]);

    // The parent builds nothing itself.
    Queue::assertNotPushed(RenderReportCardJob::class);
});

it('puts the report cards on their own queue, away from the announcement fan-out', function (): void {
    expect((new BuildReportCardsJob('2026-08-01', '2026-08-31'))->queue)->toBe('report-cards')
        ->and((new BuildStudentReportCardsJob('2026-08-01', '2026-08-31', []))->queue)->toBe('report-cards')
        ->and((new RenderReportCardJob(1))->queue)->toBe('report-cards');

    // A queue with no supervisor in `environments` is a queue nothing drains.
    expect(config('horizon.defaults.supervisor-report-cards.queue'))->toBe(['report-cards'])
        ->and(config('horizon.environments.production'))->toHaveKey('supervisor-report-cards')
        ->and(config('horizon.environments.local'))->toHaveKey('supervisor-report-cards')
        ->and(config('horizon.defaults.supervisor-community.queue'))->toBe(['community']);
});
