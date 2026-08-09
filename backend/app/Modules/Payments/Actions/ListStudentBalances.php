<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Learning\Models\Enrollment;
use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\WithholdingReader;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use App\Shared\Contracts\AccountStanding;
use Illuminate\Support\Collection;

/**
 * One row per (student × course) for the teacher's panel.
 *
 * ⚠️ DRIVEN FROM `enrollments`, NEVER FROM `credit_balances`. The account is
 * created lazily, so a student who has not bought anything yet HAS NO BALANCE
 * ROW — and they are precisely the row a teacher needs to see. Leading from the
 * balances would show a panel that fills up as people pay, which reads as
 * "everyone is fine" on the day a class starts.
 *
 * ⚠️ AND NEVER SUMMED ACROSS COURSES. +10 in maths and −6 in physics reads as
 * +4 and unblocked, while the design withholds per course precisely so the
 * paid-up course stays open. A total here is a wrong answer, not a compact one.
 *
 * Three queries whatever the size of the list (NFR-012): the enrolments, their
 * balances, and the withholding reader's one pass over the workspaces involved.
 * Nothing in this class runs per row, and {@see AccountStanding}
 * is deliberately not called from here — asking it once per student is the N+1
 * the contract forbids by name.
 */
class ListStudentBalances extends Action
{
    public function __construct(
        private readonly WithholdingReader $withholding,
    ) {}

    /**
     * @return Collection<int, array<string, scalar>>
     */
    public function handle(Workspace $workspace): Collection
    {
        /** @var Collection<int, Enrollment> $enrollments */
        $enrollments = Enrollment::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->with(['student:id,uuid,name', 'course:id,uuid,title'])
            ->get();

        if ($enrollments->isEmpty()) {
            return collect();
        }

        $balances = $this->balancesFor($workspace, $enrollments);

        return $enrollments->map(function (Enrollment $enrollment) use ($balances): array {
            $balance = $balances->get($enrollment->student_user_id.':'.$enrollment->course_id);

            /** @var array<string, scalar> $row */
            $row = [
                'student_uuid' => $enrollment->student->uuid,
                'student_name' => $enrollment->student->name,
                'course_uuid' => $enrollment->course->uuid,
                'course_title' => $enrollment->course->title,
                // Zeros, not nulls. A student with no balance row holds nothing,
                // which is a number — and a null here renders as a blank cell
                // that reads like "not loaded" rather than "none".
                'remaining_credits' => $balance === null ? 0 : $balance->remaining_credits,
                'purchased_credits' => $balance === null ? 0 : $balance->purchased_credits,
                'consumed_credits' => $balance === null ? 0 : $balance->consumed_credits,
                'credit_limit_credits' => $balance === null ? 0 : $balance->credit_limit_credits,
                'is_withheld' => $balance !== null && (bool) $balance->getAttribute('is_withheld'),
            ];

            return $row;
        })->values();
    }

    /**
     * Balances keyed by student and course, withholding already stamped.
     *
     * `student_user_id` is denormalised onto the balance, which is what lets
     * this be one query: without it the join would go through
     * `student_credit_accounts` — three tables for a two-table question, with a
     * left-hand side that has to survive a missing row in the middle.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @return Collection<string, CreditBalance>
     */
    private function balancesFor(Workspace $workspace, Collection $enrollments): Collection
    {
        $balances = CreditBalance::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('student_user_id', $enrollments->pluck('student_user_id')->unique()->all())
            ->whereIn('course_id', $enrollments->pluck('course_id')->unique()->all())
            ->get();

        return $this->withholding->stamp($balances)
            ->keyBy(fn (CreditBalance $balance): string => $balance->student_user_id.':'.$balance->course_id);
    }
}
