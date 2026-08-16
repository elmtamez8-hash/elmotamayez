<?php

declare(strict_types=1);

namespace App\Modules\Assessments\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Stamps a list of sessions with whether this student may open each one.
 *
 * ⚠️ THE BULK FORM EXISTS BECAUSE THE PER-ROW ONE IS AN N+1 BY CONSTRUCTION —
 * exactly the shape `WithholdingReader::stamp()` was written for in 006. A
 * Resource runs once per row, so a timetable of twenty sessions asking the gate
 * individually is six queries times twenty: a hundred and twenty, on the screen
 * a student opens first every morning. `QueryBudgetTest` fails over precisely
 * this, and would not have caught it here without this class existing to be used.
 *
 * The stamped attributes are NOT columns. They are computed per reader — the
 * same session is open for one student and shut for another — so storing them
 * would need a sweep per student per session, and the sweep would be wrong the
 * moment somebody handed in their homework.
 *
 * @phpstan-type Stampable \Illuminate\Database\Eloquent\Model
 */
class UnlockReader
{
    public function __construct(
        private readonly UnlockResolver $resolver,
    ) {}

    /**
     * @param  Collection<int, Model>  $sessions
     * @return Collection<int, Model>
     */
    public function stamp(Collection $sessions, User $student): Collection
    {
        if ($sessions->isEmpty()) {
            return $sessions;
        }

        $ids = [];

        foreach ($sessions as $session) {
            $ids[] = (int) $session->getKey();
        }

        $verdicts = $this->resolver->resolve($student, $ids);

        foreach ($sessions as $session) {
            $verdict = $verdicts[(int) $session->getKey()] ?? UnlockVerdict::open();

            $session->setAttribute('unlock_open', $verdict->open);
            $session->setAttribute('unlock_reason', $verdict->reason);
            $session->setAttribute('unlock_missing', $verdict->missing);
        }

        return $sessions;
    }
}
