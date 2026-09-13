<?php

declare(strict_types=1);

namespace App\Modules\Settlement\Support;

use App\Modules\Settlement\Actions\ReverseTeachingUnit;
use App\Modules\Settlement\Models\TeachingUnit;
use App\Shared\Contracts\SessionUnitReversal;

/**
 * ٠٣٥ — الجانبُ الذي يعرفُ ما الوحدةُ وكيف تُنقَض. راجعِ العقدَ لِمَ لا يكونُ مستمِعاً.
 */
class EloquentSessionUnitReversal implements SessionUnitReversal
{
    public function __construct(private readonly ReverseTeachingUnit $reverse) {}

    public function reverseSeat(int $classSessionId, int $studentUserId, string $reason): bool
    {
        /*
        | `withoutWorkspaceScope()` on both reads: the caller is a teacher or an
        | administrator acting in their own workspace, and the predicate below is
        | the session id — which already names one workspace. The scope here can
        | only bite the wrong way, for an officer whose `last_workspace_id` is
        | somewhere else (the five-layer defect spec 024 paid for).
        */
        $unit = TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('class_session_id', $classSessionId)
            ->where('student_user_id', $studentUserId)
            ->where('reversal_of_id', TeachingUnit::NOT_A_REVERSAL)
            ->first();

        if ($unit === null) {
            return false;
        }

        // ⚠️ BY THE UNIT'S OWN KEY. See the contract — neither of
        // `ReverseTeachingUnit`'s own guards can see a replay.
        $alreadyReversed = TeachingUnit::query()
            ->withoutWorkspaceScope()
            ->where('reversal_of_id', $unit->getKey())
            ->exists();

        if ($alreadyReversed) {
            return false;
        }

        return $this->reverse->handle($unit, $reason) !== null;
    }
}
