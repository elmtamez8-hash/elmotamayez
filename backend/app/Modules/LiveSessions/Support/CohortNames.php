<?php

declare(strict_types=1);

namespace App\Modules\LiveSessions\Support;

use App\Modules\LiveSessions\Models\ClassSession;
use App\Shared\Contracts\CohortDirectory;

/**
 * Puts the group's NAME on a page of sessions, in one query for the page.
 *
 * ⚠️ THE BULK STAMP IS THE WHOLE DESIGN, and it is `UnlockReader::stamp()`'s
 * shape deliberately. A Resource runs once per row, so a name looked up inside
 * one is an N+1 by construction — fifty sessions on a teacher's calendar is
 * fifty extra queries, on the screen they open first every morning, and
 * `QueryBudgetTest` is what would eventually notice.
 *
 * ⚠️ AND IT GOES THROUGH THE CONTRACT, NEVER THROUGH A `cohort()` RELATION.
 * There is no such relation on `ClassSession` and there must not be: the cohort
 * is Learning's model, `CohortSessionVisibility` says in as many words that this
 * module never imports one, and a relation added «just for a label» is how the
 * boundary stops being one.
 *
 * ⚠️ A SESSION WITH NO GROUP IS STAMPED `null` ON PURPOSE, rather than left
 * unstamped. The two read identically off the model — an unset attribute is null
 * too — but the difference matters to whoever reads this next: every row that
 * went through here has an answer, so a null in the payload means «no group»
 * and never «nobody asked». An id whose row is gone lands on the same null,
 * which is the right answer for a calendar that only wanted a label.
 */
final class CohortNames
{
    /** @param iterable<ClassSession> $sessions */
    public static function stamp(iterable $sessions): void
    {
        $ids = [];

        foreach ($sessions as $session) {
            if ($session->cohort_id !== null) {
                $ids[] = (int) $session->cohort_id;
            }
        }

        $names = $ids === []
            ? []
            : app(CohortDirectory::class)->namesFor(array_values(array_unique($ids)));

        foreach ($sessions as $session) {
            $session->setAttribute(
                'cohort_name',
                $session->cohort_id === null ? null : ($names[(int) $session->cohort_id] ?? null),
            );
        }
    }
}
