<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

/**
 * Who is under a legal hold right now — for the side that does not know Compliance.
 *
 * ⛔ IT EXISTS BECAUSE THE HOLD IS DECLARED IN `Compliance` AND OBEYED IN EVERY
 * MODULE THAT SWEEPS ITS OWN DATA. `RunRetentionSweepJob` re-reads it before
 * every batch and passes ids down to `PersonalDataOwner::expire()`; a module-owned sweep that
 * never goes through that job needs its own door — and without one it imports
 * `LegalHold` directly, which the third constitutional principle forbids.
 *
 * ⚠️ INTEGERS OUT, AND NO COMPLIANCE TYPE IN THE SIGNATURE — the same rule
 * {@see OutstandingCreditsDirectory} and {@see SettlementClearance} follow. A
 * contract that names the other module's enum is that module's header wearing an
 * interface.
 *
 * ⚠️ AND IT IS ASKED BEFORE EVERY BATCH, NEVER ONCE AT THE TOP OF A RUN. A hold
 * placed while the sweep is walking has to protect what is left — deletion does
 * not reverse. That is the spelling `ExecuteDataErasure` already uses, and its
 * own comment calls it "the third door".
 *
 * ⚠️ AN EMPTY LIST MEANS "NOBODY IS HELD", NEVER "DELETE NOTHING" — and the
 * difference is written down because the error direction of the second is safe
 * and the first does not reverse. A caller that cannot get an answer must stop
 * the batch rather than treat the failure as an empty list.
 */
interface LegalHoldDirectory
{
    /** @return list<int> ids of subjects under a hold that is in force now */
    public function heldUserIds(): array;
}
