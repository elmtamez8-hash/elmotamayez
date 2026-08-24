<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Community\Jobs\BuildReportCardsJob;
use App\Modules\Community\Jobs\RenderReportCardJob;
use App\Modules\Community\Models\GradingScheme;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Community\Models\ReportCardSegment;
use App\Modules\Courses\Models\Course;
use App\Modules\LiveSessions\Enums\AttendanceStatus;
use Illuminate\Support\Facades\Queue;

/*
| `FR-052` — changing the weights does NOT recompute a card that was published.
|
| ⚠️ THE SNAPSHOT IS THE COMPONENTS COLUMN, NOT A REFERENCE TO THE SCHEME. A
| segment that stored `grading_scheme_id` and resolved the weights at read time
| would pass every test written against a scheme nobody edits — and then, the
| first time a teacher adjusted their weighting, every card already sitting in
| every family's hands would quietly become a different document. There would be
| no event, no log line and no way to tell which number a parent actually saw.
|
| ⚠️ AND THE GUARD IS ALSO THE PUBLISH CLAIM. A second build of a published
| period must not rewrite the segments underneath the totals, or the card's own
| grade stops matching the rows it is printed from.
*/

beforeEach(function (): void {
    Queue::fake([RenderReportCardJob::class]);

    [$this->workspace, $this->teacher] = $this->createWorkspaceWithOwner();
    $this->setCurrentWorkspace($this->workspace, $this->teacher);

    $this->student = User::factory()->create();
    $this->course = Course::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->createEnrollment($this->workspace, $this->course, $this->student);

    GradingScheme::factory()->create([
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'weights' => ['exams' => 100, 'homework' => 0, 'attendance' => 0, 'participation' => 0],
    ]);

    periodAttempt($this->workspace, $this->student, 90, 100);

    app()->call([new BuildReportCardsJob('2026-08-01', '2026-08-31'), 'handle']);
});

it('does not recompute a published card when the weights change', function (): void {
    $card = ReportCard::query()->where('student_user_id', $this->student->getKey())->firstOrFail();
    $segment = ReportCardSegment::withoutGlobalScopes()->where('report_card_id', $card->getKey())->firstOrFail();

    $before = [
        'overall' => $card->overall_pct,
        'published_at' => $card->published_at?->toIso8601String(),
        'components' => $segment->components,
        'segment_pct' => $segment->segment_pct,
    ];

    expect($before['overall'])->toBe(90.0);

    // The teacher changes their mind about the whole term, and a session with a
    // 0% attendance now exists to prove the new weighting WOULD have moved the
    // number if anything recomputed it.
    $this->setCurrentWorkspace($this->workspace, $this->teacher);
    GradingScheme::query()->withoutGlobalScopes()->update([
        'weights' => json_encode(['exams' => 10, 'homework' => 0, 'attendance' => 90, 'participation' => 0]),
    ]);

    periodSession($this->workspace, $this->course, $this->student, AttendanceStatus::Absent);

    app()->call([new BuildReportCardsJob('2026-08-01', '2026-08-31'), 'handle']);

    $card->refresh();
    $segment->refresh();

    // Byte for byte: the totals, the publish moment, the components and the
    // weights printed beside them.
    expect($card->overall_pct)->toBe($before['overall']);
    expect($card->published_at?->toIso8601String())->toBe($before['published_at']);
    expect($segment->components)->toBe($before['components']);
    expect($segment->segment_pct)->toBe($before['segment_pct']);

    // And no second card for the same period.
    expect(ReportCard::query()->where('student_user_id', $this->student->getKey())->count())->toBe(1);
    expect(ReportCardSegment::withoutGlobalScopes()->where('report_card_id', $card->getKey())->count())->toBe(1);
});

it('renders the file once however many times the build runs', function (): void {
    app()->call([new BuildReportCardsJob('2026-08-01', '2026-08-31'), 'handle']);
    app()->call([new BuildReportCardsJob('2026-08-01', '2026-08-31'), 'handle']);

    // The conditional UPDATE is the claim, so only the first run ever dispatches
    // — two PDFs of one card is a second document nothing would ever reconcile.
    Queue::assertPushed(RenderReportCardJob::class, 1);
});

it('carries the weights it was computed with, not a reference to them', function (): void {
    $segment = ReportCardSegment::withoutGlobalScopes()->firstOrFail();

    // The weight sits inside the row, beside the percentage it multiplied.
    expect($segment->components['exams'])->toHaveKeys(['pct', 'weight']);
    expect((float) $segment->components['exams']['weight'])->toBe(100.0);
});
