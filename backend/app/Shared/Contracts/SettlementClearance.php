<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Models\User;
use App\Shared\Data\SettlementStanding;

/**
 * Whether a departing teacher's account is settled (spec 013 · FR-032).
 *
 * ⚠️ A CONTRACT RATHER THAN A QUERY, because `ContextIsolationTest` fails the
 * build on anything that joins the settlement and billing schemas — and a
 * `Compliance` query against `teaching_units` or `ledger_entries` is that join
 * wearing a third module's name. (That guard did not cover `Compliance` until
 * this phase widened it; the earlier claim that it already did was wrong.)
 *
 * ⚠️ AND NO AMOUNT REACHES THE TEACHER'S SCREEN FROM HERE. `GET /teaching/offboarding`
 * answers whether they are cleared and when the notice ends — not a figure. The
 * money in this product is a signed integer in minor units precisely because
 * Laravel's `decimal:2` cast returns a STRING, so every sum passes through a
 * float; tolerable for an order total, not for what decides a salary.
 */
interface SettlementClearance
{
    /** What is owed to the teacher and what is owed by them, in minor units. */
    public function outstandingFor(User $teacher, int $workspaceId): SettlementStanding;

    /** Whether nothing is outstanding in either direction. */
    public function isCleared(User $teacher, int $workspaceId): bool;
}
