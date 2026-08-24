<?php

declare(strict_types=1);

namespace App\Modules\Community\Jobs;

use App\Models\User;
use App\Modules\Community\Models\GradingScheme;
use App\Modules\Community\Models\PeriodicReview;
use App\Modules\Community\Models\ReportCard;
use App\Modules\Community\Models\ReportCardSegment;
use App\Modules\Community\Support\GradeWeighting;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Contracts\EnrollmentDirectory;
use App\Shared\Contracts\SessionAttendanceDirectory;
use App\Shared\Contracts\StudentGradeDirectory;
use App\Shared\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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
 * ⚠️ AND `WorkspaceContext::set()` IS FORBIDDEN HERE (NFR-012). It is an
 * application-wide singleton that caches its resolution, so a set inside a queued
 * job leaks that workspace into whatever the same worker handles next.
 * `forWorkspace()` restores the previous context and spatie's team id afterwards.
 */
class BuildReportCardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $periodStart,
        private readonly string $periodEnd,
    ) {
        $this->onQueue('community');
    }

    public function handle(
        EnrollmentDirectory $enrollments,
        StudentGradeDirectory $grades,
        SessionAttendanceDirectory $attendance,
        GradeWeighting $weighting,
        WorkspaceContext $context,
    ): void {
        $from = CarbonImmutable::parse($this->periodStart);
        $to = CarbonImmutable::parse($this->periodEnd);

        /** @var array<int, list<int>> $byStudent */
        $byStudent = [];

        foreach ($enrollments->enrolledPairsInPeriod($from, $to) as $pair) {
            $byStudent[$pair['student_user_id']][] = $pair['workspace_id'];
        }

        foreach ($byStudent as $studentId => $workspaceIds) {
            $student = User::query()->find($studentId);

            if ($student === null) {
                continue;
            }

            $card = $this->claimCard($studentId, $from, $to);

            // A published card is a document somebody has already read. Nothing
            // rebuilds it — that is FR-052 with the weights left out of it.
            if ($card->published_at !== null) {
                continue;
            }

            $improvements = [];

            foreach (array_unique($workspaceIds) as $workspaceId) {
                $workspace = Workspace::query()->find($workspaceId);

                if ($workspace === null) {
                    continue;
                }

                $improvement = $context->forWorkspace(
                    $workspace,
                    fn (): ?float => $this->buildSegment(
                        $card, $student, $workspace, $from, $to, $grades, $attendance, $weighting
                    )
                );

                if ($improvement !== null) {
                    $improvements[] = $improvement;
                }
            }

            $this->publish($card, $improvements);
        }
    }

    /**
     * ⚠️ `insertOrIgnore` WITH `uuid` AND `created_at` PASSED EXPLICITLY, THEN A
     * READ-BACK THAT THROWS ON ZERO. The pattern `CreditLedger::writeEntry()`
     * established: `insertOrIgnore` writes without booting the model, so
     * `HasUuid` never fires — and on MySQL the resulting NOT NULL violation is
     * downgraded to a warning and `''` is stored, after which every later card
     * collides on `unique(uuid)`, is read as "already recorded", and is silently
     * skipped while every caller reports success.
     *
     * The read-back is the other half: zero rows means EITHER a duplicate (two
     * runs of the same period, which is fine) OR a swallowed failure, so the row
     * is fetched by its key and a miss throws rather than returning null.
     */
    private function claimCard(int $studentId, CarbonImmutable $from, CarbonImmutable $to): ReportCard
    {
        $now = now();

        DB::table('report_cards')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'student_user_id' => $studentId,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'generated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $card = ReportCard::query()
            ->where('student_user_id', $studentId)
            ->where('period_start', $from->toDateString())
            ->where('period_end', $to->toDateString())
            ->first();

        if ($card === null) {
            throw new RuntimeException(
                "Report card for student {$studentId} was neither inserted nor found."
            );
        }

        return $card;
    }

    /** @return float|null the teacher's improvement rating, if they gave one */
    private function buildSegment(
        ReportCard $card,
        User $student,
        Workspace $workspace,
        CarbonImmutable $from,
        CarbonImmutable $to,
        StudentGradeDirectory $grades,
        SessionAttendanceDirectory $attendance,
        GradeWeighting $weighting,
    ): ?float {
        $workspaceId = (int) $workspace->getKey();

        $official = $grades->officialGradesInPeriod($student, $workspaceId, $from, $to);
        $attendancePct = $attendance->attendanceShareInPeriod($student, $workspaceId, $from, $to);
        $review = $this->publishedReview($student, $workspaceId, $from, $to);

        $measured = [
            'exams' => $official['exams'],
            'homework' => $official['homework'],
            'attendance' => $attendancePct,
            // The teacher's own participation axis on a PUBLISHED assessment —
            // the only participation signal the product has. A draft is not an
            // opinion the teacher has stood behind, so it does not grade anybody.
            'participation' => $review === null ? null : $review->participation / 5 * 100,
        ];

        $result = $weighting->apply($measured, $this->weightsFor($workspaceId, $from, $to));

        // Nothing happened between this student and this teacher in the period.
        // No segment at all, rather than a row of nulls that reads on the page as
        // a teacher who taught them and reported nothing.
        if ($result['components'] === [] && $attendancePct === null) {
            return $review === null ? null : $review->improvement / 5 * 100;
        }

        ReportCardSegment::updateOrCreate(
            [
                'report_card_id' => $card->getKey(),
                'workspace_id' => $workspaceId,
            ],
            [
                'teacher_user_id' => $workspace->owner_user_id,
                'student_user_id' => $student->getKey(),
                'components' => $result['components'],
                'attendance_pct' => $attendancePct,
                'segment_pct' => $result['total'],
            ]
        );

        return $review === null ? null : $review->improvement / 5 * 100;
    }

    private function publishedReview(
        User $student,
        int $workspaceId,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): ?PeriodicReview {
        return PeriodicReview::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('student_user_id', $student->getKey())
            ->whereNotNull('published_at')
            ->where('period_start', '>=', $from->toDateString())
            ->where('period_end', '<=', $to->toDateString())
            ->latest('period_end')
            ->first();
    }

    /**
     * Which weighting applies to this teacher's segment.
     *
     * ⚠️ A SEGMENT SPANS EVERY COURSE THIS STUDENT TAKES WITH THIS TEACHER, so a
     * per-course scheme cannot be honoured when there are two of them — the
     * segment has one components blob and there is no correct way to merge two
     * weightings into it. The workspace-wide row (`course_id = 0`) is therefore
     * the answer whenever it exists, a lone course scheme is used when it is the
     * only one covering the period, and equal weights are the last resort.
     *
     * Equal weights rather than refusing to build: a teacher who never opened the
     * weights screen still taught the term, and a card that omits them entirely
     * would report their student as having studied with nobody.
     *
     * @return array<string, int>
     */
    private function weightsFor(int $workspaceId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $schemes = GradingScheme::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('period_start', '<=', $to->toDateString())
            ->where('period_end', '>=', $from->toDateString())
            ->get();

        $workspaceWide = $schemes->firstWhere('course_id', GradingScheme::ALL_COURSES);

        if ($workspaceWide !== null) {
            return $workspaceWide->weights;
        }

        if ($schemes->count() === 1) {
            return $schemes->firstOrFail()->weights;
        }

        return GradingScheme::EQUAL_WEIGHTS;
    }

    /**
     * ⚠️ THE TOTALS ARE COMPUTED INSIDE THE PUBLISH CLAIM AND NOWHERE ELSE. A
     * total accumulated as each segment is written interleaves between two
     * workspaces and produces a number matching no set of segments at all — and
     * permanently, since nothing recomputes it. The conditional UPDATE is both
     * the check and the claim, the seat idiom; only the claimant renders the file,
     * so two overlapping runs cannot produce two PDFs of one card.
     *
     * @param  list<float>  $improvements
     */
    private function publish(ReportCard $card, array $improvements): void
    {
        $segments = ReportCardSegment::query()
            ->withoutGlobalScopes()
            ->where('report_card_id', $card->getKey())
            ->whereNotNull('segment_pct')
            ->pluck('segment_pct');

        // A flat mean of the teachers' grades: the platform has no basis for
        // weighting one teacher's term above another's, and inventing one would
        // be a judgement nobody made. Null when no teacher produced a grade.
        $overall = $segments->isEmpty()
            ? null
            : round((float) $segments->avg(), 2);

        $improvement = $improvements === []
            ? null
            : round(array_sum($improvements) / count($improvements), 2);

        $claimed = ReportCard::query()
            ->whereKey($card->getKey())
            ->whereNull('published_at')
            ->update([
                'published_at' => now(),
                'overall_pct' => $overall,
                'improvement_index' => $improvement,
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            RenderReportCardJob::dispatch((int) $card->getKey());
        }
    }
}
