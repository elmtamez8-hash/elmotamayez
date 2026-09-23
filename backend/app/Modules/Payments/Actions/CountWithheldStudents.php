<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Models\CreditBalance;
use App\Modules\Payments\Support\WithholdingReader;
use App\Modules\Tenancy\Models\Workspace;
use App\Shared\Actions\Action;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * How many PEOPLE in a workspace are withheld in at least one course.
 *
 * The teacher's balances panel is paginated ({@see ListStudentBalances}), so a
 * caller that counted the withheld rows of the page it was handed would be
 * counting one page — quietly wrong for exactly the teachers with the most
 * students. This counts over every row, once, on the server.
 *
 * Distinct students, never rows: withholding is per course, so one student
 * stopped in two subjects is two rows and one person — counting rows tells the
 * teacher about twice the people they have.
 *
 * Derived by the same {@see WithholdingReader::stamp()} the rows use, never by a
 * predicate written beside it: `is_withheld` is five inputs deep, and a second
 * spelling of it in SQL is a dashboard that disagrees with the panel one click
 * away. Only a balance can be withheld (a student with no balance row holds
 * nothing and is not withheld), so the walk is over balances whose enrolment is
 * active, in chunks so memory stays flat however large the workspace — one
 * query per five hundred balances plus the stamp's own reads.
 */
class CountWithheldStudents extends Action
{
    private const CHUNK = 500;

    public function __construct(
        private readonly WithholdingReader $withholding,
    ) {}

    public function handle(Workspace $workspace): int
    {
        $withheld = [];

        CreditBalance::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('enrollments')
                ->whereColumn('enrollments.student_user_id', 'credit_balances.student_user_id')
                ->whereColumn('enrollments.course_id', 'credit_balances.course_id')
                ->where('enrollments.workspace_id', $workspace->getKey())
                ->where('enrollments.status', 'active'))
            // The same two orphans the panel skips, so the card never counts a
            // person the panel does not list.
            ->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('users')
                ->whereColumn('users.id', 'credit_balances.student_user_id'))
            ->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('courses')
                ->whereColumn('courses.id', 'credit_balances.course_id'))
            ->chunkById(self::CHUNK, function (Collection $balances) use (&$withheld): void {
                foreach ($this->withholding->stamp($balances) as $balance) {
                    if ((bool) $balance->getAttribute('is_withheld')) {
                        $withheld[(int) $balance->student_user_id] = true;
                    }
                }
            });

        return count($withheld);
    }
}
