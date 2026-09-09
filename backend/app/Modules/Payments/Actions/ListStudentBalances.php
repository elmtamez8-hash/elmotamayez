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
            ->with(['student:id,uuid,first_name,last_name', 'course:id,uuid,title'])
            ->get();

        if ($enrollments->isEmpty()) {
            return collect();
        }

        $balances = $this->balancesFor($workspace, $enrollments);

        /*
         | ⚠️ AN ORPHANED ENROLMENT TOOK THE WHOLE PANEL DOWN WITH A 500, and the
         | column that would have prevented it does not exist: `student_user_id`
         | is a bare `unsignedBigInteger` with an index and NO foreign key, on
         | MySQL as much as on SQLite. So a user row that goes away — an erasure
         | under spec 013, a hand-run delete, an e2e teardown — leaves its
         | enrolment behind, `$enrollment->student` resolves to null, and reading
         | `->uuid` off it is `ErrorException: Attempt to read property "uuid" on
         | null`. Not for that row: for the REQUEST. Every student in the
         | workspace disappears from the teacher's screen, permanently, over one
         | record that names nobody.
         |
         | Skipping is the honest answer rather than a placeholder row: the panel
         | exists so a teacher can chase a student about credits, and there is no
         | one left to chase. Found by walking the product (`029` · `T057`) — the
         | suite could not see it, because every fixture builds the enrolment
         | from a student it just created.
         |
         | ⚠️ `getRelation()` AND NOT `->student`, BECAUSE THE MODEL'S ANNOTATION
         | SAYS THE RELATION CANNOT BE NULL — and it is wrong: it reasons from
         | «course_id is NOT NULL», which is a statement about the COLUMN and not
         | about the row it points at. Retyping both to `?Course`/`?User` is the
         | real fix and it is **thirty-one PHPStan errors across nine files**, in
         | every module that dereferences an enrolment's course — a change of its
         | own, not a line inside a dashboard. The deeper fix is the FOREIGN KEY
         | neither column has, which would make the annotation true and delete
         | this filter; that is a migration with platform-wide deletion semantics
         | behind it. Reading the loaded relation directly is neither a cast nor a
         | suppression: it asks for what is actually there.
         |
         | ⚠️ AND THAT MAKES THE EAGER LOAD ABOVE LOAD-BEARING FOR THE GUARD, not
         | only for the query count: `getRelation()` on a relation nobody loaded
         | throws `RelationNotFoundException` rather than answering null. Drop the
         | `->with([...])` to «save a query» and this filter becomes a different
         | 500.
         */
        return $enrollments
            ->filter(fn (Enrollment $enrollment): bool => $enrollment->getRelation('student') !== null
                && $enrollment->getRelation('course') !== null)
            ->map(function (Enrollment $enrollment) use ($balances): array {
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
